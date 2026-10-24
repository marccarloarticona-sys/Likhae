<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\PlatformSetting;
use App\Models\Finance\ShippingRateChangeRequest;
use App\Models\User;
use App\Services\Finance\RegionShippingRateService;
use App\Services\Finance\ShippingRateGovernanceService;
use App\Services\Finance\ShippingRatePolicyService;
use App\Support\FinancialRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $tab = (string) $request->query('tab', 'requests');
        if (! in_array($tab, ['requests', 'transfer-commission', 'regional-tiers'], true)) {
            $tab = 'requests';
        }
        $requests = $ready && $tab === 'requests'
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
            'tab' => $tab,
            'shippingSettings' => [
                'transfer_commission_type' => PlatformSetting::valueOf('transfer_commission_type', 'PERCENT'),
                'transfer_commission_value' => PlatformSetting::valueOf('transfer_commission_value', 0),
                'additional_region_tier_fee' => PlatformSetting::valueOf('additional_region_tier_fee', RegionShippingRateService::DEFAULT_ADDITIONAL_TIER_FEE),
                'shipping_region_tiers' => PlatformSetting::valueOf('shipping_region_tiers', ''),
            ],
        ]);
    }

    public function updateTransferCommission(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'transfer_commission_type' => ['required', Rule::in(['PERCENT', 'FIXED'])],
            'transfer_commission_value' => ['required', 'numeric', 'min:0', 'max:10000'],
        ]);

        if ($data['transfer_commission_type'] === 'PERCENT' && (float) $data['transfer_commission_value'] > 100) {
            return back()
                ->withErrors(['transfer_commission_value' => 'The transfer commission percentage cannot exceed 100%.'])
                ->withInput();
        }

        PlatformSetting::put('transfer_commission_type', $data['transfer_commission_type'], 'string', $request->user()->id);
        PlatformSetting::put('transfer_commission_value', $data['transfer_commission_value'], 'decimal', $request->user()->id);

        return redirect()->route('admin.shipping-rate-requests', ['tab' => 'transfer-commission'])
            ->with('status', 'Transfer commission settings updated.');
    }

    public function updateRegionalShippingTiers(Request $request, RegionShippingRateService $regionShipping): RedirectResponse
    {
        $data = $request->validate([
            'additional_region_tier_fee' => ['required', 'decimal:0,2', 'min:0', 'max:1000000'],
            'shipping_region_tiers' => ['nullable', 'string', 'max:10000'],
        ]);

        $normalizedRegionTiers = $regionShipping->normalizeTierMap((string) ($data['shipping_region_tiers'] ?? ''));
        PlatformSetting::put('additional_region_tier_fee', $data['additional_region_tier_fee'], 'decimal', $request->user()->id);
        PlatformSetting::put('shipping_region_tiers', $normalizedRegionTiers, 'string', $request->user()->id);

        return redirect()->route('admin.shipping-rate-requests', ['tab' => 'regional-tiers'])
            ->with('status', 'Regional shipping tier settings updated.');
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
