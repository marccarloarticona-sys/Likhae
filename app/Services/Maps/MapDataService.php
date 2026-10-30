<?php

namespace App\Services\Maps;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Rider\RiderAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class MapDataService
{
    private const LOCATION_FRESHNESS_MINUTES = 2;

    /**
     * Return a deliberately small, role-safe marker payload for the browser.
     * Navigation details are scoped to the authenticated viewer's authorized
     * shipment destination. No viewer receives a different user's shipment
     * coordinates through this service.
     */
    public function forShipment(?Shipment $shipment, bool $includeDestination = true, bool $includeSeller = false, ?User $viewer = null): array
    {
        if (! $shipment) {
            return [];
        }

        $viewer ??= auth()->user();
        $relations = [
            'sellerOrder.order.address',
            'sellerOrder.sellerProfile.businessAddress',
            'riderAssignments.riderProfile',
            'riderAssignments.liveLocation',
            'logisticsCenter.address',
            'serviceArea.logisticsCenter.address',
        ];
        if (Schema::hasTable('shipment_transfer_legs')) {
            $relations[] = 'transferLegs.originLogisticsCenter.address';
            $relations[] = 'transferLegs.destinationLogisticsCenter.address';
        } elseif (! $shipment->relationLoaded('transferLegs')) {
            $shipment->setRelation('transferLegs', collect());
        }
        $shipment->loadMissing($relations);
        $tracking = $this->trackingState($shipment, $viewer);
        $markers = [];

        if ($includeDestination) {
            $address = $shipment->sellerOrder?->order?->address;
            $marker = $this->addressMarker($address, 'destination-'.$shipment->id, 'Buyer delivery address');
            if ($marker) {
                $marker['target_kind'] = 'buyer';
                $marker['shipment_id'] = $shipment->id;
                $markers[] = $marker;
            }
        }

        if ($includeSeller) {
            $marker = $this->addressMarker($shipment->sellerOrder?->sellerProfile?->businessAddress, 'seller-'.$shipment->id, 'Seller pickup address');
            if ($marker) {
                $marker['kind'] = 'seller';
                $marker['target_kind'] = 'seller';
                $marker['shipment_id'] = $shipment->id;
                $markers[] = $marker;
            }
        }

        foreach ($shipment->riderAssignments ?? [] as $assignment) {
            $canSeeLive = $this->canSeeLive($shipment, $assignment, $viewer);
            $canPublishLocation = $this->canPublishLocation($shipment, $assignment, $viewer);
            $canSeePendingDeliveryLocation = $this->canSeePendingDeliveryLocation($shipment, $assignment, $tracking, $viewer);
            if (! $canSeeLive && ! $canPublishLocation && ! $canSeePendingDeliveryLocation) {
                continue;
            }

            $marker = $this->assignmentMarker($assignment);
            if (! $marker && ($canPublishLocation || $canSeePendingDeliveryLocation)) {
                $marker = $this->pendingAssignmentMarker($assignment);
            }
            if ($marker) {
                $marker['shipment_id'] = $shipment->id;
                $marker['active_rider'] = (int) $assignment->id === (int) ($tracking['active_assignment_id'] ?? 0);
                if ($canPublishLocation) {
                    $marker['location_update_endpoint'] = route('rider.assignments.location', ['assignment' => $assignment->id]);
                }
                $markers[] = $marker;
            }
        }

        $transferLegs = ($shipment->transferLegs ?? collect())
            ->filter(fn (ShipmentTransferLeg $leg): bool => in_array($leg->status, [
                ShipmentTransferLeg::RELEASED,
                ShipmentTransferLeg::IN_TRANSIT,
                ShipmentTransferLeg::RECEIVED,
            ], true));
        $transferCenterMarkerIndexes = [];
        foreach ($transferLegs as $transferLeg) {
            foreach ([
                ['center' => $transferLeg->originLogisticsCenter, 'role' => 'origin'],
                ['center' => $transferLeg->destinationLogisticsCenter, 'role' => 'destination'],
            ] as $transferStop) {
                $centerId = (int) ($transferStop['center']?->id ?? 0);
                $marker = $this->transferCenterMarker(
                    $transferStop['center'],
                    $transferStop['role'],
                    (int) $shipment->id,
                    (int) $transferLeg->id,
                    $transferLeg->status,
                    (int) $transferLeg->sequence_no,
                );
                if (! $marker) {
                    continue;
                }

                // A hub can be the destination of one leg and the origin of
                // the next. Keep one visible stop, but retain its latest leg
                // role/status so route progress points at the active transfer.
                if ($centerId > 0 && isset($transferCenterMarkerIndexes[$centerId])) {
                    $index = $transferCenterMarkerIndexes[$centerId];
                    if ((int) ($markers[$index]['sequence_no'] ?? -1) <= (int) $transferLeg->sequence_no) {
                        $markers[$index] = $marker;
                    }
                    continue;
                }

                if ($centerId > 0) {
                    $transferCenterMarkerIndexes[$centerId] = count($markers);
                }
                $markers[] = $marker;
            }
        }

        $center = $shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter;
        $centerId = (int) ($center?->id ?? 0);
        if ($centerId > 0 && isset($transferCenterMarkerIndexes[$centerId])) {
            $markers[$transferCenterMarkerIndexes[$centerId]]['target_kind'] = 'logistics';
        }
        if ($center && ($centerId === 0 || ! isset($transferCenterMarkerIndexes[$centerId]))) {
            $marker = $this->centerMarker($center);
            if ($marker) {
                $marker['id'] = 'logistics-center-'.$centerId.'-shipment-'.$shipment->id;
                $marker['center_id'] = $center->id;
                $marker['shipment_id'] = $shipment->id;
                $marker['target_kind'] = 'logistics';
                if ($transferLegs->isNotEmpty() && $transferCenterMarkerIndexes !== []) {
                    $marker['sequence_no'] = (int) $transferLegs->max('sequence_no') + 1;
                    $marker['center_role'] = 'current';
                    $marker['label'] = 'Current logistics center';
                    $marker['title'] = 'Current logistics center';
                }
                $markers[] = $marker;
            }
        }

        $markers = $this->addNavigationDetails($markers, $shipment, $tracking, $viewer);

        return collect($markers)
            ->map(fn (array $marker): array => array_merge($marker, [
                'tracking_endpoint' => $tracking['endpoint'],
                'tracking_channel' => $tracking['channel'],
                'tracking_status' => $tracking['status'],
                'tracking_location_state' => $tracking['location_state'],
                'active_destination_kind' => $tracking['active_destination_kind'],
            ]))
            ->values()
            ->all();
    }

