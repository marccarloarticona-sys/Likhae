<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderLocalTrip;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Maps\MapDataService;
use App\Support\RiderVehicleType;
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

    public function parcels(Request $request): View
    {
        $center = $this->center($request);
        $status = strtoupper((string) $request->query('status', ''));

        $shipments = Shipment::query()
            ->when($center, fn ($query) => $query->where(function ($q) use ($center) {
                $q->where('logistics_center_id', $center->id)
                    ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                        ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id)));
            }))
            ->when($status !== '', fn ($query) => $query->where('current_status', $status))
            ->with(['sellerOrder.sellerProfile.user', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'serviceArea', 'riderAssignments.riderProfile.user', 'riderAssignments.liveLocation', 'logisticsCenter.address'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $mapMarkers = app(MapDataService::class)->forShipments($shipments->getCollection(), true);
        return view('Logistics.parcels.index', compact('shipments', 'status', 'center', 'mapMarkers'));
    }

    public function pickups(Request $request, LogisticsSchemaReadiness $schema): View
    {
        $center = $this->center($request);
        $tracking = trim((string) $request->query('tracking', ''));

        $pickups = PickupRequest::query()
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->whereHas('shipment', function ($query) use ($center, $tracking) {
                if ($center) {
                    $query->where(function ($q) use ($center) {
                        $q->where('logistics_center_id', $center->id)
                            ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                                ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id)));
                        });
                }
                if ($tracking !== '') {
                    $query->where('tracking_number', $tracking);
                }
            })
            ->with(['shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress', 'shipment.sellerOrder.order.address', 'shipment.serviceArea', 'shipment.riderAssignments.riderProfile.user', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $riders = RiderProfile::query()
            ->where('status', 'ACTIVE')
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->orderBy('id')
            ->get();

        $workflow = app(ShipmentWorkflowService::class);
        $pickupAreaIds = $pickups->getCollection()->mapWithKeys(fn ($pickup) => [
            $pickup->shipment_id => $workflow->sellerPickupServiceArea($pickup->shipment, $center)?->id,
        ]);
        $pickupEligibleRiderIds = $pickups->getCollection()->mapWithKeys(fn ($pickup) => [
            $pickup->shipment_id => $riders
                ->filter(fn (RiderProfile $rider) => $workflow->riderCoversSellerPickupArea($pickup->shipment, $rider))
                ->pluck('id')
                ->values(),
        ]);
        $localTripsByRider = collect();
        if ($schema->localTripsReady()) {
            $localTripsByRider = RiderLocalTrip::query()
                ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                ->whereIn('status', [RiderLocalTrip::LOADING, RiderLocalTrip::READY])
                ->whereIn('rider_profile_id', $riders->pluck('id'))
                ->withCount(['items as load_count' => fn ($query) => $query->whereIn('status', ['QUEUED', 'LOADED'])])
                ->get()
                ->groupBy('rider_profile_id');
        }

        $mapMarkers = app(MapDataService::class)->forShipments(
            $pickups->getCollection()->map->shipment->filter(),
            includeSeller: true,
            viewer: auth()->user(),
        );
        return view('Logistics.pickups.index', compact('pickups', 'riders', 'center', 'pickupAreaIds', 'pickupEligibleRiderIds', 'localTripsByRider', 'mapMarkers'));
    }

    public function receive(Request $request): View
    {
        $center = $this->center($request);
        $tracking = trim((string) $request->query('tracking', ''));

        $shipments = Shipment::query()
            ->where(function ($query): void {
                $query->where('current_status', 'PICKED_UP')
                    ->orWhere(function ($dropoff): void {
                        $dropoff->where('current_status', 'READY_FOR_PICKUP')
                            ->whereHas('pickupRequests', fn ($requests) => $requests
                                ->whereIn('status', ['PENDING', 'APPROVED'])
                                ->where('notes', 'like', 'SELLER_DROPOFF:%'));
                    });
            })
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

        return view('Logistics.parcels.receive', compact('shipments', 'center'));
    }

    public function sorting(Request $request): View
    {
        $center = $this->center($request);
        $tracking = trim((string) $request->query('tracking', ''));

        $shipments = Shipment::query()
            ->whereIn('current_status', ['AT_SORTING_CENTER', 'SORTED'])
            ->when($tracking !== '', fn ($query) => $query->where('tracking_number', $tracking))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->with(['sellerOrder.order.address', 'serviceArea'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $areas = ServiceArea::query()
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('Logistics.sorting.index', compact('shipments', 'areas', 'center'));
    }

    public function assignments(Request $request, ShipmentWorkflowService $workflow, LogisticsSchemaReadiness $schema): View
    {
        $center = $this->center($request);
        $tracking = trim((string) $request->query('tracking', ''));

        $shipments = Shipment::query()
            ->whereIn('current_status', ['SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERY_FAILED'])
            ->when($tracking !== '', fn ($query) => $query->where('tracking_number', $tracking))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->with(['sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'serviceArea', 'riderAssignments.riderProfile.user', 'riderAssignments.liveLocation', 'logisticsCenter.address'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $riders = RiderProfile::query()
            ->where('status', 'ACTIVE')
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
                ->whereIn('status', [RiderLocalTrip::LOADING, RiderLocalTrip::READY])
                ->whereIn('rider_profile_id', $riders->pluck('id'))
                ->withCount(['items as load_count' => fn ($query) => $query->whereIn('status', ['QUEUED', 'LOADED'])])
                ->get()
                ->groupBy('rider_profile_id');
        }

        $mapMarkers = app(MapDataService::class)->forShipments($shipments->getCollection(), true);
        return view('Logistics.assignments.index', compact('shipments', 'riders', 'center', 'eligibleRidersByShipment', 'localTripsByRider', 'mapMarkers'));
    }

    public function tracking(Request $request): View
    {
        $shipment = null;
        $tracking = trim((string) $request->query('tracking'));

        if ($tracking !== '') {
            $center = $this->center($request);
            abort_unless($center, 403);
            $shipment = Shipment::query()
                ->where('tracking_number', $tracking)
                ->where(function ($query) use ($center) {
                    $query->where('logistics_center_id', $center->id)
                        ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                            ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id)));
                })
                ->with(['sellerOrder.items', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'events.actor', 'scans', 'deliveryAttempts', 'riderAssignments.liveLocation', 'logisticsCenter.address'])
                ->first();
        }

        $mapMarkers = app(MapDataService::class)->forShipment($shipment, true, true);
        return view('Logistics.tracking.index', compact('shipment', 'tracking', 'mapMarkers'));
    }

    public function show(Shipment $shipment): View
    {
        $center = request()->user()?->logisticsCenter;
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);
        $shipment->load(['sellerOrder.items.product.images', 'sellerOrder.sellerProfile.user', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'sellerOrder.order.buyer', 'serviceArea', 'riderAssignments.riderProfile.user', 'riderAssignments.liveLocation', 'logisticsCenter.address', 'events.actor', 'scans', 'deliveryAttempts']);

        $mapMarkers = app(MapDataService::class)->forShipment($shipment, true, true);
        return view('Logistics.parcels.show', compact('shipment', 'mapMarkers'));
    }

    public function assign(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow, LogisticsSchemaReadiness $schema, LocalRiderTripService $localTrips): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
            'assignment_type' => ['required', 'string', 'in:PICKUP,DELIVERY'],
            'local_trip_id' => ['nullable', 'integer'],
        ]);

        $rider = RiderProfile::query()->find($data['rider_profile_id']);
        if (! $rider || (int) $rider->logistics_center_id !== (int) $center->id) {
            return back()
                ->withErrors(['rider_profile_id' => 'Select an active rider from this logistics center.'])
                ->withInput();
        }
        try {
            DB::transaction(function () use ($workflow, $localTrips, $shipment, $rider, $data, $request, $schema): void {
                $assignment = $workflow->assignRider($shipment, $rider, $data['assignment_type'], $request->user());
                if ($schema->localTripsReady() && ! empty($data['local_trip_id'])) {
                    $trip = RiderLocalTrip::query()->findOrFail((int) $data['local_trip_id']);
                    $localTrips->addAssignment($request->user(), $trip, $assignment);
                }
            });
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['rider_profile_id' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', 'Rider assignment created.');
    }

    public function assignPickup(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow, LogisticsSchemaReadiness $schema, LocalRiderTripService $localTrips): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
            'coverage_override' => ['nullable', 'boolean'],
            'coverage_override_reason' => ['required_if:coverage_override,1', 'nullable', 'string', 'max:1000'],
            'local_trip_id' => ['nullable', 'integer'],
        ]);

        $pickup = $shipment->pickupRequests()->whereIn('status', ['PENDING', 'APPROVED'])->latest()->firstOrFail();

        $rider = RiderProfile::query()->find($data['rider_profile_id']);
        if (! $rider || (int) $rider->logistics_center_id !== (int) $center->id) {
            return back()
                ->withErrors(['rider_profile_id' => 'Select an active rider from this logistics center.'])
                ->withInput();
        }
        $coverageOverride = (bool) ($data['coverage_override'] ?? false);
        $pickupArea = $workflow->sellerPickupServiceArea($shipment, $center);

        if ($coverageOverride && $pickupArea) {
            $hasCoveredRider = RiderProfile::query()
                ->where('logistics_center_id', $center->id)
                ->where('status', 'ACTIVE')
                ->whereIn('vehicle_type', RiderVehicleType::localValues())
                ->whereHas('user', fn ($query) => $query
                    ->where('account_type', \App\Models\User::TYPE_RIDER)
                    ->where('status', \App\Models\User::STATUS_ACTIVE))
                ->whereHas('areaAssignments', fn ($query) => $query
                    ->where('service_area_id', $pickupArea->id)
                    ->where('is_active', true))
                ->exists();

            if ($hasCoveredRider) {
                return back()
                    ->withErrors(['rider_profile_id' => 'A rider already covers this pickup area. Select that rider instead of using the shortage override.'])
                    ->withInput();
            }
        }

        try {
            DB::transaction(function () use ($workflow, $shipment, $rider, $request, $coverageOverride, $data, $pickup): void {
                $assignment = $workflow->assignRider(
                    $shipment,
                    $rider,
                    'PICKUP',
                    $request->user(),
                    $coverageOverride,
                    $data['coverage_override_reason'] ?? null,
                );
                if (app(LogisticsSchemaReadiness::class)->localTripsReady() && ! empty($data['local_trip_id'])) {
                    $trip = RiderLocalTrip::query()->findOrFail((int) $data['local_trip_id']);
                    app(LocalRiderTripService::class)->addAssignment($request->user(), $trip, $assignment);
                }
                if ($pickup->status === 'PENDING') {
                    $workflow->approvePickup($pickup, $request->user());
                }
            });
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['rider_profile_id' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', 'Pickup rider assigned.');
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

    public function confirmReceive(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);

        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);

        $workflow->receiveAtCenter($shipment, $center, $request->user(), $data['scanned_code'], $data['scan_method'] ?? 'MANUAL');

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
        ]);

        $area = isset($data['service_area_id'])
            ? ServiceArea::query()->where('logistics_center_id', $center->id)->where('is_active', true)->findOrFail($data['service_area_id'])
            : null;
        try {
            $workflow->sortShipment($shipment, $center, $request->user(), $area);
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['service_area_id' => $exception->getMessage()])
                ->withInput();
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
            ->with(['sellerOrder.items', 'sellerOrder.sellerProfile.user', 'sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'waybills'])
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
