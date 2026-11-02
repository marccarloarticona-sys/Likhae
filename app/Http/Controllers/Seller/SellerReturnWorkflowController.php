<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Services\Fulfillment\ShipmentReturnWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SellerReturnWorkflowController extends Controller
{
    public function confirm(Request $request, ShipmentReturnWorkflow $workflow, ShipmentReturnWorkflowService $returns): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        try {
            $confirmed = $returns->confirmSellerReceipt($workflow, $request->user(), $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        $message = $confirmed->seller_return_refund_request_id
            ? 'Seller receipt confirmed through the buyer return route. The original delivered shipment remains unchanged; inspect the items before refund payment.'
            : 'Seller receipt confirmed. The shipment is now marked RETURNED.';

        return back()->with('status', $message);
    }
}