    private function addNavigationDetails(array $markers, Shipment $shipment, array $tracking, ?User $viewer): array
    {
        if (! $viewer || ! $tracking['active_assignment_id']) {
            return $markers;
        }

        $assignment = collect($shipment->riderAssignments ?? [])
            ->first(fn (RiderAssignment $candidate): bool => (int) $candidate->id === (int) $tracking['active_assignment_id']);
        if (! $assignment || ! $this->canViewNavigationDestination($shipment, $assignment, $viewer)) {
            return $markers;
        }

        $destinationKind = $tracking['active_destination_kind'] ?? null;
        $destination = match ($destinationKind) {
            'seller' => $shipment->sellerOrder?->sellerProfile?->businessAddress,
            'logistics' => ($shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter)?->address,
            'buyer' => $shipment->sellerOrder?->order?->address,
            default => null,
        };
        if (! $destination) {
            return $markers;
        }

        $markerIndex = collect($markers)->search(fn (array $marker): bool => ($marker['target_kind'] ?? null) === $destinationKind);
        if ($markerIndex === false) {
            return $markers;
        }

        $markers[$markerIndex]['navigation_destination'] = [
            'name' => match ($destinationKind) {
                'seller' => $shipment->sellerOrder?->sellerProfile?->business_name ?: 'Seller pickup address',
                'logistics' => ($shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter)?->business_name ?: 'Logistics sorting center',
                'buyer' => $destination->recipient_name ?: 'Buyer delivery address',
                default => 'Active destination',
            },
            'address' => method_exists($destination, 'formatted') ? $destination->formatted() : ($markers[$markerIndex]['popup'] ?? 'Saved destination'),
        ];

        return $markers;
    }

