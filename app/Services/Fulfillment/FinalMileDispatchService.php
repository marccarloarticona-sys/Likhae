<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\LocalTripCapacityRules;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FinalMileDispatchService
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly LocalRiderTripService $trips,
        private readonly ShipmentWorkflowService $workflow,
    ) {}

    public function dispatch(
        User $actor,
        LogisticsCenter $center,
        Shipment $shipment,
        RiderProfile $rider,
        ?int $localTripId = null,
        bool $allowDeliveryAreaOverride = false,
        ?string $deliveryAreaOverrideReason = null,
        bool $allowUnavailableDeliveryTakeover = false,
        bool $requireFreshRiderLocation = false,
    ): RiderLocalTrip
    {
        if (! $this->schema->localTripsReady()) {
            throw new RuntimeException('Local rider trip schema is not installed; final-mile dispatch is disabled.');
        }

        return DB::transaction(function () use ($actor, $center, $shipment, $rider, $localTripId, $allowDeliveryAreaOverride, $deliveryAreaOverrideReason, $allowUnavailableDeliveryTakeover, $requireFreshRiderLocation): RiderLocalTrip {
            $actor->loadMissing('logisticsCenter');
            if ($actor->account_type !== User::TYPE_LOGISTICS
                || $actor->status !== User::STATUS_ACTIVE
                || (int) $actor->logisticsCenter?->id !== (int) $center->id) {
                throw new RuntimeException('Only the active destination logistics center can dispatch this parcel.');
            }

            $shipment = Shipment::query()->whereKey($shipment->id)->with('serviceArea')->lockForUpdate()->firstOrFail();
            $validDispatchStatus = $shipment->current_status === 'SORTED'
                || ($allowUnavailableDeliveryTakeover && $shipment->current_status === 'ASSIGNED_TO_RIDER');
            if ((int) $shipment->logistics_center_id !== (int) $center->id || ! $validDispatchStatus) {
                throw new RuntimeException('The parcel must be sorted into this center and in its custody before final-mile dispatch.');
            }

            $rider = RiderProfile::query()
                ->whereKey($rider->id)
                ->with(['user', 'logisticsCenter', 'areaAssignments.serviceArea.locations'])
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $rider->logistics_center_id !== (int) $center->id
                || $rider->status !== 'ACTIVE'
                || $rider->user?->account_type !== User::TYPE_RIDER
                || $rider->user?->status !== User::STATUS_ACTIVE) {
                throw new RuntimeException('Select an active final-mile rider assigned to this logistics center.');
            }
            if ($requireFreshRiderLocation
                && (! $this->schema->riderAvailabilityReady()
                    || ! $rider->is_online
                    || ! is_numeric($rider->availability_latitude)
                    || ! is_numeric($rider->availability_longitude)
                    || ! ($rider->availability_location_updated_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false))) {
                throw new RuntimeException('Nearest-rider matching requires an online rider with a location updated in the last two minutes.');
            }
            if ($allowDeliveryAreaOverride) {
                if ($localTripId !== null) {
                    throw new RuntimeException('A temporary coverage exception must start a new destination-barangay batch.');
                }
                if (! $this->schema->riderAvailabilityReady()
                    || ! $rider->is_online
                    || ! ($rider->availability_location_updated_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false)) {
                    throw new RuntimeException('A temporary coverage exception requires an online rider with a location updated in the last two minutes.');
                }
                if ($this->workflow->riderCoversShipmentDestinationArea($shipment, $rider)) {
                    throw new RuntimeException('Use the normal area assignment when the selected rider covers this destination.');
                }
                if (blank(trim((string) $deliveryAreaOverrideReason))) {
                    throw new RuntimeException('A reason is required for a temporary delivery coverage exception.');
                }
            }

            $activeAssignment = RiderAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                ->with('riderProfile.user')
                ->lockForUpdate()
                ->first();
            $activeTripItem = RiderLocalTripItem::query()
                ->whereHas('riderAssignment', fn ($query) => $query
                    ->where('shipment_id', $shipment->id)
                    ->where('assignment_type', RiderAssignment::TYPE_DELIVERY))
                ->whereHas('trip', fn ($query) => $query->whereNotIn('status', [
                    RiderLocalTrip::COMPLETED,
                    RiderLocalTrip::CANCELLED,
                    RiderLocalTrip::EXCEPTION,
                ]))
                ->with('trip')
                ->lockForUpdate()
                ->first();
            if ($activeAssignment || $activeTripItem) {
                $priorRider = $activeAssignment?->riderProfile;
                $priorRiderUnavailable = $activeAssignment?->status === 'ASSIGNED'
                    && (! $priorRider?->is_online
                        || ! ($priorRider?->availability_location_updated_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false));
                $canTakeOver = $allowUnavailableDeliveryTakeover
                    && $priorRiderUnavailable
                    && (! $activeTripItem
                        || ((int) $activeTripItem->rider_assignment_id === (int) $activeAssignment?->id
                            && $activeTripItem->status === RiderLocalTripItem::QUEUED
                            && $activeTripItem->trip?->status === RiderLocalTrip::LOADING));
                if (! $canTakeOver) {
                    throw new RuntimeException('This parcel already belongs to an active delivery assignment or trip. Only an unaccepted assignment with an offline or stale rider can be taken over.');
                }
                if ($activeTripItem) {
                    $activeTripItem->update([
                        'status' => RiderLocalTripItem::CANCELLED,
                        'failure_reason' => 'Cancelled during unavailable-rider reassignment before delivery acceptance.',
                    ]);
                }
            }
            if (! $activeAssignment && $activeTripItem) {
                throw new RuntimeException('This parcel already belongs to an active delivery assignment or trip.');
            }

            if ($localTripId !== null) {
                $trip = RiderLocalTrip::query()
                    ->whereKey($localTripId)
                    ->where('rider_profile_id', $rider->id)
                    ->where('logistics_center_id', $center->id)
                    ->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)
                    ->where('status', RiderLocalTrip::LOADING)
                    ->lockForUpdate()
                    ->first();
                if (! $trip) {
                    throw new RuntimeException('Choose an open loading trip for this rider or create a new trip.');
                }
                if (trim((string) $shipment->destination_province_code) !== trim((string) $trip->province_code)
                    || trim((string) $shipment->destination_municipality_code) !== trim((string) $trip->municipality_code)
                    || trim((string) $shipment->destination_barangay_code) !== trim((string) $trip->barangay_code)) {
                    throw new RuntimeException('The assignment PSGC barangay does not match the trip barangay.');
                }
                $loadedCount = $trip->items()->whereIn('status', [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED])->count();
                LocalTripCapacityRules::assertAvailable(
                    (int) $loadedCount,
                    LocalTripCapacityRules::effectiveCapacity((int) $trip->approved_parcel_capacity, $rider->approved_parcel_capacity),
                );
            } else {
                if (! $shipment->serviceArea) {
                    throw new RuntimeException('This parcel has no destination service area. Configure its exact PSGC coverage before dispatch.');
                }
                $trip = RiderLocalTrip::query()
                    ->where('rider_profile_id', $rider->id)
                    ->where('logistics_center_id', $center->id)
                    ->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)
                    ->where('status', RiderLocalTrip::LOADING)
                    ->where('province_code', trim((string) $shipment->destination_province_code))
                    ->where('municipality_code', trim((string) $shipment->destination_municipality_code))
                    ->where('barangay_code', trim((string) $shipment->destination_barangay_code))
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get()
                    ->first(function (RiderLocalTrip $candidate) use ($rider): bool {
                        $loadCount = $candidate->items()
                            ->whereIn('status', [RiderLocalTripItem::QUEUED, RiderLocalTripItem::LOADED])
                            ->count();
                        $capacity = LocalTripCapacityRules::effectiveCapacity(
                            (int) $candidate->approved_parcel_capacity,
                            $rider->approved_parcel_capacity,
                        );

                        return $loadCount < $capacity;
                    });

                if (! $trip) {
                    $trip = $this->trips->createTrip($actor, $rider, $shipment->serviceArea, RiderLocalTrip::TYPE_DELIVERY, null, $allowDeliveryAreaOverride);
                }
            }

            $assignment = $this->workflow->assignRider(
                $shipment,
                $rider,
                RiderAssignment::TYPE_DELIVERY,
                $actor,
                false,
                null,
                $allowDeliveryAreaOverride,
                $deliveryAreaOverrideReason,
                $allowUnavailableDeliveryTakeover,
            );
            $this->trips->addAssignment($actor, $trip, $assignment, $allowDeliveryAreaOverride);

            return $trip->refresh();
        });
    }
}
