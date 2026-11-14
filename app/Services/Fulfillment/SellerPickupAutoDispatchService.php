<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerPickupCenterOffer;
use App\Models\Seller\SellerPickupRequest;
use App\Models\User;
use App\Support\LocalTripCapacityRules;
use App\Support\RiderVehicleType;
use Illuminate\Support\Collection;
use RuntimeException;

final class SellerPickupAutoDispatchService
{
    public function __construct(
        private readonly SellerPickupBatchService $batches,
    ) {}

    public function assignNearest(User $actor, LogisticsCenter $center, Shipment $shipment): RiderLocalTrip
    {
        $shipment->loadMissing('sellerOrder.sellerProfile.businessAddress', 'pickupRequests');
        $pickup = $shipment->pickupRequests
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->first(fn (PickupRequest $request): bool => ! str_starts_with((string) $request->notes, 'SELLER_DROPOFF:'));
        if (! $pickup) {
            throw new RuntimeException('The parcel must have an active seller rider-pickup request before auto-assignment.');
        }

        $address = $shipment->sellerOrder?->sellerProfile?->businessAddress;
        if (! $address) {
            throw new RuntimeException('Nearest-rider matching needs a saved seller pickup address.');
        }

        $excludedRiders = $this->previouslyTriedRiderIds([(int) $shipment->id]);
        $candidate = $this->nearestAvailableRiders($center, $address, $excludedRiders)->first();
        if (! $candidate) {
            $available = $this->nearestAvailableRiders($center, $address, collect())->isNotEmpty();
            throw new RuntimeException($available
                ? 'Every eligible idle online Rider has already declined or timed out on this pickup.'
                : 'No idle online local Rider with approved capacity is available at this center. Riders with active assignments are not offered another pickup.');
        }

        return $this->batches->createAutomatically($actor, $center, (int) $candidate->id, [(int) $shipment->id]);
    }

    /** True only when an idle, online local Rider can carry the full request. */
    public function hasAvailablePickupCapacity(LogisticsCenter $center, object $address, int $parcelCount): bool
    {
        return $this->nearestAvailableRiders($center, $address, collect(), max(1, $parcelCount))->isNotEmpty();
    }

    /** Retry declined and expired offers with the next nearest rider at each center. */
    public function retryFailedOffers(): int
    {
        $assigned = 0;
        $this->retryAcceptedFallbackDeliveryOffers();
        $centers = LogisticsCenter::query()->where('status', 'ACTIVE')->with('owner')->get();

        foreach ($centers as $center) {
            $actor = $center->owner;
            if (! $actor instanceof User
                || $actor->account_type !== User::TYPE_LOGISTICS
                || $actor->status !== User::STATUS_ACTIVE) {
                continue;
            }

            $assigned += $this->retryForCenter($actor, $center);
        }

        return $assigned;
    }

    public function retryForCenter(User $actor, LogisticsCenter $center): int
    {
        if ($actor->account_type !== User::TYPE_LOGISTICS
            || $actor->status !== User::STATUS_ACTIVE
            || (int) $actor->logisticsCenter?->id !== (int) $center->id) {
            return 0;
        }

        return $this->retryLegacyPickups($actor, $center);
    }

    /** Retry Buyer-hub offers after pickup acceptance while destination approval is pending. */
    private function retryAcceptedFallbackDeliveryOffers(): void
    {
        $schema = app(LogisticsSchemaReadiness::class);
        if (! $schema->sellerPickupLegOffersReady() || ! $schema->sellerSelectedFallbackHubsReady()) {
            return;
        }

        SellerPickupRequest::query()
            ->where('seller_selected_fallback', true)
            ->whereNotIn('status', ['CANCELLED', 'REJECTED', 'RETURNED_TO_SELLER', 'NOT_COLLECTED'])
            ->whereHas('centerOffers', fn ($offer) => $offer
                ->where('leg_type', SellerPickupCenterOffer::LEG_PICKUP)
                ->where('status', SellerPickupCenterOffer::STATUS_ACCEPTED))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(fn (SellerPickupRequest $request) => app(SellerPickupMatchingService::class)
                ->retryDeliveryOffersForAcceptedPickup((int) $request->id));
    }