    private function canViewNavigationDestination(Shipment $shipment, RiderAssignment $assignment, User $viewer): bool
    {
        if ($viewer->isAccountType(User::TYPE_ADMIN)) {
            return true;
        }

        if ($viewer->isAccountType(User::TYPE_RIDER)) {
            return (int) $assignment->riderProfile?->user_id === (int) $viewer->id;
        }

        if ($viewer->isAccountType(User::TYPE_BUYER)) {
            return $shipment->current_status === 'OUT_FOR_DELIVERY'
                && (int) $shipment->sellerOrder?->order?->buyer_user_id === (int) $viewer->id;
        }

        if ($viewer->isAccountType(User::TYPE_SELLER)) {
            return in_array($shipment->current_status, ['READY_FOR_PICKUP', 'PICKUP_ASSIGNED'], true)
                && (int) $shipment->sellerOrder?->sellerProfile?->user_id === (int) $viewer->id;
        }

        if ($viewer->isAccountType(User::TYPE_LOGISTICS)) {
            $center = $shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter;

            return (int) $center?->owner_user_id === (int) $viewer->id
                && (int) $assignment->riderProfile?->logistics_center_id === (int) $center?->id;
        }

        return false;
    }

    public function trackingPayload(?Shipment $shipment, ?User $viewer = null): array
    {
        if (! $shipment) {
            return ['markers' => [], 'tracking' => []];
        }

        $viewer ??= auth()->user();

        return [
            'markers' => $this->forShipment($shipment, true, true, $viewer),
            'tracking' => $this->trackingState($shipment, $viewer),
        ];
    }

    public function forShipments(iterable $shipments, bool $includeSeller = false, ?User $viewer = null): array
    {
        return collect($shipments)
            ->flatMap(fn ($shipment) => $this->forShipment($shipment, true, $includeSeller, $viewer))
            ->unique('id')
            ->values()
            ->all();
    }

    public function forAssignments(iterable $assignments, bool $includeSeller = false, ?User $viewer = null): array
    {
        return collect($assignments)
            ->flatMap(function (RiderAssignment $assignment) use ($includeSeller, $viewer): array {
                return $this->forShipment($assignment->shipment, true, $includeSeller, $viewer);
            })
            ->unique('id')
            ->values()
            ->all();
    }

    public function addressMarker(?object $address, string $id, string $label): ?array
    {
        $coordinates = $this->coordinatePair($address);
        if (! $coordinates) {
            return null;
        }

        return [
            'id' => $id,
            'kind' => 'destination',
            'label' => $label,
            'title' => $label,
            'latitude' => round($coordinates['latitude'], 7),
            'longitude' => round($coordinates['longitude'], 7),
            'popup' => collect([
                $address->barangay_name,
                $address->municipality_name,
                $address->province_name,
            ])->filter()->implode(', '),
        ];
    }

    public function centerMarker(?LogisticsCenter $center): ?array
    {
        $marker = $this->addressMarker($center?->address, 'logistics-center-'.($center?->id ?? 'unknown'), 'Logistics center');
        if ($marker) {
            $marker['kind'] = 'center';
            $marker['center_id'] = $center?->id;
        }
        return $marker;
    }

    private function transferCenterMarker(?LogisticsCenter $center, string $role, int $shipmentId, int $legId, string $status, int $sequenceNo): ?array
    {
        $marker = $this->centerMarker($center);
        if (! $marker) {
            return null;
        }

        $label = $role === 'origin' ? 'Origin sorting center' : 'Destination sorting center';
        $marker['id'] = 'logistics-center-'.$center->id.'-shipment-'.$shipmentId.'-transfer-'.$legId.'-'.$role;
        $marker['shipment_id'] = $shipmentId;
        $marker['center_role'] = $role;
        $marker['transfer_leg_id'] = $legId;
        $marker['transfer_status'] = $status;
        $marker['sequence_no'] = $sequenceNo;
        $marker['label'] = $label;
        $marker['title'] = $label;

        return $marker;
    }

