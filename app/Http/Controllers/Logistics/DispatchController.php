<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Dispute;
use App\Models\Admin\DisputeEvidence;
use App\Models\Admin\Notification;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Seller\SellerPickupRequest;
use App\Models\User;
use App\Services\Fulfillment\SellerPickupRequestService;
use App\Services\Fulfillment\FinalMileDispatchService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\LogisticsShipmentQuery;
use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use App\Services\Fulfillment\SellerPickupBatchService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\TransferManifestService;
use App\Services\Maps\MapDataService;
use App\Services\RefundProofStorageService;
use App\Support\RiderVehicleType;
use App\Support\LocalTripCapacityRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DispatchController extends Controller
{
    public const MANUAL_OVERRIDE_STATUSES = [
        'READY_FOR_PICKUP', 'PICKED_UP', 'AT_SORTING_CENTER', 'SORTED',
        'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERY_FAILED',
    ];

    public function index(Request $request): View
    {
        return $this->assignments($request);
    }

    public function parcels(Request $request, LogisticsShipmentQuery $shipmentQuery): View
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $filters = $shipmentQuery->filters($request, true);
        $status = $filters['status'] ?? '';

        $query = $shipmentQuery->dated($shipmentQuery->operationalFor($center), $filters);
        if (isset(LogisticsShipmentQuery::CATEGORIES[$status])) {
            $shipmentQuery->category($query, $status);
        } elseif ($status !== '') {
            $query->where('current_status', $status);
        }

        $shipments = $query
            ->with(['sellerOrder.sellerProfile.user', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'serviceArea', 'riderAssignments.riderProfile.user', 'riderAssignments.liveLocation', 'logisticsCenter.address'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $mapMarkers = app(MapDataService::class)->forShipments($shipments->getCollection(), true);
        return view('logistics.parcels.index', compact('shipments', 'status', 'center', 'mapMarkers', 'filters'));
    }

    public function pickups(Request $request, LogisticsSchemaReadiness $schema, RiderAssignmentOfferExpiryService $offers): View
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $offers->expireDuePickupOffers(logisticsCenterId: (int) $center->id);
        $tracking = trim((string) $request->query('tracking', ''));

        $pickupQuery = PickupRequest::query()
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->where(fn ($query) => $query->whereNull('notes')->orWhere('notes', 'not like', 'SELLER_DROPOFF:%'))
            ->whereHas('shipment', function ($query) use ($center, $tracking): void {
                $query->where('current_status', 'READY_FOR_PICKUP')
                    ->where(fn ($centerQuery) => $centerQuery->where('logistics_center_id', $center->id)
                        ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                            ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id))));
                if ($tracking !== '') {
                    $query->where('tracking_number', $tracking);
                }
            })
            ->whereDoesntHave('shipment.riderAssignments', fn ($assignment) => $assignment
                ->where('assignment_type', 'PICKUP')->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']));
        if ($schema->sellerPickupRequestsReady()) {
            $pickupQuery->whereDoesntHave('shipment.sellerPickupMemberships');
        }
        $pickups = $pickupQuery
            ->with(['shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress', 'shipment.sellerOrder.order.address', 'shipment.serviceArea', 'shipment.riderAssignments.riderProfile.user', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address'])
            ->latest('id')
            ->get()
            ->unique('shipment_id')
            ->values();

        $riders = RiderProfile::query()
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->where('is_online', true)
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->where('logistics_center_id', $center->id)
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->orderBy('id')
            ->get();

        $workflow = app(ShipmentWorkflowService::class);
        $sellerPickupQueue = $pickups->groupBy(fn ($pickup): int => (int) $pickup->shipment?->sellerOrder?->seller_profile_id)
            ->map(function ($sellerRequests) use ($center, $riders, $workflow): array {
                $seller = $sellerRequests->first()?->shipment?->sellerOrder?->sellerProfile;
                $firstShipment = $sellerRequests->first()?->shipment;
                $area = $firstShipment ? $workflow->sellerPickupServiceArea($firstShipment, $center) : null;

                return [
                    'seller' => $seller,
                    'area' => $area,
                    'pickup_requests' => $sellerRequests->keyBy('shipment_id'),
                    'shipments' => $sellerRequests->map(fn ($pickup) => $pickup->shipment)->filter()->values(),
                    'eligible_riders' => $firstShipment
                        ? $riders->filter(fn (RiderProfile $rider) =>
                            LocalTripCapacityRules::remainingCapacity((int) $rider->approved_parcel_capacity, (int) $rider->active_assignments_count) > 0
                            && $workflow->riderCoversSellerPickupArea($firstShipment, $rider))->values()
                        : collect(),
                ];
            })
            ->filter(fn (array $group): bool => $group['seller'] !== null)
            ->values();

        $sellerPickupRequests = $schema->sellerPickupRequestsReady()
            ? SellerPickupRequest::query()
                ->whereHas('memberships.shipment', function ($query) use ($center): void {
                    $query->where('logistics_center_id', $center->id)
                        ->orWhereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id));
                })
                ->with([
                    'sellerProfile.user',
                    'pickupAddress',
                    'riderLocalTrip.rider.user',
                    'memberships.sellerOrder',
                    'memberships.shipment.serviceArea',
                    'memberships.shipment.orderItem',
                    'memberships.shipment.waybills',
                ])
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();
        $sellerPickupRequests->each(function (SellerPickupRequest $pickupRequest) use ($riders, $workflow): void {
            $shipments = $pickupRequest->memberships->map(fn ($membership) => $membership->shipment)->filter();
            $count = $shipments->count();
            $pickupRequest->setAttribute('eligible_riders', $riders->filter(fn (RiderProfile $rider): bool =>
                $count > 0
                && LocalTripCapacityRules::remainingCapacity((int) $rider->approved_parcel_capacity, (int) $rider->active_assignments_count) > 0
                && $shipments->every(fn (Shipment $shipment): bool => $workflow->riderCoversSellerPickupArea($shipment, $rider)))->values());
            $pickupRequest->setAttribute('progress_payload', app(SellerPickupRequestService::class)
                ->progress($pickupRequest, (int) $pickupRequest->seller_profile_id));
        });

        $activePickupBatches = $schema->sellerPickupBatchesReady()
            ? RiderLocalTrip::query()
                ->where('logistics_center_id', $center->id)
                ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                ->whereNotNull('seller_profile_id')
                ->whereIn('status', [RiderLocalTrip::LOADING, RiderLocalTrip::READY, RiderLocalTrip::IN_PROGRESS, RiderLocalTrip::EN_ROUTE_TO_HUB])
                ->with(['seller.businessAddress', 'rider.user', 'items.riderAssignment.shipment'])
                ->latest()
                ->get()
            : collect();
        $collectedAssignmentIdsByTrip = app(SellerPickupBatchService::class)->collectedAssignmentIdsByTrip($activePickupBatches);
        $activePickupBatches->each(function (RiderLocalTrip $batch) use ($collectedAssignmentIdsByTrip): void {
            $batch->setAttribute('collected_assignment_ids', $collectedAssignmentIdsByTrip->get((int) $batch->id, collect()));
        });
        $activePickupBatches->each(function (RiderLocalTrip $batch) use ($riders, $workflow): void {
            $items = $batch->items;
            $canReassign = in_array($batch->status, [RiderLocalTrip::LOADING, RiderLocalTrip::READY, RiderLocalTrip::IN_PROGRESS], true)
                && $items->isNotEmpty()
                && $items->every(fn ($item): bool => in_array($item->riderAssignment?->status, ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'], true)
                    && $item->riderAssignment?->shipment?->current_status === 'PICKUP_ASSIGNED');
            $eligible = $canReassign
                ? $riders->filter(fn (RiderProfile $candidate): bool =>
                    (int) $candidate->id !== (int) $batch->rider_profile_id
                    && LocalTripCapacityRules::remainingCapacity((int) $candidate->approved_parcel_capacity, (int) $candidate->active_assignments_count) >= $items->count()
                    && $items->every(fn ($item): bool => $workflow->riderCoversSellerPickupArea($item->riderAssignment->shipment, $candidate)))->values()
                : collect();
            $batch->setAttribute('reassignable_riders', $eligible);
        });

        return view('logistics.pickups.index', [
            'center' => $center,
            'tracking' => $tracking,
            'sellerPickupQueue' => $sellerPickupQueue,
            'sellerPickupRequests' => $sellerPickupRequests,
            'activePickupBatches' => $activePickupBatches,
            'riders' => $riders,
            'pickupSchemaReady' => $schema->sellerPickupBatchesReady(),
        ]);
    }

    public function receive(Request $request): View
    {
        $center = $this->center($request);
        $schema = app(LogisticsSchemaReadiness::class);
        $pickupBatchesReady = $schema->sellerPickupBatchesReady();
        $tracking = trim((string) $request->query('tracking', ''));

        $shipments = Shipment::query()
            ->where(function ($query): void {
                $query->where('current_status', 'PICKED_UP')
                    ->orWhere(fn ($dropoffs) => $dropoffs
                        ->where('current_status', 'READY_FOR_PICKUP')
                        ->whereHas('pickupRequests', fn ($pickup) => $pickup
                            ->whereIn('status', ['PENDING', 'APPROVED'])
                            ->where('notes', 'like', 'SELLER_DROPOFF:%')));
            })
            ->whereDoesntHave('riderAssignments.localTripItems.trip', fn ($trip) => $trip
                ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                ->when($pickupBatchesReady, fn ($query) => $query->whereNotNull('seller_profile_id'))
                ->where('status', RiderLocalTrip::EN_ROUTE_TO_HUB))
            ->when($tracking !== '', fn ($query) => $query->where('tracking_number', $tracking))
            ->when($center, fn ($query) => $query->where(function ($q) use ($center) {
                $q->where('logistics_center_id', $center->id)
                    ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                        ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id)));
            }))
            ->with(['sellerOrder.sellerProfile.user', 'sellerOrder.order.address'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $receivedBatches = RiderLocalTrip::query()
            ->where('logistics_center_id', $center?->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->when($pickupBatchesReady, fn ($query) => $query->whereNotNull('seller_profile_id'))
            ->where('status', RiderLocalTrip::EN_ROUTE_TO_HUB)
            ->with(['seller', 'rider.user', 'items.riderAssignment.shipment.sellerOrder'])
            ->latest()
            ->get();

        $incomingTransferItems = $schema->transferReady()
            ? TransferManifestItem::query()
                ->where('status', TransferManifestItem::EXPECTED)
                ->whereHas('manifest', fn ($query) => $query
                    ->where('destination_logistics_center_id', $center?->id)
                    ->whereIn('status', [TransferManifest::IN_TRANSIT, TransferManifest::ARRIVED, TransferManifest::PARTIAL, TransferManifest::EXCEPTION]))
                ->with(['manifest.originLogisticsCenter', 'transferLeg.shipment.sellerOrder.order.address'])
                ->latest('id')
                ->get()
            : collect();

        return view('logistics.parcels.receive', compact('shipments', 'center', 'receivedBatches', 'incomingTransferItems'));
    }

    public function sorting(Request $request): View
    {
        $center = $this->center($request);
        $tracking = trim((string) $request->query('tracking', ''));
        $automaticRoutingReady = app(LogisticsSchemaReadiness::class)->automaticRoutingReady();

        $shipments = Shipment::query()
            ->whereIn('current_status', ['AT_SORTING_CENTER', 'SORTED'])
            ->when($tracking !== '', fn ($query) => $query->where('tracking_number', $tracking))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->with([
                'sellerOrder.order.address',
                'serviceArea.locations',
                'serviceArea.logisticsCenter',
                ...($automaticRoutingReady ? ['destinationLogisticsCenter'] : []),
            ])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $areas = ServiceArea::query()
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->where('is_active', true)
            ->with('locations')
            ->orderBy('name')
            ->get();

        $destinationCenters = $automaticRoutingReady
            ? LogisticsCenter::query()
                ->approvedActive()
                ->with([
                    'address',
                    'serviceAreas' => fn ($query) => $query->where('is_active', true)->with('locations'),
                ])
                ->orderBy('business_name')
                ->get()
            : collect();

        $shipments->getCollection()->each(function (Shipment $shipment) use ($areas, $automaticRoutingReady, $center, $destinationCenters): void {
            $destinationCodes = [
                trim((string) $shipment->destination_province_code),
                trim((string) $shipment->destination_municipality_code),
                trim((string) $shipment->destination_barangay_code),
            ];
            $matchingAreas = collect($destinationCodes)->contains('') ? collect() : $areas->filter(
                fn (ServiceArea $area): bool => $area->locations->contains(fn ($location): bool =>
                    trim((string) $location->province_code) === $destinationCodes[0]
                    && trim((string) $location->municipality_code) === $destinationCodes[1]
                    && trim((string) $location->barangay_code) === $destinationCodes[2]),
            );
            $matchedArea = $matchingAreas->count() === 1 ? $matchingAreas->first() : null;

            $isCrossHubRoute = $automaticRoutingReady
                && filled($shipment->origin_logistics_center_id)
                && filled($shipment->destination_logistics_center_id)
                && (int) $shipment->origin_logistics_center_id !== (int) $shipment->destination_logistics_center_id
                && (int) $shipment->logistics_center_id === (int) $center?->id
                && (int) $shipment->origin_logistics_center_id === (int) $center?->id;

            $shipment->setAttribute('sorting_is_cross_hub_route', $isCrossHubRoute);
            $shipment->setAttribute('sorting_match_is_ambiguous', $matchingAreas->count() > 1);
            $shipment->setRelation('sorting_auto_matched_area', $matchedArea);
            $shipment->setRelation('sorting_matching_areas', $matchingAreas);

            $matchingDestinationCenters = collect();
            if ($isCrossHubRoute && ! collect($destinationCodes)->contains('')) {
                $matchingDestinationCenters = $destinationCenters
                    ->map(fn (LogisticsCenter $destinationCenter): LogisticsCenter => clone $destinationCenter)
                    ->filter(function (LogisticsCenter $destinationCenter) use ($destinationCodes, $shipment): bool {
                        if ((int) $destinationCenter->id === (int) $shipment->origin_logistics_center_id) {
                            return false;
                        }

                        $hubAddress = $destinationCenter->address;
                        if (! $hubAddress
                            || trim((string) $hubAddress->province_code) !== $destinationCodes[0]
                            || trim((string) $hubAddress->municipality_code) !== $destinationCodes[1]) {
                            return false;
                        }

                        $matchingLocations = $destinationCenter->serviceAreas
                            ->flatMap(fn (ServiceArea $area) => $area->locations
                                ->filter(fn ($location): bool =>
                                    trim((string) $location->province_code) === $destinationCodes[0]
                                    && trim((string) $location->municipality_code) === $destinationCodes[1]
                                    && trim((string) $location->barangay_code) === $destinationCodes[2])
                                ->map(fn ($location) => ['area' => $area, 'location' => $location]))
                            ->values();

                        if ($matchingLocations->count() !== 1) {
                            return false;
                        }

                        $destinationCenter->setRelation('sorting_destination_area', $matchingLocations->first()['area']);

                        return true;
                    })
                    ->values();
            }
            $shipment->setRelation('sorting_destination_hubs', $matchingDestinationCenters);
        });

        return view('logistics.sorting.index', compact('shipments', 'areas', 'center'));
    }

    public function assignments(Request $request, ShipmentWorkflowService $workflow, LogisticsSchemaReadiness $schema): View
    {
        $center = $this->center($request);
        $tracking = trim((string) $request->query('tracking', ''));

        $shipments = Shipment::query()
            ->whereIn('current_status', ['SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERY_FAILED'])
            ->when($schema->automaticRoutingReady(), fn ($query) => $query->where(fn ($route) => $route
                ->whereNull('origin_logistics_center_id')
                ->orWhereNull('destination_logistics_center_id')
                ->orWhereColumn('origin_logistics_center_id', 'destination_logistics_center_id')
                ->orWhereColumn('logistics_center_id', 'destination_logistics_center_id')))
            ->when($tracking !== '', fn ($query) => $query->where('tracking_number', $tracking))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->with(['sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'serviceArea', 'riderAssignments.riderProfile.user', 'riderAssignments.liveLocation', 'logisticsCenter.address'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $riders = RiderProfile::query()
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->where('is_online', true)
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->orderBy('id')
            ->get();

        $eligibleRidersByShipment = $shipments->getCollection()
            ->mapWithKeys(fn (Shipment $shipment): array => [
                $shipment->id => $riders
                    ->filter(fn (RiderProfile $rider): bool => LocalTripCapacityRules::remainingCapacity((int) $rider->approved_parcel_capacity, (int) $rider->active_assignments_count) > 0
                        && $workflow->riderCoversShipmentDestinationArea($shipment, $rider))
                    ->values(),
            ])
            ->all();
        $localTripsByRider = collect();
        if ($schema->localTripsReady()) {
            $localTripsByRider = RiderLocalTrip::query()
                ->where('trip_type', RiderLocalTrip::TYPE_DELIVERY)
                ->where('status', RiderLocalTrip::LOADING)
                ->whereIn('rider_profile_id', $riders->pluck('id'))
                ->withCount(['items as load_count' => fn ($query) => $query->whereIn('status', ['QUEUED', 'LOADED'])])
                ->get()
                ->groupBy('rider_profile_id');
        }

        $localTripDispatchReady = $schema->localTripsReady();
        return view('logistics.assignments.index', compact('shipments', 'riders', 'center', 'eligibleRidersByShipment', 'localTripsByRider', 'localTripDispatchReady'));
    }

    public function tracking(Request $request, LogisticsShipmentQuery $shipmentQuery): View
    {
        $shipment = null;
        $trackingResults = collect();
        $tracking = trim((string) $request->query('tracking'));

        if ($tracking !== '') {
            $center = $this->center($request);
            abort_unless($center, 403);
            $trackingResults = $shipmentQuery->visibleTo($center)
                ->where(function ($query) use ($tracking): void {
                    $query->where('shipments.tracking_number', $tracking)
                        ->orWhereHas('sellerOrder', fn ($sellerOrder) => $sellerOrder
                            ->where('seller_order_number', $tracking)
                            ->orWhereHas('order', fn ($order) => $order
                                ->where('order_number', $tracking)
                                ->orWhere('id', ctype_digit($tracking) ? (int) $tracking : 0)))
                        ->orWhereHas('sellerOrder.order.buyer', fn ($buyer) => $buyer
                            ->where('first_name', 'like', '%'.$tracking.'%')
                            ->orWhere('last_name', 'like', '%'.$tracking.'%')
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ['%'.$tracking.'%']));
                })
                ->with(['sellerOrder.items', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'sellerOrder.order.buyer', 'events.actor', 'scans', 'deliveryAttempts', 'riderAssignments.liveLocation', 'logisticsCenter.address'])
                ->orderByRaw('CASE WHEN shipments.tracking_number = ? THEN 0 ELSE 1 END', [$tracking])
                ->limit(50)
                ->get();
            $shipment = $trackingResults->firstWhere('tracking_number', $tracking)
                ?? ($trackingResults->count() === 1 ? $trackingResults->first() : null);
        }

        $mapMarkers = app(MapDataService::class)->forShipments($trackingResults, true);
        return view('logistics.tracking.index', compact('shipment', 'tracking', 'trackingResults', 'mapMarkers'));
    }

    public function overrideShipmentStatus(Request $request, Shipment $shipment, LogisticsShipmentQuery $shipmentQuery): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        abort_unless($shipmentQuery->visibleTo($center)->whereKey($shipment->id)->exists(), 403);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(self::MANUAL_OVERRIDE_STATUSES)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $reason = trim($data['reason']);
        abort_if($reason === '', 422, 'A reason is required for a shipment status override.');

        DB::transaction(function () use ($request, $shipment, $center, $data, $reason): void {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $oldStatus = $locked->current_status;
            $newStatus = strtoupper($data['status']);
            abort_if($newStatus === 'DELIVERED', 409, 'A manual status override cannot confirm delivery. Complete the assigned Rider delivery workflow with its scan, proof, and COD checks.');
            abort_if(in_array($oldStatus, ['DELIVERED', 'COMPLETED', 'RETURNED', 'CANCELLED', 'LOST', 'DAMAGED'], true), 409, 'A closed or exception shipment cannot be manually overridden.');
            abort_if(app(\App\Services\Fulfillment\ShipmentReturnWorkflowService::class)->hasOpenWorkflow($locked), 409, 'An active return-to-seller workflow controls this parcel status until seller receipt is confirmed.');
            abort_if($oldStatus === $newStatus, 422, 'The shipment already has that status.');

            $locked->update(['current_status' => $newStatus]);
            app(ShipmentWorkflowService::class)->recordEvent(
                $locked,
                $newStatus,
                $request->user(),
                "MANUAL_OVERRIDE: {$oldStatus} → {$newStatus}. Reason: {$reason}",
                null,
                $center,
            );

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'shipment.status_manual_override',
                'auditable_type' => Shipment::class,
                'auditable_id' => $locked->id,
                'old_values' => ['status' => $oldStatus],
                'new_values' => ['status' => $newStatus, 'reason' => $reason],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Shipment status changed to '.str($data['status'])->headline().'. The override reason was recorded.');
    }

    public function reportShipmentException(Request $request, Shipment $shipment, LogisticsShipmentQuery $shipmentQuery, RefundProofStorageService $proofs): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        abort_unless($shipmentQuery->visibleTo($center)->whereKey($shipment->id)->exists(), 403);

        $data = $request->validate([
            'exception_type' => ['required', Rule::in(['LOST', 'DAMAGED'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'photo' => ['required_if:exception_type,DAMAGED', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $reason = trim($data['reason']);
        abort_if($reason === '', 422, 'Please describe the shipment exception.');
        $type = $data['exception_type'];
        $storedPhoto = null;

        if ($type === 'DAMAGED') {
            try {
                $storedPhoto = $proofs->storeImage($request->file('photo'), 'shipment-exceptions');
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['photo' => $exception->getMessage()])->withInput();
            }
        }

        try {
            DB::transaction(function () use ($request, $shipment, $center, $type, $reason, $storedPhoto): void {
                $locked = Shipment::query()
                    ->with(['sellerOrder.order.buyer', 'sellerOrder.sellerProfile.user'])
                    ->lockForUpdate()
                    ->findOrFail($shipment->id);
                abort_if(in_array($locked->current_status, ['DELIVERED', 'COMPLETED', 'RETURNED', 'CANCELLED', 'LOST', 'DAMAGED'], true), 409, 'This shipment is already delivered, closed, or has an exception recorded.');

                $sellerOrder = $locked->sellerOrder;
                $order = $sellerOrder?->order;
                $buyer = $order?->buyer;
                $seller = $sellerOrder?->sellerProfile?->user;
                abort_unless($order && $buyer && $seller, 409, 'The shipment does not have both buyer and seller accounts for claim notifications.');

                $dispute = Dispute::query()->create([
                    'dispute_number' => 'SHIP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                    'opened_by_user_id' => $request->user()->id,
                    'order_id' => $order->id,
                    'seller_order_id' => $sellerOrder->id,
                    'shipment_id' => $locked->id,
                    'type' => 'SHIPMENT_'.$type,
                    'subject' => 'Shipment '.str($type)->lower()->headline().' — '.$locked->tracking_number,
                    'description' => "Shipment exception reported by logistics.\nTracking: {$locked->tracking_number}\nOrder: {$order->order_number}\nReason: {$reason}",
                    'status' => 'OPEN',
                    'opened_at' => now(),
                ]);

                if ($storedPhoto) {
                    DisputeEvidence::query()->create([
                        'dispute_id' => $dispute->id,
                        'uploaded_by_user_id' => $request->user()->id,
                        'file_path' => $storedPhoto['path'],
                        'disk' => $storedPhoto['disk'],
                        'encrypted' => $storedPhoto['encrypted'],
                        'original_name' => $storedPhoto['original_name'],
                        'mime_type' => $storedPhoto['mime'],
                        'notes' => 'Required shipment damage photo evidence.',
                    ]);
                }

                $locked->update(['current_status' => $type]);
                app(ShipmentWorkflowService::class)->recordEvent(
                    $locked,
                    $type,
                    $request->user(),
                    "SHIPMENT_EXCEPTION: {$reason}. Claim {$dispute->dispute_number} opened.",
                    null,
                    $center,
                );

                $message = "Shipment {$locked->tracking_number} was reported {$type}. Claim {$dispute->dispute_number} is open for review.";
                foreach ([$buyer, $seller] as $recipient) {
                    Notification::query()->create([
                        'user_id' => $recipient->id,
                        'type' => 'SHIPMENT_EXCEPTION',
                        'title' => 'Shipment exception reported',
                        'message' => $message,
                        'reference_type' => Dispute::class,
                        'reference_id' => $dispute->id,
                        'action_url' => route('tracking.show', $locked->tracking_number),
                    ]);
                }
                User::query()->where('account_type', User::TYPE_ADMIN)
                    ->where('status', User::STATUS_ACTIVE)->get()
                    ->each(fn (User $admin) => Notification::query()->create([
                        'user_id' => $admin->id,
                        'type' => 'SHIPMENT_EXCEPTION',
                        'title' => 'Shipment claim requires review',
                        'message' => "{$type} exception for tracking {$locked->tracking_number}. Claim {$dispute->dispute_number}.",
                        'reference_type' => Dispute::class,
                        'reference_id' => $dispute->id,
                        'action_url' => route('admin.complaints'),
                    ]));
            });
        } catch (\Throwable $exception) {
            if ($storedPhoto) {
                \Illuminate\Support\Facades\Storage::disk($storedPhoto['disk'])->delete($storedPhoto['path']);
            }
            throw $exception;
        }

        return back()->with('status', 'Shipment marked '.$type.'. The buyer, seller, and admin team were notified, and a claim was opened.');
    }

    public function show(Shipment $shipment, LogisticsShipmentQuery $shipmentQuery): View
    {
        $center = request()->user()?->logisticsCenter;
        abort_unless($center, 403);
        abort_unless($shipmentQuery->visibleTo($center)->whereKey($shipment->id)->exists(), 403);
        $shipment->load(['sellerOrder.items.product.images', 'sellerOrder.sellerProfile.user', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'sellerOrder.order.buyer', 'serviceArea', 'riderAssignments.riderProfile.user', 'riderAssignments.liveLocation', 'logisticsCenter.address', 'events.actor', 'scans', 'deliveryAttempts']);

        $mapMarkers = app(MapDataService::class)->forShipment($shipment, true, true);
        return view('logistics.parcels.show', compact('shipment', 'mapMarkers'));
    }

    public function assign(Request $request, Shipment $shipment, LogisticsSchemaReadiness $schema, FinalMileDispatchService $dispatch): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
            'assignment_type' => ['required', 'string', 'in:DELIVERY'],
            'local_trip_id' => ['required', 'integer', 'min:0'],
        ]);
        if (! $schema->localTripsReady()) {
            return back()->withErrors(['rider_profile_id' => 'Local rider trip schema is not installed; final-mile dispatch is disabled.'])->withInput();
        }

        $autoGroup = (int) $data['local_trip_id'] === 0;
        try {
            $trip = $dispatch->dispatch(
                $request->user(),
                $center,
                $shipment,
                RiderProfile::query()->findOrFail((int) $data['rider_profile_id']),
                $autoGroup ? null : (int) $data['local_trip_id'],
            );
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['rider_profile_id' => $exception->getMessage()])
                ->withInput();
        }

        if ($autoGroup) {
            return redirect()->route('logistics.local-trips.show', $trip->id)
                ->with('status', 'Parcel added to delivery batch #'.$trip->id.'. Batches follow the rider’s approved parcel capacity, up to 10 parcels. The rider can accept and start the batch together.');
        }

        return back()->with('status', 'Parcel added to local trip #'.$trip->id.'. Add other parcels from the same barangay to group their deliveries.');
    }

    public function autoMatchDelivery(Request $request, Shipment $shipment, LogisticsSchemaReadiness $schema, FinalMileDispatchService $dispatch, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);

        if (! $schema->localTripsReady() || ! $schema->riderAvailabilityReady()) {
            return back()->withErrors(['rider_profile_id' => 'Nearest-rider matching needs local trip support and live rider availability enabled.']);
        }

        $shipment->loadMissing('sellerOrder.order.address', 'serviceArea');
        $destination = $shipment->sellerOrder?->order?->address;
        if (! $destination || ! is_numeric($destination->latitude) || ! is_numeric($destination->longitude)) {
            return back()->withErrors(['rider_profile_id' => 'Auto-match needs a confirmed map pin for the buyer destination.']);
        }

        $currentAssignment = $shipment->riderAssignments()
            ->where('assignment_type', 'DELIVERY')
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->with('riderProfile')
            ->latest('id')
            ->first();
        $takingOverUnavailableRider = false;
        if ($currentAssignment) {
            $currentRider = $currentAssignment->riderProfile;
            $takingOverUnavailableRider = $currentAssignment->status === 'ASSIGNED'
                && (! $currentRider?->is_online
                    || ! ($currentRider?->availability_location_updated_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false));
            if (! $takingOverUnavailableRider) {
                return back()->withErrors(['rider_profile_id' => 'A delivery can be taken over only before the assigned rider accepts, and when that rider is offline or has a stale location.']);
            }
        }

        $candidates = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->where('status', 'ACTIVE')
            ->where('is_online', true)
            ->whereNotNull('availability_latitude')
            ->whereNotNull('availability_longitude')
            ->where('availability_location_updated_at', '>=', now()->subMinutes(2))
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE))
            ->whereHas('areaAssignments', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('serviceArea', fn ($area) => $area
                    ->where('is_active', true)
                    ->where('logistics_center_id', $center->id)))
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->get()
            ->filter(fn (RiderProfile $candidate): bool =>
                LocalTripCapacityRules::remainingCapacity((int) $candidate->approved_parcel_capacity, (int) $candidate->active_assignments_count) > 0)
            ->map(function (RiderProfile $candidate) use ($destination, $workflow, $shipment): RiderProfile {
                $candidate->setAttribute('covers_destination', $workflow->riderCoversShipmentDestinationArea($shipment, $candidate));
                $candidate->setAttribute('destination_distance_km', $this->distanceKm(
                    (float) $destination->latitude,
                    (float) $destination->longitude,
                    (float) $candidate->availability_latitude,
                    (float) $candidate->availability_longitude,
                ));

                return $candidate;
            });

        $areaCandidates = $candidates->where('covers_destination', true);
        $usedCoverageException = $areaCandidates->isEmpty();
        $rider = ($usedCoverageException ? $candidates : $areaCandidates)
            ->sortBy(fn (RiderProfile $candidate): string => sprintf('%012.6f-%03d-%012d',
                (float) $candidate->destination_distance_km,
                (int) $candidate->active_assignments_count,
                (int) $candidate->id,
            ))
            ->first();

        if (! $rider) {
            return back()->withErrors(['rider_profile_id' => 'No active online rider in this hub has a recent location and available capacity.']);
        }

        $reason = 'Temporary nearest-rider coverage exception: no online, capacity-ready rider assigned to this destination barangay.';
        try {
            $trip = $dispatch->dispatch(
                $request->user(),
                $center,
                $shipment,
                $rider,
                null,
                $usedCoverageException,
                $reason,
                $takingOverUnavailableRider,
                true,
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()]);
        }

        $status = 'Auto-matched '.$rider->user?->name.' as the nearest online rider for delivery batch #'.$trip->id.'.';
        if ($usedCoverageException) {
            $status .= ' Temporary coverage exception recorded because no available rider covers this barangay.';
        }
        if ($takingOverUnavailableRider) {
            $status .= ' The previous unaccepted assignment was cancelled because its rider was unavailable.';
        }

        return redirect()->route('logistics.local-trips.show', $trip->id)->with('status', $status);
    }

    public function assignPickup(Request $request, Shipment $shipment, SellerPickupBatchService $batches): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
            'coverage_override' => ['nullable', 'boolean'],
            'coverage_override_reason' => ['required_if:coverage_override,1', 'nullable', 'string', 'max:1000'],
        ]);
        try {
            $batches->create(
                $request->user(),
                $center,
                (int) $data['rider_profile_id'],
                [$shipment->id],
                (bool) ($data['coverage_override'] ?? false),
                $data['coverage_override_reason'] ?? null,
            );
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['rider_profile_id' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', 'One-parcel seller pickup batch assigned.');
    }

    public function assignPickupBatch(Request $request, SellerPickupBatchService $batches): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:10'],
            'shipment_ids.*' => ['required', 'integer', 'distinct', 'exists:shipments,id'],
        ]);

        try {
            $result = $batches->createPartial($request->user(), $center, (int) $data['rider_profile_id'], $data['shipment_ids']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()])->withInput();
        }

        $assignedCount = count($result['assigned_ids']);
        $skippedCount = count($result['skipped_ids']);
        $message = 'Seller pickup batch #P-'.$result['trip']->id.' assigned with '.$assignedCount.' parcel(s).';
        if ($skippedCount > 0) {
            $message .= ' '.$skippedCount.' parcel(s) remain unassigned because they exceed the rider\'s remaining approved capacity.';
        }

        return back()->with('status', $message);
    }

    public function reassignPickupBatch(Request $request, RiderLocalTrip $trip, SellerPickupBatchService $batches): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        abort_unless((int) $trip->logistics_center_id === (int) $center->id, 403);
        $data = $request->validate(['rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id']]);

        try {
            $newTrip = $batches->reassign(
                $request->user(),
                $center,
                $trip,
                (int) $data['rider_profile_id'],
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Pickup batch reassigned to '.$newTrip->rider?->user?->name.'. The new rider must accept the updated assignment.');
    }

    public function assignSellerPickupRequest(Request $request, SellerPickupRequest $pickupRequest, SellerPickupRequestService $pickupRequests): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
        ]);

        try {
            $assigned = $pickupRequests->assign($request->user(), $center, (int) $pickupRequest->id, (int) $data['rider_profile_id']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()])->withInput();
        }

        $message = 'Seller pickup request #'.$assigned->id.' assigned to trip #'.$assigned->rider_local_trip_id.'.';
        if ((int) $assigned->skipped_count > 0) {
            $message .= ' '.$assigned->skipped_count.' parcel(s) were left in a new request because they exceed the rider\'s remaining approved capacity.';
        }

        return back()->with('status', $message);
    }

    public function rejectSellerPickupRequest(Request $request, SellerPickupRequest $pickupRequest, SellerPickupRequestService $pickupRequests): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);

        try {
            $pickupRequests->reject($request->user(), $center, $pickupRequest);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['pickup_request' => $exception->getMessage()]);
        }

        return back()->with('status', 'Seller pickup request #'.$pickupRequest->id.' rejected. Parcel history was retained.');
    }

    public function autoAssignPickup(Request $request, Shipment $shipment, SellerPickupBatchService $batches, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);

        $pickup = $shipment->pickupRequests()->whereIn('status', ['PENDING', 'APPROVED'])->latest()->firstOrFail();
        if (str_starts_with((string) $pickup->notes, 'SELLER_DROPOFF:')) {
            return back()->withErrors(['rider_profile_id' => 'Seller drop-off parcels are received at the center and cannot be auto-assigned for pickup.']);
        }

        $shipment->loadMissing('sellerOrder.sellerProfile.businessAddress');
        $sellerAddress = $shipment->sellerOrder?->sellerProfile?->businessAddress;
        if (! $sellerAddress || ! is_numeric($sellerAddress->latitude) || ! is_numeric($sellerAddress->longitude)) {
            return back()->withErrors(['rider_profile_id' => 'Auto-assignment needs a confirmed map pin for the seller pickup address.']);
        }

        $rider = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->where('is_online', true)
            ->whereNotNull('availability_latitude')
            ->whereNotNull('availability_longitude')
            ->where('availability_location_updated_at', '>=', now()->subMinutes(15))
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->get()
            ->filter(fn (RiderProfile $candidate): bool =>
                LocalTripCapacityRules::remainingCapacity((int) $candidate->approved_parcel_capacity, (int) $candidate->active_assignments_count) > 0
                && $workflow->riderCoversSellerPickupArea($shipment, $candidate))
            ->map(function (RiderProfile $candidate) use ($sellerAddress): RiderProfile {
                $candidate->setAttribute('seller_distance_km', $this->distanceKm(
                    (float) $sellerAddress->latitude,
                    (float) $sellerAddress->longitude,
                    (float) $candidate->availability_latitude,
                    (float) $candidate->availability_longitude,
                ));

                return $candidate;
            })
            ->sortBy(fn (RiderProfile $candidate): string => sprintf('%012.6f-%03d-%012d',
                (float) $candidate->seller_distance_km,
                (int) $candidate->active_assignments_count,
                (int) $candidate->id,
            ))
            ->first();

        if (! $rider) {
            return back()->withErrors(['rider_profile_id' => 'No online rider with a recent location covers this pickup area and has an available job slot.']);
        }

        try {
            $trip = $batches->create($request->user(), $center, (int) $rider->id, [$shipment->id]);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()]);
        }

        return back()->with('status', 'Pickup auto-assigned to '.$rider->user?->name.' in batch #P-'.$trip->id.'.');
    }

    public function approvePickup(Request $request, PickupRequest $pickupRequest, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($pickupRequest->shipment, $center);
        $workflow->approvePickup($pickupRequest, $request->user(), $request->input('notes'));

        return back()->with('status', 'Pickup request approved.');
    }

    public function rejectPickup(Request $request, PickupRequest $pickupRequest, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($pickupRequest->shipment, $center);
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $workflow->rejectPickup($pickupRequest, $request->user(), $data['rejection_reason']);

        return back()->with('status', 'Pickup request rejected.');
    }

    public function receiveScanned(
        Request $request,
        ShipmentWorkflowService $workflow,
        LogisticsSchemaReadiness $schema,
        TransferManifestService $manifests,
    ): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'tracking' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);
        $shipment = Shipment::query()->where('tracking_number', trim($data['tracking']))->first();

        if (! $shipment) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'No incoming parcel matches that tracking number.'], 404);
            }

            return back()
                ->withErrors(['tracking' => 'No incoming parcel matches that tracking number.'])
                ->withInput();
        }

        $request->merge(['scanned_code' => $data['tracking']]);

        return $this->confirmReceive($request, $shipment, $workflow, $schema, $manifests);
    }

    public function confirmReceive(
        Request $request,
        Shipment $shipment,
        ShipmentWorkflowService $workflow,
        LogisticsSchemaReadiness $schema,
        TransferManifestService $manifests,
    ): JsonResponse|RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);

        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);

        if ($schema->transferReady()) {
            $incomingTransferItem = TransferManifestItem::query()
                ->where('status', TransferManifestItem::EXPECTED)
                ->whereHas('transferLeg', fn ($query) => $query
                    ->where('shipment_id', $shipment->id)
                    ->where('destination_logistics_center_id', $center->id))
                ->whereHas('manifest', fn ($query) => $query
                    ->where('destination_logistics_center_id', $center->id)
                    ->whereIn('status', [TransferManifest::IN_TRANSIT, TransferManifest::ARRIVED, TransferManifest::PARTIAL, TransferManifest::EXCEPTION]))
                ->with('manifest')
                ->latest('id')
                ->first();

            if ($incomingTransferItem) {
                try {
                    $manifests->receiveItem(
                        $request->user(),
                        $incomingTransferItem,
                        TransferManifestItem::RECEIVED,
                        $data['scanned_code'],
                        null,
                        $data['scan_method'] ?? 'MANUAL',
                    );
                } catch (\RuntimeException $exception) {
                    if ($request->expectsJson()) {
                        return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
                    }

                    return back()->withErrors(['scanned_code' => $exception->getMessage()])->withInput();
                }

                $message = 'Incoming transfer parcel received and moved to sorting for local delivery assignment.';
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'tracking' => $shipment->tracking_number,
                        'open_url' => route('logistics.sorting', ['tracking' => $shipment->tracking_number]),
                        'already_received' => false,
                        'clear_input' => true,
                    ]);
                }

                return redirect()
                    ->route('logistics.sorting', ['tracking' => $shipment->tracking_number])
                    ->with('status', $message);
            }
        }

        $this->ensureShipmentBelongsToCenter($shipment, $center);

        $pickupItem = $shipment->riderAssignments()
            ->where('assignment_type', 'PICKUP')
            ->latest('id')
            ->with('localTripItems.trip')
            ->get()
            ->flatMap->localTripItems
            ->first(fn ($item) => $item->trip?->trip_type === RiderLocalTrip::TYPE_PICKUP);

        $alreadyReceived = $shipment->hasReachedSortingCenter();
        try {
            $workflow->receiveAtCenter($shipment, $center, $request->user(), $data['scanned_code'], $data['scan_method'] ?? 'MANUAL');
        } catch (\RuntimeException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()
                ->withErrors(['scanned_code' => $exception->getMessage()])
                ->withInput();
        }

        if ($alreadyReceived) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Parcel was already received. No duplicate intake scan was created.',
                    'tracking' => $shipment->tracking_number,
                    'already_received' => true,
                    'clear_input' => true,
                ]);
            }

            return redirect()->route('logistics.parcels.receive')->with('status', 'Parcel was already received. No duplicate intake scan was created.');
        }

        if ($pickupItem?->trip) {
            $trip = $pickupItem->trip->fresh();
            $trip->load('items.riderAssignment.shipment');
            $receivedCount = $trip->items->filter(fn ($item) => $item->riderAssignment?->shipment?->hasReachedSortingCenter())->count();
            $expectedCount = $trip->items->filter(fn ($item) => $item->riderAssignment?->status === 'COMPLETED')->count();
            $message = $trip?->status === RiderLocalTrip::COMPLETED
                ? 'Parcel received. All collected parcels in pickup batch #P-'.$trip->id.' have reached the sorting center.'
                : 'Parcel received. Scan each remaining parcel in the pickup batch separately.';

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'tracking' => $shipment->tracking_number,
                    'batch_id' => $trip->id,
                    'received_count' => $receivedCount,
                    'expected_count' => $expectedCount,
                    'already_received' => false,
                    'clear_input' => true,
                ]);
            }

            return redirect()->route('logistics.parcels.receive')->with('status', $message);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Parcel received. Read the delivery address and confirm its destination area.',
                'tracking' => $shipment->tracking_number,
                'open_url' => route('logistics.sorting', ['tracking' => $shipment->tracking_number]),
                'already_received' => false,
                'clear_input' => true,
            ]);
        }

        return redirect()
            ->route('logistics.sorting', ['tracking' => $shipment->tracking_number])
            ->with('status', 'Parcel received. Read the delivery address and confirm its destination area.');
    }

    public function sortParcel(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);

        $data = $request->validate([
            'service_area_id' => ['nullable', 'integer', 'exists:service_areas,id'],
            'destination_logistics_center_id' => ['nullable', 'integer', 'exists:logistics_centers,id'],
        ]);

        $area = isset($data['service_area_id'])
            ? ServiceArea::query()->where('logistics_center_id', $center->id)->where('is_active', true)->findOrFail($data['service_area_id'])
            : null;
        $destinationCenter = isset($data['destination_logistics_center_id'])
            ? LogisticsCenter::query()->approvedActive()->findOrFail($data['destination_logistics_center_id'])
            : null;
        try {
            $sortedShipment = $workflow->sortShipment($shipment, $center, $request->user(), $area, $destinationCenter);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->withInput();
        } catch (\RuntimeException $exception) {
            $errorField = isset($data['destination_logistics_center_id'])
                ? 'destination_logistics_center_id'
                : 'service_area_id';

            return back()
                ->withErrors([$errorField => $exception->getMessage()])
                ->withInput();
        }

        if (filled($sortedShipment->destination_logistics_center_id)
            && (int) $sortedShipment->destination_logistics_center_id !== (int) $center->id) {
            return redirect()
                ->route('logistics.transfers')
                ->with('status', 'Parcel sorted for hub-to-hub transfer. Add it to a transfer manifest when ready.');
        }

        return redirect()
            ->route('logistics.assignments', ['tracking' => $shipment->tracking_number])
            ->with('status', 'Parcel sorted. Assign the rider responsible for this destination area.');
    }

    public function waybill(Request $request, string $tracking): View
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $shipment = Shipment::query()
            ->where('tracking_number', $tracking)
            ->where('logistics_center_id', $center->id)
            ->with(['orderItem', 'sellerOrder.items', 'sellerOrder.sellerProfile.user', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'waybills'])
            ->firstOrFail();

        return view('logistics.waybill', compact('shipment'));
    }

    private function center(Request $request): mixed
    {
        return $request->user()->logisticsCenter;
    }

    private function ensureShipmentBelongsToCenter(Shipment $shipment, LogisticsCenter $center): void
    {
        $shipment->loadMissing('serviceArea', 'sellerOrder.sellerProfile.businessAddress');
        if ($shipment->logistics_center_id) {
            abort_unless((int) $shipment->logistics_center_id === (int) $center->id, 403);

            return;
        }

        $areaBelongsToCenter = (int) $shipment->serviceArea?->logistics_center_id === (int) $center->id;
        $pickupAddress = $shipment->sellerOrder?->sellerProfile?->businessAddress;
        $pickupCandidates = $pickupAddress ? ServiceArea::query()
            ->where('logistics_center_id', $center->id)
            ->where('is_active', true)
            ->whereHas('locations', fn ($location) => $location
                ->where('province_code', $pickupAddress->province_code)
                ->where('municipality_code', $pickupAddress->municipality_code)
                ->where('barangay_code', $pickupAddress->barangay_code))
            ->pluck('id') : collect();
        $pickupBelongsToCenter = $pickupCandidates->count() === 1;

        abort_unless($areaBelongsToCenter || $pickupBelongsToCenter, 403);
    }

    private function distanceKm(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadiusKm = 6371.0088;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }
}