    private function retryLegacyPickups(User $actor, LogisticsCenter $center): int
    {
        $shipments = Shipment::query()
            ->where('current_status', 'READY_FOR_PICKUP')
            ->where(fn ($centerQuery) => $centerQuery->where('logistics_center_id', $center->id)
                ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                    ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id))))
            ->whereHas('pickupRequests', fn ($pickup) => $pickup
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->where(fn ($notes) => $notes->whereNull('notes')->orWhere('notes', 'not like', 'SELLER_DROPOFF:%')))
            ->whereDoesntHave('sellerPickupMemberships', fn ($membership) => $membership->where('active_slot', true))
            ->whereDoesntHave('riderAssignments', fn ($assignment) => $assignment
                ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']))
            ->whereHas('riderAssignments', fn ($assignment) => $this->failedOfferScope($assignment))
            ->with('sellerOrder.sellerProfile.businessAddress')
            ->orderBy('id')
            ->get();

        $assigned = 0;
        foreach ($shipments as $shipment) {
            try {
                $this->assignNearest($actor, $center, $shipment);
                $assigned++;
            } catch (RuntimeException) {
                // Leave the parcel in the Logistics queue until another eligible rider comes online.
            }
        }

        return $assigned;
    }

    private function failedOfferScope($query): void
    {
        $query->where('assignment_type', RiderAssignment::TYPE_PICKUP)
            ->where(fn ($failed) => $failed->where('status', 'REJECTED')
                ->orWhere(fn ($expired) => $expired->where('status', 'CANCELLED')
                    ->where('rejection_reason', 'like', 'Offer expired%')));
    }

    /** @param list<int> $shipmentIds @return Collection<int, int> */
    private function previouslyTriedRiderIds(array $shipmentIds): Collection
    {
        return RiderAssignment::query()
            ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
            ->whereIn('shipment_id', $shipmentIds)
            ->where(fn ($failed) => $failed->where('status', 'REJECTED')
                ->orWhere(fn ($expired) => $expired->where('status', 'CANCELLED')
                    ->where('rejection_reason', 'like', 'Offer expired%')))
            ->pluck('rider_profile_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function nearestAvailableRiders(LogisticsCenter $center, object $address, Collection $excludedRiders, ?int $requiredCapacity = null): Collection
    {
        $riders = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->where('is_online', true)
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->when($requiredCapacity !== null, fn ($query) => $query->where('approved_parcel_capacity', '>=', $requiredCapacity))
            ->when($excludedRiders->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $excludedRiders))
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE))
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with('user')
            ->get()
            // Pickup offers go only to idle riders. Capacity still controls
            // how many parcels fit into this one batch, but an existing job
            // makes a rider ineligible for another pickup offer.
            ->filter(fn (RiderProfile $rider): bool => (int) $rider->active_assignments_count === 0)
            ->map(function (RiderProfile $rider) use ($address, $center): RiderProfile {
                $riderLatitude = $rider->availability_latitude;
                $riderLongitude = $rider->availability_longitude;
                $targetLatitude = $address->latitude ?? null;
                $targetLongitude = $address->longitude ?? null;

                if ((! is_numeric($targetLatitude) || ! is_numeric($targetLongitude))
                    && (is_numeric($center->address?->latitude) && is_numeric($center->address?->longitude))) {
                    $targetLatitude = $center->address->latitude;
                    $targetLongitude = $center->address->longitude;
                }

                $rider->setAttribute('seller_distance_km', is_numeric($targetLatitude)
                    && is_numeric($targetLongitude)
                    && is_numeric($riderLatitude)
                    && is_numeric($riderLongitude)
                    ? $this->distanceKm(
                        (float) $targetLatitude,
                        (float) $targetLongitude,
                        (float) $riderLatitude,
                        (float) $riderLongitude,
                    )
                    : PHP_FLOAT_MAX);

                return $rider;
            });

        return $riders->sort(function (RiderProfile $a, RiderProfile $b): int {
            return ((float) $a->seller_distance_km <=> (float) $b->seller_distance_km)
                ?: ((int) $a->active_assignments_count <=> (int) $b->active_assignments_count)
                ?: ((int) $a->id <=> (int) $b->id);
        })->values();
    }

    private function distanceKm(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($latitudeB - $latitudeA);
        $lonDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($lonDelta / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1, sqrt($a)));
    }
}