    public function assignmentMarker(?RiderAssignment $assignment): ?array
    {
        $location = $assignment?->liveLocation;
        $coordinates = $this->coordinatePair($location);
        if (! $assignment || ! $coordinates) {
            return null;
        }

        $fresh = $location->recorded_at?->greaterThanOrEqualTo(now()->subMinutes(self::LOCATION_FRESHNESS_MINUTES)) ?? false;

        return [
            'id' => 'rider-assignment-'.$assignment->id,
            'kind' => 'rider',
            'assignment_id' => $assignment->id,
            'shipment_id' => $assignment->shipment_id,
            'assignment_type' => $assignment->assignment_type,
            'label' => $assignment->assignment_type === RiderAssignment::TYPE_PICKUP ? 'Pickup rider' : 'Delivery rider',
            'title' => $fresh ? 'Current rider location' : 'Last known rider location',
            'latitude' => round($coordinates['latitude'], 7),
            'longitude' => round($coordinates['longitude'], 7),
            'location_fresh' => $fresh,
            'location_state' => $fresh ? 'live' : 'stale',
            'location_recorded_at' => $location->recorded_at?->toIso8601String(),
            'popup' => $location->recorded_at?->diffForHumans()
                ? ($fresh ? 'Updated '.$location->recorded_at->diffForHumans() : 'Last updated '.$location->recorded_at->diffForHumans())
                : 'Assignment location unavailable',
        ];
    }

    private function pendingAssignmentMarker(RiderAssignment $assignment): array
    {
        return [
            'id' => 'rider-assignment-'.$assignment->id,
            'kind' => 'rider',
            'assignment_id' => $assignment->id,
            'shipment_id' => $assignment->shipment_id,
            'assignment_type' => $assignment->assignment_type,
            'label' => $assignment->assignment_type === RiderAssignment::TYPE_PICKUP ? 'Pickup rider' : 'Delivery rider',
            'title' => 'Waiting for current rider location',
            'popup' => 'Allow device location access to begin live tracking.',
            'location_pending' => true,
        ];
    }

    public function trackingState(Shipment $shipment, ?User $viewer = null): array
    {
        $viewer ??= auth()->user();
        $shipment->loadMissing([
            'sellerOrder.order.address',
            'sellerOrder.sellerProfile.businessAddress',
            'riderAssignments.riderProfile',
            'riderAssignments.liveLocation',
            'logisticsCenter.address',
            'serviceArea.logisticsCenter.address',
        ]);

        $assignment = $this->activeAssignment($shipment)
            ?: $this->acceptedDeliveryAssignment($shipment);
        $destinationKind = $this->destinationKind($shipment, $assignment);
        $destination = match ($destinationKind) {
            'seller' => $shipment->sellerOrder?->sellerProfile?->businessAddress,
            'logistics' => ($shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter)?->address,
            'buyer' => $shipment->sellerOrder?->order?->address,
            default => null,
        };
        $visible = $assignment && $this->canSeeLive($shipment, $assignment, $viewer);
        $location = $visible ? $this->assignmentMarker($assignment) : null;
        $locationState = $location['location_state'] ?? 'unavailable';
        $destinationCoordinates = $this->coordinatePair($destination);

        return [
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
            'status' => $shipment->current_status,
            'active_destination_kind' => $destinationKind,
            'active_assignment_id' => $assignment?->id,
            'active_assignment_type' => $assignment?->assignment_type,
            'live' => $locationState === 'live',
            'location_state' => $locationState,
            'last_location' => $location ? [
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
                'recorded_at' => $assignment->liveLocation?->recorded_at?->toIso8601String(),
            ] : null,
            'destination' => $destinationCoordinates ? [
                'kind' => $destinationKind,
                'latitude' => $destinationCoordinates['latitude'],
                'longitude' => $destinationCoordinates['longitude'],
            ] : null,
            'endpoint' => filled($shipment->tracking_number)
                ? route('tracking.live', ['trackingCode' => $shipment->tracking_number])
                : null,
            'channel' => 'shipments.'.$shipment->id,
        ];
    }

