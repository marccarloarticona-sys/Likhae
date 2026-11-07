<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Admin\PlatformSetting;
use App\Models\Finance\ShippingRateChangeRequest;
use App\Models\User;
use App\Services\Finance\RegionShippingRateService;
use App\Services\Finance\ShippingRateGovernanceService;
use App\Services\Finance\ShippingRatePolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return view('logistics.shipping-rate.index', [
            'center' => $user->logisticsCenter,
            'schemaReady' => $ready,
            'policy' => $policy,
            'regionalShippingSettings' => [
                'additional_region_tier_fee' => PlatformSetting::valueOf('additional_region_tier_fee', RegionShippingRateService::DEFAULT_ADDITIONAL_TIER_FEE),
                'regional_shipping_distance_km_per_tier' => PlatformSetting::valueOf('regional_shipping_distance_km_per_tier', config('likhae.regional_shipping_distance_km_per_tier', RegionShippingRateService::DEFAULT_DISTANCE_KM_PER_TIER)),
                'regional_shipping_interisland_tier_surcharge' => PlatformSetting::valueOf('regional_shipping_interisland_tier_surcharge', config('likhae.regional_shipping_interisland_tier_surcharge', RegionShippingRateService::DEFAULT_INTERISLAND_TIER_SURCHARGE)),
                'shipping_region_tiers' => PlatformSetting::valueOf('shipping_region_tiers', ''),
            ],
            'requests' => $requests,
            'pendingRequest' => $pendingRequest,
            'reasons' => ShippingRateChangeRequest::REASONS,
            'commissionRequestsReady' => $ready
                && \Illuminate\Support\Facades\Schema::hasColumn('shipping_rate_change_requests', 'requested_rider_pool_percentage')
                && \Illuminate\Support\Facades\Schema::hasColumn('shipping_rate_change_requests', 'current_rider_pool_percentage'),
        ]);
    }

    public function updateRegionalShippingTiers(Request $request, RegionShippingRateService $regionShipping): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->isAccountType(User::TYPE_LOGISTICS) && $user->logisticsCenter, 403);

        $data = $request->validate([
            'additional_region_tier_fee' => ['required', 'decimal:0,2', 'min:0', 'max:1000000'],
            'regional_shipping_distance_km_per_tier' => ['required', 'numeric', 'min:1', 'max:1000'],
            'regional_shipping_interisland_tier_surcharge' => ['required', 'integer', 'min:0', 'max:20'],
            'shipping_region_tiers' => ['nullable', 'string', 'max:10000'],
        ]);
        $normalizedRegionTiers = $regionShipping->normalizeTierMap((string) ($data['shipping_region_tiers'] ?? ''));

        DB::transaction(function () use ($data, $normalizedRegionTiers, $user): void {
            PlatformSetting::put('additional_region_tier_fee', $data['additional_region_tier_fee'], 'decimal', $user->id);
            PlatformSetting::put('regional_shipping_distance_km_per_tier', $data['regional_shipping_distance_km_per_tier'], 'decimal', $user->id);
            PlatformSetting::put('regional_shipping_interisland_tier_surcharge', $data['regional_shipping_interisland_tier_surcharge'], 'integer', $user->id);
            PlatformSetting::put('shipping_region_tiers', $normalizedRegionTiers, 'string', $user->id);
        });

        return redirect()->route('logistics.shipping-rate')->with('status', 'Regional shipping tier settings updated.');
    }

    public function store(Request $request, ShippingRateGovernanceService $governance): RedirectResponse
    {
        $data = $request->validate([
            'requested_shipping_fee' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'requested_rider_pool_percentage' => ['nullable', 'decimal:0,4', 'min:0', 'max:100'],
            'reason_code' => ['required', Rule::in(array_keys(ShippingRateChangeRequest::REASONS))],
            'reason_details' => ['nullable', 'string', 'max:2000', 'not_regex:/<[^>]*>/'],
        ]);
        $hasFee = filled($data['requested_shipping_fee'] ?? null);
        $hasRiderShare = filled($data['requested_rider_pool_percentage'] ?? null);
        if ($hasFee === $hasRiderShare) {
            return back()->withErrors(['requested_shipping_fee' => 'Enter either a shipping fee proposal or a Rider share proposal.'])->withInput();
        }
        if ($data['reason_code'] === 'OTHER' && blank($data['reason_details'] ?? null)) {
            return back()->withErrors(['reason_details' => 'Explain the reason when you select Other.'])->withInput();
        }

        try {
            $governance->submitRequest($request->user(), $data);
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }

        return redirect()->route('logistics.shipping-rate')->with('status', $hasRiderShare
            ? 'Rider shipping share request submitted for Admin review.'
            : 'Shipping fee request submitted for Admin review.');
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
