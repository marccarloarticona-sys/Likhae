@extends('layouts.admin.app')

@section('title', 'Platform Marketing')
@section('subtitle', 'Manage marketplace campaigns and scheduled announcements.')
@section('active', 'marketing')

@section('content')
<div class="ad-page ad-marketing-page">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Marketplace Growth</span>
            <h2>Platform Marketing</h2>
        </div>
        @if($section === 'campaigns' && $campaignsReady && $campaignBudgetReady)
            <button type="button" class="ad-btn ad-btn-primary" data-marketing-campaign-open="create">+ Create Campaign</button>
        @endif
    </div>

    @if(session('status'))<div class="ad-note" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>@endif

    <nav class="ad-tabs ad-marketing-tabs" aria-label="Platform marketing sections">
        <a class="ad-tab {{ $section === 'campaigns' ? 'is-active' : '' }}" href="{{ route('admin.marketing', ['section' => 'campaigns']) }}" @if($section === 'campaigns') aria-current="page" @endif>Campaigns</a>
        <a class="ad-tab {{ $section === 'announcements' ? 'is-active' : '' }}" href="{{ route('admin.marketing', ['section' => 'announcements']) }}" @if($section === 'announcements') aria-current="page" @endif>Announcements</a>
    </nav>

    @if($section === 'campaigns')
        <section class="ad-card ad-card-body" aria-labelledby="marketing-campaigns-heading">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Voucher Campaigns</span>
                    <h2 id="marketing-campaigns-heading">Platform Campaigns</h2>
                    <p>Platform campaigns and LIKHAE-funded discounts. New redemptions require an authorized allocation.</p>
                </div>
            </header>

            @unless($campaignsReady)
                <div class="ad-note is-warning" role="status">Platform campaign storage is not installed yet. The additive voucher migration is prepared but has not been applied. Campaign creation and management will be available after it is applied.</div>
            @else
                @unless($campaignBudgetReady)
                    <div class="ad-note is-warning" role="status">Campaign budgets are awaiting the additive finance migration. Existing campaigns remain visible; new platform voucher redemptions are held until budgets can be tracked.</div>
                @endunless
            @unless($homepageCarouselReady)
                <div class="ad-note is-warning" role="status">Buyer Hero Carousel placement is unavailable until the prepared additive voucher migration is applied. Existing campaigns and event pages remain available.</div>
            @endunless
                <div class="ad-table-wrap ad-marketing-table-wrap">
                    <table class="ad-table">
                        <thead><tr><th>Campaign</th><th>Benefit</th><th>Minimum</th><th>Usage</th><th>Budget & history</th><th>Validity</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            @forelse($campaigns as $campaign)
                                @php
                                    $budgetSummary = $campaignBudgetSummaries[$campaign->id] ?? ['allocated_cents' => null, 'reserved_cents' => 0, 'spent_cents' => 0, 'available_cents' => null, 'usage_percent' => 0, 'status' => 'Pending Budget Setup', 'reconciliation_mismatch' => false];
                                    $budgetHistory = $campaignBudgetHistory[$campaign->id] ?? collect();
                                    $campaignStatusKey = app(\App\Services\Marketplace\PlatformCampaignEligibility::class)->status($campaign);
                                    $campaignStatus = $campaign->requiresReview() ? 'Review required' : str($campaignStatusKey)->lower()->headline()->toString();
                                    $campaignStatusClass = match ($campaignStatus) {
                                        'Active' => 'is-active',
                                        'Scheduled' => 'is-pending',
                                        'Ended' => 'is-inactive',
                                        default => 'is-review',
                                    };
                                    $campaignImageUrl = $marketingMediaReady
                                        && $campaign->image_path
                                        && \Illuminate\Support\Facades\Storage::disk('public')->exists($campaign->image_path)
                                            ? \Illuminate\Support\Facades\Storage::disk('public')->url($campaign->image_path)
                                            : null;
                                    $homepageDesktopImageUrl = $homepageCarouselReady
                                        && $campaign->homepage_carousel_desktop_image_path
                                        && \Illuminate\Support\Facades\Storage::disk('public')->exists($campaign->homepage_carousel_desktop_image_path)
                                            ? \Illuminate\Support\Facades\Storage::disk('public')->url($campaign->homepage_carousel_desktop_image_path)
                                            : null;
                                    $homepageMobileImageUrl = $homepageCarouselReady
                                        && $campaign->homepage_carousel_mobile_image_path
                                        && \Illuminate\Support\Facades\Storage::disk('public')->exists($campaign->homepage_carousel_mobile_image_path)
                                            ? \Illuminate\Support\Facades\Storage::disk('public')->url($campaign->homepage_carousel_mobile_image_path)
                                            : null;
                                @endphp
                                <tr>
                                    <td>
                                        <div class="ad-marketing-campaign-name">
                                            @if($campaignImageUrl)<img src="{{ $campaignImageUrl }}" alt="{{ $campaign->image_alt ?: $campaign->name }}">@endif
                                                <span><strong>{{ $campaign->name }}</strong><small>{{ $campaign->code }} · {{ $campaign->benefit_scope === 'SHIPPING' ? 'Shipping voucher' : 'Product voucher' }}</small>@if($homepageCarouselReady && $campaign->homepage_carousel_enabled)<small>Buyer hero · Priority {{ $campaign->homepage_carousel_priority }}</small>@endif</span>
                                        </div>
                                    </td>
                                    <td>@if($campaign->requiresReview()) Terms need review<small>{{ $campaign->reviewReason() }}</small>@else{{ $campaign->discount_type === 'PERCENT' ? number_format((float) $campaign->discount_value, 2).'%' : '₱'.number_format((float) $campaign->discount_value, 2) }} off<small>Max discount: {{ $campaign->maximum_discount_amount === null ? 'No cap' : '₱'.number_format((float) $campaign->maximum_discount_amount, 2) }}</small>@endif</td>
                                    <td>₱{{ number_format((float) $campaign->minimum_order_amount, 2) }}</td>
                                    <td>{{ number_format((int) $campaign->redemption_count) }} / {{ $campaign->usage_limit ?: 'Unlimited' }}<small>Per buyer: {{ $campaign->per_user_limit ?: 'Unlimited' }}</small></td>
                                    <td class="ad-campaign-budget-cell">
                                        <strong>Total: {{ $budgetSummary['allocated_cents'] === null ? 'Pending setup' : '₱'.number_format($budgetSummary['allocated_cents'] / 100, 2) }}</strong>
                                        <small>Reserved: ₱{{ number_format($budgetSummary['reserved_cents'] / 100, 2) }} · Spent: ₱{{ number_format($budgetSummary['spent_cents'] / 100, 2) }}</small>
                                        <small>Available: {{ $budgetSummary['available_cents'] === null ? '—' : '₱'.number_format($budgetSummary['available_cents'] / 100, 2) }}</small>
                                        <span class="ad-status {{ $budgetSummary['status'] === 'Available' ? 'is-active' : ($budgetSummary['status'] === 'Low Budget' ? 'is-pending' : 'is-review') }}">{{ $budgetSummary['status'] }}</span>
                                        @if($budgetSummary['reconciliation_mismatch'])<small class="ad-note is-warning" role="alert">Budget ledger exceeds the allocation. Pause redemptions and reconcile.</small>@endif
                                        @if($budgetSummary['allocated_cents'] !== null)<progress max="100" value="{{ $budgetSummary['usage_percent'] }}" aria-label="Budget usage {{ number_format($budgetSummary['usage_percent'], 1) }} percent"></progress>@endif
                                        <details class="ad-campaign-budget-history"><summary>History ({{ $budgetHistory->count() }})</summary>
                                            @if($budgetHistory->isEmpty())<small>No budget transactions recorded.</small>
                                            @else<ul>@foreach($budgetHistory as $entry)<li><strong>{{ str_replace('_', ' ', $entry->entry_type) }}</strong> · ₱{{ number_format((float) $entry->amount, 2) }}<small>{{ $entry->created_at?->format('M j, Y H:i') }} · {{ data_get($entry->metadata, 'reason', data_get($entry->metadata, 'source', 'Finance event')) }} · {{ $entry->actor?->name ?: 'System' }}</small></li>@endforeach</ul>@endif
                                        </details>
                                    </td>
                                    <td>{{ $campaign->starts_at?->format('M j, Y H:i') ?? 'Now' }} – {{ $campaign->ends_at?->format('M j, Y H:i') ?? 'No end' }}</td>
                                    <td><span class="ad-status {{ $campaignStatusClass }}">{{ $campaignStatus }}</span></td>
                                    <td>
                                        <div class="ad-marketing-actions">
                                            <button type="button" class="ad-btn ad-btn-secondary ad-btn-xs" data-marketing-campaign-open="edit"
                                                data-campaign-id="{{ $campaign->id }}"
                                                data-action="{{ route('admin.marketing.campaigns.update', $campaign) }}"
                                                data-name="{{ $campaign->name }}" data-code="{{ $campaign->code }}"
                                                data-benefit-scope="{{ $campaign->benefit_scope ?: 'PRODUCT' }}" data-discount-type="{{ $campaign->discount_type }}" data-discount-value="{{ $campaign->discount_value }}"
                                                data-minimum="{{ $campaign->minimum_order_amount }}" data-maximum="{{ $campaign->maximum_discount_amount }}"
                                                data-usage-limit="{{ $campaign->usage_limit }}" data-per-user-limit="{{ $campaign->per_user_limit }}"
                                                data-starts-at="{{ $campaign->starts_at?->format('Y-m-d\TH:i') }}" data-ends-at="{{ $campaign->ends_at?->format('Y-m-d\TH:i') }}"
                                                @if($campaignControlsReady)
                                                    data-distribution-mode="{{ $campaign->distribution_mode ?: 'CODE' }}"
                                                    data-target-mode="{{ $campaign->target_mode ?: 'ALL' }}"
                                                    data-target-ids="@json($campaign->target_mode === 'CATEGORIES' ? ($campaign->target_category_ids ?? []) : ($campaign->target_mode === 'PRODUCTS' ? ($campaign->target_product_ids ?? []) : ($campaign->target_seller_ids ?? [])))"
                                                    data-buyer-audience="{{ $campaign->buyer_audience ?: 'ALL' }}"
                                                    data-stack-with-seller="{{ $campaign->stack_with_seller ? '1' : '0' }}"
                                                    data-stack-with-shipping="{{ $campaign->stack_with_shipping ? '1' : '0' }}"
                                                    data-campaign-status="{{ $campaign->campaign_status ?: 'ACTIVE' }}"
                                                @endif
                                                @if($campaignBudgetReady) data-budget="{{ $campaign->campaign_budget_total }}" @endif
                                                data-image-url="{{ $campaignImageUrl }}" data-image-alt="{{ $campaign->image_alt }}" data-theme-color="{{ $campaign->theme_color }}"
                                                @if($homepageCarouselReady)
                                                    data-homepage-carousel-enabled="{{ $campaign->homepage_carousel_enabled ? '1' : '0' }}"
                                                    data-homepage-carousel-desktop-url="{{ $homepageDesktopImageUrl }}"
                                                    data-homepage-carousel-mobile-url="{{ $homepageMobileImageUrl }}"
                                                    data-homepage-carousel-headline="{{ $campaign->homepage_carousel_headline }}"
                                                    data-homepage-carousel-description="{{ $campaign->homepage_carousel_description }}"
                                                    data-homepage-carousel-cta-label="{{ $campaign->homepage_carousel_cta_label }}"
                                                    data-homepage-carousel-cta-destination="{{ $campaign->homepage_carousel_cta_destination ?: 'EVENT' }}"
                                                    data-homepage-carousel-priority="{{ $campaign->homepage_carousel_priority }}"
                                                @endif>Edit</button>
                                            <form method="POST" action="{{ $campaignControlsReady ? route('admin.marketing.campaigns.status', $campaign) : route('admin.marketing.campaigns.toggle', $campaign) }}" onsubmit="return confirm('Change this campaign status? Existing orders and reservations remain valid.');">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="status" value="{{ $campaign->is_active ? 'PAUSED' : 'ACTIVE' }}">
                                                <button class="ad-btn {{ $campaign->is_active ? 'ad-btn-warning-soft' : 'ad-btn-primary' }} ad-btn-xs" type="submit" @disabled(!$campaign->is_active && $campaign->requiresReview())>{{ $campaign->is_active ? 'Pause' : ($campaign->requiresReview() ? 'Review required' : 'Resume') }}</button>
                                            </form>
                                            @if($campaignControlsReady && $campaignStatusKey !== 'ENDED')
                                                <form method="POST" action="{{ route('admin.marketing.campaigns.status', $campaign) }}" onsubmit="return confirm('End this campaign now? Existing orders and reservations remain valid.');">
                                                    @csrf @method('PATCH')<input type="hidden" name="status" value="ENDED">
                                                    <button class="ad-btn ad-btn-secondary ad-btn-xs" type="submit">End</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="ad-marketing-empty">No platform campaigns yet. Create one and authorize its funding before buyers can redeem it.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $campaigns->links() }}
                <p class="ad-marketing-finance-note">Budget reservations are created with an order. Spent funds are recorded only after confirmed COD collection; cancelled commitments release back to the campaign.</p>
            @endunless
        </section>

        @if($campaignsReady)
            <div class="ad-marketing-modal" id="marketing-campaign-modal" aria-hidden="true">
                <div class="ad-marketing-modal-content" role="dialog" aria-modal="true" aria-labelledby="marketing-campaign-modal-title">
                    <button type="button" class="ad-marketing-modal-close" data-marketing-modal-close aria-label="Close">×</button>
                    <h3 id="marketing-campaign-modal-title">Create Platform Campaign</h3>
                    <p class="ad-marketing-modal-help">Every platform discount is funded by LIKHAE and reserves funds when the order is placed.</p>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.marketing.campaigns.store') }}" id="marketing-campaign-form">
                        @csrf
                        <input type="hidden" name="_method" id="marketing-campaign-method" value="POST">
                        <input type="hidden" name="editing_campaign" id="marketing-campaign-id" value="{{ old('editing_campaign') }}">
                        <section class="ad-marketing-media-fields"><div class="ad-marketing-media-intro"><strong>1. Basic Campaign Information</strong></div><div class="ad-form-grid">
                            <label class="ad-field"><span>Campaign name</span><input id="campaign-name" name="name" value="{{ old('name') }}" maxlength="150" required></label>
                            <label class="ad-field"><span>Voucher code</span><input id="campaign-code" name="code" value="{{ old('code') }}" maxlength="80" required></label>
                        </div></section>
                        <section class="ad-marketing-media-fields"><div class="ad-marketing-media-intro"><strong>2. Discount Rules</strong></div><div class="ad-form-grid">
                            <label class="ad-field"><span>Applies to</span><select id="campaign-benefit-scope" name="benefit_scope" required><option value="PRODUCT" @selected(old('benefit_scope', 'PRODUCT') === 'PRODUCT')>Eligible product amounts</option><option value="SHIPPING" @selected(old('benefit_scope') === 'SHIPPING')>Shipping fees</option></select></label>
                            <label class="ad-field"><span>Discount type</span><select id="campaign-discount-type" name="discount_type" required><option value="PERCENT" @selected(old('discount_type', 'PERCENT') === 'PERCENT')>Percent</option><option value="FIXED" @selected(old('discount_type') === 'FIXED')>Fixed amount</option></select></label>
                            <label class="ad-field"><span>Discount value</span><input id="campaign-discount-value" name="discount_value" type="number" min="0.01" step="0.01" value="{{ old('discount_value', '10') }}" required><small>Percentage vouchers are limited to 99.99%.</small></label>
                            <label class="ad-field"><span>Minimum spend</span><input id="campaign-minimum" name="minimum_order_amount" type="number" min="0" step="0.01" value="{{ old('minimum_order_amount', '0') }}"></label>
                            <label class="ad-field"><span>Maximum discount</span><input id="campaign-maximum" name="maximum_discount_amount" type="number" min="0.01" step="0.01" value="{{ old('maximum_discount_amount') }}"></label>
                        </div></section>
                        @if($campaignControlsReady)<label class="ad-field"><span>Campaign status</span><select id="campaign-status" name="campaign_status"><option value="DRAFT" @selected(old('campaign_status') === 'DRAFT')>Draft</option><option value="ACTIVE" @selected(old('campaign_status', 'ACTIVE') === 'ACTIVE')>Active / scheduled</option><option value="PAUSED" @selected(old('campaign_status') === 'PAUSED')>Paused</option><option value="ENDED" @selected(old('campaign_status') === 'ENDED')>Ended</option></select></label>@endif
                        </div></section>
                        @if($campaignControlsReady)
                            <section class="ad-marketing-media-fields" aria-labelledby="campaign-distribution-heading">
                                <div class="ad-marketing-media-intro"><strong id="campaign-distribution-heading">3. Distribution and Eligibility</strong><span>Claiming does not reserve funding or count as redemption. Checkout rechecks each rule.</span></div>
                                <div class="ad-form-grid">
                                    <label class="ad-field"><span>Distribution method</span><select id="campaign-distribution-mode" name="distribution_mode"><option value="CODE" @selected(old('distribution_mode', 'CODE') === 'CODE')>Voucher code</option><option value="CLAIMABLE" @selected(old('distribution_mode') === 'CLAIMABLE')>Claimable in Rewards</option><option value="AUTO" @selected(old('distribution_mode') === 'AUTO')>Auto apply</option></select></label>
                                    <label class="ad-field"><span>Buyer audience</span><select id="campaign-buyer-audience" name="buyer_audience"><option value="ALL" @selected(old('buyer_audience', 'ALL') === 'ALL')>All buyers</option><option value="FIRST_TIME" @selected(old('buyer_audience') === 'FIRST_TIME')>First completed order</option><option value="EXISTING" @selected(old('buyer_audience') === 'EXISTING')>Has a completed order</option></select></label>
                                    <label class="ad-field"><span>Product targeting</span><select id="campaign-target-mode" name="target_mode"><option value="ALL" @selected(old('target_mode', 'ALL') === 'ALL')>All eligible products</option><option value="CATEGORIES" @selected(old('target_mode') === 'CATEGORIES')>Selected categories / subcategories</option><option value="PRODUCTS" @selected(old('target_mode') === 'PRODUCTS')>Selected products</option><option value="SELLERS" @selected(old('target_mode') === 'SELLERS')>Selected sellers</option></select></label>
                                </div>
                                <div id="campaign-target-picker" class="ad-field" data-endpoint="{{ route('admin.marketing.campaigns.targets') }}" @if(old('target_mode', 'ALL') === 'ALL') hidden @endif>
                                    <label for="campaign-target-search">Search campaign targets</label>
                                    <input id="campaign-target-search" type="search" maxlength="80" placeholder="Search categories, products, or sellers" autocomplete="off">
                                    <div id="campaign-target-results" role="listbox" aria-label="Campaign targets"></div>
                                    <div id="campaign-target-selection" aria-live="polite"></div>
                                    <small>Results are paginated; selected records are checked against active database rows when saved.</small>
                                </div>
                                <div class="ad-marketing-media-intro"><strong>4. Stacking Compatibility</strong><span>At most one LIKHAE product voucher can be selected.</span></div>
                                <div class="ad-form-grid">
                                    <label class="ad-marketing-popup-toggle"><input type="hidden" name="stack_with_seller" value="0"><input id="campaign-stack-seller" type="checkbox" name="stack_with_seller" value="1" checked><span><strong>Can stack with a Seller voucher</strong></span></label>
                                    <label class="ad-marketing-popup-toggle"><input type="hidden" name="stack_with_shipping" value="0"><input id="campaign-stack-shipping" type="checkbox" name="stack_with_shipping" value="1" checked><span><strong>Can stack with a shipping voucher</strong></span></label>
                                </div>
                            </section>
                        @endif
                        <section class="ad-marketing-media-fields"><div class="ad-marketing-media-intro"><strong>5. Budget and Redemption Limits</strong></div><div class="ad-form-grid">
                            <label class="ad-field"><span>Total redemptions</span><input id="campaign-usage-limit" name="usage_limit" type="number" min="1" value="{{ old('usage_limit') }}"></label>
                            <label class="ad-field"><span>Uses per buyer</span><input id="campaign-per-user-limit" name="per_user_limit" type="number" min="1" value="{{ old('per_user_limit') }}"></label>
                            @if($campaignBudgetReady)
                                <label class="ad-field"><span>Total allocated fund (₱)</span><input id="campaign-budget-total" name="campaign_budget_total" type="number" min="0.01" step="0.01" value="{{ old('campaign_budget_total') }}" required></label>
                                <label class="ad-field"><span>Budget change reason</span><input id="campaign-budget-reason" name="campaign_budget_reason" maxlength="500" value="{{ old('campaign_budget_reason') }}" placeholder="Initial campaign allocation" required></label>
                            @else
                                <div class="ad-note is-warning is-full" role="status">
                                    Fund configuration is unavailable until the campaign finance migration is applied. Campaign details can still be edited; funding and redemptions stay paused until allocations can be recorded.
                                </div>
                                <label class="ad-field"><span>Total allocated fund (₱)</span><input id="campaign-budget-total" type="number" placeholder="Unavailable until finance setup" disabled aria-disabled="true"></label>
                                <label class="ad-field"><span>Budget change reason</span><input id="campaign-budget-reason" type="text" placeholder="Unavailable until finance setup" disabled aria-disabled="true"></label>
                            @endif
                        </div></section>
                        <section class="ad-marketing-media-fields"><div class="ad-marketing-media-intro"><strong>6. Scheduling and Status</strong><span>All times use Asia/Manila. Existing orders keep their campaign reservations after pause or end.</span></div><div class="ad-form-grid">
                            <label class="ad-field"><span>Starts at</span><input id="campaign-starts-at" name="starts_at" type="datetime-local" value="{{ old('starts_at') }}"></label>
                            <label class="ad-field"><span>Ends at</span><input id="campaign-ends-at" name="ends_at" type="datetime-local" value="{{ old('ends_at') }}"></label>
                            @if($marketingMediaReady)
                            <div class="ad-marketing-media-fields">
                                <div class="ad-marketing-media-intro"><strong>7. Campaign artwork and carousel</strong><span>Optional presentation only. It does not change discounts, redemption limits, or checkout totals.</span></div>
                                <label class="ad-field"><span>Promotional image</span><input id="campaign-image" name="campaign_image" type="file" accept="image/jpeg,image/png,image/webp"></label>
                                <div class="ad-form-grid ad-marketing-media-meta">
                                    <label class="ad-field"><span>Image alt text</span><input id="campaign-image-alt" name="image_alt" value="{{ old('image_alt') }}" maxlength="255" placeholder="Describe the campaign artwork"></label>
                                    <label class="ad-field"><span>Theme color</span><input id="campaign-theme-color" name="theme_color" value="{{ old('theme_color') }}" maxlength="7" placeholder="#641F19" pattern="#[A-Fa-f0-9]{6}"></label>
                                </div>
                                <label class="ad-marketing-remove-image"><input id="campaign-remove-image" type="checkbox" name="remove_image" value="1"> Remove current campaign image</label>
                                <div class="ad-marketing-media-preview" id="campaign-image-preview" hidden><img id="campaign-image-preview-img" alt=""><span id="campaign-image-preview-label"></span></div>
                            </div>
                        @endif
                        @if($homepageCarouselReady)
                            <section class="ad-marketing-media-fields" aria-labelledby="campaign-homepage-carousel-heading">
                                <div class="ad-marketing-media-intro">
                                    <strong id="campaign-homepage-carousel-heading">Buyer Hero Carousel options</strong>
                                    <span>Carousel slides use this campaign’s schedule and show only while it is active, public, and funded. The CTA opens this campaign or the offers list.</span>
                                </div>
                                <input type="hidden" name="homepage_carousel_enabled" value="0">
                                <label class="ad-marketing-popup-toggle">
                                    <input id="campaign-homepage-carousel-enabled" type="checkbox" name="homepage_carousel_enabled" value="1" @checked(old('homepage_carousel_enabled', false))>
                                    <span><strong>Show in Buyer Hero Carousel</strong><small>Uncheck to keep this campaign off the homepage.</small></span>
                                </label>
                                <div class="ad-form-grid">
                                    <label class="ad-field"><span>Promotional headline</span><input id="campaign-homepage-carousel-headline" name="homepage_carousel_headline" value="{{ old('homepage_carousel_headline') }}" maxlength="150" placeholder="Defaults to campaign name"></label>
                                    <label class="ad-field"><span>CTA label</span><input id="campaign-homepage-carousel-cta-label" name="homepage_carousel_cta_label" value="{{ old('homepage_carousel_cta_label', 'View Offer') }}" maxlength="60" placeholder="View Offer"></label>
                                    <label class="ad-field"><span>CTA destination</span><select id="campaign-homepage-carousel-cta-destination" name="homepage_carousel_cta_destination"><option value="EVENT" @selected(old('homepage_carousel_cta_destination', 'EVENT') === 'EVENT')>This campaign</option><option value="OFFERS" @selected(old('homepage_carousel_cta_destination') === 'OFFERS')>All platform offers</option></select></label>
                                    <label class="ad-field"><span>Display order / priority</span><input id="campaign-homepage-carousel-priority" name="homepage_carousel_priority" type="number" min="0" max="65535" value="{{ old('homepage_carousel_priority', 0) }}" required><small>Lower numbers appear first.</small></label>
                                </div>
                                <label class="ad-field"><span>Short description</span><textarea id="campaign-homepage-carousel-description" name="homepage_carousel_description" rows="3" maxlength="280" placeholder="A short message shown beneath the headline">{{ old('homepage_carousel_description') }}</textarea></label>
                                <div class="ad-form-grid">
                                    <div>
                                        <label class="ad-field"><span>Desktop promotional image <small>(required to enable)</small></span><input id="campaign-homepage-carousel-desktop-image" name="homepage_carousel_desktop_image" type="file" accept="image/jpeg,image/png,image/webp"></label>
                                        <label class="ad-marketing-remove-image"><input id="campaign-homepage-carousel-remove-desktop" type="checkbox" name="remove_homepage_carousel_desktop_image" value="1" @checked(old('remove_homepage_carousel_desktop_image'))> Remove desktop image</label>
                                        <div class="ad-marketing-media-preview" id="campaign-homepage-carousel-desktop-preview" hidden><img id="campaign-homepage-carousel-desktop-preview-img" alt=""><span id="campaign-homepage-carousel-desktop-preview-label"></span></div>
                                    </div>
                                    <div>
                                        <label class="ad-field"><span>Mobile promotional image <small>(optional)</small></span><input id="campaign-homepage-carousel-mobile-image" name="homepage_carousel_mobile_image" type="file" accept="image/jpeg,image/png,image/webp"></label>
                                        <label class="ad-marketing-remove-image"><input id="campaign-homepage-carousel-remove-mobile" type="checkbox" name="remove_homepage_carousel_mobile_image" value="1" @checked(old('remove_homepage_carousel_mobile_image'))> Remove mobile image</label>
                                        <div class="ad-marketing-media-preview" id="campaign-homepage-carousel-mobile-preview" hidden><img id="campaign-homepage-carousel-mobile-preview-img" alt=""><span id="campaign-homepage-carousel-mobile-preview-label"></span></div>
                                    </div>
                                </div>
                                <small class="ad-marketing-cta-help">Artwork must be JPG, PNG, or WebP and up to 5 MB. Future and expired campaigns follow their existing start and end times.</small>
                            </section>
                        @endif
                        <section class="ad-marketing-media-fields"><div class="ad-marketing-media-intro"><strong>8. Review and Publish</strong><span>Confirm budget, audience, selected targets, date range, and preview artwork before saving.</span></div></section>
                        <div class="ad-btn-bar">
                            <button type="button" class="ad-btn ad-btn-secondary" data-marketing-modal-close>Cancel</button>
                            <button class="ad-btn ad-btn-primary" id="marketing-campaign-submit" type="submit">Create Campaign</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @else
        @php
            $editingAnnouncementId = old('editing_announcement');
            $editingAnnouncementRecord = $editingAnnouncementId
                ? $announcements->getCollection()->firstWhere('id', (int) $editingAnnouncementId)
                : null;
            $editingAnnouncementImageUrl = $marketingMediaReady
                && $editingAnnouncementRecord?->image_path
                && \Illuminate\Support\Facades\Storage::disk('public')->exists($editingAnnouncementRecord->image_path)
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($editingAnnouncementRecord->image_path)
                    : null;
            $currentAnnouncementDestination = app(\App\Services\Notifications\AnnouncementDestination::class)
                ->resolve(old('cta_url', $editingAnnouncementRecord?->cta_url));
            $knownAnnouncementDestinations = collect($announcementDestinationOptions)
                ->flatMap(fn ($groups) => collect($groups)->flatMap(fn ($group) => collect($group['options'])->pluck('value')))
                ->all();
        @endphp
        <section class="ad-card ad-card-body" id="announcements" aria-labelledby="marketing-announcements-heading">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Platform Updates</span>
                    <h2 id="marketing-announcements-heading">Announcements</h2>
                    <p>Schedule informational in-app messages for all active users or selected audiences. Announcements do not apply discounts or change checkout totals.</p>
                </div>
            </header>

            @unless($marketingMediaReady)
                <div class="ad-note is-warning" role="status">Announcement and campaign media fields are unavailable until the prepared additive marketing migration is applied. Existing marketing records remain available.</div>
            @endunless
            @unless($audienceTargetingReady)
                <div class="ad-note is-warning" role="status">Audience targeting is not installed yet. Announcements currently go to all active users. The prepared additive migration enables audience targeting.</div>
            @endunless

            <div class="ad-marketing-announcement-layout">
                <form method="POST" enctype="multipart/form-data" action="{{ $editingAnnouncementId ? route('admin.marketing.announcements.update', $editingAnnouncementId) : route('admin.marketing.announcements.store') }}" data-store-action="{{ route('admin.marketing.announcements.store') }}" class="ad-marketing-announcement-form" id="marketing-announcement-form">
                    @csrf
                    <input type="hidden" name="_method" id="announcement-method" value="{{ $editingAnnouncementId ? 'PATCH' : '' }}">
                    <input type="hidden" name="editing_announcement" id="announcement-editing-id" value="{{ $editingAnnouncementId }}">
                    <h3 id="announcement-form-title">{{ $editingAnnouncementId ? 'Edit Announcement' : 'New Announcement' }}</h3>
                    <label class="ad-field"><span>Title</span><input id="announcement-title" name="title" value="{{ old('title') }}" maxlength="255" required></label>
                    <label class="ad-field"><span>Message</span><textarea id="announcement-body" name="body" rows="6" required>{{ old('body') }}</textarea></label>
                    @if($audienceTargetingReady)
                        <label class="ad-field"><span>Audience</span><select id="announcement-audience" name="target_audience" required><option value="ALL" @selected(old('target_audience', 'ALL') === 'ALL')>All active Buyer, Seller, Logistics, and Rider accounts</option><option value="BUYERS" @selected(old('target_audience') === 'BUYERS')>Buyers</option><option value="SELLERS" @selected(old('target_audience') === 'SELLERS')>Sellers</option><option value="LOGISTICS" @selected(old('target_audience') === 'LOGISTICS')>Logistics and riders (existing audience)</option><option value="LOGISTICS_CENTERS" @selected(old('target_audience') === 'LOGISTICS_CENTERS')>Logistics centers</option><option value="RIDERS" @selected(old('target_audience') === 'RIDERS')>Riders</option></select></label>
                    @else
                        <input type="hidden" name="target_audience" value="ALL">
                    @endif
                    <label class="ad-field"><span>Publish at <small>(leave blank to publish now)</small></span><input id="announcement-published-at" type="datetime-local" name="published_at" value="{{ old('published_at') }}"></label>
                    <label class="ad-field"><span>Expires at</span><input id="announcement-expires-at" type="datetime-local" name="expires_at" value="{{ old('expires_at') }}"></label>
                    @if($marketingMediaReady)
                        <div class="ad-marketing-media-fields">
                            <div class="ad-marketing-media-intro"><strong>Popup presentation</strong><span>Images and theme only affect the announcement card. They never change prices or voucher redemptions.</span></div>
                            <label class="ad-field"><span>Promotional image</span><input id="announcement-image" name="announcement_image" type="file" accept="image/jpeg,image/png,image/webp"></label>
                            <label class="ad-field"><span>Image alt text</span><input id="announcement-image-alt" name="image_alt" value="{{ old('image_alt', $editingAnnouncementRecord?->image_alt) }}" maxlength="255" placeholder="Describe the announcement image"></label>
                            <label class="ad-field"><span>Theme color</span><input id="announcement-theme-color" name="theme_color" value="{{ old('theme_color', $editingAnnouncementRecord?->theme_color) }}" maxlength="7" placeholder="#641F19" pattern="#[A-Fa-f0-9]{6}"></label>
                            <label class="ad-marketing-remove-image"><input id="announcement-remove-image" type="checkbox" name="remove_image" value="1" @checked(old('remove_image'))> Remove current announcement image</label>
                            <input type="hidden" name="show_popup" value="0">
                            <label class="ad-marketing-popup-toggle"><input id="announcement-show-popup" type="checkbox" name="show_popup" value="1" @checked(old('show_popup', $editingAnnouncementRecord?->show_popup ?? true))><span><strong>Show as login popup</strong><small>The announcement remains in the notification history when this is turned off.</small></span></label>
                            <div class="ad-marketing-media-preview" id="announcement-image-preview" @if(!$editingAnnouncementImageUrl || old('remove_image')) hidden @endif><img id="announcement-image-preview-img" src="{{ $editingAnnouncementImageUrl ?: '' }}" alt=""><span id="announcement-image-preview-label">{{ $editingAnnouncementImageUrl ? 'Current announcement image' : '' }}</span></div>
                            <small class="ad-marketing-cta-help">Allowed: JPG, PNG, or WebP up to 5 MB.</small>
                            <section class="ad-marketing-cta-settings" aria-labelledby="announcement-cta-heading">
                                <header class="ad-marketing-cta-settings-head">
                                    <span aria-hidden="true">↗</span>
                                    <div><strong id="announcement-cta-heading">Page CTA</strong><small>Choose a page that matches the announcement audience.</small></div>
                                </header>
                                <label class="ad-field"><span>CTA label</span><input id="announcement-cta-label" name="cta_label" value="{{ old('cta_label', $editingAnnouncementRecord?->cta_label) }}" maxlength="80" placeholder="Shop Now"></label>
                                <label class="ad-field"><span>CTA destination</span>
                                    <select id="announcement-cta-url" name="cta_url">
                                        <option value="">No destination</option>
                                        @foreach($announcementDestinationOptions as $audienceKey => $groups)
                                            @foreach($groups as $group)
                                                <optgroup label="{{ $group['label'] }}" data-cta-audience="{{ $audienceKey }}">
                                                    @foreach($group['options'] as $option)
                                                        <option value="{{ $option['value'] }}" @selected($currentAnnouncementDestination === $option['value'])>{{ $option['label'] }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @endforeach
                                        @endforeach
                                        @if($currentAnnouncementDestination && !in_array($currentAnnouncementDestination, $knownAnnouncementDestinations, true))
                                            <option value="{{ $currentAnnouncementDestination }}" data-cta-audience="ALL">Current saved destination · {{ $currentAnnouncementDestination }}</option>
                                        @endif
                                    </select>
                                </label>
                                <small class="ad-marketing-cta-help">The page list follows the selected audience. Leave it blank to show the announcement without a button.</small>
                            </section>
                        </div>
                    @endif
                    <div class="ad-marketing-announcement-form-actions">
                        <button class="ad-btn ad-btn-primary" id="announcement-submit" type="submit">{{ $editingAnnouncementId ? 'Save Announcement' : 'Publish Announcement' }}</button>
                        <button class="ad-btn ad-btn-secondary" id="announcement-cancel-edit" type="button" @if(!$editingAnnouncementId) hidden @endif>Cancel edit</button>
                    </div>
                </form>

                <div class="ad-marketing-announcement-history">
                    <div class="ad-marketing-history-head"><div><h3>Announcement History</h3><p>{{ number_format($announcements->total()) }} announcements</p></div></div>
                    @forelse($announcements as $announcement)
                        @php
                            $announcementStatus = ! $announcement->is_active
                                ? 'Paused'
                                : (! $announcement->published_at
                                    ? 'Draft'
                                    : ($announcement->published_at->isFuture()
                                    ? 'Scheduled'
                                    : ($announcement->expires_at?->isPast()
                                        ? 'Expired'
                                        : 'Published')));
                            $announcementStatusClass = match ($announcementStatus) {
                                'Published' => 'is-active',
                                'Scheduled' => 'is-pending',
                                'Expired' => 'is-inactive',
                                'Paused' => 'is-review',
                                default => '',
                            };
                            $announcementImageUrl = $marketingMediaReady
                                && $announcement->image_path
                                && \Illuminate\Support\Facades\Storage::disk('public')->exists($announcement->image_path)
                                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($announcement->image_path)
                                    : null;
                            $announcementCtaUrl = app(\App\Services\Notifications\AnnouncementDestination::class)
                                ->resolve($announcement->cta_url);
                        @endphp
                        <article class="ad-marketing-announcement">
                            <div class="ad-marketing-announcement-title">
                                @if($announcementImageUrl)<img src="{{ $announcementImageUrl }}" alt="{{ $announcement->image_alt ?: $announcement->title }}">@endif
                                <strong>{{ $announcement->title }}</strong><span class="ad-status {{ $announcementStatusClass }}">{{ $announcementStatus }}</span>
                            </div>
                            <p>{{ $announcement->body }}</p>
                            <div class="ad-marketing-announcement-meta">
                                <span>{{ str($announcement->target_audience ?? 'ALL')->replace('_', ' ')->headline() }}</span>
                                <span>{{ $announcement->published_at?->format('M j, Y H:i') ?? 'No publish time' }}@if($announcement->expires_at) · Expires {{ $announcement->expires_at->format('M j, Y H:i') }}@endif</span>
                                @if($announcement->cta_label && $announcement->cta_url)<span>CTA: {{ $announcement->cta_label }}</span>@endif
                                <button type="button" class="ad-btn ad-btn-secondary ad-btn-xs" data-announcement-edit
                                    data-id="{{ $announcement->id }}"
                                    data-action="{{ route('admin.marketing.announcements.update', $announcement) }}"
                                    data-title="{{ $announcement->title }}" data-body="{{ $announcement->body }}"
                                    data-audience="{{ $announcement->target_audience ?? 'ALL' }}"
                                    data-published-at="{{ $announcement->published_at?->format('Y-m-d\TH:i') }}"
                                    data-expires-at="{{ $announcement->expires_at?->format('Y-m-d\TH:i') }}"
                                    data-image-url="{{ $announcementImageUrl }}" data-image-alt="{{ $announcement->image_alt }}"
                                    data-theme-color="{{ $announcement->theme_color }}" data-cta-label="{{ $announcement->cta_label }}"
                                    data-cta-url="{{ $announcementCtaUrl }}" data-show-popup="{{ $announcement->show_popup ? '1' : '0' }}">Edit</button>
                                <form method="POST" action="{{ route('admin.marketing.announcements.toggle', $announcement) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="ad-btn ad-btn-secondary ad-btn-xs">{{ $announcement->is_active ? 'Pause' : 'Resume' }}</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <p class="ad-marketing-empty">No announcements yet.</p>
                    @endforelse
                    {{ $announcements->links() }}
                </div>
            </div>
        </section>
    @endif
</div>

@if($section === 'campaigns' && $campaignsReady)
    <script>
        (() => {
            const modal = document.getElementById('marketing-campaign-modal');
            const form = document.getElementById('marketing-campaign-form');
            if (!modal || !form) return;

            const fields = {
                name: document.getElementById('campaign-name'),
                code: document.getElementById('campaign-code'),
                benefitScope: document.getElementById('campaign-benefit-scope'),
                discountType: document.getElementById('campaign-discount-type'),
                discountValue: document.getElementById('campaign-discount-value'),
                minimum: document.getElementById('campaign-minimum'),
                maximum: document.getElementById('campaign-maximum'),
                usageLimit: document.getElementById('campaign-usage-limit'),
                perUserLimit: document.getElementById('campaign-per-user-limit'),
                budgetTotal: document.getElementById('campaign-budget-total'),
                budgetReason: document.getElementById('campaign-budget-reason'),
                startsAt: document.getElementById('campaign-starts-at'),
                endsAt: document.getElementById('campaign-ends-at'),
                homepageHeadline: document.getElementById('campaign-homepage-carousel-headline'),
                homepageDescription: document.getElementById('campaign-homepage-carousel-description'),
                homepageCtaLabel: document.getElementById('campaign-homepage-carousel-cta-label'),
                homepageCtaDestination: document.getElementById('campaign-homepage-carousel-cta-destination'),
                homepagePriority: document.getElementById('campaign-homepage-carousel-priority'),
            };
            const initial = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field?.value || '']));
            const initialMedia = {
                imageAlt: document.getElementById('campaign-image-alt')?.value || '',
                themeColor: document.getElementById('campaign-theme-color')?.value || '',
                removeImage: @json((bool) old('remove_image')),
                removeHomepageDesktopImage: @json((bool) old('remove_homepage_carousel_desktop_image')),
                removeHomepageMobileImage: @json((bool) old('remove_homepage_carousel_mobile_image')),
            };
            const homepageEnabledField = document.getElementById('campaign-homepage-carousel-enabled');
            const initialHomepageEnabled = @json((bool) old('homepage_carousel_enabled'));
            const homepageImageControls = {
                desktop: {
                    field: document.getElementById('campaign-homepage-carousel-desktop-image'),
                    remove: document.getElementById('campaign-homepage-carousel-remove-desktop'),
                    preview: document.getElementById('campaign-homepage-carousel-desktop-preview'),
                    image: document.getElementById('campaign-homepage-carousel-desktop-preview-img'),
                    label: document.getElementById('campaign-homepage-carousel-desktop-preview-label'),
                    objectUrl: null,
                    currentUrl: '',
                    currentLabel: '',
                },
                mobile: {
                    field: document.getElementById('campaign-homepage-carousel-mobile-image'),
                    remove: document.getElementById('campaign-homepage-carousel-remove-mobile'),
                    preview: document.getElementById('campaign-homepage-carousel-mobile-preview'),
                    image: document.getElementById('campaign-homepage-carousel-mobile-preview-img'),
                    label: document.getElementById('campaign-homepage-carousel-mobile-preview-label'),
                    objectUrl: null,
                    currentUrl: '',
                    currentLabel: '',
                },
            };
            const method = document.getElementById('marketing-campaign-method');
            const campaignId = document.getElementById('marketing-campaign-id');
            const title = document.getElementById('marketing-campaign-modal-title');
            const submit = document.getElementById('marketing-campaign-submit');
            const campaignControlsReady = @json($campaignControlsReady);
            const targetMode = document.getElementById('campaign-target-mode');
            const targetSearch = document.getElementById('campaign-target-search');
            const targetPicker = document.getElementById('campaign-target-picker');
            const targetResults = document.getElementById('campaign-target-results');
            const targetSelection = document.getElementById('campaign-target-selection');
            const targetIds = new Set(@json(array_map('intval', (array) old('target_ids', []))));
            const initialTargetIds = Array.from(targetIds);
            const distributionMode = document.getElementById('campaign-distribution-mode');
            const buyerAudience = document.getElementById('campaign-buyer-audience');
            const campaignStatus = document.getElementById('campaign-status');
            const stackWithSeller = document.getElementById('campaign-stack-seller');
            const stackWithShipping = document.getElementById('campaign-stack-shipping');
            const initialDistributionMode = distributionMode?.value || 'CODE';
            const initialTargetMode = targetMode?.value || 'ALL';
            const initialBuyerAudience = buyerAudience?.value || 'ALL';
            const initialCampaignStatus = campaignStatus?.value || 'ACTIVE';
            const imageField = document.getElementById('campaign-image');
            const imageAltField = document.getElementById('campaign-image-alt');
            const themeColorField = document.getElementById('campaign-theme-color');
            const removeImageField = document.getElementById('campaign-remove-image');
            const imagePreview = document.getElementById('campaign-image-preview');
            const imagePreviewImage = document.getElementById('campaign-image-preview-img');
            const imagePreviewLabel = document.getElementById('campaign-image-preview-label');
            let previewObjectUrl = null;
            let opener = null;

            const targetLabels = new Map();
            const renderTargetSelection = () => {
                if (!targetSelection) return;
                targetSelection.replaceChildren();
                form.querySelectorAll('input[name="target_ids[]"]').forEach((input) => input.remove());
                targetIds.forEach((id) => {
                    const label = document.createElement('span');
                    label.className = 'ad-status is-pending';
                    label.textContent = `${targetLabels.get(id) || `#${id}`} `;
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.textContent = 'Remove';
                    remove.setAttribute('aria-label', `Remove ${label.textContent}`);
                    remove.addEventListener('click', () => { targetIds.delete(id); renderTargetSelection(); });
                    label.append(remove);
                    targetSelection.append(label);
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = 'target_ids[]'; hidden.value = String(id);
                    form.append(hidden);
                });
            };
            let targetPage = 1;
            const searchTargets = async (page = 1) => {
                if (!campaignControlsReady || !targetPicker || !targetMode || targetMode.value === 'ALL') {
                    if (targetResults) targetResults.replaceChildren();
                    return;
                }
                targetPage = page;
                const url = new URL(targetPicker.dataset.endpoint, window.location.origin);
                url.searchParams.set('mode', targetMode.value);
                url.searchParams.set('q', targetSearch?.value || '');
                url.searchParams.set('page', String(page));
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) return;
                const payload = await response.json();
                targetResults.replaceChildren();
                payload.data.forEach((record) => {
                    const button = document.createElement('button');
                    button.type = 'button'; button.setAttribute('role', 'option');
                    button.textContent = `${record.label} · #${record.id}`;
                    button.disabled = targetIds.has(record.id);
                    button.addEventListener('click', () => {
                        targetIds.add(record.id); targetLabels.set(record.id, record.label);
                        renderTargetSelection(); searchTargets(targetPage);
                    });
                    targetResults.append(button);
                });
                if (payload.last_page > 1) {
                    const pager = document.createElement('div');
                    const previous = document.createElement('button'); previous.type = 'button'; previous.textContent = 'Previous'; previous.disabled = page <= 1;
                    previous.addEventListener('click', () => searchTargets(page - 1));
                    const next = document.createElement('button'); next.type = 'button'; next.textContent = 'Next'; next.disabled = page >= payload.last_page;
                    next.addEventListener('click', () => searchTargets(page + 1));
                    pager.append(previous, document.createTextNode(` Page ${page} of ${payload.last_page} `), next);
                    targetResults.append(pager);
                }
            };
            let targetSearchTimer = null;
            targetSearch?.addEventListener('input', () => { clearTimeout(targetSearchTimer); targetSearchTimer = setTimeout(() => searchTargets(1), 250); });
            targetMode?.addEventListener('change', () => {
                targetIds.clear(); renderTargetSelection();
                targetPicker.hidden = targetMode.value === 'ALL';
                searchTargets(1);
            });
            renderTargetSelection();

            const setImagePreview = (url, label = '') => {
                if (!imagePreview) return;
                if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
                previewObjectUrl = null;
                if (!url) {
                    imagePreview.hidden = true;
                    imagePreviewImage.removeAttribute('src');
                    imagePreviewLabel.textContent = '';
                    return;
                }
                imagePreviewImage.src = url;
                imagePreviewLabel.textContent = label;
                imagePreview.hidden = false;
            };

            const setHomepageImagePreview = (imageControl, url, label = '') => {
                if (!imageControl.preview) return;
                if (imageControl.objectUrl && imageControl.objectUrl !== url) URL.revokeObjectURL(imageControl.objectUrl);
                imageControl.objectUrl = url.startsWith('blob:') ? url : null;
                if (!url) {
                    imageControl.preview.hidden = true;
                    imageControl.image.removeAttribute('src');
                    imageControl.label.textContent = '';
                    return;
                }
                imageControl.image.src = url;
                imageControl.label.textContent = label;
                imageControl.preview.hidden = false;
            };

            Object.values(homepageImageControls).forEach((imageControl) => {
                imageControl.field?.addEventListener('change', () => {
                    const file = imageControl.field.files?.[0];
                    if (!file) return;
                    if (imageControl.objectUrl) URL.revokeObjectURL(imageControl.objectUrl);
                    imageControl.objectUrl = URL.createObjectURL(file);
                    setHomepageImagePreview(imageControl, imageControl.objectUrl, file.name);
                    if (imageControl.remove) imageControl.remove.checked = false;
                });
                imageControl.remove?.addEventListener('change', () => {
                    if (imageControl.remove.checked && !imageControl.field?.files?.length) {
                        setHomepageImagePreview(imageControl, '', '');
                    } else if (!imageControl.remove.checked && imageControl.currentUrl) {
                        setHomepageImagePreview(imageControl, imageControl.currentUrl, imageControl.currentLabel);
                    }
                });
            });

            imageField?.addEventListener('change', () => {
                const file = imageField.files?.[0];
                if (!file) return;
                previewObjectUrl = URL.createObjectURL(file);
                imagePreviewImage.src = previewObjectUrl;
                imagePreviewLabel.textContent = file.name;
                imagePreview.hidden = false;
                if (removeImageField) removeImageField.checked = false;
            });
            removeImageField?.addEventListener('change', () => {
                if (removeImageField.checked && !imageField?.files?.length) setImagePreview('', '');
            });

            const close = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('ad-marketing-modal-open');
                opener?.focus();
            };
            const open = (button) => {
                opener = button;
                const editing = button.dataset.marketingCampaignOpen === 'edit';
                form.action = editing ? button.dataset.action : @json(route('admin.marketing.campaigns.store'));
                method.value = editing ? 'PATCH' : 'POST';
                campaignId.value = editing ? button.dataset.campaignId : '';
                title.textContent = editing ? 'Edit Platform Campaign' : 'Create Platform Campaign';
                submit.textContent = editing ? 'Save Changes' : 'Create Campaign';
                if (editing) {
                    fields.name.value = button.dataset.name || '';
                    fields.code.value = button.dataset.code || '';
                    fields.benefitScope.value = button.dataset.benefitScope || 'PRODUCT';
                    fields.discountType.value = button.dataset.discountType || 'PERCENT';
                    fields.discountValue.value = button.dataset.discountValue || '';
                    fields.minimum.value = button.dataset.minimum || '0';
                    fields.maximum.value = button.dataset.maximum || '';
                    fields.usageLimit.value = button.dataset.usageLimit || '';
                    fields.perUserLimit.value = button.dataset.perUserLimit || '';
                    if (fields.budgetTotal) fields.budgetTotal.value = button.dataset.budget || '';
                    if (fields.budgetReason) fields.budgetReason.value = '';
                    fields.startsAt.value = button.dataset.startsAt || '';
                    fields.endsAt.value = button.dataset.endsAt || '';
                    if (campaignControlsReady) {
                        distributionMode.value = button.dataset.distributionMode || 'CODE';
                        buyerAudience.value = button.dataset.buyerAudience || 'ALL';
                        campaignStatus.value = button.dataset.campaignStatus || 'ACTIVE';
                        targetMode.value = button.dataset.targetMode || 'ALL';
                        stackWithSeller.checked = button.dataset.stackWithSeller === '1';
                        stackWithShipping.checked = button.dataset.stackWithShipping === '1';
                        targetIds.clear();
                        try { JSON.parse(button.dataset.targetIds || '[]').forEach((id) => targetIds.add(Number(id))); } catch (_) {}
                        targetPicker.hidden = targetMode.value === 'ALL';
                        renderTargetSelection();
                        searchTargets(1);
                    }
                    if (fields.homepageHeadline) fields.homepageHeadline.value = button.dataset.homepageCarouselHeadline || '';
                    if (fields.homepageDescription) fields.homepageDescription.value = button.dataset.homepageCarouselDescription || '';
                    if (fields.homepageCtaLabel) fields.homepageCtaLabel.value = button.dataset.homepageCarouselCtaLabel || 'View Offer';
                    if (fields.homepageCtaDestination) fields.homepageCtaDestination.value = button.dataset.homepageCarouselCtaDestination || 'EVENT';
                    if (fields.homepagePriority) fields.homepagePriority.value = button.dataset.homepageCarouselPriority || '0';
                    if (homepageEnabledField) homepageEnabledField.checked = button.dataset.homepageCarouselEnabled === '1';
                    if (imageField) imageField.value = '';
                    if (imageAltField) imageAltField.value = button.dataset.imageAlt || '';
                    if (themeColorField) themeColorField.value = button.dataset.themeColor || '';
                    if (removeImageField) removeImageField.checked = false;
                    setImagePreview(button.dataset.imageUrl || '', button.dataset.imageUrl ? 'Current campaign image' : '');
                    ['desktop', 'mobile'].forEach((kind) => {
                        const imageControl = homepageImageControls[kind];
                        if (imageControl.field) imageControl.field.value = '';
                        if (imageControl.remove) imageControl.remove.checked = false;
                        imageControl.currentUrl = kind === 'desktop'
                            ? (button.dataset.homepageCarouselDesktopUrl || '')
                            : (button.dataset.homepageCarouselMobileUrl || '');
                        imageControl.currentLabel = imageControl.currentUrl ? ('Current ' + kind + ' artwork') : '';
                        setHomepageImagePreview(imageControl, imageControl.currentUrl, imageControl.currentLabel);
                    });
                } else {
                    Object.entries(fields).forEach(([key, field]) => { if (field) field.value = initial[key]; });
                    if (campaignControlsReady) {
                        distributionMode.value = initialDistributionMode;
                        buyerAudience.value = initialBuyerAudience;
                        campaignStatus.value = initialCampaignStatus;
                        targetMode.value = initialTargetMode;
                        stackWithSeller.checked = true;
                        stackWithShipping.checked = true;
                        targetIds.clear(); initialTargetIds.forEach((id) => targetIds.add(id));
                        targetPicker.hidden = targetMode.value === 'ALL';
                        renderTargetSelection();
                        searchTargets(1);
                    }
                    if (homepageEnabledField) homepageEnabledField.checked = initialHomepageEnabled;
                    if (imageField) imageField.value = '';
                    if (imageAltField) imageAltField.value = initialMedia.imageAlt;
                    if (themeColorField) themeColorField.value = initialMedia.themeColor;
                    if (removeImageField) removeImageField.checked = false;
                    setImagePreview('', '');
                    ['desktop', 'mobile'].forEach((kind) => {
                        const imageControl = homepageImageControls[kind];
                        if (imageControl.field) imageControl.field.value = '';
                        if (imageControl.remove) imageControl.remove.checked = false;
                        imageControl.currentUrl = '';
                        imageControl.currentLabel = '';
                        setHomepageImagePreview(imageControl, '', '');
                    });
                }
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('ad-marketing-modal-open');
                fields.name.focus();
            };

            document.querySelectorAll('[data-marketing-campaign-open]').forEach((button) => button.addEventListener('click', () => open(button)));
            modal.querySelectorAll('[data-marketing-modal-close]').forEach((button) => button.addEventListener('click', close));
            modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
            document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.classList.contains('is-open')) close(); });
            @if($errors->has('name') || $errors->has('code') || $errors->has('benefit_scope') || $errors->has('discount_type') || $errors->has('discount_value') || $errors->has('minimum_order_amount') || $errors->has('maximum_discount_amount') || $errors->has('usage_limit') || $errors->has('per_user_limit') || $errors->has('campaign_budget_total') || $errors->has('campaign_budget_reason') || $errors->has('starts_at') || $errors->has('ends_at') || $errors->has('campaign_image') || $errors->has('image_alt') || $errors->has('theme_color') || $errors->has('distribution_mode') || $errors->has('target_mode') || $errors->has('target_ids') || $errors->has('buyer_audience') || $errors->has('stack_with_seller') || $errors->has('stack_with_shipping') || $errors->has('campaign_status') || $errors->has('homepage_carousel_enabled') || $errors->has('homepage_carousel_headline') || $errors->has('homepage_carousel_description') || $errors->has('homepage_carousel_cta_label') || $errors->has('homepage_carousel_cta_destination') || $errors->has('homepage_carousel_priority') || $errors->has('homepage_carousel_desktop_image') || $errors->has('homepage_carousel_mobile_image') || $errors->has('remove_homepage_carousel_desktop_image') || $errors->has('remove_homepage_carousel_mobile_image'))
                const failedCampaignId = @json(old('editing_campaign'));
                const editButton = Array.from(document.querySelectorAll('[data-marketing-campaign-open="edit"]'))
                    .find((button) => button.dataset.campaignId === String(failedCampaignId));
                const createButton = document.querySelector('[data-marketing-campaign-open="create"]');
                const failedButton = editButton || createButton;
                if (failedButton) {
                    open(failedButton);
                    Object.entries(fields).forEach(([key, field]) => { if (field) field.value = initial[key]; });
                    if (imageAltField) imageAltField.value = initialMedia.imageAlt;
                    if (themeColorField) themeColorField.value = initialMedia.themeColor;
                    if (removeImageField) removeImageField.checked = initialMedia.removeImage;
                    if (initialMedia.removeImage) setImagePreview('', '');
                    if (homepageEnabledField) homepageEnabledField.checked = initialHomepageEnabled;
                    if (homepageImageControls.desktop.remove) homepageImageControls.desktop.remove.checked = initialMedia.removeHomepageDesktopImage;
                    if (homepageImageControls.mobile.remove) homepageImageControls.mobile.remove.checked = initialMedia.removeHomepageMobileImage;
                    if (initialMedia.removeHomepageDesktopImage) setHomepageImagePreview(homepageImageControls.desktop, '', '');
                    if (initialMedia.removeHomepageMobileImage) setHomepageImagePreview(homepageImageControls.mobile, '', '');
                }
            @endif
        })();
    </script>
