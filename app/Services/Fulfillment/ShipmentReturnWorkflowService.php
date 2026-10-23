<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\RiderVehicleType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShipmentReturnWorkflowService
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly ShipmentWorkflowService $shipments,
    ) {}

    public function available(): bool
    {
        return $this->schema->shipmentReturnsReady();
    }

    public function hasOpenWorkflow(Shipment $shipment): bool
    {
        return $this->available()
            && ShipmentReturnWorkflow::query()
                ->where('shipment_id', $shipment->id)
                ->whereIn('status', ShipmentReturnWorkflow::OPEN_STATUSES)
                ->exists();
    }

    public function initiateFromDeliveryFailure(Shipment $shipment, RiderAssignment $assignment, User $actor, string $reason): ShipmentReturnWorkflow
    {
        $this->requireAvailable();

        $centerId = $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id;
        if (! $centerId) {
            throw new RuntimeException('This shipment has no assigned return sorting center.');
        }

        return ShipmentReturnWorkflow::query()->firstOrCreate(
            ['shipment_id' => $shipment->id],
            [
                'logistics_center_id' => $centerId,
                'original_delivery_assignment_id' => $assignment->id,
                'status' => ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF,
                'reason' => trim($reason),
                'initiated_by_user_id' => $actor->id,
                'initiated_at' => now(),
            ],
        );
    }

    public function handoffToCenterAsOriginalRider(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $assignment = $locked->originalDeliveryAssignment()->firstOrFail();
            $this->assertAssignedRider($assignment, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::AWAITING_CENTER_HANDOFF]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();

            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_CENTER_HANDOFF', $method, $shipment->tracking_number, 'SUCCESS', $center, $assignment, $notes);
            $locked->update([
                'status' => ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT,
                'center_handoff_by_user_id' => $actor->id,
                'center_handoff_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Return parcel handed to the sorting center by the original delivery rider.', $assignment);

            return $locked->refresh();
        });
    }

    public function receiveAtCenter(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();

            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_CENTER_RECEIVED', $method, $shipment->tracking_number, 'SUCCESS', $center, null, $notes);
            $locked->update([
                'status' => ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT,
                'center_received_by_user_id' => $actor->id,
                'center_received_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Sorting center confirmed receipt of the returned parcel.');

            return $locked->refresh();
        });
    }

    public function assignReturnRider(ShipmentReturnWorkflow $workflow, User $actor, int $riderProfileId): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $riderProfileId): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $center = $this->assertLogisticsCenter($locked, $actor);
            $this->assertStatus($locked, [
                ShipmentReturnWorkflow::READY_FOR_RETURN_ASSIGNMENT,
                ShipmentReturnWorkflow::ASSIGNED_TO_RETURN_RIDER,
                ShipmentReturnWorkflow::RETURN_RIDER_ACCEPTED,
            ]);
            $rider = RiderProfile::query()
                ->whereKey($riderProfileId)
                ->where('logistics_center_id', $center->id)
                ->whereIn('status', ['ACTIVE', 'WARNED'])
                ->whereIn('vehicle_type', RiderVehicleType::localValues())
                ->first();
            if (! $rider) {
                throw new RuntimeException('Select an active local rider assigned to this sorting center.');
            }
            if ((int) $locked->return_rider_profile_id === (int) $rider->id) {
                throw new RuntimeException('This rider is already assigned to this return.');
            }
            $previousRiderName = $locked->returnRider()->with('user')->first()?->user?->name;
            $riderName = $rider->user?->name ?? 'the selected rider';

            $locked->update([
                'return_rider_profile_id' => $rider->id,
                'return_rider_assigned_by_user_id' => $actor->id,
                'return_rider_assigned_at' => now(),
                'return_rider_accepted_at' => null,
                'return_rider_picked_up_by_user_id' => null,
                'return_rider_picked_up_at' => null,
                'seller_handoff_by_user_id' => null,
                'seller_handoff_at' => null,
                'status' => ShipmentReturnWorkflow::ASSIGNED_TO_RETURN_RIDER,
            ]);
            $assignmentNote = $previousRiderName
                ? "Logistics reassigned the return rider from {$previousRiderName} to {$riderName}."
                : "Logistics assigned {$riderName} as the return-to-seller rider.";
            $this->recordReturnEvent($locked->shipment()->firstOrFail(), $actor, $center, $assignmentNote);

            return $locked->refresh();
        });
    }

    public function acceptAsReturnRider(ShipmentReturnWorkflow $workflow, User $actor): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::ASSIGNED_TO_RETURN_RIDER]);
            $locked->update([
                'status' => ShipmentReturnWorkflow::RETURN_RIDER_ACCEPTED,
                'return_rider_accepted_at' => now(),
            ]);
            $this->recordReturnEvent($locked->shipment()->firstOrFail(), $actor, $locked->logisticsCenter()->firstOrFail(), 'Return rider accepted the seller return.');

            return $locked->refresh();
        });
    }

    public function pickupFromCenterAsReturnRider(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::RETURN_RIDER_ACCEPTED]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $center = $locked->logisticsCenter()->firstOrFail();

            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_SELLER_PICKUP', $method, $shipment->tracking_number, 'SUCCESS', $center, null, $notes);
            $locked->update([
                'status' => ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER,
                'return_rider_picked_up_by_user_id' => $actor->id,
                'return_rider_picked_up_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, $center, 'Return rider collected the parcel from the sorting center.');

            return $locked->refresh();
        });
    }

    public function handoffToSellerAsReturnRider(ShipmentReturnWorkflow $workflow, User $actor, string $method, string $code, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $method, $code, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $this->assertReturnRider($locked, $actor);
            $this->assertStatus($locked, [ShipmentReturnWorkflow::IN_TRANSIT_TO_SELLER]);
            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $this->assertDeliveryFailed($shipment);
            $this->assertTrackingCode($shipment, $code);
            $this->shipments->recordScan($shipment, $actor, 'RETURN_TO_SELLER_HANDOFF', $method, $shipment->tracking_number, 'SUCCESS', null, null, $notes);
            $locked->update([
                'status' => ShipmentReturnWorkflow::AWAITING_SELLER_CONFIRMATION,
                'seller_handoff_by_user_id' => $actor->id,
                'seller_handoff_at' => now(),
            ]);
            $this->recordReturnEvent($shipment, $actor, null, 'Return rider recorded seller handoff; seller confirmation is pending.', null, false);

            return $locked->refresh();
        });
    }

    public function confirmSellerReceipt(ShipmentReturnWorkflow $workflow, User $actor, ?string $notes = null): ShipmentReturnWorkflow
    {
        return DB::transaction(function () use ($workflow, $actor, $notes): ShipmentReturnWorkflow {
            $locked = $this->lockWorkflow($workflow);
            $shipment = $locked->shipment()->with('sellerOrder')->lockForUpdate()->firstOrFail();
            $sellerId = $actor->sellerProfile?->id;
            if (! $sellerId || (int) $shipment->sellerOrder?->seller_profile_id !== (int) $sellerId) {
                abort(403);
            }
            if ($locked->status === ShipmentReturnWorkflow::SELLER_RECEIVED) {
                return $locked;
            }
            $this->assertStatus($locked, [ShipmentReturnWorkflow::AWAITING_SELLER_CONFIRMATION]);
            $this->assertDeliveryFailed($shipment);
            $this->shipments->recordScan(
                $shipment,
                $actor,
                'RETURN_TO_SELLER_CONFIRMED',
                'MANUAL',
                $shipment->tracking_number,
                'SUCCESS',
                null,
                null,
                $notes ?: 'Seller confirmed receipt of the returned parcel.',
            );
            $locked->update([
                'status' => ShipmentReturnWorkflow::SELLER_RECEIVED,
                'seller_received_by_user_id' => $actor->id,
                'seller_received_at' => now(),
                'seller_confirmation_notes' => $notes,
            ]);
            $shipment->update(['current_status' => 'RETURNED']);
            $this->shipments->recordEvent($shipment, 'RETURNED', $actor, 'RETURN_TO_SELLER_COMPLETED: Seller confirmed receipt after reverse custody handoff.', null, null, false);

            return $locked->refresh();
        });
    }

    private function lockWorkflow(ShipmentReturnWorkflow $workflow): ShipmentReturnWorkflow
    {
        $this->requireAvailable();

        return ShipmentReturnWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
    }

    private function requireAvailable(): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Return-to-seller workflow is unavailable until its additive database migration is installed.');
        }
    }

    private function assertStatus(ShipmentReturnWorkflow $workflow, array $allowed): void
    {
        if (! in_array($workflow->status, $allowed, true)) {
            throw new RuntimeException('This return step is no longer available. Refresh the page to see the current custody status.');
        }
    }

    private function assertDeliveryFailed(Shipment $shipment): void
    {
        if ($shipment->current_status !== 'DELIVERY_FAILED') {
            throw new RuntimeException('The parcel must remain in DELIVERY_FAILED until the seller confirms return receipt.');
        }
    }

    private function assertTrackingCode(Shipment $shipment, string $code): void
    {
        if (! hash_equals((string) $shipment->tracking_number, trim($code))) {
            throw new RuntimeException('The scanned code does not match this parcel tracking number.');
        }
    }

    private function assertLogisticsCenter(ShipmentReturnWorkflow $workflow, User $actor): LogisticsCenter
    {
        $center = $actor->logisticsCenter;
        if (! $center || (int) $center->id !== (int) $workflow->logistics_center_id) {
            abort(403);
        }

        return $center;
    }

    private function assertAssignedRider(RiderAssignment $assignment, User $actor): void
    {
        $actor->loadMissing('riderProfile');
        if ($assignment->assignment_type !== RiderAssignment::TYPE_DELIVERY
            || ! $actor->riderProfile
            || (int) $assignment->rider_profile_id !== (int) $actor->riderProfile->id) {
            abort(403);
        }
    }

    private function assertReturnRider(ShipmentReturnWorkflow $workflow, User $actor): RiderProfile
    {
        $actor->loadMissing('riderProfile');
        $profile = $actor->riderProfile;
        if (! $profile || (int) $profile->id !== (int) $workflow->return_rider_profile_id) {
            abort(403);
        }

        return $profile;
    }

    private function recordReturnEvent(Shipment $shipment, User $actor, ?LogisticsCenter $center, string $notes, ?RiderAssignment $assignment = null, bool $useShipmentCenterFallback = true): void
    {
        $this->shipments->recordEvent($shipment, 'DELIVERY_FAILED', $actor, 'RETURN_CUSTODY: '.$notes, $assignment, $center, $useShipmentCenterFallback);
    }
}
