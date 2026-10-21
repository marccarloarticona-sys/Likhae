<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Finance\ShippingRateChangeRequest;
use App\Models\User;
use App\Services\Finance\ShippingRateGovernanceService;
use App\Services\Finance\ShippingRatePolicyService;
use App\Support\FinancialRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class AdminShippingRateController extends Controller
{
    public function index(Request $request, ShippingRatePolicyService $rates): View
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_ADMIN), 403);

        $ready = $rates->schemaReady();
        $status = strtoupper((string) $request->query('status', 'PENDING'));
        if (! in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', 'SUPERSEDED', 'ALL'], true)) {
            $status = 'PENDING';
        }
        $requests = $ready
            ? ShippingRateChangeRequest::query()
                ->with('requester.logisticsCenter', 'reviewer')
                ->when($status !== 'ALL', fn ($query) => $query->where('status', $status))
                ->latest('created_at')
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
            : collect();

        return view('Admin.shipping-rate-requests.index', [
            'schemaReady' => $ready,
            'policy' => $rates->currentPolicy(),
            'requests' => $requests,
            'status' => $status,
            'currentVersion' => $ready ? $rates->currentVersion() : null,
        ]);
    }

    public function show(Request $request, ShippingRateChangeRequest $shippingRateRequest, ShippingRatePolicyService $rates): View
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_ADMIN), 403);
        abort_unless($rates->schemaReady(), 503);

        $shippingRateRequest->load('requester.logisticsCenter', 'reviewer', 'rateVersion');
        $policy = $rates->currentPolicy();
        $isStale = $policy['initialized']
            && FinancialRules::cents($shippingRateRequest->current_shipping_fee)
                !== FinancialRules::cents($policy['shipping_fee'])
            || ($policy['initialized'] && $shippingRateRequest->requested_rider_pool_percentage !== null
                && (float) $shippingRateRequest->current_rider_pool_percentage !== (float) $policy['rider_pool_percentage']);
        $history = ShippingRateChangeRequest::query()
            ->with('reviewer')
            ->where('requesting_logistics_user_id', $shippingRateRequest->requesting_logistics_user_id)
            ->where('id', '<>', $shippingRateRequest->id)
            ->latest('created_at')
            ->get();

        return view('Admin.shipping-rate-requests.show', [
            'shippingRateRequest' => $shippingRateRequest,
            'history' => $history,
            'policy' => $policy,
            'isStale' => $isStale,
        ]);
    }

    public function initializeGlobalRate(Request $request, ShippingRateGovernanceService $governance): RedirectResponse
    {
        try {
            $governance->initializeGlobalRate($request->user());
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('admin.shipping-rate-requests')->with('status', 'The current global shipping rate was initialized as an approved policy version.');
    }

    public function updateCommissionSplit(Request $request, ShippingRateGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'logistics_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $version = $governance->updateCommissionSplit($request->user(), $data['logistics_percentage']);
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('admin.shipping-rate-requests')->with(
            'status',
            'Shipping split updated: Rider '.number_format((float) $version->rider_pool_percentage, 2).'% / Logistics '.number_format((float) $version->logistics_percentage, 2).'%.',
        );
    }

    public function approve(Request $request, ShippingRateChangeRequest $shippingRateRequest, ShippingRateGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'admin_decision_reason' => ['nullable', 'string', 'max:2000', 'not_regex:/<[^>]*>/'],
            'confirm_stale_baseline' => ['sometimes', 'accepted'],
            'expected_active_version_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $governance->approve(
                $request->user(),
                $shippingRateRequest,
                $data['admin_decision_reason'] ?? null,
                $request->boolean('confirm_stale_baseline'),
                (int) $data['expected_active_version_id'],
            );
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('admin.shipping-rate-requests.show', $shippingRateRequest)->with('status', 'The exact requested fee was approved and is effective now.');
    }

    public function reject(Request $request, ShippingRateChangeRequest $shippingRateRequest, ShippingRateGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'admin_decision_reason' => ['required', 'string', 'min:3', 'max:2000', 'not_regex:/<[^>]*>/'],
        ]);

        try {
            $governance->reject($request->user(), $shippingRateRequest, $data['admin_decision_reason']);
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('admin.shipping-rate-requests.show', $shippingRateRequest)->with('status', 'The request was rejected. The current global fee remains unchanged.');
    }
}