@endif

@if($section === 'announcements')
    <script>
        (() => {
            const form = document.getElementById('marketing-announcement-form');
            if (!form) return;

            const fields = {
                title: document.getElementById('announcement-title'),
                body: document.getElementById('announcement-body'),
                audience: document.getElementById('announcement-audience'),
                publishedAt: document.getElementById('announcement-published-at'),
                expiresAt: document.getElementById('announcement-expires-at'),
                imageAlt: document.getElementById('announcement-image-alt'),
                themeColor: document.getElementById('announcement-theme-color'),
                ctaLabel: document.getElementById('announcement-cta-label'),
                ctaUrl: document.getElementById('announcement-cta-url'),
                showPopup: document.getElementById('announcement-show-popup'),
            };
            const initial = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field?.type === 'checkbox' ? field.checked : (field?.value || '')]));
            const method = document.getElementById('announcement-method');
            const editingId = document.getElementById('announcement-editing-id');
            const title = document.getElementById('announcement-form-title');
            const submit = document.getElementById('announcement-submit');
            const cancel = document.getElementById('announcement-cancel-edit');
            const imageField = document.getElementById('announcement-image');
            const removeImage = document.getElementById('announcement-remove-image');
            const imagePreview = document.getElementById('announcement-image-preview');
            const imagePreviewImage = document.getElementById('announcement-image-preview-img');
            const imagePreviewLabel = document.getElementById('announcement-image-preview-label');
            let previewObjectUrl = null;

            const syncDestinationOptions = () => {
                if (!fields.ctaUrl) return;
                const audience = fields.audience?.value || 'ALL';
                fields.ctaUrl.querySelectorAll('optgroup[data-cta-audience]').forEach((group) => {
                    group.disabled = group.dataset.ctaAudience !== 'ALL' && group.dataset.ctaAudience !== audience;
                });
                const selectedGroup = fields.ctaUrl.selectedOptions[0]?.parentElement;
                if (selectedGroup instanceof HTMLOptGroupElement && selectedGroup.disabled) fields.ctaUrl.value = '';
            };

            const setDestination = (value) => {
                if (!fields.ctaUrl) return;
                fields.ctaUrl.querySelectorAll('option[data-cta-saved]').forEach((option) => option.remove());
                if (value && !Array.from(fields.ctaUrl.options).some((option) => option.value === value)) {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = `Current saved destination · ${value}`;
                    option.dataset.ctaSaved = 'true';
                    fields.ctaUrl.append(option);
                }
                fields.ctaUrl.value = value || '';
            };

            syncDestinationOptions();
            fields.audience?.addEventListener('change', syncDestinationOptions);

            const setImagePreview = (url, label = '') => {
                if (!imagePreview) return;
                if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
                previewObjectUrl = null;
                if (!url) {
                    imagePreview.hidden = true;
                    imagePreviewImage.removeAttribute('src');
                    imagePreviewLabel.textContent = '';
                    return;
                }
                imagePreviewImage.src = url;
                imagePreviewLabel.textContent = label;
                imagePreview.hidden = false;
            };

            const resetToCreate = () => {
                form.action = form.dataset.storeAction;
                method.value = '';
                editingId.value = '';
                title.textContent = 'New Announcement';
                submit.textContent = 'Publish Announcement';
                Object.entries(fields).forEach(([key, field]) => {
                    if (!field) return;
                    if (field.type === 'checkbox') field.checked = Boolean(initial[key]);
                    else field.value = initial[key];
                });
                syncDestinationOptions();
                setDestination(initial.ctaUrl);
                if (imageField) imageField.value = '';
                if (removeImage) removeImage.checked = false;
                setImagePreview('', '');
                cancel.hidden = true;
            };

            document.querySelectorAll('[data-announcement-edit]').forEach((button) => {
                button.addEventListener('click', () => {
                    form.action = button.dataset.action;
                    method.value = 'PATCH';
                    editingId.value = button.dataset.id || '';
                    title.textContent = 'Edit Announcement';
                    submit.textContent = 'Save Announcement';
                    if (fields.title) fields.title.value = button.dataset.title || '';
                    if (fields.body) fields.body.value = button.dataset.body || '';
                    if (fields.audience) fields.audience.value = button.dataset.audience || 'ALL';
                    syncDestinationOptions();
                    if (fields.publishedAt) fields.publishedAt.value = button.dataset.publishedAt || '';
                    if (fields.expiresAt) fields.expiresAt.value = button.dataset.expiresAt || '';
                    if (fields.imageAlt) fields.imageAlt.value = button.dataset.imageAlt || '';
                    if (fields.themeColor) fields.themeColor.value = button.dataset.themeColor || '';
                    if (fields.ctaLabel) fields.ctaLabel.value = button.dataset.ctaLabel || '';
                    setDestination(button.dataset.ctaUrl || '');
                    if (fields.showPopup) fields.showPopup.checked = button.dataset.showPopup === '1';
                    if (imageField) imageField.value = '';
                    if (removeImage) removeImage.checked = false;
                    setImagePreview(button.dataset.imageUrl || '', button.dataset.imageUrl ? 'Current announcement image' : '');
                    cancel.hidden = false;
                    form.scrollIntoView({behavior: 'smooth', block: 'start'});
                    fields.title?.focus({preventScroll: true});
                });
            });

            cancel?.addEventListener('click', resetToCreate);
            imageField?.addEventListener('change', () => {
                const file = imageField.files?.[0];
                if (!file) return;
                previewObjectUrl = URL.createObjectURL(file);
                imagePreviewImage.src = previewObjectUrl;
                imagePreviewLabel.textContent = file.name;
                imagePreview.hidden = false;
                if (removeImage) removeImage.checked = false;
            });
            removeImage?.addEventListener('change', () => {
                if (removeImage.checked && !imageField?.files?.length) setImagePreview('', '');
            });
        })();
    </script>
@endif
@endsection
