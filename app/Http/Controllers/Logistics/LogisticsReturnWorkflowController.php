<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Rider\RiderProfile;
use App\Services\Fulfillment\ShipmentReturnWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class LogisticsReturnWorkflowController extends Controller
{
    public function index(Request $request, ShipmentReturnWorkflowService $returns): View
    {
        abort_unless($returns->available(), 503, 'Return-to-seller workflow is unavailable until its additive database migration is installed.');
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);

        $workflows = ShipmentReturnWorkflow::query()
            ->where('logistics_center_id', $center->id)
            ->with([
                'shipment.sellerOrder.sellerProfile',
                'originalDeliveryAssignment.riderProfile.user',
                'returnRider.user',
                'initiatedBy', 'centerHandoffBy', 'centerReceivedBy', 'returnRiderAssignedBy',
                'returnRiderPickedUpBy', 'sellerHandoffBy', 'sellerReceivedBy',
            ])
            ->latest('updated_at')
            ->paginate(20);
        $workflowCounts = ShipmentReturnWorkflow::query()
            ->where('logistics_center_id', $center->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $riders = RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->active()
            ->whereIn('vehicle_type', \App\Support\RiderVehicleType::localValues())
            ->with('user')
            ->orderBy('id')
            ->get();

        return view('logistics.returns.index', [
            'active' => 'returns',
            'center' => $center,
            'workflows' => $workflows,
            'workflowCounts' => $workflowCounts,
            'riders' => $riders,
        ]);
    }

    public function receive(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $data = $request->validate([
            'scan_method' => ['required', 'in:QR,BARCODE,MANUAL'],
            'scanned_code' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $returns->receiveAtCenter($workflow, $request->user(), $data['scan_method'], $data['scanned_code'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Sorting center receipt was scanned and recorded.');
    }

    public function assign(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $data = $request->validate(['rider_profile_id' => ['required', 'integer']]);

        try {
            $returns->assignReturnRider($workflow, $request->user(), (int) $data['rider_profile_id']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Return rider assigned. The rider must accept before collecting the parcel.');
    }
}