    private function coordinatePair(?object $location): ?array
    {
        if (! $location || ! is_numeric($location->latitude) || ! is_numeric($location->longitude)) {
            return null;
        }

        $latitude = (float) $location->latitude;
        $longitude = (float) $location->longitude;

        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    private function destinationKind(Shipment $shipment, ?RiderAssignment $assignment): ?string
    {
        if ($assignment) {
            return match ($assignment->assignment_type) {
                'PICKUP' => $assignment->status === 'COMPLETED' || $shipment->current_status === 'PICKED_UP'
                    ? 'logistics'
                    : 'seller',
                'DELIVERY' => 'buyer',
                default => null,
            };
        }

        return match ($shipment->current_status) {
            'READY_FOR_PICKUP', 'PICKUP_ASSIGNED' => 'seller',
            'PICKED_UP' => 'logistics',
            'OUT_FOR_DELIVERY' => 'buyer',
            default => null,
        };
    }

    private function acceptedDeliveryAssignment(Shipment $shipment): ?RiderAssignment
    {
        if ($shipment->current_status !== 'ASSIGNED_TO_RIDER') {
            return null;
        }

        return $shipment->riderAssignments
            ->where('assignment_type', 'DELIVERY')
            ->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])
            ->sortByDesc('id')
            ->first();
    }

    private function activeAssignment(Shipment $shipment): ?RiderAssignment
    {
        return match ($shipment->current_status) {
            'PICKUP_ASSIGNED' => $shipment->riderAssignments
                ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                ->sortByDesc('id')
                ->first(),
            'READY_FOR_PICKUP' => $shipment->riderAssignments
                ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                ->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])
                ->sortByDesc('id')
                ->first(),
            'PICKED_UP' => $shipment->riderAssignments
                ->where('assignment_type', RiderAssignment::TYPE_PICKUP)
                ->where('status', 'COMPLETED')
                ->sortByDesc('id')
                ->first(),
            'OUT_FOR_DELIVERY' => $shipment->riderAssignments
                ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
                ->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])
                ->sortByDesc('id')
                ->first(),
            default => null,
        };
    }

    private function canSeeLive(Shipment $shipment, RiderAssignment $assignment, ?User $viewer): bool
    {
        if (! $viewer || ! $assignment->liveLocation) {
            return false;
        }

        if ($viewer->isAccountType('ADMIN')) {
            return true;
        }

        $phaseVisible = match ($shipment->current_status) {
            'READY_FOR_PICKUP', 'PICKUP_ASSIGNED', 'PICKED_UP' => $assignment->assignment_type === RiderAssignment::TYPE_PICKUP,
            'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY' => $assignment->assignment_type === RiderAssignment::TYPE_DELIVERY,
            default => false,
        };

        if (! $phaseVisible) {
            return false;
        }

        if ($viewer->isAccountType('RIDER')) {
            return (int) $assignment->riderProfile?->user_id === (int) $viewer->id;
        }

        if ($viewer->isAccountType('BUYER')) {
            return $shipment->current_status === 'OUT_FOR_DELIVERY'
                && (int) $shipment->sellerOrder?->order?->buyer_user_id === (int) $viewer->id;
        }

        if ($viewer->isAccountType('SELLER')) {
            return in_array($shipment->current_status, ['READY_FOR_PICKUP', 'PICKUP_ASSIGNED'], true)
                && (int) $shipment->sellerOrder?->sellerProfile?->user_id === (int) $viewer->id;
        }

        if ($viewer->isAccountType('LOGISTICS')) {
            $center = $shipment->logisticsCenter ?: $shipment->serviceArea?->logisticsCenter;
            return (int) $center?->owner_user_id === (int) $viewer->id
                && (int) $assignment->riderProfile?->logistics_center_id === (int) $center?->id;
        }

        return false;
    }

    private function canPublishLocation(Shipment $shipment, RiderAssignment $assignment, ?User $viewer): bool
    {
        if (! $viewer?->isAccountType('RIDER')
            || (int) $assignment->riderProfile?->user_id !== (int) $viewer->id) {
            return false;
        }

        if ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP
            && in_array($shipment->current_status, ['READY_FOR_PICKUP', 'PICKUP_ASSIGNED'], true)
            && in_array($assignment->status, ['ACCEPTED', 'IN_PROGRESS'], true)) {
            return true;
        }

        if ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY
            && in_array($shipment->current_status, ['ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY'], true)
            && in_array($assignment->status, ['ACCEPTED', 'IN_PROGRESS'], true)) {
            return true;
        }

        return $assignment->assignment_type === RiderAssignment::TYPE_PICKUP
            && $assignment->status === 'COMPLETED'
            && $shipment->current_status === 'PICKED_UP';
    }

    private function canSeePendingDeliveryLocation(Shipment $shipment, RiderAssignment $assignment, array $tracking, ?User $viewer): bool
    {
        return $viewer?->isAccountType(User::TYPE_BUYER) === true
            && $shipment->current_status === 'OUT_FOR_DELIVERY'
            && $assignment->assignment_type === RiderAssignment::TYPE_DELIVERY
            && (int) ($tracking['active_assignment_id'] ?? 0) === (int) $assignment->id
            && (int) $shipment->sellerOrder?->order?->buyer_user_id === (int) $viewer->id;
    }
}
