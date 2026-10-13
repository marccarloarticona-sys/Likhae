<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ShipmentTransferService
{
    public function __construct(private readonly LogisticsSchemaReadiness $schema)
    {
    }

    public function createPlannedLeg(Shipment $shipment, LogisticsCenter $origin, LogisticsCenter $destination): ?ShipmentTransferLeg
    {
        $this->assertReady();

        if ((int) $origin->id === (int) $destination->id) {
            return null;
        }

        $originAddress = $origin->address;
        $destinationAddress = $destination->address;
        if (! $originAddress || ! $destinationAddress
            || blank($originAddress->province_code) || blank($originAddress->municipality_code)
            || blank($destinationAddress->province_code) || blank($destinationAddress->municipality_code)) {
            throw new RuntimeException('Transfer routing requires valid origin and destination municipality PSGC snapshots.');
        }

        return DB::transaction(function () use ($shipment, $origin, $destination, $originAddress, $destinationAddress): ShipmentTransferLeg {
            $last = ShipmentTransferLeg::query()
                ->where('shipment_id', $shipment->id)
                ->orderByDesc('sequence_no')
                ->lockForUpdate()
                ->first();

            if ($last && (int) $last->destination_logistics_center_id !== (int) $origin->id) {
                throw new RuntimeException('The next transfer origin must match the previous leg destination.');
            }

            if ($last
                && (int) $last->origin_logistics_center_id === (int) $origin->id
                && (int) $last->destination_logistics_center_id === (int) $destination->id
                && $last->status === ShipmentTransferLeg::PLANNED) {
                return $last;
            }

            return ShipmentTransferLeg::query()->create([
                'shipment_id' => $shipment->id,
                'sequence_no' => $last ? ((int) $last->sequence_no + 1) : 1,
                'origin_logistics_center_id' => $origin->id,
                'destination_logistics_center_id' => $destination->id,
                'origin_province_code' => $originAddress->province_code,
                'origin_municipality_code' => $originAddress->municipality_code,
                'destination_province_code' => $destinationAddress->province_code,
                'destination_municipality_code' => $destinationAddress->municipality_code,
                'status' => ShipmentTransferLeg::PLANNED,
            ]);
        });
    }

    public function markReady(ShipmentTransferLeg $leg, User $actor): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor): ShipmentTransferLeg {
            $leg = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($leg->shipment_id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $leg->origin_logistics_center_id);
            $this->assertPreviousLegReceived($leg);

            if ($leg->status === ShipmentTransferLeg::READY) {
                return $leg;
            }
            if ($leg->status !== ShipmentTransferLeg::PLANNED) {
                throw new RuntimeException('Only a planned transfer leg can become ready.');
            }
            if ((int) $shipment->logistics_center_id !== (int) $leg->origin_logistics_center_id) {
                throw new RuntimeException('The shipment is not in the transfer origin hub custody.');
            }

            return tap($leg)->update(['status' => ShipmentTransferLeg::READY]);
        });
    }

    public function release(ShipmentTransferLeg $leg, User $actor, string $scannedCode): ShipmentTransferLeg
    {
        return $this->transitionWithScan($leg, $actor, $scannedCode, ShipmentTransferLeg::READY, ShipmentTransferLeg::RELEASED, 'TRANSFER_RELEASE', function (ShipmentTransferLeg $locked, User $user): void {
            $locked->update(['released_by_user_id' => $user->id, 'released_at' => now()]);
        });
    }

    public function depart(ShipmentTransferLeg $leg, User $actor, string $scannedCode): ShipmentTransferLeg
    {
        return $this->transitionWithScan($leg, $actor, $scannedCode, ShipmentTransferLeg::RELEASED, ShipmentTransferLeg::IN_TRANSIT, 'TRANSFER_DEPARTURE', null, true);
    }

    public function recordArrival(ShipmentTransferLeg $leg, User $actor, string $scannedCode): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor, $scannedCode): ShipmentTransferLeg {
            $locked = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->destination_logistics_center_id);
            if ($locked->status !== ShipmentTransferLeg::IN_TRANSIT) {
                throw new RuntimeException('Only an in-transit transfer leg can be marked arrived.');
            }
            $this->recordScan($locked, $actor, $scannedCode, 'TRANSFER_ARRIVAL');

            return $locked;
        });
    }

    public function receive(ShipmentTransferLeg $leg, User $actor, string $scannedCode): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor, $scannedCode): ShipmentTransferLeg {
            $locked = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($locked->shipment_id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->destination_logistics_center_id);

            if ($locked->status === ShipmentTransferLeg::RECEIVED) {
                return $locked;
            }
            if ($locked->status !== ShipmentTransferLeg::IN_TRANSIT) {
                throw new RuntimeException('Only an in-transit transfer leg can be received.');
            }
            $this->recordScan($locked, $actor, $scannedCode, 'TRANSFER_RECEIVE');

            $locked->update([
                'status' => ShipmentTransferLeg::RECEIVED,
                'received_by_user_id' => $actor->id,
                'received_at' => now(),
            ]);
            $shipment->update([
                'logistics_center_id' => $locked->destination_logistics_center_id,
                'current_status' => 'AT_SORTING_CENTER',
            ]);
            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'status' => $shipment->current_status,
                'actor_user_id' => $actor->id,
                'logistics_center_id' => $locked->destination_logistics_center_id,
                'rider_assignment_id' => null,
                'notes' => 'Transfer leg received after destination custody scan.',
                'occurred_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    private function transitionWithScan(ShipmentTransferLeg $leg, User $actor, string $scannedCode, string $from, string $to, string $scanType, ?callable $beforeReturn = null, bool $allowInterDriver = false): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor, $scannedCode, $from, $to, $scanType, $beforeReturn): ShipmentTransferLeg {
            $locked = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            if ($allowInterDriver) {
                $this->assertOriginTransporter($actor, (int) $locked->origin_logistics_center_id);
            } else {
                $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            }
            if ($locked->status === $to) {
                return $locked;
            }
            if ($locked->status !== $from) {
                throw new RuntimeException("Transfer leg must be {$from} before it can become {$to}.");
            }
            $this->recordScan($locked, $actor, $scannedCode, $scanType);
            $locked->update(['status' => $to]);
            if ($beforeReturn) {
                $beforeReturn($locked, $actor);
            }

            return $locked->refresh();
        });
    }

    private function recordScan(ShipmentTransferLeg $leg, User $actor, string $scannedCode, string $scanType): ParcelScan
    {
        $code = trim($scannedCode);
        if ($code === '') {
            throw new RuntimeException('A shipment tracking number is required for a transfer custody scan.');
        }

        $existing = ParcelScan::query()
            ->where('transfer_leg_id', $leg->id)
            ->where('scan_type', $scanType)
            ->where('scanned_code', $code)
            ->where('result', 'SUCCESS')
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        return ParcelScan::query()->create([
            'shipment_id' => $leg->shipment_id,
            'scanned_by_user_id' => $actor->id,
            'logistics_center_id' => $scanType === 'TRANSFER_RELEASE' || $scanType === 'TRANSFER_DEPARTURE'
                ? $leg->origin_logistics_center_id
                : $leg->destination_logistics_center_id,
            'rider_assignment_id' => null,
            'transfer_leg_id' => $leg->id,
            'scan_type' => $scanType,
            'scan_method' => 'MANUAL',
            'scanned_code' => $code,
            'result' => 'SUCCESS',
            'notes' => 'Transfer custody scan.',
            'scanned_at' => now(),
        ]);
    }

    private function assertPreviousLegReceived(ShipmentTransferLeg $leg): void
    {
        if ((int) $leg->sequence_no <= 1) {
            return;
        }

        $previous = ShipmentTransferLeg::query()
            ->where('shipment_id', $leg->shipment_id)
            ->where('sequence_no', (int) $leg->sequence_no - 1)
            ->lockForUpdate()
            ->first();
        if (! $previous || $previous->status !== ShipmentTransferLeg::RECEIVED
            || (int) $previous->destination_logistics_center_id !== (int) $leg->origin_logistics_center_id) {
            throw new RuntimeException('The previous transfer leg must be received before this leg can proceed.');
        }
    }

    private function assertActorAtCenter(User $actor, int $centerId): void
    {
        if ((int) $actor->logisticsCenter?->id !== $centerId) {
            throw new RuntimeException('The authenticated user is not authorized for this logistics center.');
        }
    }

    private function assertOriginTransporter(User $actor, int $centerId): void
    {
        $actor->loadMissing('riderProfile.logisticsCenter');
        $rider = $actor->riderProfile;
        if ($actor->account_type !== User::TYPE_RIDER
            || $actor->status !== User::STATUS_ACTIVE
            || $rider?->status !== 'ACTIVE'
            || (int) $rider?->logistics_center_id !== $centerId
            || ! \App\Support\RiderVehicleType::isInterLogistics($rider?->vehicle_type)) {
            throw new RuntimeException('Only the assigned active inter-logistics driver may depart this transfer.');
        }
    }

    private function assertReady(): void
    {
        if (! $this->schema->transferReady()) {
            throw new RuntimeException('Transfer schema is not installed; cross-hub custody is disabled.');
        }
    }
}
