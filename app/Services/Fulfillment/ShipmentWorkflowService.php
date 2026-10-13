<?php

namespace App\Services\Fulfillment;

use App\Events\ShipmentTrackingUpdated;
use App\Models\Buyer\Order;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\Waybill;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerOrder;
use App\Services\Finance\CodCollectionService;
use App\Services\Finance\FinancialAllocationService;
use App\Models\User;
use App\Support\RiderVehicleType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ShipmentWorkflowService
{
    public function __construct(
        private readonly LocalRiderEligibilityService $localRiderEligibility,
        private readonly LogisticsSchemaReadiness $schema,
        private readonly ShipmentTransferService $transfers,
        private readonly LocalRiderTripService $localTrips,
    ) {}

    public function sellerTransition(SellerOrder $sellerOrder, string $action, User $actor): SellerOrder
    {
        return DB::transaction(function () use ($sellerOrder, $action, $actor): SellerOrder {
            $sellerOrder = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->with(['shipment', 'order.address'])
                ->lockForUpdate()
                ->firstOrFail();
            $shipment = $this->ensureShipment($sellerOrder);

            match ($action) {
                'confirm' => $this->confirmSellerOrder($sellerOrder, $shipment, $actor),
                'prepare' => $this->prepareSellerOrder($sellerOrder, $shipment, $actor),
                'ready' => $this->readyForPickup($sellerOrder, $shipment, $actor),
                'handover_confirm' => $this->confirmSellerHandover($sellerOrder, $shipment, $actor),
                'cancel' => $this->cancelSellerOrder($sellerOrder, $shipment, $actor),
                default => throw new RuntimeException('Unsupported seller order action.'),
            };

            return $sellerOrder->refresh();
        });
    }

    public function requestSellerHandover(SellerOrder $sellerOrder, User $actor, bool $sellerDropoff, ?string $note = null): SellerOrder
    {
        return DB::transaction(function () use ($sellerOrder, $actor, $sellerDropoff, $note): SellerOrder {
            $sellerOrder = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->with(['shipment', 'order.address'])
                ->lockForUpdate()
                ->firstOrFail();
            $shipment = $this->ensureShipment($sellerOrder);
            $this->readyForPickup($sellerOrder, $shipment, $actor, $sellerDropoff, $note);

            return $sellerOrder->refresh();
        });
    }

    public function ensureShipment(SellerOrder $sellerOrder): Shipment
    {
        $sellerOrder->loadMissing('shipment', 'order.address');

        if ($sellerOrder->shipment) {
            return $sellerOrder->shipment;
        }

        $address = $sellerOrder->order?->address;

        if (! $address) {
            throw new RuntimeException('Order delivery address is missing.');
        }

        $serviceArea = $this->findServiceAreaForAddress(
            (string) $address->province_code,
            (string) $address->municipality_code,
            (string) $address->barangay_code,
        );

        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => $this->newTrackingNumber(),
            'logistics_center_id' => $serviceArea?->logistics_center_id,
            'service_area_id' => $serviceArea?->id,
            'destination_province_code' => $address->province_code,
            'destination_province_name' => $address->province_name,
            'destination_municipality_code' => $address->municipality_code,
            'destination_municipality_name' => $address->municipality_name,
            'destination_barangay_code' => $address->barangay_code,
            'destination_barangay_name' => $address->barangay_name,
            'current_status' => 'PLACED',
        ]);

        $this->recordEvent($shipment, 'PLACED', null, 'Shipment created from seller order.');

        return $shipment;
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

            if ($rider->status !== 'ACTIVE'
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
            $dropoffRequest = $shipment->pickupRequests()->whereIn('status', ['PENDING', 'APPROVED'])
                ->where('notes', 'like', 'SELLER_DROPOFF:%')->latest()->first();
            $isSellerDropoff = $shipment->current_status === 'READY_FOR_PICKUP' && $dropoffRequest;
            $this->requireShipmentStatus($shipment, $isSellerDropoff ? ['READY_FOR_PICKUP'] : ['PICKED_UP'], 'receive the parcel at the sorting center');

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
            $assignment = RiderAssignment::query()
                ->whereKey($assignment->id)
                ->with(['shipment', 'riderProfile'])
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRiderActor($assignment, $actor);
            $shipment = $assignment->shipment;

            match ($action) {
                'accept' => $this->acceptAssignment($assignment, $shipment, $actor),
                'start' => $this->startAssignment($assignment, $shipment, $actor),
                'pickup_complete' => $this->completePickup($assignment, $shipment, $actor, $payload),
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

    private function confirmSellerOrder(SellerOrder $sellerOrder, Shipment $shipment, User $actor): void
    {
        $this->requireSellerAndShipmentStatus($sellerOrder, $shipment, 'PLACED', 'confirm the order');
        $sellerOrder->update(['status' => 'CONFIRMED']);
        $shipment->update(['current_status' => 'CONFIRMED']);
        $this->syncParentOrderProgress($sellerOrder->order);
        $this->recordEvent($shipment, 'CONFIRMED', $actor, 'Seller confirmed the order.');
    }

    private function prepareSellerOrder(SellerOrder $sellerOrder, Shipment $shipment, User $actor): void
    {
        $this->requireSellerAndShipmentStatus($sellerOrder, $shipment, 'CONFIRMED', 'prepare the order');
        $sellerOrder->update(['status' => 'PREPARING']);
        $shipment->update(['current_status' => 'PREPARING']);
        $this->recordEvent($shipment, 'PREPARING', $actor, 'Seller is preparing the parcel.');
    }

    private function readyForPickup(SellerOrder $sellerOrder, Shipment $shipment, User $actor, bool $sellerDropoff = false, ?string $note = null): void
    {
        $this->requireSellerAndShipmentStatus($sellerOrder, $shipment, 'PREPARING', 'mark the order ready for pickup');
        $sellerOrder->update(['status' => 'READY_FOR_PICKUP']);
        $pickupArea = $this->pickupServiceArea($shipment);
        $shipment->update([
            'current_status' => 'READY_FOR_PICKUP',
            // Keep the provider selected at checkout. Only infer a center when
            // the shipment does not already have an explicit provider.
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
        $this->recordEvent($shipment, 'READY_FOR_PICKUP', $actor, $sellerDropoff ? 'Seller registered a drop-off at the assigned logistics center.' : 'Seller marked parcel ready for pickup.');
    }

    private function cancelSellerOrder(SellerOrder $sellerOrder, Shipment $shipment, User $actor): void
    {
        if (in_array($sellerOrder->status, ['PICKED_UP', 'COMPLETED', 'CANCELLED'], true)) {
            throw new RuntimeException('This order can no longer be cancelled by the seller.');
        }
        $sellerOrder->update(['status' => 'CANCELLED']);
        $shipment->update(['current_status' => 'RETURNED']);
        $this->recordEvent($shipment, 'RETURNED', $actor, 'Seller cancelled the seller order.');
    }

    private function confirmSellerHandover(SellerOrder $sellerOrder, Shipment $shipment, User $actor): void
    {
        $this->requireSellerAndShipmentStatus($sellerOrder, $shipment, 'READY_FOR_PICKUP', 'confirm parcel handover');

        $assignment = $shipment->riderAssignments()
            ->where('assignment_type', 'PICKUP')
            ->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])
            ->latest()
            ->first();

        if (! $assignment) {
            throw new RuntimeException('The pickup rider must accept the assignment before handover can be confirmed.');
        }

        $this->recordEvent($shipment, 'READY_FOR_PICKUP', $actor, 'Seller confirmed parcel handover to the pickup rider.', $assignment);
    }

    private function generateWaybill(Shipment $shipment, User $actor): Waybill
    {
        return Waybill::firstOrCreate(
            ['shipment_id' => $shipment->id, 'format_version' => 1],
            [
                'generated_by_user_id' => $actor->id,
                'label_path' => 'waybills/'.$shipment->tracking_number.'.pdf',
                'generated_at' => now(),
            ],
        );
    }

    private function acceptAssignment(RiderAssignment $assignment, Shipment $shipment, User $actor): void
    {
        $this->requireAssignmentStatus($assignment, ['ASSIGNED'], 'accept this assignment');
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
        $this->recordEvent($shipment, 'PICKED_UP', $actor, 'Pickup rider collected the parcel from seller.', $assignment);
    }

    private function completeDelivery(RiderAssignment $assignment, Shipment $shipment, User $actor, array $payload): void
    {
        if ($assignment->assignment_type !== 'DELIVERY') {
            throw new RuntimeException('This assignment is not a delivery assignment.');
        }
        $this->requireAssignmentStatus($assignment, ['IN_PROGRESS'], 'complete delivery');
        $this->requireShipmentStatus($shipment, ['OUT_FOR_DELIVERY'], 'complete delivery');

        $proofPath = trim((string) ($payload['proof_path'] ?? ''));
        if ($proofPath === '') {
            throw ValidationException::withMessages(['proof_file' => 'A delivery proof photo is required before marking the parcel delivered.']);
        }

        app(CodCollectionService::class)->recordSuccessfulCollection($shipment, $assignment, $actor, $payload);

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

        $reason = (string) ($payload['failure_reason'] ?? 'Delivery failed.');
        $attemptNumber = (int) $shipment->deliveryAttempts()->count() + 1;
        $status = strtoupper((string) ($payload['attempt_status'] ?? 'FAILED'));

        if (! in_array($status, ['FAILED', 'RESCHEDULED', 'RETURNED'], true)) {
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
            'next_attempt_at' => $payload['next_attempt_at'] ?? null,
        ]);

        $shipmentStatus = $status === 'RETURNED' ? 'RETURNED' : 'DELIVERY_FAILED';

        $shipment->update(['current_status' => $shipmentStatus]);
        $assignment->update([
            'status' => $status === 'RESCHEDULED' ? 'ACCEPTED' : 'COMPLETED',
            'completed_at' => $status === 'RESCHEDULED' ? null : now(),
        ]);

        $this->recordEvent($shipment, $shipmentStatus, $actor, $reason, $assignment);
    }

    private function rejectAssignment(RiderAssignment $assignment, Shipment $shipment, User $actor, string $reason): void
    {
        $this->requireAssignmentStatus($assignment, ['ASSIGNED', 'ACCEPTED'], 'reject this assignment');

        $assignment->update([
            'status' => 'REJECTED',
            'cancelled_at' => now(),
            'rejection_reason' => $reason,
        ]);

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

        $order->loadMissing('sellerOrders.shipment');
        $hasStarted = $order->sellerOrders->contains(fn (SellerOrder $sellerOrder): bool =>
            $sellerOrder->status !== 'PLACED'
            || ($sellerOrder->shipment && $sellerOrder->shipment->current_status !== 'PLACED')
        );

        if ($hasStarted && $order->status !== 'PROCESSING') {
            $order->update(['status' => 'PROCESSING']);
        }
    }

    private function newTrackingNumber(): string
    {
        do {
            $value = 'LKH-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Shipment::where('tracking_number', $value)->exists());

        return $value;
    }

    private function requireSellerAndShipmentStatus(SellerOrder $sellerOrder, Shipment $shipment, string $status, string $action): void
    {
        if ($sellerOrder->status !== $status || $shipment->current_status !== $status) {
            throw new RuntimeException("The order must be {$status} to {$action}.");
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
