<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Logistics\TransferManifest;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\TransferManifestService;
use App\Services\Maps\MapDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class RiderTransferController extends Controller
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly TransferManifestService $manifests,
    ) {}

    public function index(Request $request): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        $ready = $this->schema->transferReady();
        $manifests = $ready
            ? TransferManifest::query()->where('assigned_driver_rider_profile_id', $rider->id)->with('originLogisticsCenter', 'destinationLogisticsCenter')->latest()->paginate(20)
            : collect();

        return view('rider.transfers', compact('rider', 'ready', 'manifests'));
    }

    public function show(Request $request, int $manifest): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        abort_unless($this->schema->transferReady(), 503, 'Transfer schema is not installed.');
        $manifest = TransferManifest::query()->where('assigned_driver_rider_profile_id', $rider->id)->with('originLogisticsCenter.address', 'destinationLogisticsCenter.address', 'items.transferLeg.shipment.sellerOrder.items')->findOrFail($manifest);
        $mapMarkers = [];
        $mapRouteReady = false;
        if (in_array($manifest->status, [TransferManifest::RELEASED, TransferManifest::IN_TRANSIT], true)) {
            $mapData = app(MapDataService::class);
            $originMarker = $mapData->centerMarker($manifest->originLogisticsCenter);
            $destinationMarker = $mapData->centerMarker($manifest->destinationLogisticsCenter);
            $mapRouteReady = $destinationMarker !== null;
            $routeStatus = $manifest->status === TransferManifest::IN_TRANSIT
                ? TransferManifest::IN_TRANSIT
                : TransferManifest::RELEASED;
            $routeGroupId = 'transfer-manifest-'.$manifest->id;

            foreach ([
                ['marker' => $originMarker, 'role' => 'origin', 'sequence' => 1, 'name' => $manifest->originLogisticsCenter?->business_name ?: 'Origin logistics center'],
                ['marker' => $destinationMarker, 'role' => 'destination', 'sequence' => 2, 'name' => $manifest->destinationLogisticsCenter?->business_name ?: 'Destination logistics center'],
            ] as $stop) {
                if (! $stop['marker']) {
                    continue;
                }

                $marker = $stop['marker'];
                $marker['id'] = $routeGroupId.'-'.$stop['role'];
                $marker['shipment_id'] = $routeGroupId;
                $marker['center_role'] = $stop['role'];
                $marker['transfer_leg_id'] = $manifest->id;
                $marker['transfer_status'] = $routeStatus;
                $marker['tracking_status'] = 'SORTED';
                $marker['sequence_no'] = $stop['sequence'];
                $marker['label'] = $stop['role'] === 'origin' ? 'Origin logistics center' : 'Destination logistics center';
                $marker['title'] = $stop['name'];
                $marker['target_kind'] = $stop['role'] === 'destination' ? 'destination' : 'origin';

                if ($stop['role'] === 'destination') {
                    $marker['navigation_destination'] = [
                        'name' => $stop['name'],
                        'address' => $marker['popup'] ?: 'Saved hub address pin',
                    ];
                }

                $mapMarkers[] = $marker;
            }
        }

        return view('rider.transfer-show', compact('rider', 'manifest', 'mapMarkers', 'mapRouteReady'));
    }

    public function accept(Request $request, int $manifest): RedirectResponse
    {
        return $this->run($request, $manifest, fn (TransferManifest $model) => $this->manifests->driverAccept($request->user(), $model), 'Transfer manifest accepted.');
    }

    public function decline(Request $request, int $manifest): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        abort_unless($this->schema->transferReady(), 503, 'Transfer schema is not installed.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        try {
            $model = TransferManifest::query()
                ->where('assigned_driver_rider_profile_id', $rider->id)
                ->findOrFail($manifest);
            $this->manifests->driverDecline($request->user(), $model, $data['reason']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['manifest' => $exception->getMessage()]);
        }

        return redirect()->route('rider.transfers')
            ->with('status', 'Transfer manifest declined and returned to the origin hub dispatch queue.');
    }

    public function start(Request $request, int $manifest): RedirectResponse
    {
        return $this->run($request, $manifest, fn (TransferManifest $model) => $this->manifests->driverStart(
            $request->user(),
            $model,
        ), 'Inter-hub transport started.');
    }

    private function run(Request $request, int $id, callable $action, string $message): RedirectResponse
    {
        abort_unless($this->schema->transferReady(), 503, 'Transfer schema is not installed.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        try {
            $manifest = TransferManifest::query()->where('assigned_driver_rider_profile_id', $rider->id)->findOrFail($id);
            $action($manifest);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['manifest' => $exception->getMessage()]);
        }
        return back()->with('status', $message);
    }
}
