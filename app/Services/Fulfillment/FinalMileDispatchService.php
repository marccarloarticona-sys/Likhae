<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FinalMileDispatchService
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly LocalRiderTripService $trips,
        private readonly ShipmentWorkflowService $workflow,
    ) {}

    public function dispatch(User $actor, LogisticsCenter $center, Shipment $shipment, RiderProfile $rider, ?int $localTripId = null): RiderLocalTrip
    {
        if (! $this->schema->localTripsReady()) {
            throw new RuntimeException('Local rider trip schema is not installed; final-mile dispatch is disabled.');
        }

        return DB::transaction(function () use ($actor, $center, $shipment, $rider, $localTripId): RiderLocalTrip {
            $actor->loadMissing('logisticsCenter');
            if ($actor->account_type !== User::TYPE_LOGISTICS
                || $actor->status !== User::STATUS_ACTIVE
                || (int) $actor->logisticsCenter?->id !== (int) $center->id) {
                throw new RuntimeException('Only the active destination logistics center can dispatch this parcel.');
            }

            $shipment = Shipment::query()->whereKey($shipment->id)->with('serviceArea')->lockForUpdate()->firstOrFail();
            if ((int) $shipment->logistics_center_id !== (int) $center->id
                || $shipment->current_status !== 'SORTED') {
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

            $activeAssignment = RiderAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                ->lockForUpdate()
                ->exists();
            $activeTripItem = RiderLocalTripItem::query()
                ->whereHas('riderAssignment', fn ($query) => $query
                    ->where('shipment_id', $shipment->id)
                    ->where('assignment_type', RiderAssignment::TYPE_DELIVERY))
                ->whereHas('trip', fn ($query) => $query->whereNotIn('status', [
                    RiderLocalTrip::COMPLETED,
                    RiderLocalTrip::CANCELLED,
                    RiderLocalTrip::EXCEPTION,
                ]))
                ->lockForUpdate()
                ->exists();
            if ($activeAssignment || $activeTripItem) {
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
            } else {
                if (! $shipment->serviceArea) {
                    throw new RuntimeException('This parcel has no destination service area. Configure its exact PSGC coverage before dispatch.');
                }
                $trip = $this->trips->createTrip($actor, $rider, $shipment->serviceArea, RiderLocalTrip::TYPE_DELIVERY);
            }

            $assignment = $this->workflow->assignRider($shipment, $rider, RiderAssignment::TYPE_DELIVERY, $actor);
            $this->trips->addAssignment($actor, $trip, $assignment);

            return $trip->refresh();
        });
    }
}
