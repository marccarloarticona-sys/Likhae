<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Services\Fulfillment\ShipmentReturnWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class RiderReturnWorkflowController extends Controller
{
    public function index(Request $request, ShipmentReturnWorkflowService $returns): View
    {
        abort_unless($returns->available(), 503, 'Return-to-seller workflow is unavailable until its additive database migration is installed.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        $workflows = ShipmentReturnWorkflow::query()
            ->whereIn('status', ShipmentReturnWorkflow::OPEN_STATUSES)
            ->where(function ($query) use ($rider): void {
                $query->whereHas('originalDeliveryAssignment', fn ($assignment) => $assignment->where('rider_profile_id', $rider->id))
                    ->orWhere('return_rider_profile_id', $rider->id);
            })
            ->with([
                'shipment.sellerOrder.order.address',
                'shipment.sellerOrder.sellerProfile.businessAddress',
                'originalDeliveryAssignment', 'returnRider.user', 'logisticsCenter.address',
            ])
            ->latest('updated_at')
            ->paginate(20);

        return view('rider.returns.index', [
            'active' => 'returns',
            'rider' => $rider,
            'workflows' => $workflows,
        ]);
    }

    public function centerHandoff(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        return $this->transition($request, $workflow, $returns, 'center_handoff');
    }

    public function accept(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        try {
            $returns->acceptAsReturnRider($workflow, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()]);
        }

        return back()->with('status', 'Return assignment accepted. Scan the parcel when you collect it from the center.');
    }

    public function pickup(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        return $this->transition($request, $workflow, $returns, 'pickup');
    }

    public function sellerHandoff(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        return $this->transition($request, $workflow, $returns, 'seller_handoff');
    }

    private function transition(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns, string $step): RedirectResponse
    {
        $data = $request->validate([
            'scan_method' => ['required', 'in:QR,BARCODE,MANUAL'],
            'scanned_code' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            match ($step) {
                'center_handoff' => $returns->handoffToCenterAsOriginalRider($workflow, $request->user(), $data['scan_method'], $data['scanned_code'], $data['notes'] ?? null),
                'pickup' => $returns->pickupFromCenterAsReturnRider($workflow, $request->user(), $data['scan_method'], $data['scanned_code'], $data['notes'] ?? null),
                'seller_handoff' => $returns->handoffToSellerAsReturnRider($workflow, $request->user(), $data['scan_method'], $data['scanned_code'], $data['notes'] ?? null),
                default => throw new RuntimeException('Unsupported return custody action.'),
            };
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Return custody scan recorded.');
    }
}
