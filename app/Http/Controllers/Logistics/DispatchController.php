<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderProfile;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Services\Maps\MapDataService;
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

    public function pickups(Request $request): View
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
                                ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $center->id)))
                            ->orWhereHas('sellerOrder.sellerProfile.businessAddress', function ($address) use ($center) {
                                $address->whereExists(function ($locations) use ($center) {
                                    $locations->select(DB::raw(1))
                                        ->from('service_area_locations')
                                        ->join('service_areas', 'service_areas.id', '=', 'service_area_locations.service_area_id')
                                        ->where('service_areas.logistics_center_id', $center->id)
                                        ->where('service_areas.is_active', true)
                                        ->whereColumn('service_area_locations.province_code', 'addresses.province_code')
                                        ->whereColumn('service_area_locations.municipality_code', 'addresses.municipality_code')
                                        ->whereColumn('service_area_locations.barangay_code', 'addresses.barangay_code');
                                });
                            });
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
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->when($center, fn ($query) => $query->where('logistics_center_id', $center->id))
            ->withCount(['assignments as active_pickup_assignments_count' => fn ($query) => $query
                ->where('assignment_type', 'PICKUP')
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->orderBy('id')
            ->get();

        $workflow = app(ShipmentWorkflowService::class);
        $pickupAreaIds = $pickups->getCollection()->mapWithKeys(fn ($pickup) => [
            $pickup->shipment_id => $workflow->sellerPickupServiceArea($pickup->shipment, $center)?->id,
        ]);
        $pickupEligibleRiderIds = $pickups->getCollection()->mapWithKeys(fn ($pickup) => [
            $pickup->shipment_id => $riders
                ->filter(fn (RiderProfile $rider) =>
                    (int) $rider->active_pickup_assignments_count < ShipmentWorkflowService::MAX_ACTIVE_PICKUP_ASSIGNMENTS
                    && $workflow->riderCoversSellerPickupArea($pickup->shipment, $rider))
                ->pluck('id')
                ->values(),
        ]);

        $mapMarkers = app(MapDataService::class)->forShipments(
            $pickups->getCollection()->map->shipment->filter(),
            includeSeller: true,
            viewer: auth()->user(),
        );
        return view('Logistics.pickups.index', compact('pickups', 'riders', 'center', 'pickupAreaIds', 'pickupEligibleRiderIds', 'mapMarkers'));
    }

    public function receive(Request $request): View
    {
        $center = $this->center($request);
        $tracking = trim((string) $request->query('tracking', ''));

        $shipments = Shipment::query()
            ->where('current_status', 'PICKED_UP')
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

    public function assignments(Request $request, ShipmentWorkflowService $workflow): View
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
            ->whereIn('status', ['ACTIVE', 'WARNED'])
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

        $mapMarkers = app(MapDataService::class)->forShipments($shipments->getCollection(), true);
        return view('Logistics.assignments.index', compact('shipments', 'riders', 'center', 'eligibleRidersByShipment', 'mapMarkers'));
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

    public function assign(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
            'assignment_type' => ['required', 'string', 'in:PICKUP,DELIVERY'],
        ]);

        $rider = RiderProfile::query()->find($data['rider_profile_id']);
        if (! $rider || (int) $rider->logistics_center_id !== (int) $center->id) {
            return back()
                ->withErrors(['rider_profile_id' => 'Select an active rider from this logistics center.'])
                ->withInput();
        }
        try {
            $workflow->assignRider($shipment, $rider, $data['assignment_type'], $request->user());
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['rider_profile_id' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', 'Rider assignment created.');
    }

    public function assignPickup(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id'],
            'coverage_override' => ['nullable', 'boolean'],
        ]);

        $pickup = $shipment->pickupRequests()->whereIn('status', ['PENDING', 'APPROVED'])->latest()->firstOrFail();
        if ($pickup->status === 'PENDING') {
            $workflow->approvePickup($pickup, $request->user());
        }

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
                ->whereIn('status', ['ACTIVE', 'WARNED'])
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
            $workflow->assignRider($shipment, $rider, 'PICKUP', $request->user(), $coverageOverride);
        } catch (\RuntimeException $exception) {
            return back()
                ->withErrors(['rider_profile_id' => $exception->getMessage()])
                ->withInput();
        }

        return back()->with('status', 'Pickup rider assigned.');
    }

    public function autoAssignPickup(Request $request, Shipment $shipment, ShipmentWorkflowService $workflow): RedirectResponse
    {
        $center = $this->center($request);
        abort_unless($center, 403);
        $this->ensureShipmentBelongsToCenter($shipment, $center);

        $pickup = $shipment->pickupRequests()->whereIn('status', ['PENDING', 'APPROVED'])->latest()->firstOrFail();
        if (str_starts_with((string) $pickup->notes, 'SELLER_DROPOFF:')) {
            return back()->withErrors(['rider_profile_id' => 'Seller drop-off parcels are received at the center and cannot be auto-assigned for pickup.']);
        }

        $pickupArea = $workflow->sellerPickupServiceArea($shipment, $center);
        $rider = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->withCount(['assignments as active_pickup_assignments_count' => fn ($query) => $query
                ->where('assignment_type', 'PICKUP')
                ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])])
            ->with(['user', 'areaAssignments.serviceArea.locations'])
            ->get()
            ->filter(fn (RiderProfile $candidate): bool =>
                (int) $candidate->active_pickup_assignments_count < ShipmentWorkflowService::MAX_ACTIVE_PICKUP_ASSIGNMENTS
                && $workflow->riderCoversSellerPickupArea($shipment, $candidate))
            ->sortBy('active_pickup_assignments_count')
            ->first();

        if (! $rider) {
            return back()->withErrors(['rider_profile_id' => $pickupArea
                ? 'No eligible rider covers this pickup area and has capacity for another parcel.'
                : 'The seller pickup address is not covered by an active service area.']);
        }

        try {
            if ($pickup->status === 'PENDING') {
                $workflow->approvePickup($pickup, $request->user());
            }
            $workflow->assignRider($shipment, $rider, 'PICKUP', $request->user());
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()]);
        }

        return back()->with('status', 'Pickup auto-assigned to '.$rider->user?->name.'.');
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

        return back()->with('status', 'Parcel received at sorting center.');
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

        return back()->with('status', 'Parcel sorted successfully.');
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
        $isAssignedToCenter = (int) $shipment->logistics_center_id === (int) $center->id;
        $areaBelongsToCenter = ! $shipment->logistics_center_id
            && (int) $shipment->serviceArea?->logistics_center_id === (int) $center->id;
        $pickupAddress = $shipment->sellerOrder?->sellerProfile?->businessAddress;
        $pickupBelongsToCenter = $pickupAddress && ServiceArea::query()
            ->where('logistics_center_id', $center->id)
            ->where('is_active', true)
            ->whereHas('locations', fn ($location) => $location
                ->where('province_code', $pickupAddress->province_code)
                ->where('municipality_code', $pickupAddress->municipality_code)
                ->where('barangay_code', $pickupAddress->barangay_code))
            ->exists();

        abort_unless($isAssignedToCenter || $areaBelongsToCenter || $pickupBelongsToCenter, 403);
    }
}
