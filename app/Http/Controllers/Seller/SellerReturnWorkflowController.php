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
            $returns->confirmSellerReceipt($workflow, $request->user(), $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Seller receipt confirmed. The shipment is now marked RETURNED.');
    }
}
