<?php

namespace App\Services\Fulfillment;

use App\Models\Buyer\Address;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\LogisticsCoverageRoute;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerPickupCenterOffer;
use App\Models\Seller\SellerPickupRequest;
use App\Models\Seller\SellerPickupRequestShipment;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Support\ParcelWeightEstimator;
use App\Support\RiderVehicleType;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SellerPickupMatchingService
{
    private const OFFER_MINUTES = 15;
    private const NO_CAPACITY_RETRY_MINUTES = 10;

    public function __construct(
        private readonly SellerPickupRequestService $pickupRequests,
        private readonly LogisticsSchemaReadiness $schema,
        private readonly PsgcLogisticsRoutingService $routing,
        private readonly TravelDistanceEstimator $distance,
        private readonly LocalRiderEligibilityService $areaEligibility,
        private readonly AccountNotificationService $notifications,
    ) {}

    /** Restore in-flight requests to the manual queue when rollout is disabled. */
    public function pausePendingWhenDisabled(): int
    {
        if ((bool) config('likhae.automatic_seller_pickup_matching', false)
            || ! $this->schema->sellerPickupRequestsReady()
            || ! $this->schema->sellerPickupCenterOffersReady()) {
            return 0;
        }

        $ids = SellerPickupRequest::query()
            ->whereIn('status', ['MATCHING', 'WAITING_FOR_RESPONSE', 'PENDING'])
            ->orderBy('id')
            ->pluck('id');
        $restored = 0;
        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($id): bool {
                $request = SellerPickupRequest::query()->whereKey($id)->lockForUpdate()->first();
                if (! $request || ! in_array($request->status, ['MATCHING', 'WAITING_FOR_RESPONSE', 'PENDING'], true)) {
                    return false;
                }
                $request->centerOffers()
                    ->where('status', SellerPickupCenterOffer::STATUS_OFFERED)
                    ->update(['status' => SellerPickupCenterOffer::STATUS_SUPERSEDED, 'responded_at' => now()]);
                $request->update(['status' => 'REQUESTED']);

                return true;
            });
            $restored += (int) $changed;
        }

        return $restored;
    }

    /** Create and immediately match a pickup request without a Seller-selected center. */
    public function createAutomaticRequest(
        User $actor,
        int $pickupAddressId,
        array $shipmentIds,
        array $sellerOrderIds,
        ?int $preferredLogisticsCenterId = null,
        array $preferredDestinationCenterIds = [],
        bool $sellerSelectedFallback = false,
    ): SellerPickupRequest {
        abort_unless($this->schema->automaticSellerPickupMatchingReady(), 503);
        abort_unless(! $sellerSelectedFallback || $this->schema->sellerSelectedFallbackHubsReady(), 503);

        $request = $this->pickupRequests->create(
            $actor,
            $pickupAddressId,
            $shipmentIds,
            $sellerOrderIds,
            $preferredLogisticsCenterId,
            $preferredDestinationCenterIds,
            $sellerSelectedFallback,
        );
        $request->update(['status' => 'MATCHING']);
        $this->startRequest((int) $request->id);

        return $request->fresh(['pickupAddress', 'memberships.shipment.waybills']);
    }

    /** Offer a request to its Seller-selected center when a route and capacity are available. */
    public function startRequest(int $requestId): void
    {
        if (! $this->schema->automaticSellerPickupMatchingReady()) {
            return;
        }

        $request = $this->loadRequest($requestId);
        if (! $request || in_array($request->status, ['ASSIGNED', 'CANCELLED', 'COMPLETED', 'RETURNED_TO_SELLER'], true)) {
            return;
        }
        if ($request->status === 'REQUESTED') {
            $this->notifySeller($request, 'FINDING');
        }

        $active = $request->centerOffers()
            ->where('status', SellerPickupCenterOffer::STATUS_OFFERED)
            ->where('expires_at', '>', now())
            ->first();
        if ($active) {
            $request->update(['status' => 'WAITING_FOR_RESPONSE']);

            return;
        }

        $this->expireCurrentOffers($request);
        $excludedCenterIds = $this->recentlyAttemptedCenterIds($request);
        $candidates = $this->candidatePlans($request, $excludedCenterIds);

        if ($candidates === []) {
            $this->markPending($requestId);

            return;
        }

        $candidate = $candidates[0];
        try {
            $offer = DB::transaction(function () use ($requestId, $candidate): ?SellerPickupCenterOffer {
            $locked = SellerPickupRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, ['ASSIGNED', 'CANCELLED', 'COMPLETED'], true)) {
                return null;
            }
            $active = $locked->centerOffers()
                ->where('status', SellerPickupCenterOffer::STATUS_OFFERED)
                ->where('expires_at', '>', now())
                ->first();
            if ($active) {
                $locked->update(['status' => 'WAITING_FOR_RESPONSE']);

                return $active;
            }

            $fresh = $this->loadRequest((int) $locked->id);
            $origin = LogisticsCenter::query()->approvedActive()->whereKey($candidate['center']->id)->first();
            if (! $fresh || ! $origin || ! $this->centerStillAuthorized($origin, $fresh->pickupAddress, $fresh)) {
                $locked->update(['status' => 'PENDING']);

                return null;
            }

            $plans = $this->routePlansForRequest($fresh, $origin, true);
            $plan = collect($plans)->first(fn (array $current): bool => $this->sameRoutePlan($candidate['plan']['snapshot'], $current));
            if (! $plan) {
                $locked->update(['status' => 'PENDING']);

                return null;
            }
            if (! $this->originPickupCapacity($origin, $fresh->pickupAddress, $fresh->memberships->count())) {
                $locked->update(['status' => 'PENDING']);

                return null;
            }
            if (! $this->lockRouteCenters($plan)) {
                $locked->update(['status' => 'PENDING']);

                return null;
            }
            if (($plan['seller_selected_fallback'] ?? false) !== true
                && ! $this->routeHasDestinationCapacity($plan, (int) $fresh->id)) {
                $locked->update(['status' => 'PENDING']);

                return null;
            }

            $attempt = (int) $locked->centerOffers()->max('attempt_number') + 1;
            $now = now();
            $offer = SellerPickupCenterOffer::query()->create([
                'seller_pickup_request_id' => $locked->id,
                'logistics_center_id' => $origin->id,
                'attempt_number' => $attempt,
                'status' => SellerPickupCenterOffer::STATUS_OFFERED,
                'offered_at' => $now,
                'expires_at' => $now->copy()->addMinutes(self::OFFER_MINUTES),
                'estimated_distance_meters' => $candidate['estimate']['distance_meters'],
                'estimated_travel_seconds' => $candidate['estimate']['travel_seconds'],
                'estimate_method' => $candidate['estimate']['method'],
                'destination_logistics_center_id' => $plan['destination_center_id'],
                'destination_service_area_location_id' => $plan['destination_area_id'],
                'coverage_route_id' => $plan['coverage_route_id'],
                'service_date' => $plan['service_date'],
                'reserved_parcel_count' => 0,
                'route_snapshot' => $plan['snapshot'],
            ]);
            $locked->update(['status' => 'WAITING_FOR_RESPONSE']);
            DB::afterCommit(fn () => $this->notifySeller($locked->fresh(), 'WAITING', $origin));

                return $offer;
            });
        } catch (RuntimeException|ValidationException) {
            $this->markPending($requestId);

            return;
        }

        if ($offer === null) {
            $this->markPending($requestId);
        }
    }

    /** Accept an offer only after fresh route and origin/destination capacity checks. */
    public function accept(
        User $actor,
        LogisticsCenter $center,
        int $requestId,
        int $offerId,
        SellerPickupAutoDispatchService $autoDispatch,
    ): bool {
        abort_unless($this->schema->automaticSellerPickupMatchingReady(), 503);

        try {
            return DB::transaction(function () use ($actor, $center, $requestId, $offerId, $autoDispatch): bool {
                $request = SellerPickupRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
                $offer = SellerPickupCenterOffer::query()->whereKey($offerId)->lockForUpdate()->firstOrFail();
                $this->assertOfferActor($actor, $center, $request, $offer);

                if ($offer->status !== SellerPickupCenterOffer::STATUS_OFFERED) {
                    abort(409, 'This center offer is no longer available.');
                }
                if ($offer->expires_at?->isPast()) {
                    $offer->update(['status' => SellerPickupCenterOffer::STATUS_EXPIRED, 'responded_at' => now()]);
                    if ($this->shouldReturnToSeller($request)) {
                        $this->returnToSellerLocked($request, 'TIMED_OUT');
                    } else {
                        $request->update(['status' => 'MATCHING']);
                        DB::afterCommit(fn () => $this->startRequest((int) $request->id));
                    }

                    return false;
                }

                if ($request->status !== 'WAITING_FOR_RESPONSE') {
                    abort(409, 'This Seller pickup request is no longer waiting for a center response.');
                }

                $fresh = $this->loadRequest((int) $request->id);
                if (! $fresh || ! $this->centerStillAuthorized($center, $fresh->pickupAddress, $fresh)) {
                    throw new RuntimeException('This center is no longer authorized for the exact Seller pickup PSGC.');
                }

                $shipmentIds = $fresh->memberships->pluck('shipment_id')->map(fn ($id): int => (int) $id)->all();
                $count = count($shipmentIds);
                if ($count < 1 || $count > 10 || $fresh->memberships->contains(fn ($membership): bool =>
                    $membership->active_slot !== true || $membership->shipment?->current_status !== 'READY_FOR_PICKUP')) {
                    throw new RuntimeException('The request no longer contains 1 to 10 active Ready for Pickup parcels.');
                }

                $plans = $this->routePlansForRequest($fresh, $center, true);
                $plan = collect($plans)->first(fn (array $current): bool => $this->sameRoutePlan($offer->route_snapshot ?? [], $current));
                if (! $plan || ! $this->originPickupCapacity($center, $fresh->pickupAddress, $count)) {
                    throw new RuntimeException('Pickup Rider or complete route capacity changed after this offer was sent.');
                }
                if (! $this->lockRouteCenters($plan) || ! $this->routeHasDestinationCapacity($plan, (int) $fresh->id)) {
                    throw new RuntimeException(($plan['seller_selected_fallback'] ?? false) === true
                        ? 'Inter-hub vehicle or destination delivery capacity changed after this offer was sent.'
                        : 'Destination capacity changed after this offer was sent.');
                }

                $this->assertNoConflictingTransferLegs($fresh, $center, $plan);
                $this->applyShipmentPlan($fresh, $center, $plan);

                // SellerPickupRequestService::assign accepts only REQUESTED;
                // this state transition is inside the same locked transaction.
                $request->update(['status' => 'REQUESTED']);
                $request->refresh();
                $trip = $autoDispatch->assignRequestToNearest($actor, $center, $request);
                $offer->update([
                    'status' => SellerPickupCenterOffer::STATUS_ACCEPTED,
                    'responded_at' => now(),
                    'destination_logistics_center_id' => $plan['destination_center_id'],
                    'destination_service_area_location_id' => $plan['destination_area_id'],
                    'coverage_route_id' => $plan['coverage_route_id'],
                    'service_date' => $plan['service_date'],
                    'reserved_parcel_count' => $count,
                    'route_snapshot' => $plan['snapshot'],
                ]);

                return $trip !== null;
            });
        } catch (HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException|ValidationException $exception) {
            $this->markOfferUnavailable($requestId, $offerId);

            return false;
        }
    }

    public function decline(User $actor, LogisticsCenter $center, int $requestId, int $offerId): bool
    {
        abort_unless($this->schema->automaticSellerPickupMatchingReady(), 503);

        $declined = DB::transaction(function () use ($actor, $center, $requestId, $offerId): bool {
            $request = SellerPickupRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            $offer = SellerPickupCenterOffer::query()->whereKey($offerId)->lockForUpdate()->firstOrFail();
            $this->assertOfferActor($actor, $center, $request, $offer);
            if ($offer->status !== SellerPickupCenterOffer::STATUS_OFFERED) {
                abort(409, 'This center offer is no longer available.');
            }
            if ($request->status !== 'WAITING_FOR_RESPONSE') {
                abort(409, 'This Seller pickup request is no longer waiting for a center response.');
            }

            $offer->update([
                'status' => $offer->expires_at?->isPast()
                    ? SellerPickupCenterOffer::STATUS_EXPIRED
                    : SellerPickupCenterOffer::STATUS_DECLINED,
                'responded_at' => now(),
            ]);
            if ($this->shouldReturnToSeller($request)) {
                $this->returnToSellerLocked($request, $offer->status === SellerPickupCenterOffer::STATUS_EXPIRED ? 'TIMED_OUT' : 'DECLINED');
            } else {
                $request->update(['status' => 'MATCHING']);
                DB::afterCommit(fn () => $this->startRequest((int) $request->id));
            }

            return true;
        });

        return $declined;
    }

    /** Expire due offers and return unanswered Seller-selected center requests. */
    public function expireDueOffers(?int $centerId = null): int
    {
        if (! $this->schema->automaticSellerPickupMatchingReady()) {
            return 0;
        }

        $ids = SellerPickupCenterOffer::query()
            ->where('status', SellerPickupCenterOffer::STATUS_OFFERED)
            ->where('expires_at', '<=', now())
            ->when($centerId !== null, fn ($query) => $query->where('logistics_center_id', $centerId))
            ->orderBy('id')
            ->limit(250)
            ->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $requestIdForOffer = (int) SellerPickupCenterOffer::query()->whereKey($id)->value('seller_pickup_request_id');
            if ($requestIdForOffer < 1) {
                continue;
            }
            $requestId = DB::transaction(function () use ($id, $requestIdForOffer): ?int {
                $request = SellerPickupRequest::query()->whereKey($requestIdForOffer)->lockForUpdate()->first();
                $offer = SellerPickupCenterOffer::query()->whereKey($id)->lockForUpdate()->first();
                if (! $request || ! $offer || $offer->status !== SellerPickupCenterOffer::STATUS_OFFERED || $offer->expires_at?->isFuture()) {
                    return null;
                }
                $offer->update(['status' => SellerPickupCenterOffer::STATUS_EXPIRED, 'responded_at' => now()]);
                if (! $request || in_array($request->status, ['ASSIGNED', 'CANCELLED', 'COMPLETED', 'RETURNED_TO_SELLER'], true)) {
                    return null;
                }
                if ($this->shouldReturnToSeller($request)) {
                    $this->returnToSellerLocked($request, 'TIMED_OUT');
                } else {
                    $request->update(['status' => 'MATCHING']);
                    DB::afterCommit(fn () => $this->startRequest((int) $request->id));
                }

                return (int) $request->id;
            });
            if ($requestId !== null) {
                $expired++;
            }
        }

        return $expired;
    }

    /** Retry pending requests after capacity or route configuration changes. */
    public function retryPending(): int
    {
        if (! $this->schema->automaticSellerPickupMatchingReady()) {
            return 0;
        }

        $ids = SellerPickupRequest::query()
            ->whereIn('status', ['MATCHING', 'PENDING'])
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');
        foreach ($ids as $id) {
            $returned = DB::transaction(function () use ($id): bool {
                $request = SellerPickupRequest::query()->whereKey($id)->lockForUpdate()->first();
                if (! $request || $request->status !== 'PENDING' || ! $this->shouldReturnToSeller($request)
                    || ! $request->updated_at || $request->updated_at->gt(now()->subMinutes(self::OFFER_MINUTES))
                    || ($this->schema->sellerPickupCenterOffersReady()
                        && $request->centerOffers()->where('status', SellerPickupCenterOffer::STATUS_OFFERED)->where('expires_at', '>', now())->exists())) {
                    return false;
                }

                $this->returnToSellerLocked($request, 'NO_CAPACITY');

                return true;
            });
            if ($returned) {
                continue;
            }

            $this->startRequest((int) $id);
        }

        return $ids->count();
    }

    /** Return selected-center requests that were never accepted within their 15-minute window. */
    public function returnUnansweredRequests(): int
    {
        if (! $this->schema->sellerPickupCenterSelectionReady()) {
            return 0;
        }

        $cutoff = now()->subMinutes(self::OFFER_MINUTES);
        $ids = SellerPickupRequest::query()
            ->where('status', 'REQUESTED')
            ->whereNotNull('preferred_logistics_center_id')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(250)
            ->pluck('id');
        $returned = 0;

        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($id, $cutoff): bool {
                $request = SellerPickupRequest::query()->whereKey($id)->lockForUpdate()->first();
                if (! $request || $request->status !== 'REQUESTED' || ! $this->shouldReturnToSeller($request)
                    || ! $request->updated_at || $request->updated_at->gt($cutoff)
                    || ($this->schema->sellerPickupCenterOffersReady()
                        && $request->centerOffers()->where('status', SellerPickupCenterOffer::STATUS_OFFERED)->where('expires_at', '>', now())->exists())) {
                    return false;
                }

                $this->returnToSellerLocked($request, 'TIMED_OUT');

                return true;
            });
            $returned += (int) $changed;
        }

        return $returned;
    }

    /** @return list<array{center:LogisticsCenter,estimate:array,plan:array,rank:float}> */
    private function candidatePlans(SellerPickupRequest $request, Collection $excludedCenterIds): array
    {
        $address = $request->pickupAddress;
        if (! $address || ! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
            return [];
        }

        $centers = ($request->seller_selected_fallback && (int) $request->preferred_logistics_center_id > 0
            ? $this->fallbackOriginCenters()
            : $this->eligibleOriginCenters($address))
            ->reject(fn (LogisticsCenter $center): bool => $excludedCenterIds->contains((int) $center->id))
            ->values();
        $candidates = [];
        foreach ($centers as $center) {
            if (! $center->address || ! is_numeric($center->address->latitude) || ! is_numeric($center->address->longitude)) {
                continue;
            }
            $count = $request->memberships->count();
            if (! $this->originPickupCapacity($center, $address, $count)) {
                continue;
            }

            try {
                $plans = $this->routePlansForRequest($request, $center, false);
            } catch (RuntimeException|ValidationException) {
                continue;
            }
            if ($plans === []) {
                continue;
            }

            $estimate = $this->distance->between(
                (float) $address->latitude,
                (float) $address->longitude,
                (float) $center->address->latitude,
                (float) $center->address->longitude,
            );
            $rank = $estimate['travel_seconds'] ?? ($estimate['distance_meters'] / 8.0);
            foreach ($plans as $plan) {
                $candidates[] = compact('center', 'estimate', 'plan', 'rank');
            }
        }

        usort($candidates, function (array $left, array $right) use ($request): int {
            $preferredId = (int) $request->preferred_logistics_center_id;
            $leftPreferred = $preferredId > 0 && (int) $left['center']->id === $preferredId;
            $rightPreferred = $preferredId > 0 && (int) $right['center']->id === $preferredId;

            return ($rightPreferred <=> $leftPreferred)
                ?: ($left['rank'] <=> $right['rank'])
                ?: ((int) $left['center']->id <=> (int) $right['center']->id)
                ?: ((int) $left['plan']['destination_center_id'] <=> (int) $right['plan']['destination_center_id']);
        });

        return $candidates;
    }

    private function eligibleOriginCenters(Address $address): EloquentCollection
    {
        return LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('owner', fn ($owner) => $owner
                ->where('account_type', User::TYPE_LOGISTICS)
                ->where('status', User::STATUS_ACTIVE))
            ->whereHas('serviceAreas', function ($area) use ($address): void {
                $area->where('is_active', true)
                    ->whereHas('locations', function ($location) use ($address): void {
                        $location->where('province_code', trim((string) $address->province_code))
                            ->where('municipality_code', trim((string) $address->municipality_code))
                            ->where('barangay_code', trim((string) $address->barangay_code));
                    });
            })
            ->with('address', 'owner', 'serviceAreas.locations')
            ->orderBy('id')
            ->get()
            ->filter(fn (LogisticsCenter $center): bool => $center->serviceAreas->contains(fn ($area): bool =>
                $area->is_active && $area->locations->contains(fn (ServiceAreaLocation $location): bool =>
                    trim((string) $location->province_code) === trim((string) $address->province_code)
                    && trim((string) $location->municipality_code) === trim((string) $address->municipality_code)
                    && trim((string) $location->barangay_code) === trim((string) $address->barangay_code)
                    && $this->areaEligibility->centerAuthorizesExactLocation($center, $location))))
            ->values();
    }

    /** @return EloquentCollection<int, LogisticsCenter> */
    private function fallbackOriginCenters(): EloquentCollection
    {
        return LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('owner', fn ($owner) => $owner
                ->where('account_type', User::TYPE_LOGISTICS)
                ->where('status', User::STATUS_ACTIVE))
            ->with('address', 'owner')
            ->orderBy('id')
            ->get()
            ->filter(fn (LogisticsCenter $center): bool => $center->address
                && is_numeric($center->address->latitude)
                && is_numeric($center->address->longitude))
            ->values();
    }

    /** @return list<array<string,mixed>> */
    private function routePlansForRequest(SellerPickupRequest $request, LogisticsCenter $origin, bool $lock): array
    {
        $request->loadMissing('sellerProfile', 'pickupAddress', 'memberships.shipment.sellerOrder.order.address');
        $memberships = $request->memberships->sortBy('shipment_id')->values();
        if ($memberships->isEmpty()) {
            return [];
        }

        if ($request->seller_selected_fallback && $this->schema->sellerSelectedFallbackHubsReady()) {
            return $this->routePlansForSellerSelectedFallback($request, $origin, $memberships, $lock);
        }

        $shipmentIds = $memberships->pluck('shipment_id')->map(fn ($id): int => (int) $id)->all();
        $groups = $memberships->groupBy(function (SellerPickupRequestShipment $membership): string {
            $address = $membership->shipment?->sellerOrder?->order?->address;

            return implode('|', [
                trim((string) $address?->province_code),
                trim((string) $address?->municipality_code),
                trim((string) $address?->barangay_code),
            ]);
        });
        $shipmentPlans = [];
        $destinations = [];
        $routes = [];

        foreach ($groups as $group) {
            /** @var SellerPickupRequestShipment $first */
            $first = $group->first();
            $destination = $first->shipment?->sellerOrder?->order?->address;
            if (! $destination instanceof OrderAddress) {
                throw new RuntimeException('A Buyer delivery address is missing from this Seller pickup request.');
            }
            foreach ($group as $membership) {
                $other = $membership->shipment?->sellerOrder?->order?->address;
                if (! $other || trim((string) $other->province_code) !== trim((string) $destination->province_code)
                    || trim((string) $other->municipality_code) !== trim((string) $destination->municipality_code)
                    || trim((string) $other->barangay_code) !== trim((string) $destination->barangay_code)) {
                    throw new RuntimeException('A Seller pickup request contains parcels with different Buyer destinations.');
                }
            }

            $groupIds = $group->pluck('shipment_id')->map(fn ($id): int => (int) $id)->all();
            $plans = $this->routing->resolveForAutomaticPickup(
                $request->sellerProfile,
                $request->pickupAddress,
                $destination,
                $origin,
                [
                    'lock_capacity' => $lock,
                    'required_parcels' => count($groupIds),
                    'exclude_shipment_ids' => $shipmentIds,
                ],
            );

            $feasible = collect($plans)
                ->map(fn (array $plan): array => $plan + ['shipment_ids' => $groupIds])
                ->filter(fn (array $plan): bool => $this->planMatchesExistingTransferLegs($groupIds, $origin, $plan))
                ->filter(fn (array $plan): bool => $this->routeHasDestinationCapacityForPlan($plan, (int) $request->id))
                ->values();
            if ($feasible->isEmpty()) {
                throw new RuntimeException('No complete authorized destination route has available operational capacity.');
            }

            $feasible = $feasible->sort(function (array $left, array $right) use ($origin): int {
                $leftEstimate = $this->centerToCenterEstimate($origin, $left['destination']);
                $rightEstimate = $this->centerToCenterEstimate($origin, $right['destination']);
                $leftScore = $leftEstimate['travel_seconds'] ?? ($leftEstimate['distance_meters'] / 8.0);
                $rightScore = $rightEstimate['travel_seconds'] ?? ($rightEstimate['distance_meters'] / 8.0);

                return ($leftScore <=> $rightScore)
                    ?: ((int) $left['destination']->id <=> (int) $right['destination']->id)
                    ?: ((int) ($left['coverage_route']?->id ?? 0) <=> (int) ($right['coverage_route']?->id ?? 0));
            });
            $selected = $feasible->first();
            $destinationArea = $selected['destination_area'];
            $destinationCenter = $selected['destination'];
            $route = $selected['coverage_route'] ?? null;
            $routeEntry = [
                'origin_logistics_center_id' => (int) $origin->id,
                'destination_logistics_center_id' => (int) $destinationCenter->id,
                'destination_service_area_location_id' => (int) $destinationArea->id,
                'destination_service_area_id' => (int) $destinationArea->service_area_id,
                'coverage_type' => (string) $selected['coverage_type'],
                'coverage_route_id' => $route ? (int) $route->id : null,
                'service_date' => $selected['service_date']?->toDateString(),
                'shipment_ids' => $groupIds,
                'pickup_psgc' => $this->psgc($request->pickupAddress),
                'destination_psgc' => $this->psgc($destination),
                'transfer_hops' => (int) ((int) $origin->id === (int) $destinationCenter->id ? 0 : 1),
            ];
            foreach ($groupIds as $shipmentId) {
                $shipmentPlans[$shipmentId] = $routeEntry;
            }
            $destinations[(int) $destinationCenter->id] = (int) $destinationCenter->id;
            if ($route) {
                $routes[(int) $route->id] = (int) $route->id;
            }
        }

        $uniqueDestinations = array_values($destinations);
        $uniqueAreas = collect($shipmentPlans)->pluck('destination_service_area_location_id')->unique()->values()->all();
        $uniqueRoutes = array_values($routes);
        $firstRoute = reset($shipmentPlans) ?: [];
        $snapshot = [
            'version' => 1,
            'estimate_basis' => 'Center offer distance uses Geoapify drive routing when configured; otherwise it is a straight-line geographic estimate.',
            'pickup_address_id' => (int) $request->pickup_address_id,
            'origin_logistics_center_id' => (int) $origin->id,
            'shipment_routes' => $shipmentPlans,
        ];

        return [[
            'origin_center_id' => (int) $origin->id,
            'destination_center_id' => count($uniqueDestinations) === 1 ? $uniqueDestinations[0] : null,
            'destination_area_id' => count($uniqueAreas) === 1 ? $uniqueAreas[0] : null,
            'coverage_route_id' => count($uniqueRoutes) === 1 ? $uniqueRoutes[0] : null,
            'service_date' => count($uniqueRoutes) === 1 ? ($firstRoute['service_date'] ?? null) : null,
            'snapshot' => $snapshot,
            'routes' => $shipmentPlans,
            'destination_center_ids' => $uniqueDestinations,
        ]];
    }

    /**
     * Resolve only the Seller-selected fallback flow. Normal, locally covered
     * requests continue through the existing authorized PSGC route resolver.
     *
     * @param Collection<int, SellerPickupRequestShipment> $memberships
     * @return list<array<string,mixed>>
     */
    private function routePlansForSellerSelectedFallback(
        SellerPickupRequest $request,
        LogisticsCenter $origin,
        Collection $memberships,
        bool $lock,
    ): array {
        $originAddress = $origin->address;
        if (! $originAddress || ! is_numeric($originAddress->latitude) || ! is_numeric($originAddress->longitude)) {
            return [];
        }

        $shipmentPlans = [];
        $destinations = [];
        $groups = $memberships->groupBy(function (SellerPickupRequestShipment $membership): string {
            $address = $membership->shipment?->sellerOrder?->order?->address;

            return implode('|', [
                trim((string) $address?->province_code),
                trim((string) $address?->municipality_code),
                trim((string) $address?->barangay_code),
                (string) ((int) $membership->preferred_destination_logistics_center_id),
            ]);
        });

        foreach ($groups as $group) {
            /** @var SellerPickupRequestShipment $first */
            $first = $group->first();
            $buyerAddress = $first->shipment?->sellerOrder?->order?->address;
            if (! $buyerAddress) {
                throw new RuntimeException('A Buyer delivery address is missing from this Seller pickup request.');
            }
            foreach ($group as $membership) {
                $other = $membership->shipment?->sellerOrder?->order?->address;
                if (! $other
                    || trim((string) $other->province_code) !== trim((string) $buyerAddress->province_code)
                    || trim((string) $other->municipality_code) !== trim((string) $buyerAddress->municipality_code)
                    || trim((string) $other->barangay_code) !== trim((string) $buyerAddress->barangay_code)) {
                    throw new RuntimeException('A pickup destination choice cannot combine different Buyer PSGC addresses.');
                }
            }

            $selectedDestinationId = (int) $first->preferred_destination_logistics_center_id;
            $destinationArea = null;
            if ($selectedDestinationId > 0) {
                $destination = LogisticsCenter::query()
                    ->approvedActive()
                    ->whereKey($selectedDestinationId)
                    ->whereHas('owner', fn ($owner) => $owner
                        ->where('account_type', User::TYPE_LOGISTICS)
                        ->where('status', User::STATUS_ACTIVE))
                    ->with('address')
                    ->when($lock, fn ($query) => $query->lockForUpdate())
                    ->first();
                if (! $destination || ! is_numeric($destination->address?->latitude) || ! is_numeric($destination->address?->longitude)) {
                    throw new RuntimeException('The Seller-selected destination Logistics center is no longer active or has no confirmed location.');
                }
            } else {
                $destinationChoice = $this->localDestinationForAddress($buyerAddress);
                $destination = $destinationChoice['center'] ?? null;
                $destinationArea = $destinationChoice['location'] ?? null;
                if (! $destination || ! $destinationArea) {
                    throw new RuntimeException('A Buyer address with local Logistics coverage must keep its automatic destination route.');
                }
            }

            if ((int) $origin->id !== (int) $destination->id && ! $this->schema->manifestReady()) {
                throw new RuntimeException('Inter-hub transfers are unavailable until the transfer manifest schema is installed.');
            }

            $groupIds = $group->pluck('shipment_id')->map(fn ($id): int => (int) $id)->all();
            $routeEntry = [
                'origin_logistics_center_id' => (int) $origin->id,
                'destination_logistics_center_id' => (int) $destination->id,
                'destination_service_area_location_id' => $destinationArea ? (int) $destinationArea->id : null,
                'destination_service_area_id' => $destinationArea ? (int) $destinationArea->service_area_id : null,
                'destination_center_name' => (string) $destination->business_name,
                'destination_center_address' => $destination->address?->formatted(),
                'coverage_type' => 'SELLER_SELECTED_FALLBACK',
                'coverage_route_id' => null,
                'service_date' => null,
                'seller_selected_fallback' => true,
                'shipment_ids' => $groupIds,
                'pickup_psgc' => $this->psgc($request->pickupAddress),
                'destination_psgc' => $this->psgc($buyerAddress),
                'transfer_hops' => (int) ((int) $origin->id === (int) $destination->id ? 0 : 1),
            ];
            foreach ($groupIds as $shipmentId) {
                $shipmentPlans[$shipmentId] = $routeEntry;
            }
            $destinations[(int) $destination->id] = $destination;
        }

        if ($shipmentPlans === []) {
            return [];
        }

        $snapshot = [
            'version' => 1,
            'routing_mode' => 'SELLER_SELECTED_UNCOVERED_FALLBACK',
            'origin_logistics_center_id' => (int) $origin->id,
            'shipment_routes' => $shipmentPlans,
        ];
        $destinationIds = array_keys($destinations);
        $uniqueAreaIds = collect($shipmentPlans)->pluck('destination_service_area_location_id')->filter()->unique()->values();

        return [[
            'origin_center_id' => (int) $origin->id,
            'destination_center_id' => count($destinationIds) === 1 ? $destinationIds[0] : null,
            'destination_area_id' => $uniqueAreaIds->count() === 1 ? (int) $uniqueAreaIds->first() : null,
            'coverage_route_id' => null,
            'service_date' => null,
            'seller_selected_fallback' => true,
            'snapshot' => $snapshot,
            'routes' => $shipmentPlans,
            'destination_center_ids' => $destinationIds,
            'shipment_ids' => array_map('intval', array_keys($shipmentPlans)),
        ]];
    }

    /** @return array{center:LogisticsCenter,location:ServiceAreaLocation}|null */
    private function localDestinationForAddress(object $address): ?array
    {
        $locations = ServiceAreaLocation::query()
            ->where('province_code', trim((string) $address->province_code))
            ->where('municipality_code', trim((string) $address->municipality_code))
            ->where('barangay_code', trim((string) $address->barangay_code))
            ->when($this->schema->coverageTypeReady(), fn ($query) => $query->where('coverage_type', 'PRIMARY'))
            ->whereHas('serviceArea', fn ($area) => $area->where('is_active', true)
                ->whereHas('logisticsCenter', fn ($center) => $center->approvedActive()
                    ->whereHas('owner', fn ($owner) => $owner
                        ->where('account_type', User::TYPE_LOGISTICS)
                        ->where('status', User::STATUS_ACTIVE))))
            ->with('serviceArea.logisticsCenter.address', 'serviceArea.logisticsCenter.owner')
            ->orderBy('id')
            ->get();

        foreach ($locations as $location) {
            $center = $location->serviceArea?->logisticsCenter;
            if ($center
                && is_numeric($center->address?->latitude)
                && is_numeric($center->address?->longitude)
                && trim((string) $center->address?->province_code) === trim((string) $address->province_code)
                && trim((string) $center->address?->municipality_code) === trim((string) $address->municipality_code)
                && $this->areaEligibility->centerAuthorizesExactLocation($center, $location)) {
                return ['center' => $center, 'location' => $location];
            }
        }

        return null;
    }

    private function fallbackRouteHasDestinationCapacity(array $plan, int $requestId): bool
    {
        foreach ($plan['destination_center_ids'] ?? [] as $destinationId) {
            $required = collect($plan['routes'] ?? [])
                ->filter(fn (array $route): bool => (int) $route['destination_logistics_center_id'] === (int) $destinationId)
                ->count();
            $center = LogisticsCenter::query()->approvedActive()->with('address')
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
                ->find($destinationId);
            if (! $center || $required < 1) {
                return false;
            }
            $available = $this->availableDestinationCapacity($center);

            $reserved = 0;
            foreach (SellerPickupCenterOffer::query()
                ->where('status', SellerPickupCenterOffer::STATUS_ACCEPTED)
                ->where('seller_pickup_request_id', '!=', $requestId)
                ->whereNotNull('route_snapshot')
                ->get(['route_snapshot']) as $offer) {
                foreach ((array) data_get($offer->route_snapshot, 'shipment_routes', []) as $route) {
                    if ((int) ($route['destination_logistics_center_id'] ?? 0) === (int) $destinationId) {
                        $reserved++;
                    }
                }
            }
            if ($available - $reserved < $required) {
                return false;
            }
        }

        return ! empty($plan['destination_center_ids']) && $this->fallbackRouteHasInterhubCapacity($plan);
    }

    /**
     * Confirm that the origin hub has an idle, approved inter-hub vehicle
     * capable of carrying each selected transfer batch before Logistics accepts.
     */
    private function fallbackRouteHasInterhubCapacity(array $plan): bool
    {
        $originId = (int) ($plan['origin_center_id'] ?? 0);
        if ($originId < 1) {
            return false;
        }

        foreach ($plan['destination_center_ids'] ?? [] as $destinationId) {
            if ((int) $destinationId === $originId) {
                continue;
            }

            $shipmentIds = collect($plan['routes'] ?? [])
                ->filter(fn (array $route): bool => (int) $route['destination_logistics_center_id'] === (int) $destinationId)
                ->keys()
                ->map(fn ($id): int => (int) $id)
                ->values();
            if ($shipmentIds->isEmpty()) {
                return false;
            }

            $shipments = Shipment::query()
                ->whereIn('id', $shipmentIds)
                ->with('orderItem.productVariant')
                ->get();
            if ($shipments->count() !== $shipmentIds->count()) {
                return false;
            }
            $parcelWeights = $shipments->map(fn (Shipment $shipment): array => [
                'weight_kg' => ParcelWeightEstimator::recordedWeight($shipment)
                    ?? ParcelWeightEstimator::productEstimate($shipment),
            ])->values();

            $drivers = app(TransferManifestService::class)->eligibleDriversForCenter($originId);
            $hasTransferCapacity = $drivers->contains(function (RiderProfile $driver) use ($parcelWeights): bool {
                try {
                    app(TransferCapacityService::class)->assertCapacity($driver, $parcelWeights);

                    return true;
                } catch (RuntimeException) {
                    return false;
                }
            });
            if (! $hasTransferCapacity) {
                return false;
            }
        }

        return true;
    }

    private function availableDestinationCapacity(LogisticsCenter $center): int
    {
        return RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($user) => $user
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE))
            ->withCount(['assignments as active_assignments_count' => fn ($assignments) => $assignments
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->get()
            ->sum(fn (RiderProfile $rider): int => max(
                0,
                (int) $rider->approved_parcel_capacity - (int) $rider->active_assignments_count,
            ));
    }

    private function routeHasDestinationCapacityForPlan(array $plan, int $requestId): bool
    {
        if (($plan['seller_selected_fallback'] ?? false) === true) {
            return $this->fallbackRouteHasDestinationCapacity($plan, $requestId);
        }

        return $this->destinationCapacity($plan['destination'], $plan['destination_area'], $requestId) >= count($plan['shipment_ids'] ?? []);
    }

    private function routeHasDestinationCapacity(array $plan, int $requestId): bool
    {
        if (($plan['seller_selected_fallback'] ?? false) === true) {
            return $this->fallbackRouteHasDestinationCapacity($plan, $requestId);
        }

        foreach ($plan['destination_center_ids'] as $destinationId) {
            $routes = collect($plan['routes'])->filter(fn (array $route): bool =>
                (int) $route['destination_logistics_center_id'] === (int) $destinationId)
                ->unique(fn (array $route): string => implode('|', [
                    $route['destination_service_area_location_id'],
                    $route['coverage_route_id'] ?? 0,
                    $route['service_date'] ?? '',
                    implode(',', $route['shipment_ids']),
                ]));
            $required = $routes->sum(fn (array $route): int => count($route['shipment_ids']));
            $locationIds = $routes->pluck('destination_service_area_location_id')->unique()->values();
            $capacity = null;
            foreach ($locationIds as $locationId) {
                $location = ServiceAreaLocation::query()->with('serviceArea.logisticsCenter')->find($locationId);
                if (! $location || (int) $location->serviceArea?->logistics_center_id !== (int) $destinationId) {
                    return false;
                }
                $capacity = $this->destinationCapacity($location->serviceArea->logisticsCenter, $location, $requestId);
                if ($capacity < $required) {
                    return false;
                }
            }
            if ($required < 1 || $capacity === null) {
                return false;
            }
        }

        return $plan['destination_center_ids'] !== [];
    }

    private function destinationCapacity(LogisticsCenter $center, ServiceAreaLocation $location, int $requestId): int
    {
        $areaRiders = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($user) => $user
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE))
            ->whereHas('areaAssignments', fn ($assignment) => $assignment
                ->where('service_area_id', $location->service_area_id)
                ->where('is_active', true))
            ->exists();
        if (! $areaRiders) {
            return 0;
        }

        $riders = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($user) => $user
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE))
            ->withCount(['assignments as active_assignments_count' => fn ($assignments) => $assignments
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->get();
        if ($riders->isEmpty()) {
            return 0;
        }

        $capacity = $riders->sum(fn (RiderProfile $rider): int => max(
            0,
            (int) $rider->approved_parcel_capacity - (int) $rider->active_assignments_count,
        ));
        $reserved = 0;
        $accepted = SellerPickupCenterOffer::query()
            ->where('status', SellerPickupCenterOffer::STATUS_ACCEPTED)
            ->where('seller_pickup_request_id', '!=', $requestId)
            ->whereNotNull('route_snapshot')
            ->get(['reserved_parcel_count', 'route_snapshot']);
        foreach ($accepted as $offer) {
            foreach ((array) data_get($offer->route_snapshot, 'shipment_routes', []) as $route) {
                if ((int) ($route['destination_logistics_center_id'] ?? 0) === (int) $center->id) {
                    $reserved++;
                }
            }
        }

        return max(0, $capacity - $reserved);
    }

    private function originPickupCapacity(LogisticsCenter $center, ?Address $address, int $parcelCount): bool
    {
        if (! $address || ! is_numeric($address->latitude) || ! is_numeric($address->longitude) || $parcelCount < 1) {
            return false;
        }

        return RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->where('is_online', true)
            ->whereNotNull('availability_latitude')
            ->whereNotNull('availability_longitude')
            ->where('availability_location_updated_at', '>=', now()->subMinutes(15))
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>=', $parcelCount)
            ->whereHas('user', fn ($user) => $user
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE))
            ->whereDoesntHave('assignments', fn ($assignment) => $assignment
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']))
            ->exists();
    }

    private function centerStillAuthorized(LogisticsCenter $center, ?Address $address, ?SellerPickupRequest $request = null): bool
    {
        if (! $address) {
            return false;
        }

        if ($request?->seller_selected_fallback && (int) $request->preferred_logistics_center_id > 0) {
            $center->loadMissing('owner', 'address');

            return (int) $center->owner?->id === (int) $center->owner_user_id
                && $center->owner?->account_type === User::TYPE_LOGISTICS
                && $center->owner?->status === User::STATUS_ACTIVE
                && is_numeric($center->address?->latitude)
                && is_numeric($center->address?->longitude)
                && LogisticsCenter::query()->approvedActive()->whereKey($center->id)->exists();
        }

        return ServiceAreaLocation::query()
            ->where('province_code', trim((string) $address->province_code))
            ->where('municipality_code', trim((string) $address->municipality_code))
            ->where('barangay_code', trim((string) $address->barangay_code))
            ->whereHas('serviceArea', fn ($area) => $area
                ->where('logistics_center_id', $center->id)
                ->where('is_active', true))
            ->with('serviceArea.logisticsCenter.address')
            ->get()
            ->contains(fn (ServiceAreaLocation $location): bool => $this->areaEligibility->centerAuthorizesExactLocation($center, $location));
    }

    private function planMatchesExistingTransferLegs(array $shipmentIds, LogisticsCenter $origin, array $plan): bool
    {
        foreach ($shipmentIds as $shipmentId) {
            $first = ShipmentTransferLeg::query()->where('shipment_id', $shipmentId)->orderBy('sequence_no')->first();
            if ($first && ((int) $first->origin_logistics_center_id !== (int) $origin->id
                || (int) $first->destination_logistics_center_id !== (int) $plan['destination']->id)) {
                return false;
            }
        }

        return true;
    }

    private function assertNoConflictingTransferLegs(SellerPickupRequest $request, LogisticsCenter $origin, array $plan): void
    {
        foreach ($request->memberships as $membership) {
            $first = ShipmentTransferLeg::query()->where('shipment_id', $membership->shipment_id)->orderBy('sequence_no')->first();
            $route = $plan['routes'][(int) $membership->shipment_id] ?? null;
            if ($first && (! $route
                || (int) $first->origin_logistics_center_id !== (int) $origin->id
                || (int) $first->destination_logistics_center_id !== (int) $route['destination_logistics_center_id'])) {
                throw new RuntimeException('An existing transfer leg conflicts with this route. Keep the parcel in the current custody plan for Logistics review.');
            }
        }
    }

    private function applyShipmentPlan(SellerPickupRequest $request, LogisticsCenter $origin, array $plan): void
    {
        $originAddress = $origin->address;
        if (! $originAddress) {
            throw new RuntimeException('The accepted pickup hub has no saved address.');
        }
        foreach ($request->memberships as $membership) {
            $shipment = Shipment::query()->whereKey($membership->shipment_id)->lockForUpdate()->firstOrFail();
            $sellerOrder = $shipment->sellerOrder()->with('order.address')->first();
            $destinationAddress = $sellerOrder?->order?->address;
            $route = $plan['routes'][(int) $shipment->id] ?? null;
            if (! $destinationAddress || ! $route) {
                throw new RuntimeException('A shipment is missing its verified Buyer route snapshot.');
            }
            $destinationCenter = LogisticsCenter::query()->approvedActive()
                ->whereKey($route['destination_logistics_center_id'])
                ->with('address')
                ->first();
            $destinationLocation = filled($route['destination_service_area_location_id'] ?? null)
                ? ServiceAreaLocation::query()
                    ->whereKey($route['destination_service_area_location_id'])
                    ->with('serviceArea')
                    ->first()
                : null;
            $sellerSelectedFallback = $request->seller_selected_fallback
                && ($route['seller_selected_fallback'] ?? false) === true;
            if (! $destinationCenter
                || (! $destinationLocation && ! $sellerSelectedFallback)
                || ($destinationLocation && (int) $destinationLocation->serviceArea?->logistics_center_id !== (int) $destinationCenter->id)) {
                throw new RuntimeException('The authorized destination hub changed before acceptance.');
            }

            $shipment->update([
                'logistics_center_id' => $origin->id,
                'origin_logistics_center_id' => $origin->id,
                'destination_logistics_center_id' => $destinationCenter->id,
                'service_area_id' => $destinationLocation?->service_area_id,
                'origin_province_code' => $originAddress->province_code,
                'origin_province_name' => $originAddress->province_name,
                'origin_municipality_code' => $originAddress->municipality_code,
                'origin_municipality_name' => $originAddress->municipality_name,
                'origin_barangay_code' => $request->pickupAddress->barangay_code,
                'origin_barangay_name' => $request->pickupAddress->barangay_name,
                'destination_province_code' => $destinationAddress->province_code,
                'destination_province_name' => $destinationAddress->province_name,
                'destination_municipality_code' => $destinationAddress->municipality_code,
                'destination_municipality_name' => $destinationAddress->municipality_name,
                'destination_barangay_code' => $destinationAddress->barangay_code,
                'destination_barangay_name' => $destinationAddress->barangay_name,
                'smart_coverage_route_id' => $route['coverage_route_id'] ?? null,
                'smart_coverage_service_date' => $route['service_date'] ?? null,
            ]);

            if ((int) $origin->id !== (int) $destinationCenter->id) {
                app(ShipmentTransferService::class)->createPlannedLeg($shipment->refresh(), $origin, $destinationCenter);
            }
        }
    }

    private function lockRouteCenters(array $plan): bool
    {
        $ids = collect([$plan['origin_center_id'], ...$plan['destination_center_ids']])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $locked = LogisticsCenter::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $ids->every(fn (int $id): bool => $locked->has($id));
    }

    private function sameRoutePlan(array $savedSnapshot, array $plan): bool
    {
        $savedRoutes = (array) data_get($savedSnapshot, 'shipment_routes', []);
        $currentRoutes = (array) data_get($plan['snapshot'], 'shipment_routes', []);
        if (array_keys($savedRoutes) !== array_keys($currentRoutes)) {
            ksort($savedRoutes);
            ksort($currentRoutes);
        }

        foreach ($currentRoutes as $shipmentId => $route) {
            $saved = $savedRoutes[$shipmentId] ?? null;
            if (! is_array($saved)
                || (int) ($saved['destination_logistics_center_id'] ?? 0) !== (int) $route['destination_logistics_center_id']
                || (int) ($saved['destination_service_area_location_id'] ?? 0) !== (int) $route['destination_service_area_location_id']
                || (int) ($saved['coverage_route_id'] ?? 0) !== (int) ($route['coverage_route_id'] ?? 0)
                || (string) ($saved['service_date'] ?? '') !== (string) ($route['service_date'] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /** @return array{distance_meters:int,travel_seconds:?int,method:string} */
    private function centerToCenterEstimate(LogisticsCenter $origin, LogisticsCenter $destination): array
    {
        if (! $origin->address || ! $destination->address
            || ! is_numeric($origin->address->latitude) || ! is_numeric($origin->address->longitude)
            || ! is_numeric($destination->address->latitude) || ! is_numeric($destination->address->longitude)) {
            return ['distance_meters' => PHP_INT_MAX, 'travel_seconds' => null, 'method' => 'STRAIGHT_LINE_ESTIMATE'];
        }

        return $this->distance->between(
            (float) $origin->address->latitude,
            (float) $origin->address->longitude,
            (float) $destination->address->latitude,
            (float) $destination->address->longitude,
        );
    }

    private function assertOfferActor(User $actor, LogisticsCenter $center, SellerPickupRequest $request, SellerPickupCenterOffer $offer): void
    {
        $actor->loadMissing('logisticsCenter');
        abort_unless($actor->account_type === User::TYPE_LOGISTICS
            && $actor->status === User::STATUS_ACTIVE
            && (int) $actor->logisticsCenter?->id === (int) $center->id
            && (int) $center->owner_user_id === (int) $actor->id
            && (int) $offer->seller_pickup_request_id === (int) $request->id
            && (int) $offer->logistics_center_id === (int) $center->id
            && (! $this->schema->sellerPickupCenterSelectionReady()
                || $request->preferred_logistics_center_id === null
                || $this->schema->automaticSellerPickupMatchingReady()
                || ((int) $request->preferred_logistics_center_id === (int) $center->id))
            && LogisticsCenter::query()->approvedActive()->whereKey($center->id)->exists(), 403);
    }

    private function markOfferUnavailable(int $requestId, int $offerId): void
    {
        DB::transaction(function () use ($requestId, $offerId): void {
            $request = SellerPickupRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            $offer = SellerPickupCenterOffer::query()->whereKey($offerId)->lockForUpdate()->first();
            if (! $request || ! $offer || $offer->status !== SellerPickupCenterOffer::STATUS_OFFERED
                || in_array($request->status, ['ASSIGNED', 'CANCELLED', 'COMPLETED', 'RETURNED_TO_SELLER'], true)) {
                return;
            }
            $offer->update([
                'status' => SellerPickupCenterOffer::STATUS_NO_CAPACITY,
                'responded_at' => now(),
            ]);
            if (! in_array($request->status, ['ASSIGNED', 'CANCELLED', 'COMPLETED'], true)) {
                $request->update(['status' => 'MATCHING']);
                DB::afterCommit(fn () => $this->startRequest((int) $request->id));
            }
        });
    }

    private function markPending(int $requestId): void
    {
        $result = DB::transaction(function () use ($requestId): ?array {
            $locked = SellerPickupRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, ['ASSIGNED', 'CANCELLED', 'COMPLETED', 'RETURNED_TO_SELLER'], true)
                || $locked->centerOffers()->where('status', SellerPickupCenterOffer::STATUS_OFFERED)->where('expires_at', '>', now())->exists()) {
                return null;
            }
            $changed = $locked->status !== 'PENDING';
            $locked->update(['status' => 'PENDING']);

            return [$locked->fresh(['sellerProfile.user']), $changed];
        });
        if ($result && $result[1]) {
            $this->notifySeller($result[0], 'PENDING');
        }
    }

    private function shouldReturnToSeller(SellerPickupRequest $request): bool
    {
        return ! $this->schema->automaticSellerPickupMatchingReady()
            && $this->schema->sellerPickupCenterSelectionReady()
            && (int) $request->preferred_logistics_center_id > 0;
    }

    /** Return a selected-center request atomically and make its parcels selectable again. */
    private function returnToSellerLocked(SellerPickupRequest $request, string $reason): void
    {
        $request->update(['status' => 'RETURNED_TO_SELLER']);
        $request->memberships()->where('active_slot', true)->update(['active_slot' => null]);
        $returned = $request->fresh(['sellerProfile.user']);
        DB::afterCommit(fn () => $this->notifySeller($returned, $reason));
    }

    private function expireCurrentOffers(SellerPickupRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $locked = SellerPickupRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }
            $locked->centerOffers()
                ->where('status', SellerPickupCenterOffer::STATUS_OFFERED)
                ->where('expires_at', '<=', now())
                ->update(['status' => SellerPickupCenterOffer::STATUS_EXPIRED, 'responded_at' => now()]);
        });
    }

    private function recentlyAttemptedCenterIds(SellerPickupRequest $request): Collection
    {
        return $request->centerOffers()
            ->where(function ($query): void {
                $query->whereIn('status', [
                    SellerPickupCenterOffer::STATUS_ACCEPTED,
                    SellerPickupCenterOffer::STATUS_DECLINED,
                    SellerPickupCenterOffer::STATUS_EXPIRED,
                    SellerPickupCenterOffer::STATUS_SUPERSEDED,
                ])->orWhere(fn ($noCapacity) => $noCapacity
                    ->where('status', SellerPickupCenterOffer::STATUS_NO_CAPACITY)
                    ->where('responded_at', '>=', now()->subMinutes(self::NO_CAPACITY_RETRY_MINUTES)));
            })
            ->pluck('logistics_center_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function loadRequest(int $requestId): ?SellerPickupRequest
    {
        return SellerPickupRequest::query()
            ->with([
                'sellerProfile.user',
                'pickupAddress',
                'memberships.shipment.sellerOrder.order.address',
            ])
            ->whereKey($requestId)
            ->first();
    }

    private function psgc(object $address): array
    {
        return [
            'province_code' => trim((string) $address->province_code),
            'municipality_code' => trim((string) $address->municipality_code),
            'barangay_code' => trim((string) $address->barangay_code),
        ];
    }

    private function notifySeller(SellerPickupRequest $request, string $state, ?LogisticsCenter $center = null): void
    {
        $this->notifications->sellerPickupMatchingStatus($request, $state, $center);
    }
}
