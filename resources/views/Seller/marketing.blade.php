@extends('layouts.seller.app')

@section('title', 'Marketing Center')
@section('active', 'marketing')
@section('subtitle', 'Plan store campaigns and review their performance.')

@section('content')
<div class="sl-page sl-marketing-center">
    @if(session('status'))<div class="sl-compliance-feedback" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-compliance-feedback is-warning" role="alert">{{ $errors->first() }}</div>@endif

    <div class="sl-page-toolbar">
        <div><span class="sl-eyebrow">Growth Tools</span><h2>Marketing Center</h2><p>Manage product pricing campaigns and store offers in one place.</p></div>
        @if($section === 'vouchers')
            <button type="button" class="sl-btn sl-btn-primary" data-modal-open="campaignModal"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Create Voucher</button>
        @endif
    </div>

    <nav class="sl-marketing-tabs" aria-label="Marketing sections">
        @foreach(['overview' => 'Overview', 'product-discounts' => 'Product Discounts', 'flash-sales' => 'Flash Sales', 'vouchers' => 'Vouchers', 'bundle-deals' => 'Bundle Deals', 'buy-x-get-y' => 'Buy X Get Y'] as $key => $label)
            <a href="{{ route('seller.marketing', ['section' => $key]) }}" @if($section === $key) class="is-active" aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if($section === 'product-discounts')
        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>Product and variant discounts</h3><p>Set regular or scheduled fixed amount and percentage reductions. Flash Sale takes priority when both base prices are active; the discounts are never added together.</p></div></div>
            <div class="sl-compliance-feedback is-warning" role="note">Keep every discounted price at PHP 0.01 or higher. Seller vouchers and bundle deals cannot overlap with a product discount.</div>
            @if($eligibleProducts->isEmpty())
                <p class="sl-empty-state">No active products are available for discounts.</p>
            @else
                @foreach($eligibleProducts as $product)
                    @php
                        $configuredDiscounts = $product->variants->filter(fn ($variant) => $variant->resolved_discount_type !== 'none' && (float) $variant->discount_value > 0);
                        $discountStatus = $configuredDiscounts->isEmpty() ? 'Not configured'
                            : (! (bool) ($product->discounts_enabled ?? true) ? 'Paused'
                                : ($product->discount_starts_at?->isFuture() ? 'Scheduled'
                                    : ($product->discount_ends_at?->isPast() ? 'Ended' : 'Active')));
                    @endphp
                    <article class="sl-marketing-flash-product" data-discount-product data-search="{{ mb_strtolower($product->name) }}">
                        <header><div><h3>{{ $product->name }}</h3><p>{{ $product->variants->count() }} active variant{{ $product->variants->count() === 1 ? '' : 's' }}</p></div><span class="sl-status {{ $discountStatus === 'Active' ? 'is-success' : ($discountStatus === 'Scheduled' ? 'is-info' : ($discountStatus === 'Ended' ? 'is-warning' : 'is-neutral')) }}">{{ $discountStatus }}</span></header>
                        @php
                            $otherOffers = collect($promotionConflicts['products'][$product->id] ?? [])->reject(fn ($conflict) => in_array($conflict['kind'], ['discount', 'flash_sale'], true));
                        @endphp
                        @if($otherOffers->isNotEmpty())
                            <div class="sl-compliance-feedback is-warning sl-promotion-conflict" role="status"><strong>Existing offers affect this product</strong><ul>@foreach($otherOffers as $conflict)<li>{{ $conflict['label'] }} · {{ $conflict['status'] }}</li>@endforeach</ul><p>Resolve Seller vouchers or bundle deals before saving overlapping discounts.</p></div>
                        @endif
                        <form method="POST" action="{{ route('seller.marketing.product-discounts.update', ['product' => $product, 'section' => 'product-discounts']) }}" class="sl-form-section">@csrf @method('PATCH')
                            <input type="hidden" name="discounts_enabled" value="{{ (bool) ($product->discounts_enabled ?? true) ? 1 : 0 }}">
                            <div class="sl-form-grid">
                                <label class="sl-field"><span>Discount starts at</span><input name="discount_starts_at" type="datetime-local" value="{{ $product->discount_starts_at?->format('Y-m-d\TH:i') }}"><small>Leave blank to start immediately.</small></label>
                                <label class="sl-field"><span>Discount ends at</span><input name="discount_ends_at" type="datetime-local" value="{{ $product->discount_ends_at?->format('Y-m-d\TH:i') }}"><small>Leave blank for no end date.</small></label>
                            </div>
                            <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Variant</th><th>Regular price</th><th>Discount type</th><th>Discount value</th><th>Product discount price</th></tr></thead><tbody>
                                @foreach($product->variants as $variant)
                                    @php
                                        $discountType = $variant->resolved_discount_type;
                                        $discountValue = (float) ($variant->discount_value ?? 0);
                                        $basePrice = (float) $variant->price;
                                        $discountAmount = $discountType === 'fixed' ? $discountValue : ($discountType === 'percentage' ? $basePrice * min(100, $discountValue) / 100 : 0);
                                        $discountPrice = $discountAmount > 0 && $basePrice > 0 ? max(0.01, round($basePrice - $discountAmount, 2)) : $basePrice;
                                    @endphp
                                    <tr><td><strong>{{ $variant->description ?: $variant->sku }}</strong><small>{{ $variant->sku }} · {{ number_format((int) $variant->stock) }} in stock</small><input type="hidden" name="variants[{{ $loop->index }}][id]" value="{{ $variant->id }}"></td><td>PHP {{ number_format($basePrice, 2) }}</td><td><select name="variants[{{ $loop->index }}][discount_type]" aria-label="Discount type for {{ $variant->sku }}"><option value="none" @selected($discountType === 'none')>No discount</option><option value="percentage" @selected($discountType === 'percentage')>Percentage</option><option value="fixed" @selected($discountType === 'fixed')>Fixed amount</option></select></td><td><input name="variants[{{ $loop->index }}][discount_value]" type="number" min="0" max="9999999999.99" step="0.01" value="{{ $discountValue ?: '' }}" placeholder="0" aria-label="Discount value for {{ $variant->sku }}"></td><td>PHP {{ number_format($discountPrice, 2) }}</td></tr>
                                @endforeach
                            </tbody></table></div>
                            <div class="sl-toolbar-group"><button class="sl-btn sl-btn-primary" type="submit">Save Product Discounts</button></div>
                        </form>
                        @if($configuredDiscounts->isNotEmpty())
                            <form method="POST" action="{{ route('seller.marketing.product-discounts.toggle', ['product' => $product, 'section' => 'product-discounts']) }}" class="sl-inline-actions">@csrf @method('PATCH')<button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">{{ (bool) ($product->discounts_enabled ?? true) ? 'Pause discounts' : 'Resume discounts' }}</button></form>
                        @endif
                    </article>
                @endforeach
            @endif
        </section>
    @elseif($section === 'vouchers')
        @php
            $voucherPromotionConflicts = $promotionConflicts;
            foreach (['products', 'variants'] as $scope) {
                $voucherPromotionConflicts[$scope] = collect($promotionConflicts[$scope] ?? [])
                    ->map(fn ($entries) => collect($entries)->reject(fn ($entry) => ($entry['kind'] ?? '') === 'flash_sale')->values()->all())
                    ->all();
            }
        @endphp
        <section class="sl-mini-stats">
            <div><span>Vouchers</span><strong>{{ number_format($voucherStats['total']) }}</strong></div>
            <div><span>Active</span><strong>{{ number_format($voucherStats['active']) }}</strong></div>
            <div><span>Redemptions</span><strong>{{ number_format($voucherStats['redemptions']) }}</strong></div>
            <div><span>Scheduled</span><strong>{{ number_format($voucherStats['scheduled']) }}</strong></div>
        </section>

        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>LIKHAE Platform Campaigns</h3><p>Read-only campaigns available to buyers. Platform funding details remain private to LIKHAE.</p></div></div>
            @if(!$platformCampaignsReady)
                <div class="sl-compliance-feedback is-warning" role="status">Platform campaign storage is not installed yet. Active campaigns will appear when the prepared schema is available.</div>
            @else
                <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Campaign / Code</th><th>Benefit</th><th>Minimum Spend</th><th>Redemptions</th><th>Validity</th><th>Access</th></tr></thead><tbody>
                    @if($platformCampaigns->isEmpty())
                        <tr><td colspan="6">No active platform campaigns are available right now.</td></tr>
                    @else
                    @foreach($platformCampaigns as $campaign)
                        <tr><td><strong>{{ $campaign->name }}</strong><small class="sl-code">{{ $campaign->code }}</small></td><td>{{ $campaign->discount_type === 'PERCENT' ? number_format((float) $campaign->discount_value, 2).'%' : 'PHP '.number_format((float) $campaign->discount_value, 2) }} off</td><td>PHP {{ number_format((float) $campaign->minimum_order_amount, 2) }}</td><td>{{ number_format((int) $campaign->redemption_count) }} / {{ $campaign->usage_limit ?: 'Unlimited' }}</td><td>{{ $campaign->starts_at?->format('M d') ?: 'Now' }} &ndash; {{ $campaign->ends_at?->format('M d, Y') ?: 'No end' }}</td><td><span class="sl-status is-neutral">Read only</span></td></tr>
                    @endforeach
                    @endif
                </tbody></table></div>
            @endif
        </section>

        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>Your Vouchers</h3><p>Choose the discount, audience, and eligibility for each Seller-funded voucher.</p></div><button type="button" class="sl-btn sl-btn-primary sl-btn-sm" data-modal-open="campaignModal">Create Voucher</button></div>
            @unless($voucherProductTargetingReady)
                <div class="sl-compliance-feedback is-warning" role="status">Vouchers for selected products are temporarily unavailable while the voucher targeting migration is pending. Existing store vouchers and other marketing tools remain available.</div>
            @endunless
            @unless($voucherVisibilityReady)
                <div class="sl-compliance-feedback is-warning" role="status">Code-only vouchers are temporarily unavailable while the voucher visibility migration is pending.</div>
            @endunless
            @unless($voucherVariantTargetingReady)
                <div class="sl-compliance-feedback is-warning" role="status">Selected-variant vouchers will be available after the additive variant-targeting migration is applied.</div>
            @endunless
            @unless($sellerVoucherBudgetReady)
                <div class="sl-compliance-feedback is-warning" role="status">Optional Seller discount budgets are temporarily unavailable until the financial ledger and budget fields are ready.</div>
            @endunless
            <div class="sl-table-wrap sl-marketing-voucher-table-wrap"><table class="sl-table sl-marketing-voucher-table"><thead><tr><th>Name / code</th><th>Eligible items</th><th>Who can use it</th><th>Discount</th><th>Cap / minimum</th><th>Seller budget</th><th>Redemptions</th><th>Validity</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                @if($vouchers->count() === 0)
                    <tr><td colspan="10">No vouchers yet. Create one to get started.</td></tr>
                @else
                @foreach($vouchers as $voucher)
                    @php
                        $sellerBudget = $voucher->seller_budget_summary;
                        $eligibleVariantIds = $voucherVariantTargetingReady ? $voucher->eligibleVariants->pluck('id') : collect();
                    @endphp
                    <tr>
                        <td><strong>{{ $voucher->name }}</strong><small class="sl-code">{{ $voucher->code }}</small></td>
                        <td>
                            @if($voucher->applies_to === 'VARIANTS')
                                Selected variants<small>{{ $voucher->eligibleVariants->count() }} variant{{ $voucher->eligibleVariants->count() === 1 ? '' : 's' }}</small>
                            @elseif($voucher->applies_to === 'PRODUCTS')
                                Selected products<small>{{ $voucher->eligibleProducts->count() }} product{{ $voucher->eligibleProducts->count() === 1 ? '' : 's' }}</small>
                            @else
                                Entire store
                            @endif
                        </td>
                        <td>{{ match($voucher->visibility ?? 'PUBLIC') { 'CODE_ONLY' => 'Private code', 'FOLLOWERS' => 'Store followers', default => 'Public' } }}</td>
                        <td>
                            @if($voucher->requiresReview()) Terms need review<small>{{ $voucher->reviewReason() }}</small>
                            @elseif($voucher->discount_type === 'PERCENT') {{ number_format((float) $voucher->discount_value, 2) }}% off
                            @else PHP {{ number_format((float) $voucher->discount_value, 2) }} off
                            @endif
                        </td>
                        <td>{{ $voucher->maximum_discount_amount !== null ? 'PHP '.number_format((float) $voucher->maximum_discount_amount, 2) : 'No cap' }}<small>Minimum PHP {{ number_format((float) $voucher->minimum_order_amount, 2) }}</small></td>
                        <td>
                            @if($voucher->seller_discount_budget_total !== null)
                                <strong>PHP {{ number_format((float) $voucher->seller_discount_budget_total, 2) }} cap</strong>
                                <small>Reserved PHP {{ number_format(($sellerBudget['reserved_cents'] ?? 0) / 100, 2) }}</small>
                                <small>Confirmed PHP {{ number_format(($sellerBudget['spent_cents'] ?? 0) / 100, 2) }}</small>
                                @if($sellerVoucherBudgetReady)
                                    <small>Remaining PHP {{ number_format(($sellerBudget['available_cents'] ?? 0) / 100, 2) }}</small>
                                @else
                                    <small>Ledger status unavailable</small>
                                @endif
                            @else
                                No spending cap
                            @endif
                        </td>
                        <td><strong>{{ number_format((int) $voucher->redemption_count) }} / {{ $voucher->usage_limit !== null ? number_format((int) $voucher->usage_limit) : 'Unlimited' }}</strong><small>{{ $voucher->remaining_capacity !== null ? number_format((int) $voucher->remaining_capacity).' uses left' : 'Unlimited total uses' }}</small><small>{{ $voucher->per_user_limit !== null ? number_format((int) $voucher->per_user_limit).' per buyer' : 'No per-buyer limit' }}</small></td>
                        <td>{{ $voucher->starts_at?->format('M d, Y H:i') ?: 'Now' }} &ndash; {{ $voucher->ends_at?->format('M d, Y H:i') ?: 'No end' }}</td>
                        <td><span class="sl-status {{ $voucher->marketing_status === 'Active' ? 'is-success' : ($voucher->marketing_status === 'Upcoming' ? 'is-info' : ($voucher->marketing_status === 'Paused' ? 'is-neutral' : 'is-warning')) }}">{{ $voucher->marketing_status }}</span>@if($voucher->requiresReview())<small>{{ $voucher->reviewReason() }}</small>@endif</td>
                        <td class="sl-inline-actions">
                            <button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-modal-open="editVoucherModal" data-voucher-edit="true"
                                data-update-url="{{ route('seller.marketing.campaigns.update', ['voucher' => $voucher, 'section' => 'vouchers']) }}"
                                data-voucher-id="{{ $voucher->id }}" data-voucher-name="{{ $voucher->name }}" data-voucher-code="{{ $voucher->code }}"
                                data-discount-type="{{ $voucher->discount_type }}" data-discount-value="{{ $voucher->discount_value }}"
                                data-minimum-order-amount="{{ $voucher->minimum_order_amount }}" data-maximum-discount-amount="{{ $voucher->maximum_discount_amount }}"
                                data-applies-to="{{ $voucher->applies_to }}" data-product-ids="{{ $voucher->eligibleProducts->pluck('id')->implode(',') }}" data-variant-ids="{{ $eligibleVariantIds->implode(',') }}"
                                data-seller-discount-budget-total="{{ $voucher->seller_discount_budget_total }}" data-visibility="{{ $voucher->visibility ?? 'PUBLIC' }}"
                                data-usage-limit="{{ $voucher->usage_limit }}" data-per-user-limit="{{ $voucher->per_user_limit }}"
                                data-starts-at="{{ $voucher->starts_at?->format('Y-m-d\TH:i') ?? '' }}" data-ends-at="{{ $voucher->ends_at?->format('Y-m-d\TH:i') ?? '' }}">Edit</button>
                            <form method="POST" action="{{ route('seller.marketing.campaigns.toggle', ['voucher' => $voucher, 'section' => 'vouchers']) }}" data-confirm-title="{{ $voucher->is_active ? 'Pause voucher?' : 'Activate voucher?' }}" data-confirm-message="{{ $voucher->requiresReview() ? 'Review or replace this voucher before activating it.' : ($voucher->is_active ? 'Customers will no longer be able to use this voucher.' : 'Customers will be able to use this voucher while it is valid.') }}" data-confirm-label="{{ $voucher->is_active ? 'Pause voucher' : 'Activate voucher' }}" @if($voucher->is_active) data-confirm-tone="danger" @endif>@csrf @method('PATCH')<button type="submit" class="sl-btn sl-btn-ghost sl-btn-sm" @disabled(!$voucher->is_active && $voucher->requiresReview())>{{ $voucher->is_active ? 'Pause' : ($voucher->requiresReview() ? 'Review required' : 'Activate') }}</button></form>
                        </td>
                    </tr>
                @endforeach
                @endif
            </tbody></table></div>
            {{ $vouchers->links() }}
        </section>

        <section class="sl-card sl-marketing-preview" data-voucher-preview>
            <h3>Voucher price preview</h3><p data-voucher-preview-text>Choose an eligibility scope and discount to preview the lowest eligible variant price.</p>
            <small>Estimate uses one variant and the same percent, fixed-discount, and maximum-discount rules as checkout. Checkout caps the discount so every eligible item keeps a positive price.</small>
        </section>

        <div class="sl-modal" data-modal="campaignModal" hidden>
            <div class="sl-modal-dialog sl-marketing-modal" role="dialog" aria-modal="true" aria-labelledby="campaignTitle">
                <header><div><span class="sl-eyebrow">Seller-funded campaign</span><h2 id="campaignTitle">Create Voucher</h2></div><button type="button" class="sl-icon-btn" data-modal-close aria-label="Close">&times;</button></header>
                <form method="POST" action="{{ route('seller.marketing.campaigns.store', ['section' => 'vouchers']) }}" data-voucher-target-form data-voucher-form>@csrf
                    <div class="sl-form-grid">
                        <label class="sl-field sl-span-2"><span>Voucher name</span><input name="name" maxlength="150" required></label>
                        <label class="sl-field"><span>Code</span><input name="code" maxlength="80" required></label>
                        @include('seller.marketing._targeting-picker', ['selectedProductIds' => [], 'selectedVariantIds' => [], 'promotionConflicts' => $voucherPromotionConflicts])
                        <label class="sl-field"><span>Who can use it</span><select name="visibility"><option value="PUBLIC">Public voucher</option><option value="FOLLOWERS" @disabled(!$voucherVisibilityReady || !\Illuminate\Support\Facades\Schema::hasTable('seller_followers'))>Follower reward</option><option value="CODE_ONLY" @disabled(!$voucherVisibilityReady)>Private code</option></select><small>Follower rewards require buyers to follow your store and collect the voucher.</small></label>
                    </div>
                    <fieldset class="sl-form-section"><legend>Discount rules</legend><div class="sl-form-grid">
                        <label class="sl-field"><span>Discount type</span><select name="discount_type"><option value="PERCENT">Percent</option><option value="FIXED">Fixed amount</option></select></label>
                        <label class="sl-field"><span>Discount value</span><input name="discount_value" type="number" min="0.01" step="0.01" value="10" required><small>Percent vouchers are limited to 99.99%. Checkout keeps each unit above PHP 0.00. Seller vouchers can combine with Flash Sales.</small></label>
                        <label class="sl-field"><span>Minimum eligible spend</span><input name="minimum_order_amount" type="number" min="0" step="0.01" value="0"></label>
                        <label class="sl-field"><span>Maximum discount</span><input name="maximum_discount_amount" type="number" min="0.01" step="0.01" placeholder="No cap"></label>
                        @if($sellerVoucherBudgetReady)<label class="sl-field"><span>Maximum Seller voucher discount budget</span><input name="seller_discount_budget_total" type="number" min="0.01" step="0.01" placeholder="Optional, no wallet deposit"><small>Caps the total Seller-funded voucher discounts. It is not a wallet withdrawal.</small></label>@endif
                    </div></fieldset>
                    <fieldset class="sl-form-section"><legend>Uses and schedule</legend><div class="sl-form-grid">
                        <label class="sl-field"><span>Total redemptions</span><input name="usage_limit" type="number" min="1" placeholder="No limit"></label>
                        <label class="sl-field"><span>Uses per Buyer</span><input name="per_user_limit" type="number" min="1" placeholder="No limit"></label>
                        <label class="sl-field"><span>Start date</span><input name="starts_at" type="datetime-local"></label>
                        <label class="sl-field"><span>End date</span><input name="ends_at" type="datetime-local"></label>
                    </div></fieldset>
                    <footer><button type="button" class="sl-btn sl-btn-ghost" data-modal-close>Cancel</button><button type="submit" class="sl-btn sl-btn-primary">Create Voucher</button></footer>
                </form>
            </div>
        </div>
        <div class="sl-modal" data-modal="editVoucherModal" hidden>
            <div class="sl-modal-dialog sl-marketing-modal" role="dialog" aria-modal="true" aria-labelledby="editVoucherTitle">
                <header><div><span class="sl-eyebrow">Seller-funded campaign</span><h2 id="editVoucherTitle">Edit Voucher</h2></div><button type="button" class="sl-icon-btn" data-modal-close aria-label="Close">&times;</button></header>
                <form method="POST" action="#" data-voucher-edit-form data-voucher-target-form data-voucher-form>@csrf @method('PATCH')
                    <input type="hidden" name="edit_voucher_id" value="">
                    <div class="sl-form-grid">
                        <label class="sl-field sl-span-2"><span>Voucher name</span><input name="name" maxlength="150" required></label>
                        <label class="sl-field"><span>Code</span><input name="code" maxlength="80" required></label>
                        @include('seller.marketing._targeting-picker', ['selectedProductIds' => [], 'selectedVariantIds' => [], 'promotionConflicts' => $voucherPromotionConflicts])
                        <label class="sl-field"><span>Who can use it</span><select name="visibility"><option value="PUBLIC">Public voucher</option><option value="FOLLOWERS" @disabled(!$voucherVisibilityReady || !\Illuminate\Support\Facades\Schema::hasTable('seller_followers'))>Follower reward</option><option value="CODE_ONLY" @disabled(!$voucherVisibilityReady)>Private code</option></select><small>Follower rewards require buyers to follow your store and collect the voucher.</small></label>
                    </div>
                    <fieldset class="sl-form-section"><legend>Discount rules</legend><div class="sl-form-grid">
                        <label class="sl-field"><span>Discount type</span><select name="discount_type" required><option value="PERCENT">Percent</option><option value="FIXED">Fixed amount</option></select></label>
                        <label class="sl-field"><span>Discount value</span><input name="discount_value" type="number" min="0.01" step="0.01" required><small>Percent vouchers are limited to 99.99%. Checkout keeps each unit above PHP 0.00. Seller vouchers can combine with Flash Sales.</small></label>
                        <label class="sl-field"><span>Minimum eligible spend</span><input name="minimum_order_amount" type="number" min="0" step="0.01"></label>
                        <label class="sl-field"><span>Maximum discount</span><input name="maximum_discount_amount" type="number" min="0.01" step="0.01" placeholder="No cap"></label>
                        @if($sellerVoucherBudgetReady)<label class="sl-field"><span>Maximum Seller voucher discount budget</span><input name="seller_discount_budget_total" type="number" min="0.01" step="0.01" placeholder="Optional, no wallet deposit"><small>Reserved or confirmed discounts cannot exceed the configured amount.</small></label>@endif
                    </div></fieldset>
                    <fieldset class="sl-form-section"><legend>Uses and schedule</legend><div class="sl-form-grid">
                        <label class="sl-field"><span>Total redemptions</span><input name="usage_limit" type="number" min="1" placeholder="No limit"></label>
                        <label class="sl-field"><span>Uses per Buyer</span><input name="per_user_limit" type="number" min="1" placeholder="No limit"></label>
                        <label class="sl-field"><span>Start date</span><input name="starts_at" type="datetime-local"></label>
                        <label class="sl-field"><span>End date</span><input name="ends_at" type="datetime-local"></label>
                    </div></fieldset>
                    <footer><button type="button" class="sl-btn sl-btn-ghost" data-modal-close>Cancel</button><button type="submit" class="sl-btn sl-btn-primary">Save Changes</button></footer>
                </form>
            </div>
        </div>
    @elseif($section === 'flash-sales')
        <section class="sl-mini-stats">
            <div><span>Active variants</span><strong>{{ number_format($activeFlashSales) }}</strong></div>
            <div><span>Scheduled variants</span><strong>{{ number_format($scheduledFlashSales) }}</strong></div>
            <div><span>Units sold</span><strong>{{ number_format($overview['flash_units_sold']) }}</strong></div>
        </section>
        @if(!$flashSaleSchemaReady)
            <div class="sl-compliance-feedback is-warning" role="status">Flash sales are temporarily unavailable while their variant allocation and order snapshot migrations are pending.</div>
        @else
            <section class="sl-card">
                <div class="sl-table-toolbar"><div><h3>Variant Flash Sales</h3><p>Flash Sale has priority over Product Discount while active. The buyer gets one base price, never compounded discounts. Allocations are unit limits, not money.</p></div></div>
                <label class="sl-field"><span>Find a product</span><input type="search" data-flash-search placeholder="Search active products"></label>
                @if($eligibleProducts->isEmpty())
                    <p class="sl-empty-state">No active products are available for flash sales.</p>
                @else
                @foreach($eligibleProducts as $product)
                    <article class="sl-marketing-flash-product" data-flash-product data-flash-product-id="{{ $product->id }}" data-search="{{ mb_strtolower($product->name) }}">
                        <header><div><h3>{{ $product->name }}</h3><p>{{ $product->variants->count() }} active variants</p></div><span class="sl-status is-neutral">One schedule per product</span></header>
                        @php
                            $otherOffers = collect($promotionConflicts['products'][$product->id] ?? [])
                                ->reject(fn ($conflict) => in_array($conflict['kind'], ['flash_sale', 'voucher'], true));
                        @endphp
                        @if($otherOffers->isNotEmpty())
                            <div class="sl-compliance-feedback is-warning sl-promotion-conflict" role="status"><strong>Existing offers affect this product</strong><ul>@foreach($otherOffers as $conflict)<li>{{ $conflict['label'] }} · {{ $conflict['status'] }}</li>@endforeach</ul><p>Seller vouchers may still apply at checkout. Flash sales take priority over product discounts. Clear incompatible bundle deals before saving; each flash price must be at least PHP 0.01.</p></div>
                        @endif
                        <form method="POST" action="{{ route('seller.marketing.flash-sales.update', ['product' => $product, 'section' => 'flash-sales']) }}" class="sl-form-section">@csrf @method('PATCH')
                            <div class="sl-form-grid">
                                <label class="sl-field"><span>Sale starts at</span><input name="flash_sale_starts_at" type="datetime-local" value="{{ $product->flash_sale_starts_at?->format('Y-m-d\TH:i') }}"><small>Leave blank to start immediately.</small></label>
                                <label class="sl-field"><span>Sale ends at</span><input name="flash_sale_ends_at" type="datetime-local" value="{{ $product->flash_sale_ends_at?->format('Y-m-d\TH:i') }}"><small>Leave blank for no end date.</small></label>
                                <label class="sl-field"><span>Maximum flash-sale units per Buyer</span><input name="flash_sale_limit_per_buyer" type="number" min="1" step="1" value="{{ $product->flash_sale_limit_per_buyer }}" placeholder="No limit"><small>Optional. Counts this campaign's units across all variants.</small></label>
                            </div>
                            <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Variant</th><th>Product discount price</th><th>Stock</th><th>Flash price</th><th>Allocation (units)</th><th>Sold</th><th>Remaining</th><th>Status</th></tr></thead><tbody>
                                @if($product->variants->isEmpty())
                                    <tr><td colspan="8">No active variants for this product.</td></tr>
                                @else
                                @foreach($product->variants as $variant)
                                    @php
                                        $flashStatus = match ($variant->flash_sale_status) {
                                            'PAUSED' => 'Paused',
                                            'SOLD_OUT' => 'Sold out',
                                            'EXPIRED' => 'Expired',
                                            'SCHEDULED' => 'Scheduled',
                                            'ACTIVE' => 'Active',
                                            default => 'Not configured',
                                        };
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $variant->description ?: $variant->sku }}</strong><small>{{ $variant->sku }}</small><input type="hidden" name="variants[{{ $loop->index }}][id]" value="{{ $variant->id }}"></td>
                                        <td>PHP {{ number_format(app(\App\Services\Marketplace\ProductPriceResolver::class)->productDiscountPrice($variant), 2) }}</td>
                                        <td>{{ number_format((int) $variant->stock) }}</td>
                                        <td><input aria-label="Flash price for {{ $variant->sku }}" name="variants[{{ $loop->index }}][flash_sale_price]" type="number" min="0.01" step="0.01" value="{{ $variant->flash_sale_price }}" placeholder="No sale"></td>
                                        <td><input aria-label="Flash allocation for {{ $variant->sku }}" name="variants[{{ $loop->index }}][flash_sale_allocation]" type="number" min="1" step="1" value="{{ $variant->flash_sale_allocation }}" placeholder="Units"></td>
                                        <td>{{ number_format((int) $variant->flash_sale_sold) }}</td>
                                        <td>{{ number_format((int) $variant->flash_sale_remaining) }}</td>
                                        <td><span class="sl-status {{ $flashStatus === 'Active' ? 'is-success' : ($flashStatus === 'Scheduled' ? 'is-info' : (in_array($flashStatus, ['Sold out', 'Expired'], true) ? 'is-warning' : 'is-neutral')) }}">{{ $flashStatus }}</span></td>
                                    </tr>
                                @endforeach
                                @endif
                            </tbody></table></div>
                            <div class="sl-toolbar-group"><button class="sl-btn sl-btn-primary" type="submit">Save flash sale</button></div>
                        </form>
                        @if($product->variants->contains(fn ($variant) => $variant->flash_sale_price !== null))
                            <form method="POST" action="{{ route('seller.marketing.flash-sales.toggle', ['product' => $product, 'section' => 'flash-sales']) }}">@csrf @method('PATCH')<button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">{{ $product->flash_sale_is_paused ? 'Resume flash sale' : 'Pause flash sale' }}</button></form>
                            <form method="POST" action="{{ route('seller.marketing.flash-sales.end', ['product' => $product, 'section' => 'flash-sales']) }}" data-confirm-title="End this product's flash sale?" data-confirm-message="All flash prices and allocations for this product will be cleared. Completed order prices remain unchanged." data-confirm-label="End flash sale" data-confirm-tone="danger">@csrf @method('PATCH')<button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">End all product flash sales</button></form>
                        @endif
                    </article>
                @endforeach
                @endif
            </section>
        @endif
    @elseif(in_array($section, ['bundle-deals', 'buy-x-get-y'], true))
        @include('seller.promotions._marketing-management', ['variants' => $promotionVariants, 'promotions' => $promotions, 'promotionStorageReady' => $promotionStorageReady, 'promotionConflicts' => $promotionConflicts, 'section' => $section])
    @else
        <section class="sl-mini-stats">
            <div><span>Active vouchers</span><strong>{{ number_format($voucherStats['active']) }}</strong></div>
            <div><span>Scheduled vouchers</span><strong>{{ number_format($voucherStats['scheduled']) }}</strong></div>
            <div><span>Active flash variants</span><strong>{{ number_format($activeFlashSales) }}</strong></div>
            <div><span>Active special offers</span><strong>{{ number_format($activeSpecialOffers) }}</strong></div>
        </section>
        <section class="sl-form-grid">
            <article class="sl-card sl-marketing-metric"><span>Voucher redemptions</span><strong>{{ number_format($overview['voucher_redemptions']) }}</strong><small>Placed orders that are not cancelled.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Flash-sale units sold</span><strong>{{ number_format($overview['flash_units_sold']) }}</strong><small>Units attached to non-cancelled orders.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Estimated promotional discounts</span><strong>PHP {{ number_format($overview['estimated_discount'], 2) }}</strong><small>Discounts on all non-cancelled orders, including pending orders.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Confirmed promotional discounts</span><strong>PHP {{ number_format($overview['confirmed_discount'], 2) }}</strong><small>Orders with recognized commission records.</small></article>
        </section>
        <section class="sl-form-grid" aria-label="Recognized marketing performance">
            <article class="sl-card sl-marketing-metric"><span>Voucher claims</span><strong>{{ number_format($marketingOverview['voucher_claims']) }}</strong><small>Seller voucher claims across buyers.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Bundle sets sold</span><strong>{{ number_format($marketingOverview['bundle_redemptions']) }}</strong><small>Recognized bundle redemptions.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Buy X Get Y redemptions</span><strong>{{ number_format($marketingOverview['buy_x_get_y_redemptions']) }}</strong><small>Recognized offer sets.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Promotion-attributed revenue</span><strong>PHP {{ number_format($marketingOverview['promotion_attributed_revenue'], 2) }}</strong><small>Recognized merchandise lines with a seller promotion, counted once per item.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Seller-funded discount cost</span><strong>PHP {{ number_format($marketingOverview['seller_funded_discount_cost'], 2) }}</strong><small>Product/flash discounts, seller offers, and seller vouchers. Platform-funded discounts are excluded.</small></article>
            <article class="sl-card sl-marketing-metric"><span>Recognized flash-sale units</span><strong>{{ number_format($marketingOverview['recognized_flash_sale_units']) }}</strong><small>Flash-sale units on recognized completed seller orders.</small></article>
        </section>
        <section class="sl-mini-stats" aria-label="Campaign status overview">
            <div><span>Active campaigns</span><strong>{{ number_format($marketingOverview['active_campaigns']) }}</strong></div>
            <div><span>Scheduled campaigns</span><strong>{{ number_format($marketingOverview['scheduled_campaigns']) }}</strong></div>
            <div><span>Active product discounts</span><strong>{{ number_format($marketingOverview['active_product_discounts']) }}</strong></div>
            <div><span>Scheduled product discounts</span><strong>{{ number_format($marketingOverview['scheduled_product_discounts']) }}</strong></div>
            <div><span>Active flash sales</span><strong>{{ number_format($marketingOverview['active_flash_sales']) }}</strong></div>
            <div><span>Scheduled flash sales</span><strong>{{ number_format($marketingOverview['scheduled_flash_sales']) }}</strong></div>
        </section>
        <section class="sl-card"><div class="sl-table-toolbar"><div><h3>Promotion performance</h3><p>Confirmed sales use recognized commission records. Pending orders remain in the estimated total only.</p></div><a href="{{ route('seller.marketing', ['section' => 'vouchers']) }}" class="sl-btn sl-btn-ghost sl-btn-sm">Manage vouchers</a></div>
            @unless($overview['snapshot_ready'])
                <div class="sl-compliance-feedback is-warning" role="status">Source-aware order snapshots are not ready. Promotional totals are shown as zero until the required finance schema is installed.</div>
            @else
                <div class="sl-mini-stats"><div><span>Pending exposure</span><strong>PHP {{ number_format($overview['pending_discount'], 2) }}</strong></div><div><span>Confirmed total</span><strong>PHP {{ number_format($overview['confirmed_discount'], 2) }}</strong></div><div><span>Seller vouchers</span><strong>{{ number_format($voucherStats['redemptions']) }} redemptions</strong></div><div><span>Flash units</span><strong>{{ number_format($overview['flash_units_sold']) }} sold</strong></div></div>
                <div class="sl-form-grid">
                    <section class="sl-card"><h3>Top promoted products</h3><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Product</th><th>Confirmed units</th><th>Discounts</th></tr></thead><tbody>
                        @if($overview['top_products']->isEmpty())
                            <tr><td colspan="3">No confirmed promoted product sales yet.</td></tr>
                        @else
                            @foreach($overview['top_products'] as $row)<tr><td>{{ $row->product_name }}</td><td>{{ number_format((int) $row->units) }}</td><td>PHP {{ number_format((float) $row->discount_total, 2) }}</td></tr>@endforeach
                        @endif
                    </tbody></table></div></section>
                    <section class="sl-card"><h3>Top promoted variants</h3><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Product / variant</th><th>Confirmed units</th><th>Discounts</th></tr></thead><tbody>
                        @if($overview['top_variants']->isEmpty())
                            <tr><td colspan="3">No confirmed promoted variant sales yet.</td></tr>
                        @else
                            @foreach($overview['top_variants'] as $row)<tr><td>{{ $row->product_name }}<small>{{ $row->variant_description ?: 'Variant '.$row->product_variant_id }}</small></td><td>{{ number_format((int) $row->units) }}</td><td>PHP {{ number_format((float) $row->discount_total, 2) }}</td></tr>@endforeach
                        @endif
                    </tbody></table></div></section>
                </div>
            @endunless
            @if($voucherStats['total'] === 0 && $activeFlashSales === 0 && $activeSpecialOffers === 0)
                <p class="sl-empty-state">You have not created a campaign yet. Start with a voucher, flash sale, or special offer.</p>
            @endif
        </section>
        <section class="sl-card" aria-labelledby="campaignHistoryHeading">
            <div class="sl-table-toolbar"><div><h3 id="campaignHistoryHeading">Campaign history</h3><p>Current campaign settings and completed redemption counts from recognized order snapshots.</p></div><a href="{{ route('seller.marketing', ['section' => 'product-discounts']) }}" class="sl-btn sl-btn-ghost sl-btn-sm">Manage campaigns</a></div>
            <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Campaign</th><th>Type</th><th>Starts</th><th>Ends</th><th>Recognized redemptions</th><th>Status</th></tr></thead><tbody>
                @forelse($marketingOverview['campaign_history'] as $campaign)
                    <tr><td><strong>{{ $campaign['name'] ?: 'Untitled campaign' }}</strong>@if(!empty($campaign['code']))<small class="sl-code">{{ $campaign['code'] }}</small>@endif</td><td>{{ $campaign['type'] }}</td><td>{{ $campaign['starts_at']?->timezone('Asia/Manila')->format('M d, Y g:i A') ?? 'No start date' }}</td><td>{{ $campaign['ends_at']?->timezone('Asia/Manila')->format('M d, Y g:i A') ?? 'No end date' }}</td><td>{{ $campaign['redemptions'] === null ? '—' : number_format((int) $campaign['redemptions']) }}</td><td><span class="sl-status is-neutral">{{ ucfirst($campaign['status']) }}</span></td></tr>
                @empty
                    <tr><td colspan="6">No marketing campaigns have been created yet.</td></tr>
                @endforelse
            </tbody></table></div>
            <p class="mt-3 text-xs text-stone-500">Historical orders preserve their promotion and price snapshots. Flash-sale campaigns that were ended before campaign dates were retained cannot be listed here because their active schedule was cleared.</p>
        </section>
    @endif
</div>
@endsection
