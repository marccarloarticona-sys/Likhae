<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Logistics\TransferManifest;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\TransferManifestService;
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

        return view('Rider.transfers', compact('rider', 'ready', 'manifests'));
    }

    public function show(Request $request, int $manifest): View
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);
        abort_unless($this->schema->transferReady(), 503, 'Transfer schema is not installed.');
        $manifest = TransferManifest::query()->where('assigned_driver_rider_profile_id', $rider->id)->with('originLogisticsCenter', 'destinationLogisticsCenter', 'items.transferLeg.shipment.sellerOrder.items')->findOrFail($manifest);
        return view('Rider.transfer-show', compact('rider', 'manifest'));
    }

    public function accept(Request $request, int $manifest): RedirectResponse
    {
        return $this->run($request, $manifest, fn (TransferManifest $model) => $this->manifests->driverAccept($request->user(), $model), 'Transfer manifest accepted.');
    }

    public function start(Request $request, int $manifest): RedirectResponse
    {
        return $this->run($request, $manifest, fn (TransferManifest $model) => $this->manifests->driverStart($request->user(), $model), 'Inter-hub transport started with departure custody scans.');
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
