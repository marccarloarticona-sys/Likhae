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

        $relations = [
            'shipment.sellerOrder.sellerProfile',
            'originalDeliveryAssignment.riderProfile.user',
            'returnRider.user',
            'initiatedBy', 'centerHandoffBy', 'centerReceivedBy', 'returnRiderAssignedBy',
            'returnRiderPickedUpBy', 'sellerHandoffBy', 'sellerReceivedBy',
        ];
        $returnEnhancementsReady = app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class)->shipmentReturnEnhancementsReady();
        if ($returnEnhancementsReady) {
            $relations[] = 'pendingReturnRider.user';
            $relations[] = 'handoffAuthorizedBy';
            $relations[] = 'returnExceptionRecordedBy';
        }
        if (app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class)->transferReady()) {
            $relations[] = 'shipment.transferLegs.destinationLogisticsCenter';
        }

        $workflows = ShipmentReturnWorkflow::query()
            ->where('logistics_center_id', $center->id)
            ->with($relations)
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
            'returnEnhancementsReady' => $returnEnhancementsReady,
            'transferReady' => app(\App\Services\Fulfillment\LogisticsSchemaReadiness::class)->transferReady(),
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

    public function receiveBatch(Request $request, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $data = $request->validate([
            'tracking_numbers' => ['required', 'string', 'max:10000'],
            'scan_method' => ['required', 'in:QR,BARCODE,MANUAL'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $trackingNumbers = array_values(array_filter(array_map(
            static fn (string $trackingNumber): string => trim($trackingNumber),
            preg_split('/[\r\n,;]+/', $data['tracking_numbers']) ?: [],
        ), static fn (string $trackingNumber): bool => $trackingNumber !== ''));
        if ($trackingNumbers === [] || count($trackingNumbers) > 50) {
            return back()->withErrors(['tracking_numbers' => 'Enter between 1 and 50 tracking numbers, one per line.'])->withInput();
        }

        $results = $returns->receiveBatchAtCenter(
            $request->user(),
            $trackingNumbers,
            $data['scan_method'],
            $data['notes'] ?? null,
        );
        $received = count(array_filter($results, static fn (array $result): bool => $result['success']));
        $failed = count($results) - $received;
        $message = $failed === 0
            ? "Confirmed receipt for {$received} returned ".\Illuminate\Support\Str::plural('parcel', $received).'.'
            : "Batch receiving completed with {$received} confirmed and {$failed} not received. Review each scan result.";

        return back()->with('returnBatchResults', $results)->with('status', $message);
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

    public function prepareTransfer(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        try {
            $returns->prepareCrossCenterTransfer($workflow, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()]);
        }

        return back()->with('status', 'Return transfer is ready to join an authorized inter-center manifest.');
    }

    public function requestHandoff(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $returns->requestReturnRiderHandoff($workflow, $request->user(), (int) $data['rider_profile_id'], $data['reason']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['handoff' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Handoff is pending scans from both riders. The current rider remains the custodian until both scans are confirmed.');
    }

    public function cancelHandoff(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $returns->cancelReturnRiderHandoff($workflow, $request->user(), $data['reason']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['handoff' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Incomplete rider handoff stopped. The existing rider remains the custodian and the return is in exception review.');
    }

    public function resolveException(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $data = $request->validate(['resolution_notes' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $returns->resolveReturnException($workflow, $request->user(), $data['resolution_notes']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['exception' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Return exception resolved and the parcel returned to the appropriate Logistics step.');
    }
}
