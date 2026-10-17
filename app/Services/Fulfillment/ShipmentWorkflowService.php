<?php

namespace App\Services\Fulfillment;

use App\Events\ShipmentTrackingUpdated;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Finance\CodCollection;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Logistics\Waybill;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\ProductVariant;
use App\Services\Finance\CodCollectionService;
use App\Services\Finance\FinancialAllocationService;
use App\Models\User;
use App\Support\RiderVehicleType;
use App\Support\OrderItemParcelAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ShipmentWorkflowService
{
    public const MAX_ACTIVE_PICKUP_ASSIGNMENTS = 10;

    public function __construct(
        private readonly LocalRiderEligibilityService $localRiderEligibility,
        private readonly LogisticsSchemaReadiness $schema,
        private readonly ShipmentTransferService $transfers,
        private readonly LocalRiderTripService $localTrips,
    ) {}

    public function sellerTransition(
        SellerOrder $sellerOrder,
        string $action,
        User $actor,
        ?string $cancellationReason = null,
        ?array $shipmentIds = null,
    ): SellerOrder
    {
        return DB::transaction(function () use ($sellerOrder, $action, $actor, $cancellationReason, $shipmentIds): SellerOrder {
            $sellerOrder = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->with(['order.address', 'items.shipment'])
                ->lockForUpdate()
                ->firstOrFail();
            $shipments = $this->ensureItemShipments($sellerOrder, $actor);

            match ($action) {
                'confirm' => $this->confirmSellerOrder($sellerOrder, $shipments, $actor),
                'prepare' => $this->prepareSellerOrder($sellerOrder, $shipments, $actor),
                'pack' => $this->markSellerOrderPacked($sellerOrder, $shipments, $actor, $shipmentIds),
                'ready' => $this->readyForPickup($sellerOrder, $shipments, $actor, false, null, $shipmentIds),
                'handover_confirm' => $this->confirmSellerHandover($sellerOrder, $shipments, $actor, $shipmentIds),
                'cancel' => $this->cancelSellerOrder($sellerOrder, $shipments, $actor, (string) $cancellationReason),
                default => throw new RuntimeException('Unsupported seller order action.'),
            };

            return $sellerOrder->refresh();
        });
    }

    public function recordPackedMeasurements(Shipment $shipment, User $actor, string $weightKg, string $volumeLiters): Shipment
    {
        return DB::transaction(function () use ($shipment, $actor, $weightKg, $volumeLiters): Shipment {
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $sellerOrder = SellerOrder::query()->whereKey($locked->seller_order_id)->lockForUpdate()->firstOrFail();
            $actor->loadMissing('sellerProfile');
            if ($actor->account_type !== User::TYPE_SELLER
                || $actor->status !== User::STATUS_ACTIVE
                || (int) $sellerOrder->seller_profile_id !== (int) $actor->sellerProfile?->id) {
                throw new RuntimeException('Only the active seller who owns this parcel may record its packed measurements.');
            }
            if (! in_array($sellerOrder->status, ['PREPARING', 'READY_FOR_PICKUP'], true) || $locked->current_status !== 'PREPARING') {
                throw new RuntimeException('Packed measurements can only be recorded while this seller parcel is being prepared.');
            }

            $sealedOrReleased = TransferManifestItem::query()
                ->whereHas('transferLeg', fn ($query) => $query->where('shipment_id', $locked->id))
                ->whereHas('manifest', fn ($query) => $query->whereNotIn('status', ['DRAFT', 'CANCELLED']))
                ->exists();
            if ($sealedOrReleased) {
                throw new RuntimeException('Packed measurements are locked after this parcel is added to a sealed transfer manifest.');
            }

            $normalizeMeasurement = static function (string $value, float $maximum, string $label): string {
                $value = trim($value);
                if (! is_numeric($value)) {
                    throw new RuntimeException('Packed '.$label.' must be a valid positive decimal.');
                }
                $fraction = explode('.', $value, 2)[1] ?? '';
                $number = (float) $value;
                if (! is_finite($number) || $number <= 0) {
                    throw new RuntimeException('Packed weight and volume must both be greater than zero.');
                }
                if (! preg_match('/^\+?\d+(?:\.\d+)?$/D', $value)) {
                    throw new RuntimeException('Packed '.$label.' must be a valid positive decimal.');
                }
                if (strlen($fraction) > 3) {
                    throw new RuntimeException('Packed '.$label.' supports at most three decimal places.');
                }
                if ($number > $maximum) {
                    throw new RuntimeException('Packed measurements exceed the supported database precision.');
                }

                return number_format($number, 3, '.', '');
            };
            $weight = $normalizeMeasurement($weightKg, 9999999.999, 'weight');
            $volume = $normalizeMeasurement($volumeLiters, 999999999.999, 'volume');
            if ((string) $locked->parcel_weight_kg === $weight && (string) $locked->parcel_volume_liters === $volume) {
                return $locked;
            }

            $locked->update([
                'parcel_weight_kg' => $weight,
                'parcel_volume_liters' => $volume,
            ]);
            $this->recordEvent($locked, 'PREPARING', $actor, 'Seller recorded measured packed weight and volume for this order-item parcel.');

            return $locked->refresh();
        });
    }

    public function requestSellerHandover(
        SellerOrder $sellerOrder,
        User $actor,
        bool $sellerDropoff,
        ?string $note = null,
        ?array $shipmentIds = null,
    ): SellerOrder
    {
        return DB::transaction(function () use ($sellerOrder, $actor, $sellerDropoff, $note, $shipmentIds): SellerOrder {
            $sellerOrder = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->with(['order.address', 'items.shipment'])
                ->lockForUpdate()
                ->firstOrFail();
            $shipments = $this->ensureItemShipments($sellerOrder, $actor);
            $this->readyForPickup($sellerOrder, $shipments, $actor, $sellerDropoff, $note, $shipmentIds);

            return $sellerOrder->refresh();
        });
    }

    public function ensureShipment(SellerOrder $sellerOrder): Shipment
    {
        return $this->ensureItemShipments($sellerOrder)->first()
            ?? throw new RuntimeException('This seller order has no parcel items.');
    }

    /**
     * Ensure each order line has one persisted parcel. Ambiguous legacy
     * seller-order shipments stay intact until Logistics reconciles them.
     *
     * @return Collection<int, Shipment>
     */
    public function ensureItemShipments(SellerOrder $sellerOrder, ?User $actor = null): Collection
    {
        return DB::transaction(function () use ($sellerOrder, $actor): Collection {
            $sellerOrder = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->with([
                    'order.address',
                    'items.shipment',
                    'shipments.events',
                    'shipments.scans',
                    'shipments.pickupRequests',
                    'shipments.waybills',
                    'shipments.deliveryAttempts',
                    'shipments.transferLegs',
                ])
                ->lockForUpdate()
                ->firstOrFail();

            $items = $sellerOrder->items->sortBy('id')->values();
            if ($items->isEmpty()) {
                throw new RuntimeException('A seller order must contain at least one product line before parcels can be created.');
            }

            $unmappedShipments = $sellerOrder->shipments->whereNull('order_item_id')->values();
            if ($unmappedShipments->count() > 1) {
                throw new RuntimeException('This seller order has multiple legacy parcels without item mappings. Logistics must reconcile them before new labels can be issued.');
            }

            if ($unmappedShipments->isNotEmpty()) {
                if ($items->count() !== 1 || $sellerOrder->shipments->count() !== 1) {
                    throw new RuntimeException('This legacy shipment has no safe item mapping. Its tracking and history were preserved; Logistics must reconcile it before item-specific labels can be issued.');
                }

                // A single item is deterministic, even when the shipment has
                // history. Never choose an item by position for multi-line
                // legacy shipments.
                $source = $unmappedShipments->first();
                $item = $items->first();
                $source->update(['order_item_id' => $item->id]);
                $item->setRelation('shipment', $source);
                $this->refreshPendingCodAmount($source, $sellerOrder, $item);
            }

            $unmappedItems = $items->filter(fn (OrderItem $item): bool => ! $item->shipment)->values();
            if ($sellerOrder->shipments->isNotEmpty() && $unmappedItems->isNotEmpty()) {
                throw new RuntimeException('This seller order has an incomplete parcel-to-item mapping. Logistics must reconcile the existing shipment set before new labels can be issued.');
            }

            foreach ($items as $item) {
                if ($item->shipment) {
                    continue;
                }

                $parcel = $this->createItemParcel($sellerOrder, $item, null, $actor);
                $item->setRelation('shipment', $parcel);
                $sellerOrder->shipments->push($parcel);
            }

            return $sellerOrder->shipments->sortBy('id')->values();
        });
    }

    private function createItemParcel(SellerOrder $sellerOrder, OrderItem $item, ?Shipment $source, ?User $actor): Shipment
    {
        $address = $sellerOrder->order?->address;
        if (! $source && ! $address) {
            throw new RuntimeException('Order delivery address is missing.');
        }

        $route = null;
        $routingReviewReason = null;
        if (! $source && $this->schema->automaticRoutingReady() && $address) {
            $seller = $sellerOrder->sellerProfile;
            $seller?->loadMissing('businessAddress');
            if ($seller?->businessAddress) {
                try {
                    $route = app(PsgcLogisticsRoutingService::class)->resolve($seller, $address);
                } catch (ValidationException $exception) {
                    $routingReviewReason = $this->manualRoutingReviewReason($exception);
                    if (! $routingReviewReason) {
                        throw $exception;
                    }
                }
                if ($route && (int) $route['origin']->id !== (int) $route['destination']->id && ! $this->schema->manifestReady()) {
                    throw new RuntimeException('Cross-hub fulfillment is disabled until transfer manifests, custody scans, and approved vehicle capacities are installed.');
                }
            }
        }

        $attributes = $source?->only([
            'logistics_center_id',
            'origin_logistics_center_id',
            'destination_logistics_center_id',
            'service_area_id',
            'origin_province_code',
            'origin_province_name',
            'origin_municipality_code',
            'origin_municipality_name',
            'origin_barangay_code',
            'origin_barangay_name',
            'destination_province_code',
            'destination_province_name',
            'destination_municipality_code',
            'destination_municipality_name',
            'destination_barangay_code',
            'destination_barangay_name',
        ]) ?? [];

        if (! $source) {
            $area = $route['destination_area'] ?? null;
            if (! $route && ! $routingReviewReason) {
                $area = $this->findServiceAreaForAddress(
                    (string) $address->province_code,
                    (string) $address->municipality_code,
                    (string) $address->barangay_code,
                );
            }
            $attributes += [
                'logistics_center_id' => $route['origin']->id ?? $area?->logistics_center_id,
                'service_area_id' => $area?->id,
                'destination_province_code' => $address->province_code,
                'destination_province_name' => $address->province_name,
                'destination_municipality_code' => $address->municipality_code,
                'destination_municipality_name' => $address->municipality_name,
                'destination_barangay_code' => $address->barangay_code,
                'destination_barangay_name' => $address->barangay_name,
            ];
            if ($route) {
                $originAddress = $route['origin']->address;
                $sellerAddress = $sellerOrder->sellerProfile->businessAddress;
                $attributes += [
                    'origin_logistics_center_id' => $route['origin']->id,
                    'destination_logistics_center_id' => $route['destination']->id,
                    'origin_province_code' => $originAddress->province_code,
                    'origin_province_name' => $originAddress->province_name,
                    'origin_municipality_code' => $originAddress->municipality_code,
                    'origin_municipality_name' => $originAddress->municipality_name,
                    'origin_barangay_code' => $sellerAddress->barangay_code,
                    'origin_barangay_name' => $sellerAddress->barangay_name,
                ];
            }
        }

        $status = $source?->current_status ?? 'PLACED';
        if (! $source && $sellerOrder->status !== 'PLACED') {
            throw new RuntimeException('A parcel cannot be reconstructed for this seller order without its original shipment record.');
        }

        $shipment = Shipment::query()->create([
            ...$attributes,
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $item->id,
            'tracking_number' => $this->newTrackingNumber(),
            // Physical dimensions belong to the newly packed parcel and must
            // be measured independently instead of copied from the old pack.
            'parcel_weight_kg' => null,
            'parcel_volume_liters' => null,
            'current_status' => $status,
        ]);

        if ($source && $source->events->isNotEmpty()) {
            foreach ($source->events as $event) {
                $shipment->events()->create([
                    'status' => $event->status,
                    'actor_user_id' => $event->actor_user_id,
                    'logistics_center_id' => $event->logistics_center_id,
                    'rider_assignment_id' => null,
                    'notes' => 'Parcel created for order item '.$item->id.'. '.$event->notes,
                    'occurred_at' => $event->occurred_at,
                ]);
            }
        } else {
            $note = $routingReviewReason
                ? 'Parcel created for order item '.$item->id.'. ROUTING_REVIEW_REQUIRED: '.$routingReviewReason
                : 'Parcel created for order item '.$item->id.'.';
            $this->recordEvent($shipment, 'PLACED', $sellerOrder->order?->buyer, $note);
        }

        if ($route && (int) $route['origin']->id !== (int) $route['destination']->id) {
            app(ShipmentTransferService::class)->createPlannedLeg($shipment, $route['origin'], $route['destination']);
        }

        if ($status === 'READY_FOR_PICKUP') {
            $requestedBy = $actor?->id ?? $sellerOrder->sellerProfile()->value('user_id');
            if (! $requestedBy) {
                throw new RuntimeException('A seller account is required to request pickup for this parcel.');
            }
            $oldNote = $source?->pickupRequests->first()?->notes;
            $dropoff = str_starts_with((string) $oldNote, 'SELLER_DROPOFF:');
            PickupRequest::query()->create([
                'shipment_id' => $shipment->id,
                'requested_by_user_id' => $requestedBy,
                'status' => 'PENDING',
                'requested_at' => now(),
                'notes' => ($dropoff ? 'SELLER_DROPOFF:' : 'LOGISTICS_PICKUP:').
                    'Separate parcel created for '.$item->product_name.'; Logistics pickup approval required.',
            ]);

            $this->generateWaybill($shipment, $actor ?? $sellerOrder->sellerProfile->user);
        }

        app(\App\Services\Finance\CodCollectionService::class)->initializeForShipment($shipment);

        return $shipment->load(['orderItem', 'sellerOrder']);
    }

    private function manualRoutingReviewReason(ValidationException $exception): ?string
    {
        $reason = (string) ($exception->errors()['address_id'][0] ?? '');
        $message = mb_strtolower($reason);

        return str_contains($message, 'ambiguous')
            || str_contains($message, 'no active logistics hub')
            || str_contains($message, 'barangay psgc is not covered')
            ? $reason
            : null;
    }

    private function refreshPendingCodAmount(Shipment $shipment, SellerOrder $sellerOrder, OrderItem $item): void
    {
        $amount = OrderItemParcelAmount::forItem($sellerOrder, $item)['total'];
        CodCollection::query()
            ->where('shipment_id', $shipment->id)
            ->where('status', CodCollection::PENDING)
            ->update(['expected_amount' => number_format($amount, 2, '.', '')]);
    }

    public function approvePickup(PickupRequest $pickupRequest, User $reviewer, ?string $notes = null): PickupRequest
    {
        return DB::transaction(function () use ($pickupRequest, $reviewer, $notes): PickupRequest {
            $pickupRequest = PickupRequest::query()
                ->whereKey($pickupRequest->id)
                ->with('shipment')
                ->lockForUpdate()
                ->firstOrFail();
            if ($pickupRequest->status !== 'PENDING') {
                throw new RuntimeException('Only pending pickup requests can be approved.');
            }

            $pickupRequest->update([
                'status' => 'APPROVED',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'notes' => $notes ?: $pickupRequest->notes,
            ]);

            $shipment = $pickupRequest->shipment;
            $this->recordEvent($shipment, $shipment->current_status, $reviewer, 'Pickup request approved.');

            return $pickupRequest->refresh();
        });
    }

    public function rejectPickup(PickupRequest $pickupRequest, User $reviewer, string $reason): PickupRequest
    {
        return DB::transaction(function () use ($pickupRequest, $reviewer, $reason): PickupRequest {
            $pickupRequest = PickupRequest::query()
                ->whereKey($pickupRequest->id)
                ->with('shipment')
                ->lockForUpdate()
                ->firstOrFail();
            if ($pickupRequest->status !== 'PENDING') {
                throw new RuntimeException('Only pending pickup requests can be rejected.');
            }

            $pickupRequest->update([
                'status' => 'REJECTED',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->recordEvent($pickupRequest->shipment, $pickupRequest->shipment->current_status, $reviewer, 'Pickup request rejected: '.$reason);

            return $pickupRequest->refresh();
        });
    }

    public function assignRider(
        Shipment $shipment,
        RiderProfile $rider,
        string $assignmentType,
        User $assigner,
        bool $allowPickupAreaOverride = false,
        ?string $pickupAreaOverrideReason = null,
    ): RiderAssignment
    {
        return DB::transaction(function () use ($shipment, $rider, $assignmentType, $assigner, $allowPickupAreaOverride, $pickupAreaOverrideReason): RiderAssignment {
            $shipment = Shipment::query()
                ->whereKey($shipment->id)
                ->with('serviceArea')
                ->lockForUpdate()
                ->firstOrFail();
            $rider = RiderProfile::query()
                ->whereKey($rider->id)
                ->with(['user', 'logisticsCenter'])
                ->lockForUpdate()
                ->firstOrFail();
            $assignmentType = strtoupper($assignmentType);

            if (! in_array($assignmentType, ['PICKUP', 'DELIVERY'], true)) {
                throw new RuntimeException('Assignment type must be PICKUP or DELIVERY.');
            }

            if ($assignmentType === 'PICKUP') {
                $sellerOrder = SellerOrder::query()->whereKey($shipment->seller_order_id)->lockForUpdate()->firstOrFail();
                if ($sellerOrder->status !== 'READY_FOR_PICKUP') {
                    throw new RuntimeException('A pickup rider can only be assigned while the seller order is ready for pickup.');
                }
            }

            if (! in_array($rider->status, ['ACTIVE', 'WARNED'], true)
                || $rider->user?->account_type !== User::TYPE_RIDER
                || $rider->user?->status !== User::STATUS_ACTIVE) {
                throw new RuntimeException('Only active riders can receive assignments.');
            }
            if (! RiderVehicleType::isLocalBarangay($rider->vehicle_type)) {
                throw new RuntimeException('Only motorcycle and tricycle riders can receive local pickup and delivery assignments.');
            }
            if ($allowPickupAreaOverride) {
                $assigner->loadMissing('logisticsCenter');
                if ($assigner->account_type !== User::TYPE_LOGISTICS
                    || $assigner->status !== User::STATUS_ACTIVE
                    || ! $assigner->logisticsCenter
                    || (int) $assigner->logisticsCenter->id !== (int) $rider->logistics_center_id) {
                    throw new RuntimeException('Only an active authorized logistics center account can approve a pickup coverage override.');
                }
                if (blank(trim((string) $pickupAreaOverrideReason))) {
                    throw new RuntimeException('A reason is required for a pickup coverage override.');
                }
            }

            $pickupArea = null;

            if ($assignmentType === 'PICKUP') {
                $this->requireShipmentStatus($shipment, ['READY_FOR_PICKUP'], 'assign a pickup rider');

                $pickupArea = $this->sellerPickupServiceArea($shipment, $rider->logisticsCenter);
                if (! $pickupArea) {
                    throw new RuntimeException('The seller pickup address is not covered by an active service area.');
                }

                $pickupArea->loadMissing('logisticsCenter');
                if ((int) $rider->logistics_center_id !== (int) $pickupArea->logistics_center_id) {
                    throw new RuntimeException('The rider must belong to the logistics center that covers the seller pickup municipality.');
                }
                $this->localRiderEligibility->assertRiderCanCoverArea($pickupArea->logisticsCenter, $rider, $pickupArea);

                if (! $this->riderCoversSellerPickupArea($shipment, $rider)
                    && ! $allowPickupAreaOverride) {
                    throw new RuntimeException('The selected rider is not assigned to the seller pickup area. Assign that area to the rider or choose another rider.');
                }
            } else {
                $this->requireShipmentStatus($shipment, ['SORTED', 'DELIVERY_FAILED'], 'assign a delivery rider');

                if ($this->isUnreceivedCrossHubShipment($shipment)) {
                    throw new RuntimeException('This shipment must be received by its destination hub before final-mile delivery assignment.');
                }

                $shipmentCenterId = $shipment->logistics_center_id ?: $shipment->serviceArea?->logistics_center_id;
                if ($shipmentCenterId && (int) $rider->logistics_center_id !== (int) $shipmentCenterId) {
                    throw new RuntimeException('The rider does not belong to this shipment logistics center.');
                }

                if (! $this->riderCoversShipmentDestinationArea($shipment, $rider)) {
                    throw new RuntimeException('The selected rider is not assigned to the parcel destination area. Assign that area to the rider or choose another rider.');
                }
            }

            $shipment->riderAssignments()
                ->where('assignment_type', $assignmentType)
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                    'rejection_reason' => 'Replaced by a newer assignment.',
                ]);

            if ($assignmentType === 'PICKUP') {
                $activePickupAssignments = $rider->assignments()
                    ->where('assignment_type', 'PICKUP')
                    ->where('shipment_id', '<>', $shipment->id)
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                    ->count();

                if ($activePickupAssignments >= self::MAX_ACTIVE_PICKUP_ASSIGNMENTS) {
                    throw new RuntimeException('This rider has reached the 10-parcel active pickup limit.');
                }
            }

            $assignment = RiderAssignment::create([
                'shipment_id' => $shipment->id,
                'rider_profile_id' => $rider->id,
                'assignment_type' => $assignmentType,
                'status' => 'ASSIGNED',
                'assigned_by_user_id' => $assigner->id,
                'assigned_at' => now(),
            ]);

            if ($assignmentType === 'DELIVERY') {
                app(CodCollectionService::class)->attachDeliveryRider($shipment, $assignment);
                app(FinancialAllocationService::class)->syncRiderParticipant($shipment, $assignment->rider_profile_id, 'DELIVERY', $assignment->id);
                $shipment->update(['current_status' => 'ASSIGNED_TO_RIDER']);
                $this->recordEvent($shipment, 'ASSIGNED_TO_RIDER', $assigner, 'Delivery rider assigned.', $assignment);
            } else {
                app(FinancialAllocationService::class)->syncRiderParticipant($shipment, $assignment->rider_profile_id, 'PICKUP', $assignment->id);
                $note = $allowPickupAreaOverride
                    ? 'Pickup rider assigned with coverage override: '.trim((string) $pickupAreaOverrideReason)
                    : 'Pickup rider assigned.';
                $this->recordEvent($shipment, $shipment->current_status, $assigner, $note, $assignment);
            }

            return $assignment->refresh();
        });
    }

    public function receiveAtCenter(Shipment $shipment, LogisticsCenter $center, User $actor, string $code, string $method = 'MANUAL'): Shipment
    {
        return DB::transaction(function () use ($shipment, $center, $actor, $code, $method): Shipment {
            $shipment = Shipment::query()
                ->whereKey($shipment->id)
                ->with(['serviceArea', 'pickupRequests', 'sellerOrder'])
                ->lockForUpdate()
                ->firstOrFail();
            if ($shipment->logistics_center_id && (int) $shipment->logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('This parcel belongs to another logistics center.');
            }
            if (! $shipment->logistics_center_id && (int) $shipment->serviceArea?->logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('This parcel is not assigned to this logistics center.');
            }

            $code = trim($code);
            if ($code === '' || ! hash_equals($shipment->tracking_number, $code)) {
                throw ValidationException::withMessages(['scanned_code' => 'The scanned parcel code does not match the shipment tracking number.']);
            }

            if ($shipment->current_status === 'AT_SORTING_CENTER') {
                return $shipment;
            }
            $dropoffRequest = $shipment->pickupRequests
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->first(fn (PickupRequest $pickup): bool => str_starts_with((string) $pickup->notes, 'SELLER_DROPOFF:'));
            $isSellerDropoff = $shipment->current_status === 'READY_FOR_PICKUP' && $dropoffRequest !== null;
            $this->requireShipmentStatus(
                $shipment,
                $isSellerDropoff ? ['READY_FOR_PICKUP'] : ['PICKED_UP'],
                'receive the parcel at the sorting center',
            );

            $this->recordScan($shipment, $actor, 'CENTER_RECEIVE', $method, $code, 'SUCCESS', $center);
            $shipment->update([
                'logistics_center_id' => $center->id,
                'current_status' => 'AT_SORTING_CENTER',
            ]);
            $shipment->riderAssignments()
                ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                ->where('status', 'COMPLETED')
                ->get()
                ->each(fn (RiderAssignment $assignment) => $assignment->liveLocation()->delete());
            $dropoffRequest?->update(['status' => 'FULFILLED', 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now()]);
            $this->recordEvent($shipment, 'AT_SORTING_CENTER', $actor, 'Parcel received at logistics center.', null, $center);

            $shipment->sellerOrder?->update(['status' => 'PICKED_UP']);
            $this->localTrips->syncPickupIntake($shipment);

            return $shipment->refresh();
        });
    }

    public function sortShipment(Shipment $shipment, LogisticsCenter $center, User $actor, ?ServiceArea $serviceArea = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $center, $actor, $serviceArea): Shipment {
            $shipment = Shipment::query()
                ->whereKey($shipment->id)
                ->with('serviceArea')
                ->lockForUpdate()
                ->firstOrFail();
            $this->requireShipmentStatus($shipment, ['AT_SORTING_CENTER'], 'sort the parcel');
            if ($shipment->logistics_center_id && (int) $shipment->logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('This parcel belongs to another logistics center.');
            }

            if ($this->isCrossHubShipmentAtOrigin($shipment, $center)) {
                $leg = ShipmentTransferLeg::query()
                    ->where('shipment_id', $shipment->id)
                    ->where('sequence_no', 1)
                    ->lockForUpdate()
                    ->first();
                if (! $leg) {
                    throw new RuntimeException('The cross-hub shipment has no planned transfer leg; routing is paused safely.');
                }
                $shipment->update([
                    'logistics_center_id' => $center->id,
                    'current_status' => 'SORTED',
                ]);
                if ($leg->status === ShipmentTransferLeg::PLANNED) {
                    $this->transfers->markReady($leg, $actor);
                }
                $this->recordEvent($shipment, 'SORTED', $actor, 'Parcel sorted for an inter-hub transfer; final-mile assignment is locked until destination receipt.', null, $center);

                return $shipment->refresh();
            }
            $area = $serviceArea ?: $this->findServiceAreaForAddress(
                (string) $shipment->destination_province_code,
                (string) $shipment->destination_municipality_code,
                (string) $shipment->destination_barangay_code,
                $center,
            );

            if (! $area || (int) $area->logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('No active delivery area at this center matches the parcel destination.');
            }

            $area->loadMissing('locations');
            if ($area->locations->isEmpty() || $area->locations->contains(fn ($location): bool =>
                ! $this->localRiderEligibility->centerCoversMunicipality(
                    $center,
                    $location->province_code,
                    $location->municipality_code,
                ))) {
                throw new RuntimeException('The selected delivery area is not fully owned by this center’s canonical municipality.');
            }
            $matchesDestination = $this->serviceAreaMatchesDestinationCodes($area, $shipment);

            if (! $matchesDestination) {
                throw new RuntimeException('The selected delivery area does not match the parcel destination.');
            }

            $shipment->update([
                'logistics_center_id' => $center->id,
                'service_area_id' => $area?->id,
                'current_status' => 'SORTED',
            ]);

            $this->recordEvent($shipment, 'SORTED', $actor, $area ? 'Parcel sorted to '.$area->name.'.' : 'Parcel sorted, but no service area matched.', null, $center);

            return $shipment->refresh();
        });
    }

    public function riderTransition(RiderAssignment $assignment, string $action, User $actor, array $payload = []): RiderAssignment
    {
        return DB::transaction(function () use ($assignment, $action, $actor, $payload): RiderAssignment {
            $shipmentId = RiderAssignment::query()->whereKey($assignment->id)->value('shipment_id');
            $shipment = Shipment::query()->whereKey($shipmentId)->lockForUpdate()->firstOrFail();
            $assignment = RiderAssignment::query()
                ->whereKey($assignment->id)
                ->with('riderProfile')
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $assignment->shipment_id !== (int) $shipment->id) {
                throw new RuntimeException('The rider assignment changed while this action was being processed.');
            }
            $assignment->setRelation('shipment', $shipment);
            $this->assertRiderActor($assignment, $actor);

            if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP && $action === 'reject') {
                throw new RuntimeException('A seller pickup batch cannot be partially rejected. Resolve a parcel as not collected at the seller or contact Logistics to reassign the batch.');
            }

            if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP
                && in_array($action, ['accept', 'start', 'pickup_complete', 'pickup_not_collected'], true)) {
                $this->localTrips->assertSellerPickupBatchAssignment($assignment);
            }

            match ($action) {
                'accept' => $this->acceptAssignment($assignment, $shipment, $actor),
                'start' => $this->startAssignment($assignment, $shipment, $actor),
                'pickup_complete' => $this->completePickup($assignment, $shipment, $actor, $payload),
                'pickup_not_collected' => $this->markPickupNotCollected($assignment, $shipment, $actor, $payload),
                'delivery_success' => $this->completeDelivery($assignment, $shipment, $actor, $payload),
                'delivery_failed' => $this->failDelivery($assignment, $shipment, $actor, $payload),
                'reject' => $this->rejectAssignment($assignment, $shipment, $actor, (string) ($payload['reason'] ?? 'Rejected by rider.')),
                default => throw new RuntimeException('Unsupported rider assignment action.'),
            };

            return $assignment->refresh();
        });
    }

    public function recordScan(
        Shipment $shipment,
        ?User $actor,
        string $scanType,
        string $method,
        string $code,
        string $result = 'SUCCESS',
        ?LogisticsCenter $center = null,
        ?RiderAssignment $assignment = null,
        ?string $notes = null,
    ): ParcelScan {
        return ParcelScan::create([
            'shipment_id' => $shipment->id,
            'scanned_by_user_id' => $actor?->id,
            'logistics_center_id' => $center?->id,
            'rider_assignment_id' => $assignment?->id,
            'scan_type' => strtoupper($scanType),
            'scan_method' => strtoupper($method),
            'scanned_code' => $code,
            'result' => strtoupper($result),
            'notes' => $notes,
            'scanned_at' => now(),
        ]);
    }

    public function recordEvent(
        Shipment $shipment,
        string $status,
        ?User $actor = null,
        ?string $notes = null,
        ?RiderAssignment $assignment = null,
        ?LogisticsCenter $center = null,
    ): ShipmentEvent {
        $event = ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => strtoupper($status),
            'actor_user_id' => $actor?->id,
            'logistics_center_id' => $center?->id ?: $shipment->logistics_center_id,
            'rider_assignment_id' => $assignment?->id,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);

        $shipment->loadMissing('riderAssignments');
        event(ShipmentTrackingUpdated::fromShipment($shipment));

        return $event;
    }

    public function findServiceAreaForAddress(
        string $provinceCode,
        string $municipalityCode,
        string $barangayCode,
        ?LogisticsCenter $center = null,
    ): ?ServiceArea
    {
        if (blank(trim($provinceCode)) || blank(trim($municipalityCode)) || blank(trim($barangayCode))) {
            return null;
        }

        $query = ServiceAreaLocation::query()
            ->where('province_code', $provinceCode)
            ->where('municipality_code', $municipalityCode)
            ->where('barangay_code', $barangayCode)
            ->whereHas('serviceArea', function ($areaQuery) use ($center): void {
                $areaQuery->where('is_active', true);

                if ($center) {
                    $areaQuery->where('logistics_center_id', $center->id);
                }
            })
            ->with('serviceArea.logisticsCenter.address');

        $locations = $query->get();
        if ($locations->count() > 1) {
            throw new RuntimeException('The address matches multiple active logistics coverage areas. Routing is paused until the PSGC ownership is made unique.');
        }

        foreach ($locations as $location) {
            $area = $location->serviceArea;
            $areaCenter = $area?->logisticsCenter;
            if ($area && $areaCenter
                && $this->localRiderEligibility->centerCoversMunicipality(
                    $areaCenter,
                    $location->province_code,
                    $location->municipality_code,
                )) {
                return $area;
            }
        }

        return null;
    }

    public function pickupServiceArea(Shipment $shipment, ?LogisticsCenter $center = null): ?ServiceArea
    {
        return $this->sellerPickupServiceArea($shipment, $center);
    }

    public function sellerPickupServiceArea(Shipment $shipment, ?LogisticsCenter $center = null): ?ServiceArea
    {
        $shipment->loadMissing('sellerOrder.sellerProfile.businessAddress', 'serviceArea');
        $address = $shipment->sellerOrder?->sellerProfile?->businessAddress;

        if ($address) {
            $area = $this->findServiceAreaForAddress(
                (string) $address->province_code,
                (string) $address->municipality_code,
                (string) $address->barangay_code,
                $center,
            );

            if ($area) {
                return $area;
            }
        }

        return null;
    }

    /** Match pickup coverage using the saved PSGC codes only. */
    public function riderCoversSellerPickupArea(Shipment $shipment, RiderProfile $rider): bool
    {
        $shipment->loadMissing('sellerOrder.sellerProfile.businessAddress');
        $rider->loadMissing('areaAssignments.serviceArea.locations');

        $activeAssignments = $rider->areaAssignments->where('is_active', true);

        $address = $shipment->sellerOrder?->sellerProfile?->businessAddress;
        if (! $address) {
            return false;
        }

        return $activeAssignments->contains(fn ($assignment) =>
            $this->riderCanUseLocalArea($rider, $assignment->serviceArea)
            && $this->serviceAreaMatchesAddressCodes($assignment->serviceArea, $address));
    }

    /** Match final-mile coverage using the order snapshot PSGC codes only. */
    public function riderCoversShipmentDestinationArea(Shipment $shipment, RiderProfile $rider): bool
    {
        $rider->loadMissing('areaAssignments.serviceArea.locations');

        $activeAssignments = $rider->areaAssignments->where('is_active', true);
        return $activeAssignments->contains(fn ($assignment) =>
            $this->riderCanUseLocalArea($rider, $assignment->serviceArea)
            && $this->serviceAreaMatchesDestinationCodes($assignment->serviceArea, $shipment));
    }

    private function serviceAreaMatchesAddressCodes(?ServiceArea $area, object $address): bool
    {
        return $this->serviceAreaMatchesCodes(
            $area,
            $address->province_code,
            $address->municipality_code,
            $address->barangay_code,
        );
    }

    private function riderCanUseLocalArea(RiderProfile $rider, ?ServiceArea $area): bool
    {
        $rider->loadMissing('user', 'logisticsCenter');

        return $rider->status === 'ACTIVE'
            && $rider->user?->account_type === User::TYPE_RIDER
            && $rider->user?->status === User::STATUS_ACTIVE
            && RiderVehicleType::isLocalBarangay($rider->vehicle_type)
            && $rider->logisticsCenter
            && $this->localRiderEligibility->singleBarangayLocation($rider->logisticsCenter, $area) !== null;
    }

    private function serviceAreaMatchesDestinationCodes(?ServiceArea $area, Shipment $shipment): bool
    {
        return $this->serviceAreaMatchesCodes(
            $area,
            $shipment->destination_province_code,
            $shipment->destination_municipality_code,
            $shipment->destination_barangay_code,
        );
    }

    private function serviceAreaMatchesCodes(
        ?ServiceArea $area,
        ?string $provinceCode,
        ?string $municipalityCode,
        ?string $barangayCode,
    ): bool {
        if (! $area?->is_active
            || blank(trim((string) $provinceCode))
            || blank(trim((string) $municipalityCode))
            || blank(trim((string) $barangayCode))) {
            return false;
        }

        foreach ($area?->locations ?? [] as $location) {
            if (blank(trim((string) $location->province_code))
                || blank(trim((string) $location->municipality_code))
                || blank(trim((string) $location->barangay_code))) {
                continue;
            }
            if (trim((string) $provinceCode) === trim((string) $location->province_code)
                && trim((string) $municipalityCode) === trim((string) $location->municipality_code)
                && trim((string) $barangayCode) === trim((string) $location->barangay_code)) {
                return true;
            }
        }

        return false;
    }

    private function isCrossHubShipmentAtOrigin(Shipment $shipment, LogisticsCenter $center): bool
    {
        return $this->schema->automaticRoutingReady()
            && filled($shipment->origin_logistics_center_id)
            && filled($shipment->destination_logistics_center_id)
            && (int) $shipment->origin_logistics_center_id !== (int) $shipment->destination_logistics_center_id
            && (int) $shipment->logistics_center_id === (int) $center->id
            && (int) $shipment->origin_logistics_center_id === (int) $center->id;
    }

    private function isUnreceivedCrossHubShipment(Shipment $shipment): bool
    {
        return $this->schema->automaticRoutingReady()
            && filled($shipment->origin_logistics_center_id)
            && filled($shipment->destination_logistics_center_id)
            && (int) $shipment->origin_logistics_center_id !== (int) $shipment->destination_logistics_center_id
            && (int) $shipment->logistics_center_id !== (int) $shipment->destination_logistics_center_id;
    }

    private function confirmSellerOrder(SellerOrder $sellerOrder, Collection $shipments, User $actor): void
    {
        $this->requireSellerOrderParcelsStatus($sellerOrder, $shipments, 'PLACED', 'confirm the order');
        $sellerOrder->update(['status' => 'CONFIRMED']);
        $this->syncParentOrderProgress($sellerOrder->order);
        foreach ($shipments as $shipment) {
            $shipment->update(['current_status' => 'CONFIRMED']);
            $this->recordEvent($shipment, 'CONFIRMED', $actor, 'Seller confirmed the order.');
        }
    }

    private function prepareSellerOrder(SellerOrder $sellerOrder, Collection $shipments, User $actor): void
    {
        $this->requireSellerOrderParcelsStatus($sellerOrder, $shipments, 'CONFIRMED', 'prepare the order');
        $sellerOrder->update(['status' => 'PREPARING']);
        foreach ($shipments as $shipment) {
            $shipment->update(['current_status' => 'PREPARING']);
            $this->recordEvent($shipment, 'PREPARING', $actor, 'Seller is preparing this product-line parcel.');
        }
    }

    private function markSellerOrderPacked(SellerOrder $sellerOrder, Collection $shipments, User $actor, ?array $shipmentIds = null): void
    {
        if (! in_array($sellerOrder->status, ['PREPARING', 'READY_FOR_PICKUP'], true)
            || $shipments->isEmpty()
            || $shipments->contains(fn (Shipment $shipment): bool => ! in_array($shipment->current_status, ['PREPARING', 'READY_FOR_PICKUP'], true))) {
            throw new RuntimeException('Parcels can only be marked packed while the seller order is being prepared or partially ready for pickup.');
        }

        $preparing = $shipments->where('current_status', 'PREPARING')->values();
        $selected = $shipmentIds === null
            ? $preparing->filter(fn (Shipment $shipment): bool => ! $this->hasSellerPackedCheckpoint($shipment))->values()
            : $preparing->whereIn('id', array_map('intval', $shipmentIds))->values();
        if ($selected->isEmpty() && $shipmentIds === null) {
            return;
        }
        if ($selected->isEmpty() || ($shipmentIds !== null && $selected->count() !== count(array_unique(array_map('intval', $shipmentIds))))) {
            throw new RuntimeException('Select one or more parcels that are still being prepared before marking them packed.');
        }

        foreach ($selected as $shipment) {
            if (! $this->hasSellerPackedCheckpoint($shipment)) {
                $this->recordEvent($shipment, 'PREPARING', $actor, 'SELLER_PACKED_CHECKPOINT: Seller marked this item parcel packed.');
            }
        }
    }

    private function readyForPickup(
        SellerOrder $sellerOrder,
        Collection $shipments,
        User $actor,
        bool $sellerDropoff = false,
        ?string $note = null,
        ?array $shipmentIds = null,
    ): void
    {
        if (! in_array($sellerOrder->status, ['PREPARING', 'READY_FOR_PICKUP'], true)
            || $shipments->isEmpty()
            || $shipments->contains(fn (Shipment $shipment): bool => ! in_array($shipment->current_status, ['PREPARING', 'READY_FOR_PICKUP'], true))) {
            throw new RuntimeException('The seller order must be preparing or partially ready for pickup.');
        }

        $preparing = $shipments->where('current_status', 'PREPARING')->values();
        $eligible = $preparing->filter(fn (Shipment $shipment): bool => $this->hasSellerPackedCheckpoint($shipment))->values();
        $selected = $shipmentIds === null
            ? $eligible
            : $eligible->whereIn('id', array_map('intval', $shipmentIds))->values();
        $uniqueIds = $shipmentIds === null ? [] : array_values(array_unique(array_map('intval', $shipmentIds)));
        if ($selected->isEmpty() || ($shipmentIds !== null && $selected->count() !== count($uniqueIds))) {
            throw new RuntimeException('Pack each selected parcel before marking it ready. Select at least one packed parcel.');
        }
        if ($selected->count() > \App\Support\LocalTripCapacityRules::MAX_PARCELS_PER_TRIP) {
            throw new RuntimeException('Mark 1 to 10 packed parcels ready for pickup at a time.');
        }

        if ($this->schema->automaticRoutingReady()
            && $selected->contains(fn (Shipment $shipment): bool => ! $shipment->origin_logistics_center_id
                || ! $shipment->destination_logistics_center_id
                || ! $shipment->service_area_id)) {
            throw new RuntimeException('This parcel needs manual PSGC routing review before it can be marked ready for pickup.');
        }

        $sellerOrder->update(['status' => 'READY_FOR_PICKUP']);
        foreach ($selected as $shipment) {
            $pickupArea = $this->pickupServiceArea($shipment);
            $shipment->update([
                'current_status' => 'READY_FOR_PICKUP',
                // Keep the provider selected at checkout. Only infer a center
                // when the parcel does not already have an explicit provider.
                'logistics_center_id' => $shipment->logistics_center_id ?: $pickupArea?->logistics_center_id,
            ]);

            PickupRequest::firstOrCreate(
                ['shipment_id' => $shipment->id, 'status' => 'PENDING'],
                [
                    'requested_by_user_id' => $actor->id,
                    'requested_at' => now(),
                    'notes' => ($sellerDropoff ? 'SELLER_DROPOFF:' : 'LOGISTICS_PICKUP:').($note ?: 'Seller marked parcel ready for pickup.'),
                ],
            );

            $this->generateWaybill($shipment, $actor);
            $this->recordEvent(
                $shipment,
                'READY_FOR_PICKUP',
                $actor,
                $sellerDropoff ? 'Seller registered this parcel for drop-off at the assigned logistics center.' : 'Seller marked this parcel ready for pickup.',
            );
        }
    }

    private function cancelSellerOrder(SellerOrder $sellerOrder, Collection $shipments, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Enter a cancellation reason of 1 to 500 characters.');
        }
        if (! in_array($sellerOrder->status, ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'], true)) {
            throw new RuntimeException('This order can no longer be cancelled by the seller.');
        }
        if ($sellerOrder->order?->payments()->where('method', 'ONLINE')->where('status', 'PAID')->exists()) {
            throw new RuntimeException('A paid online order must use the approved refund workflow before seller cancellation.');
        }

        $allowedShipmentStatuses = ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'];
        if ($shipments->isEmpty() || $shipments->contains(fn (Shipment $shipment): bool => ! in_array($shipment->current_status, $allowedShipmentStatuses, true))) {
            throw new RuntimeException('This order can no longer be cancelled after a parcel has entered the delivery network.');
        }

        $shipmentIds = $shipments->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $assignments = RiderAssignment::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->where('assignment_type', 'PICKUP')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($assignments->contains(fn (RiderAssignment $assignment): bool => in_array($assignment->status, ['IN_PROGRESS', 'COMPLETED'], true))) {
            throw new RuntimeException('This order cannot be cancelled while a rider pickup is in progress or completed.');
        }

        if (Schema::hasTable('cod_collections')) {
            $collected = CodCollection::query()->whereIn('shipment_id', $shipmentIds)->lockForUpdate()
                ->whereIn('status', [CodCollection::COLLECTED, CodCollection::REMITTED, CodCollection::DISCREPANCY])
                ->exists();
            if ($collected) {
                throw new RuntimeException('This order cannot be cancelled after COD collection has been recorded.');
            }
        }

        $items = OrderItem::query()->where('seller_order_id', $sellerOrder->id)->orderBy('id')->lockForUpdate()->get();
        if ($items->isEmpty() || $items->contains(fn (OrderItem $item): bool => ! $item->product_variant_id)) {
            throw new RuntimeException('The purchased variant record is unavailable, so inventory cannot be restored safely.');
        }
        $quantityByVariant = $items->groupBy('product_variant_id')
            ->map(fn ($variantItems): int => (int) $variantItems->sum('quantity'));
        $variants = ProductVariant::query()->whereIn('id', $quantityByVariant->keys()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($variants->count() !== $quantityByVariant->count()) {
            throw new RuntimeException('A purchased variant record is unavailable, so inventory cannot be restored safely.');
        }

        foreach ($quantityByVariant as $variantId => $quantity) {
            $variants->get($variantId)->increment('stock', $quantity);
        }

        $cancelledAssignmentIds = collect();
        foreach ($assignments as $assignment) {
            if (in_array($assignment->status, ['ASSIGNED', 'ACCEPTED'], true)) {
                $assignment->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                    'rejection_reason' => 'Seller order cancelled: '.$reason,
                ]);
                $this->localTrips->syncAssignment($assignment->refresh());
                $cancelledAssignmentIds->push($assignment->id);
            }
        }
        $this->cancelEmptyPendingPickupBatches($cancelledAssignmentIds);
        PickupRequest::query()->whereIn('shipment_id', $shipmentIds)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->update(['status' => 'CANCELLED', 'rejection_reason' => 'Seller order cancelled: '.$reason]);

        if (Schema::hasTable('seller_pickup_request_shipments') && Schema::hasTable('seller_pickup_requests')) {
            $pickupRequestIds = DB::table('seller_pickup_request_shipments')
                ->where('seller_order_id', $sellerOrder->id)
                ->where('active_slot', true)
                ->distinct()
                ->pluck('seller_pickup_request_id');
            if ($pickupRequestIds->isNotEmpty()) {
                DB::table('seller_pickup_requests')
                    ->whereIn('id', $pickupRequestIds)
                    ->whereIn('status', ['REQUESTED', 'ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                    ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                DB::table('seller_pickup_request_shipments')
                    ->whereIn('seller_pickup_request_id', $pickupRequestIds)
                    ->where('active_slot', true)
                    ->update(['active_slot' => null, 'updated_at' => now()]);
            }
        }

        $sellerOrder->update(['status' => 'CANCELLED']);
        foreach ($shipments as $shipment) {
            $this->recordEvent($shipment, $shipment->current_status, $actor, 'SELLER_ORDER_CANCELLED: '.$reason);
        }

        $parentOrder = Order::query()->whereKey($sellerOrder->order_id)->lockForUpdate()->first();
        if ($parentOrder) {
            $sellerOrders = SellerOrder::query()->where('order_id', $parentOrder->id)->lockForUpdate()->get();
            $allFinal = $sellerOrders->every(fn (SellerOrder $order): bool => in_array($order->status, ['CANCELLED', 'COMPLETED'], true));
            if ($sellerOrders->every(fn (SellerOrder $order): bool => $order->status === 'CANCELLED')) {
                $parentOrder->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ]);
            } elseif ($allFinal) {
                $parentOrder->update(['status' => 'PARTIALLY_CANCELLED']);
            } elseif ($parentOrder->status !== 'PROCESSING') {
                $parentOrder->update(['status' => 'PROCESSING']);
            }
        }
    }

    /** @param Collection<int, int> $assignmentIds */
    private function cancelEmptyPendingPickupBatches(Collection $assignmentIds): void
    {
        if ($assignmentIds->isEmpty() || ! $this->schema->sellerPickupBatchesReady()) {
            return;
        }

        $tripIds = RiderLocalTripItem::query()
            ->whereIn('rider_assignment_id', $assignmentIds->all())
            ->pluck('rider_local_trip_id')
            ->unique();

        foreach ($tripIds as $tripId) {
            $trip = RiderLocalTrip::query()
                ->whereKey($tripId)
                ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                ->whereIn('status', [RiderLocalTrip::LOADING, RiderLocalTrip::READY])
                ->with('items.riderAssignment')
                ->lockForUpdate()
                ->first();
            if (! $trip || $trip->items->isEmpty()) {
                continue;
            }

            if ($trip->items->every(fn (RiderLocalTripItem $item): bool => $item->riderAssignment?->status === 'CANCELLED')) {
                $trip->update([
                    'status' => RiderLocalTrip::CANCELLED,
                    'cancelled_at' => now(),
                    'exception_notes' => 'Seller order cancellation left this pickup batch with no active parcels.',
                ]);
            }
        }
    }

    private function hasSellerPackedCheckpoint(Shipment $shipment): bool
    {
        return $shipment->events()->where('status', 'PREPARING')
            ->where('notes', 'like', 'SELLER_PACKED_CHECKPOINT:%')->exists();
    }

    private function confirmSellerHandover(SellerOrder $sellerOrder, Collection $shipments, User $actor, ?array $shipmentIds = null): void
    {
        if ($sellerOrder->status !== 'READY_FOR_PICKUP' || $shipments->isEmpty()) {
            throw new RuntimeException('The seller order must be ready before parcel handover can be confirmed.');
        }

        $ready = $shipments->where('current_status', 'READY_FOR_PICKUP')->values();
        $selected = $shipmentIds === null
            ? $ready
            : $ready->whereIn('id', array_map('intval', $shipmentIds))->values();
        $uniqueIds = $shipmentIds === null ? [] : array_values(array_unique(array_map('intval', $shipmentIds)));
        if ($selected->isEmpty() || ($shipmentIds !== null && $selected->count() !== count($uniqueIds))) {
            throw new RuntimeException('Select one or more parcels that are ready for pickup before confirming handover.');
        }
        if ($selected->count() > \App\Support\LocalTripCapacityRules::MAX_PARCELS_PER_TRIP) {
            throw new RuntimeException('Confirm handover for 1 to 10 parcels at a time.');
        }

        foreach ($selected as $shipment) {
            $assignment = $shipment->riderAssignments()
                ->where('assignment_type', 'PICKUP')
                ->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])
                ->latest()
                ->first();

            if (! $assignment) {
                throw new RuntimeException('Every parcel pickup rider must accept the assignment before handover can be confirmed.');
            }

            $this->recordEvent($shipment, 'READY_FOR_PICKUP', $actor, 'Seller confirmed this parcel handover to the pickup rider.', $assignment);
        }
    }

    private function generateWaybill(Shipment $shipment, ?User $actor): Waybill
    {
        return Waybill::firstOrCreate(
            ['shipment_id' => $shipment->id, 'format_version' => 1],
            [
                'generated_by_user_id' => $actor?->id,
                'label_path' => 'waybills/'.$shipment->tracking_number.'.pdf',
                'generated_at' => now(),
            ],
        );
    }

    private function acceptAssignment(RiderAssignment $assignment, Shipment $shipment, User $actor): void
    {
        $this->requireAssignmentStatus($assignment, ['ASSIGNED'], 'accept this assignment');
        $offerMinutes = max(1, (int) config('rider.assignment_offer_minutes', 15));
        if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY
            && $assignment->assigned_at
            && $assignment->assigned_at->lte(now()->subMinutes($offerMinutes))) {
            throw new RuntimeException('This delivery offer has expired. Refresh the dispatch queue for a new assignment.');
        }
        $assignment->update([
            'status' => 'ACCEPTED',
            'accepted_at' => now(),
        ]);

        $this->recordEvent($shipment, $shipment->current_status, $actor, $assignment->assignment_type.' rider accepted the assignment.', $assignment);
    }

    private function startAssignment(RiderAssignment $assignment, Shipment $shipment, User $actor): void
    {
        $this->requireAssignmentStatus($assignment, ['ACCEPTED'], 'start this assignment');

        if ($assignment->assignment_type === 'PICKUP') {
            $this->requireShipmentStatus($shipment, ['READY_FOR_PICKUP'], 'start pickup');
        } else {
            $this->requireShipmentStatus($shipment, ['ASSIGNED_TO_RIDER', 'DELIVERY_FAILED'], 'start delivery');
        }
        $this->localTrips->assertAssignmentMayStart($assignment);
        $assignment->update([
            'status' => 'IN_PROGRESS',
            'started_at' => now(),
        ]);

        if ($assignment->assignment_type === 'DELIVERY') {
            $shipment->update(['current_status' => 'OUT_FOR_DELIVERY']);
            $this->recordEvent($shipment, 'OUT_FOR_DELIVERY', $actor, 'Delivery rider is out for delivery.', $assignment);
        } else {
            $this->recordEvent($shipment, $shipment->current_status, $actor, 'Pickup rider started pickup.', $assignment);
        }
        $this->localTrips->startTripForAssignment($actor, $assignment);
    }

    private function completePickup(RiderAssignment $assignment, Shipment $shipment, User $actor, array $payload): void
    {
        if ($assignment->assignment_type !== 'PICKUP') {
            throw new RuntimeException('This assignment is not a pickup assignment.');
        }
        $this->requireAssignmentStatus($assignment, ['IN_PROGRESS'], 'complete pickup');
        $this->requireShipmentStatus($shipment, ['READY_FOR_PICKUP'], 'complete pickup');

        $scannedCode = trim((string) ($payload['scanned_code'] ?? ''));
        if ($scannedCode === '' || ! hash_equals($shipment->tracking_number, $scannedCode)) {
            throw ValidationException::withMessages(['scanned_code' => 'The scanned parcel code does not match the shipment tracking number.']);
        }

        $this->recordScan($shipment, $actor, 'PICKUP_COLLECTED', strtoupper((string) ($payload['scan_method'] ?? 'MANUAL')), $scannedCode, 'SUCCESS', null, $assignment);

        $shipment->update(['current_status' => 'PICKED_UP']);
        $assignment->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
        ]);
        $shipment->pickupRequests()->where('status', 'APPROVED')->update(['status' => 'FULFILLED']);
        $sellerOrder = $shipment->sellerOrder;
        if ($sellerOrder) {
            $sellerOrder->loadMissing('shipments');
            $pickedUpStates = ['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED', 'DELIVERY_FAILED'];
            if ($sellerOrder->shipments->isNotEmpty()
                && $sellerOrder->shipments->every(fn (Shipment $parcel): bool => in_array($parcel->current_status, $pickedUpStates, true))) {
                $sellerOrder->update(['status' => 'PICKED_UP']);
            }
        }
        $this->recordEvent($shipment, 'PICKED_UP', $actor, 'Pickup rider collected the parcel from seller.', $assignment);
    }

    private function markPickupNotCollected(RiderAssignment $assignment, Shipment $shipment, User $actor, array $payload): void
    {
        if ($assignment->assignment_type !== RiderAssignment::TYPE_PICKUP) {
            throw new RuntimeException('This assignment is not a pickup assignment.');
        }
        $this->requireAssignmentStatus($assignment, ['IN_PROGRESS'], 'mark a pickup parcel as not collected');
        $this->requireShipmentStatus($shipment, ['READY_FOR_PICKUP'], 'mark a pickup parcel as not collected');

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('A reason is required when a seller parcel is not collected.');
        }

        $assignment->update([
            'status' => 'REJECTED',
            'completed_at' => now(),
            'rejection_reason' => $reason,
        ]);
        $this->recordEvent($shipment, $shipment->current_status, $actor, 'Pickup parcel not collected: '.$reason, $assignment);
    }

    private function completeDelivery(RiderAssignment $assignment, Shipment $shipment, User $actor, array $payload): void
    {
        if ($assignment->assignment_type !== 'DELIVERY') {
            throw new RuntimeException('This assignment is not a delivery assignment.');
        }
        $this->requireAssignmentStatus($assignment, ['IN_PROGRESS'], 'complete delivery');
        $this->requireShipmentStatus($shipment, ['OUT_FOR_DELIVERY'], 'complete delivery');

        $scannedCode = trim((string) ($payload['scanned_code'] ?? ''));
        if ($scannedCode === '' || ! hash_equals($shipment->tracking_number, $scannedCode)) {
            throw ValidationException::withMessages(['scanned_code' => 'Tracking number does not match this job. Scan the assigned waybill before completing delivery.']);
        }

        $proofPath = trim((string) ($payload['proof_path'] ?? ''));
        if ($proofPath === '') {
            throw ValidationException::withMessages(['proof_file' => 'A delivery proof photo is required before marking the parcel delivered.']);
        }

        app(CodCollectionService::class)->recordSuccessfulCollection($shipment, $assignment, $actor, $payload);
        $this->recordScan(
            $shipment,
            $actor,
            'DELIVERY_VERIFIED',
            strtoupper((string) ($payload['scan_method'] ?? 'MANUAL')),
            $scannedCode,
            'SUCCESS',
            null,
            $assignment,
        );

        $attemptNumber = (int) $shipment->deliveryAttempts()->count() + 1;

        DeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'rider_assignment_id' => $assignment->id,
            'attempt_number' => $attemptNumber,
            'status' => 'DELIVERED',
            'proof_path' => $proofPath,
            'attempted_at' => now(),
        ]);

        $assignment->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
        ]);

        $shipment->update(['current_status' => 'DELIVERED']);
        // Step 7 allocations are created only after a valid delivered status
        // and successful COD collection. The allocation service remains
        // idempotent, so retries cannot duplicate financial records.
        app(FinancialAllocationService::class)->initializeForShipment($shipment->fresh());
        $this->syncParentOrderProgress($shipment->sellerOrder?->order);
        $this->recordEvent($shipment, 'DELIVERED', $actor, 'Delivery rider delivered the parcel.', $assignment);
    }

    private function failDelivery(RiderAssignment $assignment, Shipment $shipment, User $actor, array $payload): void
    {
        if ($assignment->assignment_type !== 'DELIVERY') {
            throw new RuntimeException('This assignment is not a delivery assignment.');
        }
        $this->requireAssignmentStatus($assignment, ['IN_PROGRESS'], 'record a failed delivery');
        $this->requireShipmentStatus($shipment, ['OUT_FOR_DELIVERY'], 'record a failed delivery');

        $reason = trim((string) ($payload['failure_reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('A delivery failure reason is required.');
        }
        $attemptNumber = (int) $shipment->deliveryAttempts()->count() + 1;
        $status = strtoupper((string) ($payload['attempt_status'] ?? 'FAILED'));

        if (! in_array($status, ['FAILED', 'RESCHEDULED'], true)) {
            $status = 'FAILED';
        }

        $buyerRefused = preg_match('/(?:buyer|recipient|customer)\\s+(?:refused|rejected|declined)(?:\\s+the)?\\s+(?:parcel|delivery)/i', $reason) === 1;
        $returnToSellerInitiated = $buyerRefused || $attemptNumber >= 3;
        if ($returnToSellerInitiated) {
            // RETURNED is reserved for a completed, scan-verified return handoff.
            // Keep the parcel in the failed-delivery state while reverse custody is pending.
            $status = 'FAILED';
        }

        DeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'rider_assignment_id' => $assignment->id,
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'failure_reason' => $reason,
            'proof_path' => $payload['proof_path'] ?? null,
            'attempted_at' => now(),
            'next_attempt_at' => $status === 'RESCHEDULED' ? ($payload['next_attempt_at'] ?? null) : null,
        ]);

        $shipmentStatus = 'DELIVERY_FAILED';

        $shipment->update(['current_status' => $shipmentStatus]);
        $assignment->update([
            'status' => $status === 'RESCHEDULED' && ! $returnToSellerInitiated ? 'ACCEPTED' : 'COMPLETED',
            'completed_at' => $status === 'RESCHEDULED' && ! $returnToSellerInitiated ? null : now(),
        ]);

        $eventNote = $returnToSellerInitiated
            ? 'RETURN_TO_SELLER_INITIATED: '.$reason.' Reverse custody handover is still required before the parcel can be marked RETURNED.'
            : $reason;
        $this->recordEvent($shipment, $shipmentStatus, $actor, $eventNote, $assignment);
    }

    private function rejectAssignment(RiderAssignment $assignment, Shipment $shipment, User $actor, string $reason): void
    {
        $this->requireAssignmentStatus($assignment, ['ASSIGNED', 'ACCEPTED'], 'reject this assignment');
        if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY) {
            $this->requireShipmentStatus($shipment, ['ASSIGNED_TO_RIDER'], 'decline this delivery assignment');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A reason is required when declining a delivery assignment.');
        }

        $assignment->update([
            'status' => 'REJECTED',
            'cancelled_at' => now(),
            'rejection_reason' => $reason,
        ]);

        if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY) {
            $shipment->update(['current_status' => 'SORTED']);
            $declinesRequired = max(1, (int) config('rider.distinct_declines_before_unassigned', 3));
            $distinctDeclines = RiderAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
                ->where('status', 'REJECTED')
                ->distinct()
                ->count('rider_profile_id');
            $declineProgress = min($distinctDeclines, $declinesRequired).'/'.$declinesRequired.' distinct rider declines';
            $eventNote = $distinctDeclines >= $declinesRequired
                ? 'Delivery offer declined by rider. Three distinct riders declined; parcel is unassigned in the sorted dispatch queue. Reason: '.$reason
                : 'Delivery offer declined by rider. Parcel returned to the sorted dispatch queue ('.$declineProgress.'). Reason: '.$reason;

            $this->recordEvent($shipment, 'SORTED', $actor, $eventNote, $assignment);

            return;
        }

        $this->recordEvent($shipment, $shipment->current_status, $actor, $assignment->assignment_type.' assignment rejected: '.$reason, $assignment);
    }

    private function assertRiderActor(RiderAssignment $assignment, User $actor): void
    {
        if ($actor->account_type !== User::TYPE_RIDER || $actor->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('Only an active rider may operate a rider assignment.');
        }

        $actor->loadMissing('riderProfile');
        if (! $actor->riderProfile
            || $actor->riderProfile->status !== 'ACTIVE'
            || (int) $actor->riderProfile->id !== (int) $assignment->rider_profile_id) {
            throw new RuntimeException('This rider assignment is not owned by the authenticated rider.');
        }
    }

    private function syncParentOrder(?Order $order): void
    {
        if (! $order) {
            return;
        }

        $order->load('sellerOrders');

        if ($order->sellerOrders->every(fn (SellerOrder $sellerOrder): bool => $sellerOrder->status === 'COMPLETED')) {
            $order->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);
        }
    }

    public function syncParentOrderProgress(?Order $order): void
    {
        if (! $order || in_array($order->status, ['COMPLETED', 'CANCELLED', 'PARTIALLY_CANCELLED', 'RETURNED'], true)) {
            return;
        }

        $order->loadMissing('sellerOrders.shipments');
        $hasStarted = $order->sellerOrders->contains(fn (SellerOrder $sellerOrder): bool =>
            $sellerOrder->status !== 'PLACED'
            || $sellerOrder->shipments->contains(fn (Shipment $shipment): bool => $shipment->current_status !== 'PLACED')
        );

        if ($hasStarted && $order->status !== 'PROCESSING') {
            $order->update(['status' => 'PROCESSING']);
        }
    }

    private function newTrackingNumber(): string
    {
        do {
            $value = 'TRK-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Shipment::where('tracking_number', $value)->exists());

        return $value;
    }

    private function requireSellerAndShipmentStatus(SellerOrder $sellerOrder, Shipment $shipment, string $status, string $action): void
    {
        if ($sellerOrder->status !== $status || $shipment->current_status !== $status) {
            throw new RuntimeException("The order must be {$status} to {$action}.");
        }
    }

    private function requireSellerOrderParcelsStatus(SellerOrder $sellerOrder, Collection $shipments, string $status, string $action): void
    {
        if ($sellerOrder->status !== $status || $shipments->isEmpty()
            || $shipments->contains(fn (Shipment $shipment): bool => $shipment->current_status !== $status)) {
            throw new RuntimeException("The seller order and every parcel must be {$status} to {$action}.");
        }
    }

    private function requireShipmentStatus(Shipment $shipment, array $statuses, string $action): void
    {
        if (! in_array($shipment->current_status, $statuses, true)) {
            throw new RuntimeException('The shipment must be '.implode(' or ', $statuses).' to '.$action.'.');
        }
    }

    private function requireAssignmentStatus(RiderAssignment $assignment, array $statuses, string $action): void
    {
        if (! in_array($assignment->status, $statuses, true)) {
            throw new RuntimeException('The assignment must be '.implode(' or ', $statuses).' to '.$action.'.');
        }
    }
}
