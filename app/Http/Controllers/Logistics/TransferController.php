<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ShipmentTransferLeg;
use App\Models\Logistics\TransferManifest;
use App\Models\Logistics\TransferManifestItem;
use App\Models\Rider\RiderProfile;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\TransferManifestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class TransferController extends Controller
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly TransferManifestService $manifests,
    ) {}

    public function index(Request $request): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        if (! $this->schema->manifestReady()) {
            return view('logistics.transfers.index', [
                'center' => $center,
                'transferReady' => false,
                'outgoing' => collect(),
                'incoming' => collect(),
                'destinations' => collect(),
                'readyLegs' => collect(),
                'drivers' => collect(),
                'unavailableDrivers' => collect(),
            ]);
        }

        ['available' => $drivers, 'unavailable' => $unavailableDrivers] = $this->driverOptionsForCenter((int) $center->id);

        return view('logistics.transfers.index', [
            'center' => $center,
            'transferReady' => true,
            'outgoing' => TransferManifest::query()
                ->where('origin_logistics_center_id', $center->id)
                ->with('assignedDriver.user')
                ->withCount('items')
                ->latest()
                ->paginate(10, ['*'], 'outgoing_page'),
            'incoming' => TransferManifest::query()
                ->where('destination_logistics_center_id', $center->id)
                ->with('assignedDriver.user')
                ->withCount('items')
                ->latest()
                ->paginate(10, ['*'], 'incoming_page'),
            'destinations' => LogisticsCenter::query()->active()->whereKeyNot($center->id)->orderBy('business_name')->get(['id', 'business_name', 'code']),
            'drivers' => $drivers,
            'unavailableDrivers' => $unavailableDrivers,
            'readyLegs' => ShipmentTransferLeg::query()
                ->where('origin_logistics_center_id', $center->id)
                ->where('status', ShipmentTransferLeg::READY)
                ->whereHas('shipment', fn ($query) => $query->where('logistics_center_id', $center->id))
                ->whereDoesntHave('manifestItems', fn ($items) => $items->whereHas('manifest', fn ($manifest) => $manifest->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED])))
                ->with(['shipment', 'destinationLogisticsCenter'])
                ->orderBy('sequence_no')
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        $data = $request->validate([
            'destination_logistics_center_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'assigned_driver_rider_profile_id' => ['nullable', 'integer', 'exists:rider_profiles,id'],
            'create_with_parcels' => ['nullable', 'boolean'],
            'transfer_leg_ids' => [$request->boolean('create_with_parcels') ? 'required' : 'nullable', 'array', 'min:1'],
            'transfer_leg_ids.*' => ['required', 'integer', 'distinct', 'exists:shipment_transfer_legs,id'],
            'transfer_leg_id' => ['nullable', 'integer', 'exists:shipment_transfer_legs,id'],
        ], [
            'transfer_leg_ids.required' => 'Choose at least one parcel before preparing this transfer.',
            'transfer_leg_ids.min' => 'Choose at least one parcel before preparing this transfer.',
        ]);
        $transferLegIds = array_values(array_unique(array_merge(
            $data['transfer_leg_ids'] ?? [],
            ! empty($data['transfer_leg_id']) ? [$data['transfer_leg_id']] : [],
        )));
        $destination = LogisticsCenter::query()->active()->findOrFail($data['destination_logistics_center_id']);
        $driver = ! empty($data['assigned_driver_rider_profile_id'])
            ? RiderProfile::query()->findOrFail($data['assigned_driver_rider_profile_id'])
            : null;

        try {
            $manifest = DB::transaction(function () use ($request, $center, $destination, $data, $driver, $transferLegIds): TransferManifest {
                $manifest = $this->manifests->create($request->user(), $center, $destination, $data['notes'] ?? null, $driver);
                foreach ($transferLegIds as $transferLegId) {
                    $leg = ShipmentTransferLeg::query()->findOrFail($transferLegId);
                    $this->manifests->addLeg($request->user(), $manifest, $leg);
                }

                return $manifest;
            });
        } catch (RuntimeException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('logistics.transfers.show', $manifest)->with('status', $transferLegIds === []
            ? 'Empty transfer preparation created. Add parcels before sealing.'
            : 'Transfer prepared with '.count($transferLegIds).' '.\Illuminate\Support\Str::plural('parcel', count($transferLegIds)).'. Review measurements and assign a driver before sealing.');
    }

    public function show(Request $request, int $manifest): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        $manifest = $this->loadManifest($manifest);
        abort_unless((int) $manifest->origin_logistics_center_id === (int) $center->id
            || (int) $manifest->destination_logistics_center_id === (int) $center->id, 403);
        $isOriginCenter = (int) $manifest->origin_logistics_center_id === (int) $center->id;

        $manifest->load(['originLogisticsCenter', 'destinationLogisticsCenter', 'assignedDriver.user', 'items.transferLeg.shipment.sellerOrder.items']);
        $readyLegs = ShipmentTransferLeg::query()
            ->where('origin_logistics_center_id', $manifest->origin_logistics_center_id)
            ->where('destination_logistics_center_id', $manifest->destination_logistics_center_id)
            ->where('status', ShipmentTransferLeg::READY)
            ->whereHas('shipment', fn ($query) => $query->where('logistics_center_id', $manifest->origin_logistics_center_id))
            ->whereDoesntHave('manifestItems', fn ($items) => $items
                ->whereHas('manifest', fn ($manifestQuery) => $manifestQuery->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED])))
            ->with('shipment')
            ->orderBy('sequence_no')
            ->limit(100)
            ->get();
        $drivers = $isOriginCenter
            ? $this->manifests->eligibleDriversForCenter((int) $manifest->origin_logistics_center_id, (int) $manifest->id)
            : collect();
        $unavailableDrivers = $isOriginCenter
            ? $this->driverOptionsForCenter((int) $manifest->origin_logistics_center_id, (int) $manifest->id)['unavailable']
            : collect();

        return view('logistics.transfers.show', compact('center', 'manifest', 'readyLegs', 'drivers', 'unavailableDrivers', 'isOriginCenter'));
    }

    public function addLeg(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        $data = $request->validate(['transfer_leg_id' => ['required', 'integer', 'exists:shipment_transfer_legs,id']]);
        try {
            $this->manifests->addLeg($request->user(), $manifest, ShipmentTransferLeg::query()->findOrFail($data['transfer_leg_id']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['transfer_leg_id' => $exception->getMessage()]);
        }
        return back()->with('status', 'Transfer leg added to the manifest.');
    }

    public function recordItemMeasurements(Request $request, int $manifest, int $item): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        $data = $request->validate([
            'parcel_weight_kg' => ['required', 'numeric', 'gt:0', 'max:9999999.999', 'decimal:0,3'],
            'parcel_volume_liters' => ['nullable', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
        ]);

        try {
            $this->manifests->recordItemMeasurements(
                $request->user(),
                $manifest,
                TransferManifestItem::query()->findOrFail($item),
                (string) $data['parcel_weight_kg'],
                isset($data['parcel_volume_liters']) ? (string) $data['parcel_volume_liters'] : null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['measurements' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', isset($data['parcel_volume_liters'])
            ? 'Required parcel weight and optional volume saved.'
            : 'Parcel weight saved; volume was left blank.');
    }

    public function assignDriver(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        $data = $request->validate(['rider_profile_id' => ['required', 'integer', 'exists:rider_profiles,id']]);
        try {
            $this->manifests->assignDriver($request->user(), $manifest, RiderProfile::query()->findOrFail($data['rider_profile_id']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['rider_profile_id' => $exception->getMessage()]);
        }
        return back()->with('status', 'Approved inter-logistics driver assigned.');
    }

    public function seal(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        return $this->runAction($request, $manifest, fn () => $this->manifests->seal($request->user(), $manifest), 'Manifest sealed.');
    }

    public function release(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        $data = $request->validate([
            'scanned_codes' => ['required', 'array'],
            'scanned_codes.*' => ['required', 'string', 'max:120', 'distinct'],
            'scan_methods' => ['nullable', 'array'],
            'scan_methods.*' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);
        return $this->runAction($request, $manifest, fn () => $this->manifests->release($request->user(), $manifest, $data['scanned_codes'], $data['scan_methods'] ?? []), 'Manifest released with custody scans.');
    }

    public function releaseItem(Request $request, int $manifest, int $item): RedirectResponse|JsonResponse
    {
        $manifest = $this->loadManifest($manifest);
        $item = TransferManifestItem::query()->where('manifest_id', $manifest->id)->findOrFail($item);
        $data = $request->validate([
            'scanned_code' => ['required', 'string', 'max:120'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);

        try {
            $updated = $this->manifests->releaseItem(
                $request->user(), $manifest, $item, $data['scanned_code'], $data['scan_method'] ?? 'MANUAL',
            );
        } catch (RuntimeException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }
            return back()->withErrors(['scanned_code' => $exception->getMessage()])->withInput();
        }

        $releasedCount = $updated->items->filter(
            fn (TransferManifestItem $manifestItem): bool => $manifestItem->transferLeg?->status === ShipmentTransferLeg::RELEASED
        )->count();
        $message = $updated->status === TransferManifest::RELEASED
            ? 'Parcel released. Every parcel is handed off; the manifest is now released.'
            : 'Parcel released to the accepted transfer driver.';
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'item_id' => $item->id,
                'tracking_number' => $item->transferLeg?->shipment?->tracking_number,
                'released_count' => $releasedCount,
                'total_count' => $updated->items->count(),
                'manifest_status' => $updated->status,
            ]);
        }

        return redirect()->route('logistics.transfers.show', $manifest)->with('status', $message);
    }

    public function depart(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        $data = $request->validate([
            'scanned_codes' => ['required', 'array', 'min:1', 'max:100'],
            'scanned_codes.*' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);

        return $this->runAction($request, $manifest, fn () => $this->manifests->depart(
            $request->user(),
            $manifest,
            $data['scanned_codes'],
            strtoupper((string) ($data['scan_method'] ?? 'MANUAL')),
        ), 'Manifest marked in transit.');
    }

    public function arrive(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        return $this->runAction($request, $manifest, fn () => $this->manifests->arrive($request->user(), $manifest), 'Manifest arrival recorded; receive items individually.');
    }

    public function cancel(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->runAction($request, $manifest, fn () => $this->manifests->cancel($request->user(), $manifest, $data['reason']), 'Manifest cancelled with an audit reason.');
    }

    public function receiveItem(Request $request, int $item): RedirectResponse
    {
        abort_unless($this->schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');
        $item = TransferManifestItem::query()->findOrFail($item);
        $data = $request->validate([
            'outcome' => ['required', 'in:RECEIVED,MISSING,DAMAGED'],
            'scanned_code' => ['required_if:outcome,RECEIVED,DAMAGED', 'nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $this->manifests->receiveItem($request->user(), $item, $data['outcome'], $data['scanned_code'] ?? null, $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['outcome' => $exception->getMessage()]);
        }
        $message = match ($data['outcome']) {
            'RECEIVED' => 'Parcel received at the destination hub and moved to sorting.',
            'DAMAGED' => 'Damaged parcel recorded at the destination hub. Review it before local dispatch.',
            default => 'Missing parcel exception recorded on the manifest.',
        };

        return back()->with('status', $message);
    }

    private function runAction(Request $request, TransferManifest $manifest, callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['manifest' => $exception->getMessage()]);
        }
        return back()->with('status', $message);
    }

    private function loadManifest(int $id): TransferManifest
    {
        abort_unless($this->schema->manifestReady(), 503, 'Transfer manifest and approved rider capacity schema is not installed.');

        return TransferManifest::query()->findOrFail($id);
    }

    /** @return array{available: \Illuminate\Support\Collection<int, RiderProfile>, unavailable: \Illuminate\Support\Collection<int, RiderProfile>} */
    private function driverOptionsForCenter(int $centerId, ?int $manifestId = null): array
    {
        $available = $this->manifests->eligibleDriversForCenter($centerId, $manifestId);
        $candidates = RiderProfile::query()
            ->where('rider_profiles.logistics_center_id', $centerId)
            ->where('rider_profiles.status', 'ACTIVE')
            ->whereNotNull('rider_profiles.approved_by_user_id')
            ->whereNotNull('rider_profiles.approved_at')
            ->whereIn('rider_profiles.vehicle_type', \App\Support\RiderVehicleType::interLogisticsValues())
            ->whereHas('user', fn ($query) => $query
                ->where('account_type', \App\Models\User::TYPE_RIDER)
                ->where('status', \App\Models\User::STATUS_ACTIVE))
            ->with('user')
            ->orderBy('rider_profiles.id')
            ->get();
        $activeManifests = TransferManifest::query()
            ->whereIn('assigned_driver_rider_profile_id', $candidates->modelKeys())
            ->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED])
            ->when($manifestId !== null, fn ($query) => $query->where('id', '<>', $manifestId))
            ->get(['id', 'manifest_number', 'status', 'assigned_driver_rider_profile_id'])
            ->keyBy('assigned_driver_rider_profile_id');
        $availableIds = array_map('intval', $available->modelKeys());
        $unavailable = $candidates
            ->reject(fn (RiderProfile $driver) => in_array((int) $driver->id, $availableIds, true))
            ->each(function (RiderProfile $driver) use ($activeManifests): void {
                $manifest = $activeManifests->get($driver->id);
                $hasCapacity = (int) $driver->approved_parcel_capacity > 0
                    && (float) $driver->approved_weight_capacity_kg > 0
                    && (float) $driver->approved_volume_capacity_liters > 0;
                $reasons = [];
                if ($manifest) {
                    $reasons[] = 'Assigned to active manifest '.$manifest->manifest_number.' ('.$manifest->status.').';
                }
                if (! $hasCapacity) {
                    $reasons[] = 'Approved parcel, weight, and volume capacities are incomplete.';
                }
                if ($reasons === []) {
                    $reasons[] = 'Currently unavailable for another transfer.';
                }
                $driver->setAttribute('inter_hub_capacity_complete', $hasCapacity);
                $driver->setAttribute('inter_hub_unavailable_reason', implode(' ', $reasons));
            });

        return ['available' => $available, 'unavailable' => $unavailable];
    }
}
