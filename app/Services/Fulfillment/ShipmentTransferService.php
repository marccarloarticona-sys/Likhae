<?php

namespace App\Services\Fulfillment;

use App\Events\ShipmentTrackingUpdated;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Logistics\ShipmentReturnWorkflow;
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
                && in_array($last->status, [ShipmentTransferLeg::PLANNED, ShipmentTransferLeg::READY], true)) {
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

    public function expectedTrackingCode(ShipmentTransferLeg $leg): string
    {
        if ($this->schema->buyerReturnWaybillsReady()) {
            $returnCode = ShipmentReturnWorkflow::query()
                ->where('shipment_id', $leg->shipment_id)
                ->whereNotNull('seller_return_refund_request_id')
                ->where('status', ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER)
                ->where('logistics_center_id', $leg->origin_logistics_center_id)
                ->value('return_tracking_number');
            if (filled($returnCode)) {
                return (string) $returnCode;
            }
        }

        return (string) Shipment::query()->whereKey($leg->shipment_id)->value('tracking_number');
    }

    public function custodyCenterId(ShipmentTransferLeg $leg): int
    {
        $workflow = $this->pendingSellerReturnForLeg($leg);
        if ($workflow?->seller_return_refund_request_id) {
            return (int) $workflow->logistics_center_id;
        }

        return (int) Shipment::query()->whereKey($leg->shipment_id)->value('logistics_center_id');
    }

    public function markReady(ShipmentTransferLeg $leg, User $actor): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor): ShipmentTransferLeg {
            $leg = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($leg->shipment_id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $leg->origin_logistics_center_id);
            $this->assertPreviousLegReceived($leg);
            $returnWorkflow = $this->pendingSellerReturnForLeg($leg);

            if ($leg->status === ShipmentTransferLeg::READY) {
                return $leg;
            }
            if ($leg->status !== ShipmentTransferLeg::PLANNED) {
                throw new RuntimeException('Only a planned transfer leg can become ready.');
            }
            $currentCenterId = $returnWorkflow?->seller_return_refund_request_id
                ? (int) $returnWorkflow->logistics_center_id
                : (int) $shipment->logistics_center_id;
            if ($currentCenterId !== (int) $leg->origin_logistics_center_id) {
                throw new RuntimeException('The shipment is not in the transfer origin hub custody.');
            }

            $leg->update(['status' => ShipmentTransferLeg::READY]);
            $this->recordReturnProgressForLeg($leg, $actor, 'Return transfer to the seller-side center is ready to join an authorized manifest.');

            return $leg->refresh();
        });
    }

    public function release(ShipmentTransferLeg $leg, User $actor, string $scannedCode, string $scanMethod = 'MANUAL'): ShipmentTransferLeg
    {
        return $this->transitionWithScan($leg, $actor, $scannedCode, ShipmentTransferLeg::READY, ShipmentTransferLeg::RELEASED, 'TRANSFER_RELEASE', function (ShipmentTransferLeg $locked, User $user): void {
            $locked->update(['released_by_user_id' => $user->id, 'released_at' => now()]);
        }, $scanMethod);
    }

    public function depart(ShipmentTransferLeg $leg, User $actor, ?string $scannedCode = null, string $scanMethod = 'MANUAL'): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor, $scannedCode, $scanMethod): ShipmentTransferLeg {
            $locked = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            $this->assertOriginTransporter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status === ShipmentTransferLeg::IN_TRANSIT) {
                return $locked;
            }
            if ($locked->status !== ShipmentTransferLeg::RELEASED) {
                throw new RuntimeException('Only a released transfer leg can depart.');
            }

            if (filled($scannedCode)) {
                $this->recordScan($locked, $actor, (string) $scannedCode, 'TRANSFER_DEPARTURE', $scanMethod);
            }
            $locked->update(['status' => ShipmentTransferLeg::IN_TRANSIT]);
            $this->recordReturnProgressForLeg($locked, $actor, 'Return parcel is in transit between sorting centers.');

            return $locked->refresh();
        });
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

    public function receive(ShipmentTransferLeg $leg, User $actor, string $scannedCode, string $scanMethod = 'MANUAL', bool $completeReturnReception = true): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor, $scannedCode, $scanMethod, $completeReturnReception): ShipmentTransferLeg {
            $locked = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()->whereKey($locked->shipment_id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->destination_logistics_center_id);

            if ($locked->status === ShipmentTransferLeg::RECEIVED) {
                return $locked;
            }
            if ($locked->status !== ShipmentTransferLeg::IN_TRANSIT) {
                throw new RuntimeException('Only an in-transit transfer leg can be received.');
            }
            $this->recordScan($locked, $actor, $scannedCode, 'TRANSFER_RECEIVE', $scanMethod);

            $locked->update([
                'status' => ShipmentTransferLeg::RECEIVED,
                'received_by_user_id' => $actor->id,
                'received_at' => now(),
            ]);
            $returnWorkflow = $this->pendingSellerReturnForLeg($locked);
            $isBuyerReturn = (bool) $returnWorkflow?->seller_return_refund_request_id;
            $isReturnTransfer = $returnWorkflow
                && ($isBuyerReturn || $shipment->current_status === 'DELIVERY_FAILED')
                && (int) $locked->destination_logistics_center_id === (int) $shipment->origin_logistics_center_id;

            if ($isReturnTransfer) {
                if (! $isBuyerReturn) {
                    $shipment->update(['logistics_center_id' => $locked->destination_logistics_center_id]);
                }
                if ($completeReturnReception) {
                    $returnWorkflow->update([
                        'logistics_center_id' => $locked->destination_logistics_center_id,
                        'status' => ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT,
                        'return_exception_reason' => null,
                        'return_exception_recorded_by_user_id' => null,
                        'return_exception_recorded_at' => null,
                    ]);
                    $this->recordReturnTransferEvent($shipment, $actor, $locked->destinationLogisticsCenter()->first(), 'Seller-side center confirmed receipt of the return parcel; final return-rider assignment is pending.', $returnWorkflow, $locked);
                } else {
                    $returnWorkflow->update([
                        'logistics_center_id' => $locked->destination_logistics_center_id,
                        'status' => ShipmentReturnWorkflow::RETURN_EXCEPTION,
                        'return_exception_reason' => 'The parcel was scanned at the seller-side center with a condition exception. Logistics review is required before assignment.',
                        'return_exception_recorded_by_user_id' => $actor->id,
                        'return_exception_recorded_at' => now(),
                    ]);
                    $this->recordReturnTransferEvent($shipment, $actor, $locked->destinationLogisticsCenter()->first(), 'Seller-side center confirmed receipt with a condition exception; Logistics review is required.', $returnWorkflow, $locked);
                }
            } else {
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
            }

            return $locked->refresh();
        });
    }

    private function transitionWithScan(ShipmentTransferLeg $leg, User $actor, string $scannedCode, string $from, string $to, string $scanType, ?callable $beforeReturn = null, string $scanMethod = 'MANUAL'): ShipmentTransferLeg
    {
        $this->assertReady();

        return DB::transaction(function () use ($leg, $actor, $scannedCode, $from, $to, $scanType, $beforeReturn, $scanMethod): ShipmentTransferLeg {
            $locked = ShipmentTransferLeg::query()->whereKey($leg->id)->lockForUpdate()->firstOrFail();
            $this->assertActorAtCenter($actor, (int) $locked->origin_logistics_center_id);
            if ($locked->status === $to) {
                return $locked;
            }
            if ($locked->status !== $from) {
                throw new RuntimeException("Transfer leg must be {$from} before it can become {$to}.");
            }
            $this->recordScan($locked, $actor, $scannedCode, $scanType, $scanMethod);
            $locked->update(['status' => $to]);
            if ($beforeReturn) {
                $beforeReturn($locked, $actor);
            }
            if ($scanType === 'TRANSFER_RELEASE') {
                $this->recordReturnProgressForLeg($locked, $actor, 'Return parcel was released into an authorized inter-center transfer.');
            }

            return $locked->refresh();
        });
    }

    private function recordScan(ShipmentTransferLeg $leg, User $actor, string $scannedCode, string $scanType, string $scanMethod = 'MANUAL'): ParcelScan
    {
        $code = trim($scannedCode);
        if ($code === '') {
            throw new RuntimeException('A shipment tracking number is required for a transfer custody scan.');
        }
        $returnWorkflow = $this->pendingSellerReturnForLeg($leg);
        $expected = filled($returnWorkflow?->return_tracking_number)
            ? (string) $returnWorkflow->return_tracking_number
            : (string) Shipment::query()->whereKey($leg->shipment_id)->value('tracking_number');
        if ($expected === '' || strtoupper($code) !== strtoupper(trim($expected))) {
            throw new RuntimeException('The scanned barcode does not match the shipment assigned to this transfer leg.');
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
            'scan_method' => in_array(strtoupper($scanMethod), ['QR', 'BARCODE'], true) ? strtoupper($scanMethod) : 'MANUAL',
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
        if ($actor->account_type !== User::TYPE_LOGISTICS
            || $actor->status !== User::STATUS_ACTIVE
            || (int) $actor->logisticsCenter?->id !== $centerId) {
            throw new RuntimeException('The authenticated user is not authorized for this logistics center.');
        }
    }

    private function assertOriginTransporter(User $actor, int $centerId): void
    {
        $actor->loadMissing('riderProfile.logisticsCenter');
        $rider = $actor->riderProfile;
        if ($actor->account_type !== User::TYPE_RIDER
            || $actor->status !== User::STATUS_ACTIVE
            || ! in_array($rider?->status, ['ACTIVE', 'WARNED'], true)
            || (int) $rider?->logistics_center_id !== $centerId
            || ! \App\Support\RiderVehicleType::isInterLogistics($rider?->vehicle_type)) {
            throw new RuntimeException('Only the assigned active inter-logistics driver may depart this transfer.');
        }
    }

    private function pendingSellerReturnForLeg(ShipmentTransferLeg $leg): ?ShipmentReturnWorkflow
    {
        if (! $this->schema->shipmentReturnEnhancementsReady()) {
            return null;
        }

        $query = ShipmentReturnWorkflow::query()
            ->where('shipment_id', $leg->shipment_id)
            ->where('status', ShipmentReturnWorkflow::AWAITING_CROSS_CENTER_TRANSFER)
            ->where('logistics_center_id', $leg->origin_logistics_center_id);
        if ($this->schema->buyerReturnWaybillsReady()) {
            $query->where(fn ($pending) => $pending->whereNotNull('seller_return_refund_request_id')
                ->orWhere('return_disposition', ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN));
        } else {
            $query->where('return_disposition', ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN);
        }

        return $query->lockForUpdate()->first();
    }

    private function recordReturnProgressForLeg(ShipmentTransferLeg $leg, User $actor, string $notes): void
    {
        $workflow = $this->pendingSellerReturnForLeg($leg);
        if (! $workflow) {
            return;
        }

        $shipment = $leg->shipment()->firstOrFail();
        $center = $leg->originLogisticsCenter()->first();
        $this->recordReturnTransferEvent($shipment, $actor, $center, $notes, $workflow, $leg);
    }

    private function recordReturnTransferEvent(Shipment $shipment, User $actor, ?LogisticsCenter $center, string $notes, ?ShipmentReturnWorkflow $workflow = null, ?ShipmentTransferLeg $leg = null): void
    {
        if ($workflow?->seller_return_refund_request_id) {
            $sellerRequest = $workflow->sellerReturnRefundRequest()->with('request')->first();
            if ($sellerRequest?->request) {
                $lowerNotes = strtolower($notes);
                $custodyEvent = str_contains($lowerNotes, 'released into')
                    ? 'TRANSFER_RELEASED'
                    : (str_contains($lowerNotes, 'confirmed receipt') ? 'TRANSFER_RECEIVED' : null);
                if ($custodyEvent) {
                    app(\App\Services\Returns\ReturnCustodyService::class)->recordCustodyEvent($sellerRequest, $actor, $custodyEvent, [
                        'from_party' => 'LOGISTICS_CENTER',
                        'to_party' => 'LOGISTICS_CENTER',
                        'shipment_id' => $shipment->id,
                        'transfer_reference' => $leg ? 'Shipment transfer leg '.$leg->sequence_no : null,
                        'location_label' => $center?->business_name,
                        'notes' => $notes,
                        'metadata' => ['return_tracking_number' => $workflow->return_tracking_number, 'transfer_leg_id' => $leg?->id],
                    ]);
                }
                app(\App\Services\ReturnRefundWorkflowService::class)->recordEvent(
                    $sellerRequest->request,
                    'BUYER_RETURN_TRANSFER_PROGRESS',
                    $actor,
                    $sellerRequest,
                    $sellerRequest->status,
                    $sellerRequest->status,
                    $notes,
                    ['return_tracking_number' => $workflow->return_tracking_number, 'workflow_status' => $workflow->status, 'transfer_leg_id' => $leg?->id],
                );
            }

            return;
        }

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'status' => 'DELIVERY_FAILED',
            'actor_user_id' => $actor->id,
            'logistics_center_id' => $center?->id,
            'rider_assignment_id' => null,
            'notes' => 'RETURN_CUSTODY: '.$notes,
            'occurred_at' => now(),
        ]);
        event(ShipmentTrackingUpdated::fromShipment($shipment->fresh()));
    }

    private function assertReady(): void
    {
        if (! $this->schema->transferReady()) {
            throw new RuntimeException('Transfer schema is not installed; cross-hub custody is disabled.');
        }
    }
}
