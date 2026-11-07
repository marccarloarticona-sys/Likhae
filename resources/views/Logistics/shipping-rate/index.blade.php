@extends('layouts.logistics.app')
@section('title', 'Shipping Rate')
@section('content')
<div class="mx-auto grid max-w-5xl gap-5">
    <section class="rounded-xl border border-line bg-surface p-5 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-primary">Global platform policy</span>
                <h1 class="mt-1 text-2xl font-semibold text-ink">Shipping Rate</h1>
                <p class="mt-1 text-sm text-muted">Logistics can request a shipping fee or Rider share change. Admin approval is required before a new policy takes effect.</p>
            </div>
            <span class="rounded-full bg-success/10 px-3 py-2 text-xs font-semibold text-success">{{ $pendingRequest ? 'Pending Admin Review' : 'GLOBAL' }}</span>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Current Approved Shipping Fee</small><h2 class="mt-1 text-2xl font-semibold text-ink">₱{{ number_format((float) $policy['shipping_fee'], 2) }}</h2>@unless($policy['initialized'])<small class="mt-1 block text-muted">Temporary config fallback until Admin initializes rate history</small>@endunless</div>
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Rider Pool</small><h2 class="mt-1 text-2xl font-semibold text-ink">{{ number_format((float) $policy['rider_pool_percentage'], 2) }}%</h2></div>
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Logistics Allocation</small><h2 class="mt-1 text-2xl font-semibold text-ink">{{ number_format((float) $policy['logistics_percentage'], 2) }}%</h2></div>
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Requested Change</small><h2 class="mt-1 text-base font-semibold text-ink">{{ $pendingRequest ? ($pendingRequest->requested_rider_pool_percentage !== null ? "Rider ".number_format((float) $pendingRequest->requested_rider_pool_percentage, 2)."%" : "PHP ".number_format((float) $pendingRequest->requested_shipping_fee, 2))." · Pending" : "None" }}</h2></div>
        </div>
    </section>

    @if(session('status'))<div role="status" class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

    <section class="rounded-xl border border-line bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="regional-tiers-title">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-wider text-primary">Shipping settings</span>
            <h2 id="regional-tiers-title" class="mt-1 text-xl font-semibold text-ink">Automatic regional shipping estimates</h2>
            <p class="mt-1 text-sm text-muted">Checkout estimates each seller parcel from the saved business and delivery regions. No route entry is needed for normal checkout.</p>
        </div>

        <form method="POST" action="{{ route('logistics.shipping-rate.regional-tiers.update') }}" class="mt-5 grid gap-4 sm:grid-cols-2">
            @csrf
            <label class="text-sm font-medium text-ink">
                Estimated add-on per tier (PHP)
                <input type="number" name="additional_region_tier_fee" min="0" max="1000000" step="0.01" value="{{ old('additional_region_tier_fee', $regionalShippingSettings['additional_region_tier_fee']) }}" required class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink">
                <small class="mt-1 block font-normal text-muted">Multiplied by the calculated distance tier and added to the base fee.</small>
                @error('additional_region_tier_fee')<small class="mt-1 block text-red-700">{{ $message }}</small>@enderror
            </label>

            <label class="text-sm font-medium text-ink">
                Kilometers per distance tier
                <input type="number" name="regional_shipping_distance_km_per_tier" min="1" max="1000" step="1" value="{{ old('regional_shipping_distance_km_per_tier', $regionalShippingSettings['regional_shipping_distance_km_per_tier']) }}" required class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink">
                <small class="mt-1 block font-normal text-muted">The default estimate adds one tier per 100 km between regional centers.</small>
                @error('regional_shipping_distance_km_per_tier')<small class="mt-1 block text-red-700">{{ $message }}</small>@enderror
            </label>

            <label class="text-sm font-medium text-ink">
                Extra tiers for inter-island routes
                <input type="number" name="regional_shipping_interisland_tier_surcharge" min="0" max="20" step="1" value="{{ old('regional_shipping_interisland_tier_surcharge', $regionalShippingSettings['regional_shipping_interisland_tier_surcharge']) }}" required class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink">
                <small class="mt-1 block font-normal text-muted">Added when a route crosses the Luzon, Visayas, Mindanao, or MIMAROPA island groups.</small>
                @error('regional_shipping_interisland_tier_surcharge')<small class="mt-1 block text-red-700">{{ $message }}</small>@enderror
            </label>

            <label class="text-sm font-medium text-ink sm:col-span-2">
                Optional route overrides
                <textarea name="shipping_region_tiers" rows="6" maxlength="10000" spellcheck="false" placeholder="National Capital Region | Central Luzon | 1&#10;Central Luzon | CALABARZON | 2" class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 font-mono text-sm text-ink">{{ old('shipping_region_tiers', $regionalShippingSettings['shipping_region_tiers']) }}</textarea>
                <small class="mt-1 block font-normal text-muted">Optional: one route per line using Origin Region | Destination Region | Tier. Each entry applies in that direction only; add a reversed route separately when needed.</small>
                @error('shipping_region_tiers')<small class="mt-1 block text-red-700">{{ $message }}</small>@enderror
            </label>

            <div class="rounded-lg border border-line bg-page-secondary p-4 text-sm text-muted sm:col-span-2">
                <strong class="text-ink">How checkout estimates work</strong>
                <p class="mt-1">Same-region routes use the base fee. Other routes use approximate distance between region centers, then add the configured inter-island tiers where needed. The IV-A reference routes keep their specified PHP 5, PHP 10, and PHP 25 regional add-ons unless an override is entered.</p>
                <p class="mt-2">These are checkout estimates, not official courier rates. Changes apply to new checkouts; saved order snapshots remain unchanged.</p>
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="min-h-10 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white hover:opacity-90">Save shipping tiers</button>
            </div>
        </form>
    </section>

    @if(! $schemaReady)
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm"><strong class="text-ink">Rate requests are not available yet.</strong><p class="mt-2 text-sm text-muted">The reviewed migration must be executed separately before request history can be stored. Checkout continues to use the current ₱50 config fallback.</p></section>
    @elseif(! $policy['initialized'])
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm"><strong class="text-ink">Admin initialization required.</strong><p class="mt-2 text-sm text-muted">An Admin must create the first persistent GLOBAL rate version before Logistics can submit a change. Checkout remains at the ₱50 fallback until then.</p></section>
    @elseif($pendingRequest)
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><h2 class="text-lg font-semibold text-ink">Pending request</h2><p class="mt-1 text-sm text-ink">@if($pendingRequest->requested_rider_pool_percentage !== null)Rider share: {{ number_format((float) $pendingRequest->current_rider_pool_percentage, 2) }}% to {{ number_format((float) $pendingRequest->requested_rider_pool_percentage, 2) }}% (Logistics receives the remainder)@else Current: PHP {{ number_format((float) $pendingRequest->current_shipping_fee, 2) }} | Requested: PHP {{ number_format((float) $pendingRequest->requested_shipping_fee, 2) }} | {{ $pendingRequest->change_type }}@endif</p><p class="mt-2 text-sm text-muted">{{ $reasons[$pendingRequest->reason_code] ?? $pendingRequest->reason_code }}{{ $pendingRequest->reason_details ? ': '.$pendingRequest->reason_details : '' }}</p></div>
                <form method="POST" action="{{ route('logistics.shipping-rate-requests.cancel', $pendingRequest) }}" onsubmit="return confirm('Cancel this pending shipping rate request?')">@csrf<button type="submit" class="min-h-10 rounded-lg border border-line px-4 py-2 text-xs font-semibold text-ink hover:bg-page-secondary">Cancel Pending Request</button></form>
            </div>
        </section>
    @else
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-ink">Request Shipping Fee or Split Change</h2>
            <p class="mt-1 text-sm text-muted">Enter one proposal. A requested Rider share sets the Logistics share to the remaining percentage. The active policy remains in place until Admin approves.</p>
            <form method="POST" action="{{ route('logistics.shipping-rate-requests.store') }}" class="mt-4 grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @csrf
                <label class="text-sm font-medium text-ink">Current Shipping Fee<input type="text" value="₱{{ number_format((float) $policy['shipping_fee'], 2) }}" readonly class="mt-1 block w-full rounded-lg border border-line bg-page-secondary px-3 py-2 text-ink"></label>
                <label class="text-sm font-medium text-ink">Requested Shipping Fee (optional)<input type="number" name="requested_shipping_fee" min="0.01" max="1000000" step="0.01" value="{{ old('requested_shipping_fee') }}" class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"></label>
                @if($commissionRequestsReady)
                    <label class="text-sm font-medium text-ink">Requested Rider Share % (optional)<input type="number" name="requested_rider_pool_percentage" min="0" max="100" step="0.0001" value="{{ old('requested_rider_pool_percentage') }}" class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"><small class="mt-1 block font-normal text-muted">Logistics receives the remaining share.</small></label>
                @else
                    <p class="text-sm text-muted sm:col-span-2 xl:col-span-3">Rider share requests will be available after the commission request migration is applied.</p>
                @endif
                <label class="text-sm font-medium text-ink">Reason category<select name="reason_code" required class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"><option value="">Select a reason</option>@foreach($reasons as $code => $label)<option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm font-medium text-ink sm:col-span-2 xl:col-span-3">Supporting reason / operational note<textarea name="reason_details" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink">{{ old('reason_details') }}</textarea><small class="mt-1 block font-normal text-muted">Required when selecting Other. HTML is not accepted.</small></label>
                <div><button type="submit" class="min-h-10 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white hover:opacity-90">Submit for Admin Approval</button></div>
            </form>
        </section>
    @endif

    <section class="rounded-xl border border-line bg-surface p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-ink">Request History</h2>
        <div class="mt-3 overflow-x-auto"><table class="w-full min-w-[720px] text-left text-xs"><thead><tr class="border-b border-line text-muted"><th class="p-3">Requested</th><th class="p-3">Current</th><th class="p-3">Requested fee or split</th><th class="p-3">Change</th><th class="p-3">Reason</th><th class="p-3">Status</th><th class="p-3">Decision</th></tr></thead><tbody>
        @forelse($requests as $rateRequest)
            <tr class="border-b border-line text-ink"><td class="p-3">{{ $rateRequest->created_at?->format('M j, Y g:i A') }}</td><td class="p-3">@if($rateRequest->requested_rider_pool_percentage !== null)Rider {{ number_format((float) $rateRequest->current_rider_pool_percentage, 2) }}% / Logistics {{ number_format(100 - (float) $rateRequest->current_rider_pool_percentage, 2) }}%@else PHP {{ number_format((float) $rateRequest->current_shipping_fee, 2) }}@endif</td><td class="p-3">@if($rateRequest->requested_rider_pool_percentage !== null)Rider {{ number_format((float) $rateRequest->requested_rider_pool_percentage, 2) }}% / Logistics {{ number_format(100 - (float) $rateRequest->requested_rider_pool_percentage, 2) }}%@else PHP {{ number_format((float) $rateRequest->requested_shipping_fee, 2) }}@endif</td><td class="p-3">{{ $rateRequest->requested_rider_pool_percentage !== null ? "Rider share change" : $rateRequest->change_type." · ".number_format((float) $rateRequest->change_percentage, 2)."%" }}</td><td class="p-3">{{ $reasons[$rateRequest->reason_code] ?? $rateRequest->reason_code }}</td><td class="p-3">{{ str($rateRequest->status)->headline() }}</td><td class="p-3">{{ $rateRequest->admin_decision_reason ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="7" class="p-4 text-muted">No shipping-rate requests yet.</td></tr>
        @endforelse
        </tbody></table></div>
        @if(method_exists($requests, 'links'))<div class="mt-3">{{ $requests->links() }}</div>@endif
    </section>

    <p class="text-xs text-muted">The base fee applies to same-region delivery. Every supported region pair receives an automatic distance estimate; optional route overrides are available for special cases.</p>
</div>
@endsection
