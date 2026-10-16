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
            return view('Logistics.transfers.index', [
                'center' => $center,
                'transferReady' => false,
                'outgoing' => collect(),
                'incoming' => collect(),
                'destinations' => collect(),
                'readyLegs' => collect(),
                'drivers' => collect(),
            ]);
        }

        return view('Logistics.transfers.index', [
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
            'drivers' => $this->manifests->eligibleDriversForCenter((int) $center->id),
            'readyLegs' => ShipmentTransferLeg::query()
                ->where('origin_logistics_center_id', $center->id)
                ->where('status', ShipmentTransferLeg::READY)
                ->whereHas('shipment', fn ($query) => $query->where('logistics_center_id', $center->id))
                ->whereDoesntHave('manifestItems', fn ($items) => $items->whereHas('manifest', fn ($manifest) => $manifest->whereNotIn('status', [TransferManifest::RECEIVED, TransferManifest::CANCELLED])))
                ->with('shipment')
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
        ]);
        $destination = LogisticsCenter::query()->active()->findOrFail($data['destination_logistics_center_id']);
        $driver = ! empty($data['assigned_driver_rider_profile_id'])
            ? RiderProfile::query()->findOrFail($data['assigned_driver_rider_profile_id'])
            : null;

        try {
            $manifest = $this->manifests->create($request->user(), $center, $destination, $data['notes'] ?? null, $driver);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['assigned_driver_rider_profile_id' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('logistics.transfers.show', $manifest)->with('status', 'Transfer manifest created in draft.');
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

        return view('Logistics.transfers.show', compact('center', 'manifest', 'readyLegs', 'drivers', 'isOriginCenter'));
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
            'parcel_volume_liters' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
        ]);

        try {
            $this->manifests->recordItemMeasurements(
                $request->user(),
                $manifest,
                TransferManifestItem::query()->findOrFail($item),
                (string) $data['parcel_weight_kg'],
                (string) $data['parcel_volume_liters'],
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['measurements' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Measured parcel weight and volume saved.');
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
        ]);
        return $this->runAction($request, $manifest, fn () => $this->manifests->release($request->user(), $manifest, $data['scanned_codes']), 'Manifest released with custody scans.');
    }

    public function depart(Request $request, int $manifest): RedirectResponse
    {
        $manifest = $this->loadManifest($manifest);
        $data = $request->validate([
            'scanned_codes' => ['required', 'array'],
            'scanned_codes.*' => ['required', 'string', 'max:120', 'distinct'],
        ]);
        return $this->runAction($request, $manifest, fn () => $this->manifests->depart($request->user(), $manifest, $data['scanned_codes']), 'Manifest marked in transit.');
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
        return back()->with('status', 'Manifest item receipt recorded.');
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
}
