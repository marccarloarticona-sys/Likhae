<?php

namespace App\Services\Fulfillment;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\PickupRequest as LegacyPickupRequest;
use App\Models\Logistics\Shipment;
use App\Models\Seller\SellerPickupRequest;
use App\Models\Seller\SellerPickupRequestShipment;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SellerPickupRequestService
{
    public function __construct(private readonly SellerPickupBatchService $batches) {}

    /**
     * @param list<int> $shipmentIds
     * @param array<int|string, int|string> $sellerOrderIds keyed by shipment id
     */
    public function create(User $actor, int $pickupAddressId, array $shipmentIds, array $sellerOrderIds): SellerPickupRequest
    {
        $ids = array_map('intval', array_values($shipmentIds));
        $count = count($ids);
        if ($count < 1 || $count > 10 || count(array_unique($ids)) !== $count) {
            throw new RuntimeException('Select 1 to 10 different ready parcels for one pickup request.');
        }

        return DB::transaction(function () use ($actor, $pickupAddressId, $ids, $sellerOrderIds): SellerPickupRequest {
            $seller = $this->sellerFor($actor);
            $address = Address::query()->whereKey($pickupAddressId)->lockForUpdate()->first();
            if (! $address
                || (int) $address->user_id !== (int) $seller->user_id) {
                throw new RuntimeException('Choose a pickup address saved to your Seller account.');
            }
            if (! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
                throw new RuntimeException('Confirm the map pin for this pickup address before requesting a Rider.');
            }

            $shipments = Shipment::query()
                ->whereIn('id', $ids)
                ->with(['sellerOrder', 'waybills', 'pickupRequests', 'riderAssignments'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($shipments->count() !== count($ids)) {
                throw new RuntimeException('One or more selected parcels could not be found. Refresh the pickup list and try again.');
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
                    throw new RuntimeException('Every selected parcel must have its own tracking number and waybill.');
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
            }

            $pickupRequest = SellerPickupRequest::query()->create([
                'seller_profile_id' => $seller->id,
                'pickup_address_id' => $address->id,
                'status' => 'REQUESTED',
            ]);

            foreach ($shipments as $shipment) {
                SellerPickupRequestShipment::query()->create([
                    'seller_pickup_request_id' => $pickupRequest->id,
                    'seller_profile_id' => $seller->id,
                    'seller_order_id' => $shipment->seller_order_id,
                    'shipment_id' => $shipment->id,
                    'active_slot' => true,
                ]);
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
            if ($pickupRequest->status !== 'REQUESTED') {
                throw new RuntimeException('Only a requested Seller pickup can be assigned.');
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

            $result = $this->batches->createPartial(
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
                $skippedRequest = SellerPickupRequest::query()->create([
                    'seller_profile_id' => $pickupRequest->seller_profile_id,
                    'pickup_address_id' => $pickupRequest->pickup_address_id,
                    'status' => 'REQUESTED',
                ]);
                foreach ($skippedMemberships as $membership) {
                    $membership->update(['active_slot' => null]);
                    SellerPickupRequestShipment::query()->create([
                        'seller_pickup_request_id' => $skippedRequest->id,
                        'seller_profile_id' => $membership->seller_profile_id,
                        'seller_order_id' => $membership->seller_order_id,
                        'shipment_id' => $membership->shipment_id,
                        'active_slot' => true,
                    ]);
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
            $this->releaseRequest($locked, 'REJECTED');
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
            if ($locked->status !== 'REQUESTED') {
                throw new RuntimeException('A pickup can only be cancelled before Logistics assigns a Rider.');
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
        $pickupRequest->loadMissing([
            'riderLocalTrip.rider.user',
            'riderLocalTrip.items.riderAssignment.shipment',
            'memberships.shipment',
            'memberships.sellerOrder',
        ]);

        $trip = $pickupRequest->riderLocalTrip;
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

        return [
            'status' => (string) $pickupRequest->status,
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
