<?php

namespace App\Services\Fulfillment;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\PickupRequest as LegacyPickupRequest;
use App\Models\Logistics\Shipment;
use App\Models\Seller\SellerPickupCenterOffer;
use App\Models\Seller\SellerPickupRequest;
use App\Models\Seller\SellerPickupRequestShipment;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Maps\ApproximateAddressCoordinates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SellerPickupRequestService
{
    public function __construct(private readonly LocalRiderEligibilityService $areaEligibility) {}

    /**
     * @param list<int> $shipmentIds
     * @param array<int|string, int|string> $sellerOrderIds keyed by shipment id
     * @param array<int|string, int|string> $preferredDestinationCenterIds keyed by shipment id
     */
    public function create(
        User $actor,
        int $pickupAddressId,
        array $shipmentIds,
        array $sellerOrderIds,
        ?int $preferredLogisticsCenterId = null,
        array $preferredDestinationCenterIds = [],
        bool $sellerSelectedFallback = false,
        bool $sellerManualCenterOverride = false,
        ?int $retryPickupRequestId = null,
    ): SellerPickupRequest
    {
        $ids = array_map('intval', array_values($shipmentIds));
        $count = count($ids);
        if ($count < 1 || $count > 10 || count(array_unique($ids)) !== $count) {
            throw new RuntimeException('Select 1 to 10 different ready parcels for one pickup request.');
        }

        return DB::transaction(function () use ($actor, $pickupAddressId, $ids, $sellerOrderIds, $preferredLogisticsCenterId, $preferredDestinationCenterIds, $sellerSelectedFallback, $sellerManualCenterOverride, $retryPickupRequestId): SellerPickupRequest {
            $seller = $this->sellerFor($actor);
            if ($sellerManualCenterOverride !== ($retryPickupRequestId !== null)) {
                throw new RuntimeException('Start a manual hub override from a returned pickup request.');
            }
            if ($sellerManualCenterOverride && $preferredLogisticsCenterId === null) {
                throw new RuntimeException('Choose another Logistics center before retrying this pickup.');
            }
            if ($retryPickupRequestId !== null) {
                $previousRequest = SellerPickupRequest::query()
                    ->whereKey($retryPickupRequestId)
                    ->where('seller_profile_id', $seller->id)
                    ->with('memberships')
                    ->lockForUpdate()
                    ->first();
                if (! $previousRequest
                    || $previousRequest->status !== 'RETURNED_TO_SELLER'
                    || (int) $previousRequest->pickup_address_id !== $pickupAddressId) {
                    throw new RuntimeException('This pickup request is no longer available for a manual hub retry. Refresh the page and try again.');
                }
                $previousShipmentIds = $previousRequest->memberships->pluck('shipment_id')->map(fn ($id): int => (int) $id)->all();
                if (array_diff($ids, $previousShipmentIds) !== []) {
                    throw new RuntimeException('A manual hub retry can include only parcels from the returned pickup request.');
                }
                $triedCenterOffers = $previousRequest->centerOffers();
                if (Schema::hasColumn('seller_pickup_center_offers', 'leg_type')) {
                    $triedCenterOffers->where('leg_type', SellerPickupCenterOffer::LEG_PICKUP);
                }
                $triedCenterIds = $triedCenterOffers->pluck('logistics_center_id')->map(fn ($id): int => (int) $id)->all();
                if ($previousRequest->preferred_logistics_center_id) {
                    $triedCenterIds[] = (int) $previousRequest->preferred_logistics_center_id;
                }
                if (in_array((int) $preferredLogisticsCenterId, $triedCenterIds, true)) {
                    throw new RuntimeException('Choose a different Logistics center than the one that already reviewed this request.');
                }
            }
            $approximateCoordinates = app(ApproximateAddressCoordinates::class);
            $address = Address::query()->whereKey($pickupAddressId)->lockForUpdate()->first();
            if (! $address
                || (int) $address->user_id !== (int) $seller->user_id) {
                throw new RuntimeException('Choose a pickup address saved to your Seller account.');
            }
            $approximateCoordinates->resolve($address);
            if ($preferredLogisticsCenterId === null
                && (! is_numeric($address->latitude) || ! is_numeric($address->longitude))) {
                throw new RuntimeException('The pickup address could not be located from its saved barangay and city. Add a map pin or complete the address details.');
            }
            $preferredCenter = null;
            if ($preferredLogisticsCenterId !== null) {
                $preferredCenter = LogisticsCenter::query()
                    ->approvedActive()
                    ->whereKey($preferredLogisticsCenterId)
                    ->whereHas('owner', fn ($owner) => $owner
                        ->where('account_type', User::TYPE_LOGISTICS)
                        ->where('status', User::STATUS_ACTIVE))
                    ->with('address')
                    ->lockForUpdate()
                    ->first();
                if ($preferredCenter?->address) {
                    $approximateCoordinates->resolve($preferredCenter->address);
                }
                if (! $preferredCenter
                    || ! is_numeric($address->latitude)
                    || ! is_numeric($address->longitude)
                    || ! $preferredCenter->address
                    || ! is_numeric($preferredCenter->address->latitude)
                    || ! is_numeric($preferredCenter->address->longitude)
                    || (! app(LogisticsSchemaReadiness::class)->automaticSellerPickupMatchingReady()
                        && ! $this->areaEligibility->centerAuthorizesAddress($preferredCenter, $address))) {
                    throw new RuntimeException('Choose an active Logistics center with approved coverage and a usable address location.');
                }
            }
            $shipments = Shipment::query()
                ->whereIn('id', $ids)
                ->with(['sellerOrder.order.address', 'serviceArea', 'waybills', 'pickupRequests', 'riderAssignments'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($shipments->count() !== count($ids)) {
                throw new RuntimeException('One or more selected parcels could not be found. Refresh the pickup list and try again.');
            }

            $destinationIds = collect($preferredDestinationCenterIds)
                ->mapWithKeys(fn ($centerId, $shipmentId): array => [(int) $shipmentId => (int) $centerId])
                ->all();
            $fallbackDestinations = [];
            $schema = app(LogisticsSchemaReadiness::class);
            $automaticMatching = $schema->automaticSellerPickupMatchingReady();
            $localCenters = $automaticMatching
                ? LogisticsCenter::query()
                    ->approvedActive()
                    ->whereHas('owner', fn ($owner) => $owner
                        ->where('account_type', User::TYPE_LOGISTICS)
                        ->where('status', User::STATUS_ACTIVE))
                    ->with('address')
                    ->get()
                    ->filter(fn (LogisticsCenter $center): bool => $center->address
                        && $approximateCoordinates->resolve($center->address) !== null)
                    ->values()
                : collect();
            $hasLocalCenter = function (object $destination) use ($localCenters): bool {
                return $localCenters->contains(fn (LogisticsCenter $candidate): bool =>
                    trim((string) $candidate->address?->province_code) === trim((string) $destination->province_code)
                    && trim((string) $candidate->address?->municipality_code) === trim((string) $destination->municipality_code)
                    && $this->areaEligibility->centerAuthorizesAddress($candidate, $destination));
            };
            $sellerHasLocalCenter = $automaticMatching ? $hasLocalCenter($address) : null;
            if ($sellerManualCenterOverride && $sellerHasLocalCenter && $preferredCenter
                && ! $localCenters->contains(fn (LogisticsCenter $candidate): bool =>
                    (int) $candidate->id === (int) $preferredCenter->id
                    && trim((string) $candidate->address?->province_code) === trim((string) $address->province_code)
                    && trim((string) $candidate->address?->municipality_code) === trim((string) $address->municipality_code)
                    && $this->areaEligibility->centerAuthorizesAddress($candidate, $address))) {
                throw new RuntimeException('Choose another approved Logistics center with pickup coverage for this address.');
            }
            $buyerHasLocalCenterByShipment = [];
            $uncoveredBuyerCount = 0;
            if ($automaticMatching) {
                foreach ($shipments as $shipment) {
                    $buyerAddress = $shipment->sellerOrder?->order?->address;
                    if (! $buyerAddress) {
                        throw new RuntimeException('A selected parcel is missing its saved Buyer delivery address.');
                    }
                    $approximateCoordinates->resolve($buyerAddress);
                    $buyerHasLocalCenterByShipment[(int) $shipment->id] = $hasLocalCenter($buyerAddress);
                    $uncoveredBuyerCount += (int) ! $buyerHasLocalCenterByShipment[(int) $shipment->id];
                }

                $fallbackRequired = ! $sellerHasLocalCenter || $uncoveredBuyerCount > 0;
                if ($fallbackRequired !== $sellerSelectedFallback) {
                    throw new RuntimeException($fallbackRequired
                        ? 'This pickup includes a location without local Logistics coverage. Refresh the page to choose the fallback hub route.'
                        : 'This pickup has local Logistics coverage at both ends and must keep the existing automatic route.');
                }
                if ($sellerHasLocalCenter && $preferredCenter && ! $sellerManualCenterOverride) {
                    throw new RuntimeException('This Seller pickup location already has local Logistics coverage. Keep its origin route automatic.');
                }
            }

            if ($sellerSelectedFallback) {
                if (! $automaticMatching || ! $schema->sellerSelectedFallbackHubsReady()) {
                    throw new RuntimeException('Seller-selected fallback hubs are not available until their additive pickup routing migration is installed.');
                }
                if (! $sellerHasLocalCenter && ! $preferredCenter) {
                    throw new RuntimeException('Choose an approved origin Logistics center for this uncovered Seller pickup location.');
                }
                if ($sellerHasLocalCenter && $preferredCenter && ! $sellerManualCenterOverride) {
                    throw new RuntimeException('This Seller pickup location already has local Logistics coverage. Keep its origin route automatic.');
                }

                foreach ($shipments as $shipment) {
                    $shipmentId = (int) $shipment->id;
                    if ($buyerHasLocalCenterByShipment[$shipmentId] ?? false) {
                        if (isset($destinationIds[$shipmentId])) {
                            throw new RuntimeException('Buyer locations already covered by a local Logistics center must keep their automatic destination route.');
                        }
                        continue;
                    }

                    $destinationCenterId = $destinationIds[$shipmentId] ?? 0;
                    $destinationCenter = $destinationCenterId > 0
                        ? LogisticsCenter::query()
                            ->approvedActive()
                            ->whereKey($destinationCenterId)
                            ->whereHas('owner', fn ($owner) => $owner
                                ->where('account_type', User::TYPE_LOGISTICS)
                                ->where('status', User::STATUS_ACTIVE))
                            ->with('address')
                            ->first()
                        : null;
                    if ($destinationCenter?->address) {
                        $approximateCoordinates->resolve($destinationCenter->address);
                    }
                    if (! is_numeric($shipment->sellerOrder?->order?->address?->latitude)
                        || ! is_numeric($shipment->sellerOrder?->order?->address?->longitude)) {
                        throw new RuntimeException('The Buyer delivery address could not be located from its saved barangay and city. Add a map pin or complete the address details.');
                    }
                    if (! $destinationCenter
                        || ! is_numeric($destinationCenter->address?->latitude)
                        || ! is_numeric($destinationCenter->address?->longitude)) {
                        throw new RuntimeException('Choose an active approved destination Logistics center with a usable address location for each uncovered Buyer address.');
                    }

                    $fallbackDestinations[$shipmentId] = (int) $destinationCenter->id;
                }

                if (array_diff(array_keys($destinationIds), array_keys($fallbackDestinations)) !== []) {
                    throw new RuntimeException('Only uncovered Buyer parcels can have a Seller-selected destination hub.');
                }
            } elseif ($destinationIds !== []) {
                throw new RuntimeException('Destination hub choices are only used for uncovered Buyer locations.');
            }

            foreach ($shipments as $shipment) {
                $sellerOrder = $shipment->sellerOrder;
                $expectedOrderId = (int) ($sellerOrderIds[$shipment->id] ?? 0);
                if (! $sellerOrder
                    || (int) $sellerOrder->seller_profile_id !== (int) $seller->id
                    || $expectedOrderId !== (int) $sellerOrder->id) {
                    throw new RuntimeException('Each parcel must belong to the Seller and SellerOrder shown in the request.');
                }
                if ($sellerOrder->status === 'CANCELLED' || $shipment->current_status === 'CANCELLED') {
                    throw new RuntimeException('Cancelled parcels cannot be included in a pickup request.');
                }
                if ($shipment->current_status !== 'READY_FOR_PICKUP') {
                    throw new RuntimeException('Every selected parcel must be Ready for Pickup and not already collected.');
                }
                if (blank(trim((string) $shipment->tracking_number)) || $shipment->waybills->isEmpty()) {
                    throw new RuntimeException('Every selected parcel must have its own tracking number and registered scan data.');
                }
                if ($shipment->riderAssignments->contains(fn ($assignment): bool =>
                    $assignment->assignment_type === 'PICKUP'
                    && in_array($assignment->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'], true))) {
                    throw new RuntimeException('A selected parcel is already assigned to or collected by a pickup Rider.');
                }
                if (ParcelScan::query()->where('shipment_id', $shipment->id)
                    ->where('scan_type', 'PICKUP_COLLECTED')->where('result', 'SUCCESS')
                    ->where('scanned_code', $shipment->tracking_number)->exists()) {
                    throw new RuntimeException('A parcel with a successful Rider pickup scan cannot be requested again.');
                }
                if (SellerPickupRequestShipment::query()->where('shipment_id', $shipment->id)
                    ->where('active_slot', true)->lockForUpdate()->exists()) {
                    throw new RuntimeException('A selected parcel is already in another active Seller pickup request.');
                }

                $hasSellerPickup = $shipment->pickupRequests->contains(fn (LegacyPickupRequest $pickup): bool =>
                    in_array($pickup->status, ['PENDING', 'APPROVED'], true)
                    && ! str_starts_with((string) $pickup->notes, 'SELLER_DROPOFF:'));
                if (! $hasSellerPickup) {
                    throw new RuntimeException('Every selected parcel must already be marked for Logistics pickup.');
                }
                if ($preferredCenter && ! app(LogisticsSchemaReadiness::class)->automaticSellerPickupMatchingReady()) {
                    $existingCenterId = $shipment->logistics_center_id ?: $shipment->serviceArea?->logistics_center_id;
                    if ($existingCenterId && (int) $existingCenterId !== (int) $preferredCenter->id) {
                        throw new RuntimeException('This parcel is already routed through another Logistics center. Choose that center or wait until automatic route matching is available.');
                    }
                }
            }

            $requestAttributes = [
                'seller_profile_id' => $seller->id,
                'pickup_address_id' => $address->id,
                'preferred_logistics_center_id' => $preferredCenter?->id,
                'status' => 'REQUESTED',
            ];
            if (app(LogisticsSchemaReadiness::class)->sellerSelectedFallbackHubsReady()) {
                $requestAttributes['seller_selected_fallback'] = $sellerSelectedFallback;
            }
            $pickupRequest = SellerPickupRequest::query()->create($requestAttributes);

            foreach ($shipments as $shipment) {
                $membershipAttributes = [
                    'seller_pickup_request_id' => $pickupRequest->id,
                    'seller_profile_id' => $seller->id,
                    'seller_order_id' => $shipment->seller_order_id,
                    'shipment_id' => $shipment->id,
                    'active_slot' => true,
                ];
                if (app(LogisticsSchemaReadiness::class)->sellerSelectedFallbackHubsReady()) {
                    $membershipAttributes['preferred_destination_logistics_center_id'] = $fallbackDestinations[$shipment->id] ?? null;
                }
                SellerPickupRequestShipment::query()->create($membershipAttributes);
            }

            return $pickupRequest->load(['memberships.shipment.waybills', 'pickupAddress']);
        });
    }

    public function assign(
        User $actor,
        LogisticsCenter $center,
        int $requestId,
        int $riderProfileId,
        bool $automaticDispatch = false,
        ?string $coverageOverrideReason = null,
    ): SellerPickupRequest
    {
        return DB::transaction(function () use ($actor, $center, $requestId, $riderProfileId, $automaticDispatch, $coverageOverrideReason): SellerPickupRequest {
            $this->assertCenterActor($actor, $center);
            $pickupRequest = SellerPickupRequest::query()
                ->whereKey($requestId)
                ->with(['memberships.shipment', 'sellerProfile', 'pickupAddress'])
                ->lockForUpdate()
                ->firstOrFail();
            $acceptedFallbackRoute = (bool) $pickupRequest->seller_selected_fallback
                && $pickupRequest->hasAcceptedOriginCenter((int) $center->id);
            $isDirectPendingPickup = $automaticDispatch && $pickupRequest->status === 'PENDING';
            $isAcceptedFallbackWaiting = $automaticDispatch
                && $pickupRequest->status === 'WAITING_FOR_RESPONSE'
                && $acceptedFallbackRoute;
            if ($pickupRequest->status !== 'REQUESTED' && ! $isDirectPendingPickup && ! $isAcceptedFallbackWaiting) {
                throw new RuntimeException('Only a requested or pending Seller pickup can be assigned.');
            }
            if (($isDirectPendingPickup || $isAcceptedFallbackWaiting)
                && Schema::hasTable('seller_pickup_center_offers')
                && $pickupRequest->centerOffers()
                    ->where('status', 'OFFERED')
                    ->where('expires_at', '>', now())
                    ->when(app(LogisticsSchemaReadiness::class)->sellerPickupLegOffersReady(), fn ($offers) => $offers
                        ->where('leg_type', SellerPickupCenterOffer::LEG_PICKUP))
                    ->exists()) {
                throw new RuntimeException('A Logistics center is already reviewing this pickup offer. Wait for its response before assigning a Rider.');
            }
            if (Schema::hasColumn('seller_pickup_requests', 'preferred_logistics_center_id')
                && $pickupRequest->preferred_logistics_center_id === null
                && ! $automaticDispatch) {
                throw new RuntimeException('This older pickup request has no Seller-selected Logistics center. Ask the Seller to cancel it and create a new request.');
            }
            if ($pickupRequest->preferred_logistics_center_id !== null
                && ! $automaticDispatch
                && (int) $pickupRequest->preferred_logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('This Seller chose a different Logistics center for the pickup.');
            }
            if ((bool) $pickupRequest->seller_selected_fallback
                && (! app(LogisticsSchemaReadiness::class)->sellerPickupCenterOffersReady()
                    || ! $pickupRequest->hasAcceptedOriginCenter((int) $center->id))) {
                throw new RuntimeException('This Logistics center must accept the Seller fallback route before assigning a pickup Rider.');
            }
            $memberships = $pickupRequest->memberships->sortBy('shipment_id')->values();
            $count = $memberships->count();
            if ($count < 1 || $count > 10 || $memberships->contains(fn ($membership): bool => $membership->active_slot !== true)) {
                throw new RuntimeException('This Seller pickup request no longer has 1 to 10 active parcels.');
            }
            if (! $pickupRequest->pickupAddress
                || (int) $pickupRequest->pickupAddress->user_id !== (int) $pickupRequest->sellerProfile?->user_id) {
                throw new RuntimeException('The saved pickup address is no longer available to this Seller. Review the request before assignment.');
            }

            $result = app(SellerPickupBatchService::class)->createPartial(
                $actor,
                $center,
                $riderProfileId,
                $memberships->pluck('shipment_id')->map(fn ($id): int => (int) $id)->all(),
                (int) $pickupRequest->id,
                $automaticDispatch,
                $coverageOverrideReason,
            );
            $trip = $result['trip'];

            if ($result['skipped_ids'] !== []) {
                $skippedMemberships = $memberships->whereIn('shipment_id', $result['skipped_ids'])->values();
                $skippedRequestAttributes = [
                    'seller_profile_id' => $pickupRequest->seller_profile_id,
                    'pickup_address_id' => $pickupRequest->pickup_address_id,
                    'preferred_logistics_center_id' => $pickupRequest->preferred_logistics_center_id,
                    'status' => 'REQUESTED',
                ];
                if (app(LogisticsSchemaReadiness::class)->sellerSelectedFallbackHubsReady()) {
                    $skippedRequestAttributes['seller_selected_fallback'] = $pickupRequest->seller_selected_fallback;
                }
                $skippedRequest = SellerPickupRequest::query()->create($skippedRequestAttributes);
                foreach ($skippedMemberships as $membership) {
                    $membership->update(['active_slot' => null]);
                    $skippedMembershipAttributes = [
                        'seller_pickup_request_id' => $skippedRequest->id,
                        'seller_profile_id' => $membership->seller_profile_id,
                        'seller_order_id' => $membership->seller_order_id,
                        'shipment_id' => $membership->shipment_id,
                        'active_slot' => true,
                    ];
                    if (app(LogisticsSchemaReadiness::class)->sellerSelectedFallbackHubsReady()) {
                        $skippedMembershipAttributes['preferred_destination_logistics_center_id'] = $membership->preferred_destination_logistics_center_id;
                    }
                    SellerPickupRequestShipment::query()->create($skippedMembershipAttributes);
                }
            }

            $pickupRequest->update([
                'rider_local_trip_id' => $trip->id,
                'status' => 'ASSIGNED',
            ]);

            $assigned = $pickupRequest->fresh(['sellerProfile.user', 'pickupAddress', 'riderLocalTrip.rider.user', 'memberships.shipment.waybills']);
            $assigned->setAttribute('skipped_count', count($result['skipped_ids']));

            return $assigned;
        });
    }

    public function reject(User $actor, LogisticsCenter $center, SellerPickupRequest $pickupRequest): void
    {
        DB::transaction(function () use ($actor, $center, $pickupRequest): void {
            $this->assertCenterActor($actor, $center);
            $locked = SellerPickupRequest::query()->whereKey($pickupRequest->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'REQUESTED') {
                throw new RuntimeException('Only an unassigned Seller pickup request can be rejected.');
            }
            if (Schema::hasTable('seller_pickup_center_offers')
                && Schema::hasColumn('seller_pickup_center_offers', 'leg_type')
                && $locked->centerOffers()
                    ->where('leg_type', SellerPickupCenterOffer::LEG_PICKUP)
                    ->where('status', SellerPickupCenterOffer::STATUS_ACCEPTED)
                    ->exists()) {
                throw new RuntimeException('This pickup route is already accepted. Assign a pickup Rider instead of rejecting it.');
            }
            if (Schema::hasColumn('seller_pickup_requests', 'preferred_logistics_center_id')
                && $locked->preferred_logistics_center_id === null) {
                throw new RuntimeException('This older pickup request has no Seller-selected Logistics center. Ask the Seller to cancel it and create a new request.');
            }
            if ($locked->preferred_logistics_center_id !== null
                && (int) $locked->preferred_logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('This Seller chose a different Logistics center for the pickup.');
            }
            $this->releaseRequest($locked, $locked->preferred_logistics_center_id !== null ? 'RETURNED_TO_SELLER' : 'REJECTED');
            if ($locked->status === 'RETURNED_TO_SELLER') {
                $returned = $locked->fresh(['sellerProfile.user']);
                DB::afterCommit(fn () => app(\App\Services\AccountNotificationService::class)
                    ->sellerPickupMatchingStatus($returned, 'DECLINED'));
            }
        });
    }

    public function cancelBySeller(User $actor, SellerPickupRequest $pickupRequest): void
    {
        DB::transaction(function () use ($actor, $pickupRequest): void {
            $seller = $this->sellerFor($actor);
            $locked = SellerPickupRequest::query()->whereKey($pickupRequest->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->seller_profile_id !== (int) $seller->id) {
                abort(404);
            }
            if (! in_array($locked->status, ['REQUESTED', 'MATCHING', 'WAITING_FOR_RESPONSE', 'PENDING', 'RETURNED_TO_SELLER'], true)) {
                throw new RuntimeException('Only a pickup that has not been assigned to a Rider can be cancelled.');
            }
            if (Schema::hasTable('seller_pickup_center_offers')) {
                $locked->centerOffers()->where('status', 'OFFERED')->update([
                    'status' => 'SUPERSEDED',
                    'responded_at' => now(),
                ]);
            }
            $this->releaseRequest($locked, 'CANCELLED');
        });
    }

    /**
     * Returns Seller scoped, per-waybill progress based only on successful pickup scan rows.
     *
     * @return array{status:string,rider:?string,collected:int,total:int,parcels:Collection<int,array{shipment_id:int,tracking_number:string,seller_order_number:string,status:string,collected:bool}>}
     */
    public function progress(SellerPickupRequest $pickupRequest, int $sellerProfileId): array
    {
        abort_unless((int) $pickupRequest->seller_profile_id === $sellerProfileId, 404);
        $progressRelations = [
            'riderLocalTrip.rider.user',
            'riderLocalTrip.logisticsCenter.address',
            'riderLocalTrip.items.riderAssignment.shipment',
            'memberships.shipment',
            'memberships.shipment.sellerOrder.order.address',
            'memberships.sellerOrder',
        ];
        $hasSelectedCenterColumn = Schema::hasColumn('seller_pickup_requests', 'preferred_logistics_center_id');
        if ($hasSelectedCenterColumn) {
            $progressRelations[] = 'preferredLogisticsCenter';
        }
        $schema = app(LogisticsSchemaReadiness::class);
        if ($schema->sellerSelectedFallbackHubsReady()) {
            $progressRelations[] = 'memberships.preferredDestinationLogisticsCenter.address';
        }
        $pickupRequest->loadMissing($progressRelations);
        $allDelivered = $pickupRequest->memberships->isNotEmpty()
            && $pickupRequest->memberships->every(fn (SellerPickupRequestShipment $membership): bool =>
                $membership->shipment?->current_status === 'DELIVERED');

        $trip = $pickupRequest->riderLocalTrip;
        $offersTableReady = Schema::hasTable('seller_pickup_center_offers');
        $legOffersReady = $offersTableReady && Schema::hasColumn('seller_pickup_center_offers', 'leg_type');
        $centerOffers = $offersTableReady
            ? $pickupRequest->centerOffers()->with('logisticsCenter.address')->reorder('attempt_number', 'desc')->get()
            : collect();
        $currentOffer = $centerOffers->first(fn ($offer): bool => $offer->status === 'OFFERED' && $offer->expires_at?->isFuture());
        $latestCenterOffer = $centerOffers->first();
        $latestPickupOffer = $legOffersReady
            ? $centerOffers->first(fn ($offer): bool => $offer->leg_type === SellerPickupCenterOffer::LEG_PICKUP)
            : $latestCenterOffer;
        $hasAcceptedPickupOffer = $legOffersReady && $centerOffers->contains(fn ($offer): bool =>
            $offer->leg_type === SellerPickupCenterOffer::LEG_PICKUP
            && $offer->status === SellerPickupCenterOffer::STATUS_ACCEPTED);
        $routeAccepted = $trip === null
            && $pickupRequest->status === 'REQUESTED'
            && $hasAcceptedPickupOffer;
        $routeOffers = $legOffersReady
            ? $centerOffers
                ->groupBy(fn ($offer): string => $offer->leg_type === 'DELIVERY'
                    ? 'DELIVERY:'.(string) data_get($offer->route_snapshot, 'delivery_group_key', $offer->id)
                    : 'PICKUP')
                ->map(function (Collection $offers) use ($pickupRequest): array {
                    $offer = $offers->first();
                    $delivery = $offer->leg_type === 'DELIVERY';
                    $shipmentIds = array_map('intval', (array) data_get($offer->route_snapshot, 'delivery_shipment_ids', []));
                    $membership = $delivery
                        ? $pickupRequest->memberships->first(fn (SellerPickupRequestShipment $candidate): bool => in_array((int) $candidate->shipment_id, $shipmentIds, true))
                        : null;
                    $buyerAddress = $membership?->shipment?->sellerOrder?->order?->address;

                    return [
                        'leg_type' => (string) $offer->leg_type,
                        'leg_label' => $delivery ? 'Buyer delivery' : 'Seller pickup',
                        'group_key' => $delivery ? (string) data_get($offer->route_snapshot, 'delivery_group_key', '') : null,
                        'center' => (string) $offer->logisticsCenter?->business_name,
                        'center_address' => $offer->logisticsCenter?->address?->formatted(),
                        'delivery_address' => $delivery
                            ? (string) data_get($offer->route_snapshot, 'delivery_address', $buyerAddress?->formatted() ?? 'Buyer address')
                            : null,
                        'attempt' => (int) $offer->attempt_number,
                        'status' => (string) $offer->status,
                        'expires_at' => $offer->expires_at?->toIso8601String(),
                        'active' => $offer->status === 'OFFERED' && $offer->expires_at?->isFuture(),
                    ];
                })
                ->values()
                ->all()
            : [];
        if ($legOffersReady && $pickupRequest->seller_selected_fallback
            && in_array($pickupRequest->status, ['MATCHING', 'WAITING_FOR_RESPONSE', 'PENDING'], true)) {
            $destinationGroups = $pickupRequest->memberships
                ->filter(fn (SellerPickupRequestShipment $membership): bool => (int) $membership->preferred_destination_logistics_center_id > 0)
                ->groupBy(function (SellerPickupRequestShipment $membership): string {
                    $address = $membership->shipment?->sellerOrder?->order?->address;

                    return implode('|', [
                        trim((string) $address?->province_code),
                        trim((string) $address?->municipality_code),
                        trim((string) $address?->barangay_code),
                        (string) ((int) $membership->preferred_destination_logistics_center_id),
                    ]);
                });
            foreach ($destinationGroups as $memberships) {
                $first = $memberships->first();
                $address = $first?->shipment?->sellerOrder?->order?->address;
                if (! $first || ! $address) {
                    continue;
                }
                $shipmentIds = $memberships->pluck('shipment_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                $psgc = implode('|', [
                    trim((string) $address->province_code),
                    trim((string) $address->municipality_code),
                    trim((string) $address->barangay_code),
                ]);
                $groupKey = hash('sha256', $psgc.'|'.implode(',', $shipmentIds));
                if (collect($routeOffers)->contains(fn (array $offer): bool => $offer['leg_type'] === SellerPickupCenterOffer::LEG_DELIVERY
                    && ($offer['group_key'] ?? '') === $groupKey)) {
                    continue;
                }
                $hub = $first->preferredDestinationLogisticsCenter;
                $routeOffers[] = [
                    'leg_type' => SellerPickupCenterOffer::LEG_DELIVERY,
                    'leg_label' => 'Buyer delivery',
                    'group_key' => $groupKey,
                    'center' => (string) $hub?->business_name,
                    'center_address' => $hub?->address?->formatted(),
                    'delivery_address' => $address->formatted(),
                    'attempt' => 0,
                    'status' => $hasAcceptedPickupOffer ? 'MATCHING' : 'WAITING_FOR_PICKUP_ACCEPTANCE',
                    'expires_at' => null,
                    'active' => false,
                ];
            }
        }
        $assignments = $trip?->items
            ->map(fn ($item) => $item->riderAssignment)
            ->filter()
            ->keyBy(fn ($assignment) => (int) $assignment->shipment_id) ?? collect();
        $assignmentIds = $assignments->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $shipmentByAssignment = $assignments->mapWithKeys(fn ($assignment): array => [(int) $assignment->id => (int) $assignment->shipment_id]);
        $trackingByShipment = $pickupRequest->memberships
            ->when($trip !== null, fn (Collection $memberships): Collection => $memberships->filter(
                fn (SellerPickupRequestShipment $membership): bool => $assignments->has((int) $membership->shipment_id),
            ))
            ->mapWithKeys(fn ($membership): array => [(int) $membership->shipment_id => (string) $membership->shipment?->tracking_number]);

        $collected = collect();
        if ($assignmentIds->isNotEmpty()) {
            $collected = ParcelScan::query()
                ->whereIn('rider_assignment_id', $assignmentIds)
                ->where('scan_type', 'PICKUP_COLLECTED')
                ->where('result', 'SUCCESS')
                ->get(['shipment_id', 'rider_assignment_id', 'scanned_code'])
                ->filter(fn (ParcelScan $scan): bool =>
                    (int) ($shipmentByAssignment[(int) $scan->rider_assignment_id] ?? 0) === (int) $scan->shipment_id
                    && isset($trackingByShipment[(int) $scan->shipment_id])
                    && hash_equals($trackingByShipment[(int) $scan->shipment_id], (string) $scan->scanned_code))
                ->pluck('shipment_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->flip();
        }

        $parcels = $pickupRequest->memberships
            ->sortBy('shipment_id')
            ->when($trip !== null, fn (Collection $memberships): Collection => $memberships->filter(
                fn (SellerPickupRequestShipment $membership): bool => $assignments->has((int) $membership->shipment_id),
            ))
            ->map(function (SellerPickupRequestShipment $membership) use ($assignments, $collected): array {
                $shipment = $membership->shipment;
                $assignment = $assignments->get((int) $membership->shipment_id);
                $isCollected = $collected->has((int) $membership->shipment_id);

                return [
                    'shipment_id' => (int) $membership->shipment_id,
                    'tracking_number' => (string) $shipment?->tracking_number,
                    'seller_order_number' => (string) $membership->sellerOrder?->seller_order_number,
                    'status' => $isCollected ? 'Collected' : match ($assignment?->status) {
                        'CANCELLED' => 'Cancelled',
                        'REJECTED' => 'Not collected',
                        default => 'Waiting',
                    },
                    'collected' => $isCollected,
                ];
            })
            ->values();

        $requiresCenterSelection = $hasSelectedCenterColumn
            && $pickupRequest->preferred_logistics_center_id === null
            && ! app(LogisticsSchemaReadiness::class)->automaticSellerPickupMatchingReady()
            && in_array($pickupRequest->status, ['REQUESTED', 'MATCHING', 'WAITING_FOR_RESPONSE', 'PENDING'], true);
        $automaticMatching = $schema->automaticSellerPickupMatchingReady();
        $sellerSelectedFallback = $schema->sellerSelectedFallbackHubsReady()
            && (bool) $pickupRequest->seller_selected_fallback;
        $destinationHubs = $sellerSelectedFallback
            ? $pickupRequest->memberships
                ->map(fn (SellerPickupRequestShipment $membership): ?array => $membership->preferredDestinationLogisticsCenter ? [
                    'name' => (string) $membership->preferredDestinationLogisticsCenter->business_name,
                    'address' => $membership->preferredDestinationLogisticsCenter->address?->formatted(),
                ] : null)
                ->filter()
                ->unique(fn (array $hub): string => $hub['name'].'|'.($hub['address'] ?? ''))
                ->values()
                ->all()
            : [];

        $selectedCenterName = $hasSelectedCenterColumn ? $pickupRequest->preferredLogisticsCenter?->business_name : null;
        $triedPickupCenterIds = $centerOffers
            ->filter(fn ($offer): bool => ! $legOffersReady || $offer->leg_type === SellerPickupCenterOffer::LEG_PICKUP)
            ->pluck('logistics_center_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($pickupRequest->preferred_logistics_center_id) {
            $triedPickupCenterIds[] = (int) $pickupRequest->preferred_logistics_center_id;
        }
        $returnMessage = $pickupRequest->status === 'RETURNED_TO_SELLER'
            ? match ($latestPickupOffer?->status) {
                'EXPIRED' => 'No response from '.($latestPickupOffer->logisticsCenter?->business_name ?: $selectedCenterName ?: 'the selected Logistics center').' within '.max(1, (int) config('likhae.seller_pickup_offer_response_minutes', 30)).' minutes. Your parcels are available again; choose a different covered center above to request pickup again.',
                'DECLINED' => ($selectedCenterName ?: 'The selected Logistics center').' declined the request. Your parcels are available again; choose a covered center above to request pickup again.',
                'NO_CAPACITY' => 'The selected center could not confirm route and Rider capacity within '.max(1, (int) config('likhae.seller_pickup_offer_response_minutes', 30)).' minutes. Your parcels are available again; choose a covered center above to request pickup again.',
                default => 'The selected Logistics center did not accept the request. Your parcels are available again; choose a covered Logistics center above to request pickup again.',
            }
            : null;

        return [
            'status' => (string) $pickupRequest->status,
            'all_delivered' => $allDelivered,
            'automatic_matching' => $automaticMatching,
            'seller_selected_fallback' => $sellerSelectedFallback,
            'destination_hubs' => $destinationHubs,
            'selected_center' => $selectedCenterName,
            'assigned_center' => $trip?->logisticsCenter ? [
                'name' => (string) $trip->logisticsCenter->business_name,
                'address' => $trip->logisticsCenter->address?->formatted(),
            ] : null,
            'route_accepted' => $routeAccepted,
            'requires_center_selection' => $requiresCenterSelection,
            'status_label' => $requiresCenterSelection ? 'Center choice required' : match ($pickupRequest->status) {
                'REQUESTED' => $routeAccepted ? 'Waiting for pickup Rider assignment' : 'Waiting for Logistics',
                'MATCHING' => $automaticMatching ? 'Matching nearby Logistics centers' : 'Finding Logistics',
                'WAITING_FOR_RESPONSE' => 'Waiting for a Logistics center',
                'PENDING' => 'Pending Logistics follow-up',
                'RETURNED_TO_SELLER' => 'Returned to Seller — choose another center',
                'ASSIGNED' => 'Accepted — Rider assigned',
                default => str($pickupRequest->status)->replace('_', ' ')->title()->toString(),
            },
            'return_message' => $returnMessage,
            'response_due_at' => ! $routeAccepted && ! $automaticMatching && $selectedCenterName && in_array($pickupRequest->status, ['REQUESTED', 'PENDING'], true)
                ? $pickupRequest->updated_at?->copy()->addMinutes(max(1, (int) config('likhae.seller_pickup_offer_response_minutes', 30)))->toIso8601String()
                : null,
            'route_offers' => $routeOffers,
            'current_offer' => $currentOffer ? [
                'center' => (string) $currentOffer->logisticsCenter?->business_name,
                'center_address' => $currentOffer->logisticsCenter?->address?->formatted(),
                'leg_type' => (string) ($currentOffer->leg_type ?? SellerPickupCenterOffer::LEG_PICKUP),
                'status' => (string) $currentOffer->status,
                'expires_at' => $currentOffer->expires_at?->toIso8601String(),
                'attempt' => (int) $currentOffer->attempt_number,
                'estimate_method' => (string) $currentOffer->estimate_method,
            ] : null,
            'tried_pickup_center_ids' => array_values(array_unique($triedPickupCenterIds)),
            'rider' => $trip?->rider?->user?->name,
            'collected' => $parcels->where('collected', true)->count(),
            'total' => $parcels->count(),
            'parcels' => $parcels,
        ];
    }

    private function sellerFor(User $actor): SellerProfile
    {
        $actor->loadMissing('sellerProfile');
        if ($actor->account_type !== User::TYPE_SELLER
            || $actor->status !== User::STATUS_ACTIVE
            || ! $actor->sellerProfile) {
            throw new RuntimeException('An active authenticated Seller account is required.');
        }

        return $actor->sellerProfile;
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

    private function releaseRequest(SellerPickupRequest $pickupRequest, string $status): void
    {
        $pickupRequest->update(['status' => $status]);
        $pickupRequest->memberships()->where('active_slot', true)->update(['active_slot' => null]);
    }
}
