<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Seller\SellerPickupRequest;
use App\Services\Fulfillment\SellerPickupRequestService;
use App\Services\Fulfillment\FinalMileDispatchService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\LogisticsShipmentQuery;
use App\Services\Fulfillment\SellerPickupBatchService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Fulfillment\TransferManifestService;
use App\Services\Maps\MapDataService;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DispatchController extends Controller
{
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
        return view('Logistics.parcels.index', compact('shipments', 'status', 'center', 'mapMarkers', 'filters'));
    }

    public function pickups(Request $request, LogisticsSchemaReadiness $schema): View
    {
        $center = $this->center($request);
        abort_unless($center, 403);
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
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->where('logistics_center_id', $center->id)
            ->withCount(['assignments as active_pickup_assignments_count' => fn ($query) => $query
                ->where('assignment_type', 'PICKUP')
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
                            (int) $rider->active_pickup_assignments_count < min(
                                ShipmentWorkflowService::MAX_ACTIVE_PICKUP_ASSIGNMENTS,
                                (int) $rider->approved_parcel_capacity,
                            )
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
                && $count <= min(
                    10,
                    (int) $rider->approved_parcel_capacity,
                    ShipmentWorkflowService::MAX_ACTIVE_PICKUP_ASSIGNMENTS - (int) $rider->active_pickup_assignments_count,
                )
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

        $mapShipments = $pickups->map->shipment->filter()
            ->concat($sellerPickupRequests->flatMap(fn ($pickup) => $pickup->memberships->map->shipment)->filter())
            ->unique('id')
            ->values();
        $mapMarkers = app(MapDataService::class)->forShipments(
            $mapShipments,
            includeSeller: true,
            viewer: auth()->user(),
        );
        return view('Logistics.pickups.index', [
            'center' => $center,
            'tracking' => $tracking,
            'sellerPickupQueue' => $sellerPickupQueue,
            'sellerPickupRequests' => $sellerPickupRequests,
            'activePickupBatches' => $activePickupBatches,
            'riders' => $riders,
            'mapMarkers' => $mapMarkers,
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

        return view('Logistics.parcels.receive', compact('shipments', 'center', 'receivedBatches', 'incomingTransferItems'));
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

        return view('Logistics.sorting.index', compact('shipments', 'areas', 'center'));
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
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->orderBy('id')
            ->get();

        $eligibleRidersByShipment = $shipments->getCollection()
            ->mapWithKeys(fn (Shipment $shipment): array => [
                $shipment->id => $riders
                    ->filter(fn (RiderProfile $rider): bool => $workflow->riderCoversShipmentDestinationArea($shipment, $rider))
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

        $mapMarkers = app(MapDataService::class)->forShipments($shipments->getCollection(), true);
        $localTripDispatchReady = $schema->localTripsReady();
        return view('Logistics.assignments.index', compact('shipments', 'riders', 'center', 'eligibleRidersByShipment', 'localTripsByRider', 'localTripDispatchReady', 'mapMarkers'));
    }

    public function tracking(Request $request, LogisticsShipmentQuery $shipmentQuery): View
    {
        $shipment = null;
        $tracking = trim((string) $request->query('tracking'));

        if ($tracking !== '') {
            $center = $this->center($request);
            abort_unless($center, 403);
            $shipment = $shipmentQuery->visibleTo($center)
                ->where('tracking_number', $tracking)
                ->with(['sellerOrder.items', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'events.actor', 'scans', 'deliveryAttempts', 'riderAssignments.liveLocation', 'logisticsCenter.address'])
                ->first();
        }

        $mapMarkers = app(MapDataService::class)->forShipment($shipment, true, true);
        return view('Logistics.tracking.index', compact('shipment', 'tracking', 'mapMarkers'));
    }

    public function show(Shipment $shipment, LogisticsShipmentQuery $shipmentQuery): View
    {
        $center = request()->user()?->logisticsCenter;
        abort_unless($center, 403);
        abort_unless($shipmentQuery->visibleTo($center)->whereKey($shipment->id)->exists(), 403);
        $shipment->load(['sellerOrder.items.product.images', 'sellerOrder.sellerProfile.user', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'sellerOrder.order.buyer', 'serviceArea', 'riderAssignments.riderProfile.user', 'riderAssignments.liveLocation', 'logisticsCenter.address', 'events.actor', 'scans', 'deliveryAttempts']);

        $mapMarkers = app(MapDataService::class)->forShipment($shipment, true, true);
        return view('Logistics.parcels.show', compact('shipment', 'mapMarkers'));
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

        $createdNewTrip = (int) $data['local_trip_id'] === 0;
        try {
            $trip = $dispatch->dispatch(
                $request->user(),
                $center,
                $shipment,
                RiderProfile::query()->findOrFail((int) $data['rider_profile_id']),
                $createdNewTrip ? null : (int) $data['local_trip_id'],
            );
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['rider_profile_id' => $exception->getMessage()])
                ->withInput();
        }

        if ($createdNewTrip) {
            return redirect()->route('logistics.local-trips.show', $trip->id)
                ->with('status', 'A compatible loading trip was created and the parcel was added. The rider can accept and start the trip.');
        }

        return back()->with('status', 'Parcel added to local trip #'.$trip->id.'. Add other parcels from the same barangay to group their deliveries.');
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
            $trip = $batches->create($request->user(), $center, (int) $data['rider_profile_id'], $data['shipment_ids']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Seller pickup batch #P-'.$trip->id.' assigned with '.$trip->items->count().' parcel(s).');
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

        return back()->with('status', 'Seller pickup request #'.$assigned->id.' assigned to trip #'.$assigned->rider_local_trip_id.'.');
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

        $rider = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereNotNull('approved_parcel_capacity')
            ->where('approved_parcel_capacity', '>', 0)
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->withCount(['assignments as active_pickup_assignments_count' => fn ($query) => $query
                ->where('assignment_type', 'PICKUP')
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->get()
            ->filter(fn (RiderProfile $candidate): bool =>
                (int) $candidate->active_pickup_assignments_count < min(
                    ShipmentWorkflowService::MAX_ACTIVE_PICKUP_ASSIGNMENTS,
                    (int) $candidate->approved_parcel_capacity,
                )
                && $workflow->riderCoversSellerPickupArea($shipment, $candidate))
            ->sortBy('active_pickup_assignments_count')
            ->first();

        if (! $rider) {
            return back()->withErrors(['rider_profile_id' => 'No eligible rider covers this pickup area and has capacity for another parcel.']);
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

        $alreadyReceived = $shipment->current_status === 'AT_SORTING_CENTER';
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
            $receivedCount = $trip->items->filter(fn ($item) => $item->riderAssignment?->shipment?->current_status === 'AT_SORTING_CENTER')->count();
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
            ->route('logistics.dispatch', ['tracking' => $shipment->tracking_number])
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

        return view('Logistics.waybill', compact('shipment'));
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
}
