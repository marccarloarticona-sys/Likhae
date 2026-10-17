<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Finance\ShippingRateChangeRequest;
use App\Models\User;
use App\Services\Finance\ShippingRateGovernanceService;
use App\Services\Finance\ShippingRatePolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class LogisticsShippingRateController extends Controller
{
    public function index(Request $request, ShippingRatePolicyService $rates): View
    {
        $user = $request->user();
        abort_unless($user?->isAccountType(User::TYPE_LOGISTICS) && $user->logisticsCenter, 403);

        $ready = $rates->schemaReady();
        $policy = $rates->currentPolicy();
        $requests = $ready
            ? ShippingRateChangeRequest::query()
                ->where('requesting_logistics_user_id', $user->id)
                ->latest('created_at')
                ->latest('id')
                ->paginate(15)
            : collect();
        $pendingRequest = $ready
            ? ShippingRateChangeRequest::query()
                ->where('requesting_logistics_user_id', $user->id)
                ->pending()
                ->latest('id')
                ->first()
            : null;

        return view('Logistics.shipping-rate.index', [
            'center' => $user->logisticsCenter,
            'schemaReady' => $ready,
            'policy' => $policy,
            'requests' => $requests,
            'pendingRequest' => $pendingRequest,
            'reasons' => ShippingRateChangeRequest::REASONS,
        ]);
    }

    public function store(Request $request, ShippingRateGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'requested_shipping_fee' => ['required', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'reason_code' => ['required', Rule::in(array_keys(ShippingRateChangeRequest::REASONS))],
            'reason_details' => ['nullable', 'string', 'max:2000', 'not_regex:/<[^>]*>/'],
        ]);
        if ($data['reason_code'] === 'OTHER' && blank($data['reason_details'] ?? null)) {
            return back()->withErrors(['reason_details' => 'Explain the reason when you select Other.'])->withInput();
        }

        try {
            $governance->submitRequest($request->user(), $data);
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('logistics.shipping-rate')->with('status', 'Shipping fee request submitted for Admin review.');
    }

    public function cancel(Request $request, ShippingRateChangeRequest $shippingRateRequest, ShippingRateGovernanceService $governance): RedirectResponse
    {
        try {
            $governance->cancelRequest($request->user(), $shippingRateRequest);
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('logistics.shipping-rate')->with('status', 'Your pending shipping fee request was cancelled.');
    }
}
