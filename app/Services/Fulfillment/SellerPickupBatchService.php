<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Support\LocalTripCapacityRules;
use App\Support\RiderVehicleType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SellerPickupBatchService
{
    private const ACTIVE_ASSIGNMENT_STATUSES = ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'];

    private const RESOLVED_ASSIGNMENT_STATUSES = ['COMPLETED', 'REJECTED', 'CANCELLED'];

    public function __construct(
        private readonly ShipmentWorkflowService $workflow,
        private readonly LocalRiderTripService $trips,
    ) {}

    /** @param list<int> $shipmentIds */
    public function create(
        User $actor,
        LogisticsCenter $center,
        int $riderProfileId,
        array $shipmentIds,
        bool $coverageOverride = false,
        ?string $coverageOverrideReason = null,
    ): RiderLocalTrip
    {
        $ids = array_values(array_unique(array_map('intval', $shipmentIds)));
        $count = count($ids);
        if ($count < 1) {
            throw new RuntimeException('Select at least one ready parcel for the seller pickup assignment.');
        }
        if ($count > LocalTripCapacityRules::MAX_PARCELS_PER_TRIP) {
            throw new RuntimeException('A seller pickup assignment can contain no more than 10 parcels.');
        }

        return DB::transaction(function () use ($actor, $center, $riderProfileId, $ids, $count, $coverageOverride, $coverageOverrideReason): RiderLocalTrip {
            $this->assertCenterActor($actor, $center);
            $rider = RiderProfile::query()
                ->with(['user', 'logisticsCenter', 'areaAssignments.serviceArea.locations'])
                ->whereKey($riderProfileId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($rider->status, ['ACTIVE', 'WARNED'], true)
                || $rider->user?->account_type !== User::TYPE_RIDER
                || $rider->user?->status !== User::STATUS_ACTIVE
                || ! RiderVehicleType::isLocalBarangay($rider->vehicle_type)) {
                throw new RuntimeException('Seller pickup assignments require an active or warned motorcycle or tricycle barangay rider.');
            }
            if ((int) $rider->logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('Select a rider assigned to this logistics center.');
            }

            $capacity = LocalTripCapacityRules::approvedCapacity($rider->approved_parcel_capacity === null
                ? null
                : (int) $rider->approved_parcel_capacity);
            if ($count > $capacity) {
                throw new RuntimeException("This rider's approved capacity is {$capacity} parcels.");
            }

            $shipments = Shipment::query()
                ->whereIn('id', $ids)
                ->with(['serviceArea', 'sellerOrder.sellerProfile.businessAddress', 'pickupRequests'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($shipments->count() !== $count) {
                throw new RuntimeException('One or more selected parcels could not be found. Refresh the pickup queue and try again.');
            }

            $sellerIds = $shipments->map(fn (Shipment $shipment): int => (int) $shipment->sellerOrder?->seller_profile_id)->unique();
            if ($sellerIds->count() !== 1 || $sellerIds->first() < 1) {
                throw new RuntimeException('Every parcel in one seller pickup assignment must belong to the same seller.');
            }
            $seller = SellerProfile::query()
                ->with('businessAddress')
                ->whereKey($sellerIds->first())
                ->lockForUpdate()
                ->firstOrFail();
            if (! $seller->businessAddress) {
                throw new RuntimeException('The seller must have a registered business pickup address.');
            }

            $pickupArea = null;
            $pickupRequestByShipment = [];
            foreach ($shipments as $shipment) {
                if ($shipment->current_status !== 'READY_FOR_PICKUP') {
                    throw new RuntimeException('Every selected parcel must be READY_FOR_PICKUP.');
                }

                $shipmentCenterId = $shipment->logistics_center_id ?: $shipment->serviceArea?->logistics_center_id;
                if ($shipmentCenterId && (int) $shipmentCenterId !== (int) $center->id) {
                    throw new RuntimeException('Every selected parcel must be assigned to this logistics center or its authorized route.');
                }

                $area = $this->workflow->sellerPickupServiceArea($shipment, $center);
                if (! $area || (int) $area->logistics_center_id !== (int) $center->id) {
                    throw new RuntimeException('The seller pickup address is not covered by this logistics center.');
                }
                if ($pickupArea && (int) $pickupArea->id !== (int) $area->id) {
                    throw new RuntimeException('All parcels in a seller pickup batch must use the same pickup address and service area.');
                }
                $pickupArea = $area;

                if (! $this->workflow->riderCoversSellerPickupArea($shipment, $rider) && ! $coverageOverride) {
                    throw new RuntimeException('The rider must be an active local rider assigned to the seller pickup barangay.');
                }
                if ($coverageOverride && $this->workflow->riderCoversSellerPickupArea($shipment, $rider) === false) {
                    if (blank(trim((string) $coverageOverrideReason))) {
                        throw new RuntimeException('A reason is required for a pickup coverage override.');
                    }
                    $hasCoveredRider = RiderProfile::query()
                        ->where('logistics_center_id', $center->id)
                        ->whereIn('status', ['ACTIVE', 'WARNED'])
                        ->whereIn('vehicle_type', RiderVehicleType::localValues())
                        ->whereNotNull('approved_parcel_capacity')
                        ->where('approved_parcel_capacity', '>', 0)
                        ->whereHas('user', fn ($query) => $query
                            ->where('account_type', User::TYPE_RIDER)
                            ->where('status', User::STATUS_ACTIVE))
                        ->whereHas('areaAssignments', fn ($query) => $query
                            ->where('service_area_id', $area->id)
                            ->where('is_active', true))
                        ->exists();
                    if ($hasCoveredRider) {
                        throw new RuntimeException('A rider already covers this pickup area. Select that rider instead of using the shortage override.');
                    }
                }

                $activeAssignment = RiderAssignment::query()
                    ->where('shipment_id', $shipment->id)
                    ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                    ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
                    ->lockForUpdate()
                    ->first();
                if ($activeAssignment) {
                    throw new RuntimeException('A selected parcel is already assigned to an active pickup batch.');
                }

                $pickupRequest = PickupRequest::query()
                    ->where('shipment_id', $shipment->id)
                    ->whereIn('status', ['PENDING', 'APPROVED'])
                    ->where(fn ($query) => $query->whereNull('notes')->orWhere('notes', 'not like', 'SELLER_DROPOFF:%'))
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if (! $pickupRequest) {
                    throw new RuntimeException('Every selected parcel must have a seller rider pickup request.');
                }
                $pickupRequestByShipment[$shipment->id] = $pickupRequest;
            }

            if (! $pickupArea) {
                throw new RuntimeException('The seller pickup service area is unavailable.');
            }

            $trip = $this->trips->createTrip($actor, $rider, $pickupArea, RiderLocalTrip::TYPE_PICKUP, $seller);
            foreach ($shipments as $shipment) {
                $assignment = $this->workflow->assignRider(
                    $shipment,
                    $rider,
                    RiderAssignment::TYPE_PICKUP,
                    $actor,
                    $coverageOverride,
                    $coverageOverrideReason,
                );
                $this->trips->addAssignment($actor, $trip, $assignment);

                $pickupRequest = $pickupRequestByShipment[$shipment->id];
                if ($pickupRequest->status === 'PENDING') {
                    $this->workflow->approvePickup($pickupRequest, $actor);
                }
            }

            return $trip->load(['seller.businessAddress', 'rider.user', 'items.riderAssignment.shipment']);
        });
    }

    public function accept(User $actor, RiderLocalTrip $trip): RiderLocalTrip
    {
        return DB::transaction(function () use ($actor, $trip): RiderLocalTrip {
            $trip = $this->lockedPickupTrip($trip);
            $this->assertTripRider($actor, $trip);
            if ($trip->status !== RiderLocalTrip::LOADING || $trip->items->isEmpty()) {
                throw new RuntimeException('Only an assigned seller pickup batch can be accepted.');
            }
            $this->assertBatchCapacity($trip);

            foreach ($trip->items as $item) {
                $assignment = $item->riderAssignment;
                if (! $assignment || $assignment->status !== 'ASSIGNED') {
                    throw new RuntimeException('Every parcel in this batch must still be assigned before acceptance.');
                }
                $this->workflow->riderTransition($assignment, 'accept', $actor);
            }
            $trip->update(['status' => RiderLocalTrip::READY]);

            return $trip->refresh();
        });
    }

    public function start(User $actor, RiderLocalTrip $trip): RiderLocalTrip
    {
        return DB::transaction(function () use ($actor, $trip): RiderLocalTrip {
            $trip = $this->lockedPickupTrip($trip);
            $this->assertTripRider($actor, $trip);
            if ($trip->status !== RiderLocalTrip::READY || $trip->items->isEmpty()) {
                throw new RuntimeException('Accept the seller pickup batch before starting it.');
            }

            foreach ($trip->items as $item) {
                $assignment = $item->riderAssignment;
                if (! $assignment || $assignment->status !== 'ACCEPTED') {
                    throw new RuntimeException('Every parcel in this batch must be accepted before pickup starts.');
                }
                $this->workflow->riderTransition($assignment, 'start', $actor);
            }
            $trip->update(['status' => RiderLocalTrip::IN_PROGRESS, 'started_at' => now()]);

            return $trip->refresh();
        });
    }

    /** @return array{already_verified: bool, assignment: RiderAssignment} */
    public function scan(User $actor, RiderLocalTrip $trip, string $code, string $method): array
    {
        return DB::transaction(function () use ($actor, $trip, $code, $method): array {
            $trip = $this->lockedPickupTrip($trip);
            $this->assertTripRider($actor, $trip);
            if ($trip->status !== RiderLocalTrip::IN_PROGRESS) {
                throw new RuntimeException('The seller pickup batch must be in progress before scanning parcels.');
            }
            $code = trim($code);
            $item = $trip->items->first(fn (RiderLocalTripItem $candidate): bool =>
                $candidate->riderAssignment?->shipment
                && hash_equals((string) $candidate->riderAssignment->shipment->tracking_number, $code));
            if (! $item || $code === '') {
                throw new RuntimeException('This parcel is not part of this seller pickup assignment.');
            }

            $assignment = $item->riderAssignment;
            if ($assignment->status === 'COMPLETED') {
                return ['already_verified' => true, 'assignment' => $assignment];
            }
            if ($assignment->status !== 'IN_PROGRESS') {
                throw new RuntimeException('This parcel is not awaiting a seller pickup scan.');
            }

            $assignment = $this->workflow->riderTransition($assignment, 'pickup_complete', $actor, [
                'scanned_code' => $code,
                'scan_method' => $method,
            ]);
            $this->trips->syncAssignment($assignment);

            return ['already_verified' => false, 'assignment' => $assignment];
        });
    }

    public function markNotCollected(User $actor, RiderLocalTrip $trip, int $assignmentId, string $reason): RiderAssignment
    {
        return DB::transaction(function () use ($actor, $trip, $assignmentId, $reason): RiderAssignment {
            $trip = $this->lockedPickupTrip($trip);
            $this->assertTripRider($actor, $trip);
            if ($trip->status !== RiderLocalTrip::IN_PROGRESS) {
                throw new RuntimeException('The seller pickup batch must be in progress to resolve a parcel as not collected.');
            }
            $item = $trip->items->firstWhere('rider_assignment_id', $assignmentId);
            if (! $item || ! $item->riderAssignment || $item->riderAssignment->status !== 'IN_PROGRESS') {
                throw new RuntimeException('Only an unresolved parcel in this seller pickup batch can be marked not collected.');
            }

            $assignment = $this->workflow->riderTransition($item->riderAssignment, 'pickup_not_collected', $actor, [
                'reason' => $reason,
            ]);
            $this->trips->syncAssignment($assignment);

            return $assignment;
        });
    }

    public function finishSellerPickup(User $actor, RiderLocalTrip $trip): RiderLocalTrip
    {
        return DB::transaction(function () use ($actor, $trip): RiderLocalTrip {
            $trip = $this->lockedPickupTrip($trip);
            $this->assertTripRider($actor, $trip);
            if ($trip->status !== RiderLocalTrip::IN_PROGRESS || $trip->items->isEmpty()) {
                throw new RuntimeException('Only an in-progress seller pickup batch can be completed at the seller.');
            }
            if ($trip->items->contains(fn (RiderLocalTripItem $item): bool =>
                ! in_array($item->riderAssignment?->status, self::RESOLVED_ASSIGNMENT_STATUSES, true))) {
                throw new RuntimeException('Scan or explicitly resolve every assigned parcel before completing the seller pickup.');
            }

            $collected = $trip->items->contains(fn (RiderLocalTripItem $item): bool => $item->riderAssignment?->status === 'COMPLETED');
            if (! $collected) {
                $trip->update([
                    'status' => RiderLocalTrip::COMPLETED,
                    'completed_at' => now(),
                    'exception_notes' => 'No parcels were collected; every assigned parcel was resolved at the seller.',
                ]);
            } else {
                $trip->update(['status' => RiderLocalTrip::EN_ROUTE_TO_HUB]);
            }

            return $trip->refresh();
        });
    }

    private function lockedPickupTrip(RiderLocalTrip $trip): RiderLocalTrip
    {
        $trip = RiderLocalTrip::query()
            ->whereKey($trip->id)
            ->with(['rider.user', 'seller.businessAddress', 'items.riderAssignment.shipment.sellerOrder'])
            ->lockForUpdate()
            ->firstOrFail();
        if ($trip->trip_type !== RiderLocalTrip::TYPE_PICKUP || (int) $trip->seller_profile_id < 1) {
            throw new RuntimeException('This is not a seller-specific pickup batch.');
        }

        return $trip;
    }

    private function assertBatchCapacity(RiderLocalTrip $trip): void
    {
        $count = $trip->items->count();
        if ($count < 1 || $count > (int) $trip->approved_parcel_capacity
            || $count > LocalTripCapacityRules::MAX_PARCELS_PER_TRIP) {
            throw new RuntimeException('The seller pickup batch must contain 1 to its approved capacity, up to 10 parcels.');
        }
    }

    private function assertTripRider(User $actor, RiderLocalTrip $trip): void
    {
        if ((int) $trip->rider?->user_id !== (int) $actor->id) {
            throw new RuntimeException('Only the rider assigned to this seller pickup batch can operate it.');
        }
        if (! RiderVehicleType::isLocalBarangay($trip->rider?->vehicle_type)) {
            throw new RuntimeException('Only a motorcycle or tricycle barangay rider can operate a seller pickup batch.');
        }
    }

    private function assertCenterActor(User $actor, LogisticsCenter $center): void
    {
        $actor->loadMissing('logisticsCenter');
        if ($actor->account_type !== User::TYPE_LOGISTICS
            || $actor->status !== User::STATUS_ACTIVE
            || (int) $actor->logisticsCenter?->id !== (int) $center->id
            || $center->status !== 'ACTIVE') {
            throw new RuntimeException('The authenticated user is not authorized for this logistics center.');
        }
    }
}
