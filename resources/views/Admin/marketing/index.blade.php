@extends('layouts.admin')

@section('title', 'Platform Marketing')
@section('subtitle', 'Manage marketplace campaigns and scheduled announcements.')
@section('active', 'marketing')

@section('content')
<div class="ad-page ad-marketing-page">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Marketplace Growth</span>
            <h2>Platform Marketing</h2>
            <p>Manage LIKHAE platform voucher campaigns and marketplace announcements.</p>
        </div>
        @if($section === 'campaigns' && $campaignsReady)
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
                    <p>Buyer vouchers owned by LIKHAE and available across participating seller shops.</p>
                </div>
            </header>

            @unless($campaignsReady)
                <div class="ad-note is-warning" role="status">Platform campaign storage is not installed yet. The additive voucher migration is prepared but has not been applied. Campaign creation and management will be available after it is applied.</div>
            @else
                <div class="ad-table-wrap ad-marketing-table-wrap">
                    <table class="ad-table">
                        <thead><tr><th>Campaign</th><th>Benefit</th><th>Minimum</th><th>Usage</th><th>Validity</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                            @forelse($campaigns as $campaign)
                                @php
                                    $campaignStatus = ! $campaign->is_active
                                        ? 'Paused'
                                        : ($campaign->starts_at?->isFuture()
                                            ? 'Scheduled'
                                            : ($campaign->ends_at?->isPast() ? 'Expired' : 'Active'));
                                    $campaignStatusClass = match ($campaignStatus) {
                                        'Active' => 'is-active',
                                        'Scheduled' => 'is-pending',
                                        'Expired' => 'is-inactive',
                                        default => 'is-review',
                                    };
                                @endphp
                                <tr>
                                    <td><strong>{{ $campaign->name }}</strong><small>{{ $campaign->code }}</small></td>
                                    <td>{{ $campaign->discount_type === 'PERCENT' ? number_format((float) $campaign->discount_value, 2).'%' : '₱'.number_format((float) $campaign->discount_value, 2) }} off<small>Max discount: {{ $campaign->maximum_discount_amount === null ? 'No cap' : '₱'.number_format((float) $campaign->maximum_discount_amount, 2) }}</small></td>
                                    <td>₱{{ number_format((float) $campaign->minimum_order_amount, 2) }}</td>
                                    <td>{{ number_format((int) $campaign->redemption_count) }} / {{ $campaign->usage_limit ?: 'Unlimited' }}<small>Per buyer: {{ $campaign->per_user_limit ?: 'Unlimited' }}</small></td>
                                    <td>{{ $campaign->starts_at?->format('M j, Y H:i') ?? 'Now' }} – {{ $campaign->ends_at?->format('M j, Y H:i') ?? 'No end' }}</td>
                                    <td><span class="ad-status {{ $campaignStatusClass }}">{{ $campaignStatus }}</span></td>
                                    <td>
                                        <div class="ad-marketing-actions">
                                            <button type="button" class="ad-btn ad-btn-secondary ad-btn-xs" data-marketing-campaign-open="edit"
                                                data-campaign-id="{{ $campaign->id }}"
                                                data-action="{{ route('admin.marketing.campaigns.update', $campaign) }}"
                                                data-name="{{ $campaign->name }}" data-code="{{ $campaign->code }}"
                                                data-discount-type="{{ $campaign->discount_type }}" data-discount-value="{{ $campaign->discount_value }}"
                                                data-minimum="{{ $campaign->minimum_order_amount }}" data-maximum="{{ $campaign->maximum_discount_amount }}"
                                                data-usage-limit="{{ $campaign->usage_limit }}" data-per-user-limit="{{ $campaign->per_user_limit }}"
                                                data-starts-at="{{ $campaign->starts_at?->format('Y-m-d\TH:i') }}" data-ends-at="{{ $campaign->ends_at?->format('Y-m-d\TH:i') }}">Edit</button>
                                            <form method="POST" action="{{ route('admin.marketing.campaigns.toggle', $campaign) }}">
                                                @csrf @method('PATCH')
                                                <button class="ad-btn {{ $campaign->is_active ? 'ad-btn-warning-soft' : 'ad-btn-primary' }} ad-btn-xs" type="submit">{{ $campaign->is_active ? 'Pause' : 'Resume' }}</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="ad-marketing-empty">No platform campaigns yet. Create one to make a LIKHAE voucher available to buyers.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $campaigns->links() }}
                <p class="ad-marketing-finance-note">Discount funding remains unresolved and requires finance review. Campaign setup does not assign the discount to seller or platform settlement.</p>
            @endunless
        </section>

        @if($campaignsReady)
            <div class="ad-marketing-modal" id="marketing-campaign-modal" aria-hidden="true">
                <div class="ad-marketing-modal-content" role="dialog" aria-modal="true" aria-labelledby="marketing-campaign-modal-title">
                    <button type="button" class="ad-marketing-modal-close" data-marketing-modal-close aria-label="Close">×</button>
                    <h3 id="marketing-campaign-modal-title">Create Platform Campaign</h3>
                    <p class="ad-marketing-modal-help">Discounts remain unresolved for finance review after redemption.</p>
                    <form method="POST" action="{{ route('admin.marketing.campaigns.store') }}" id="marketing-campaign-form">
                        @csrf
                        <input type="hidden" name="_method" id="marketing-campaign-method" value="POST">
                        <input type="hidden" name="editing_campaign" id="marketing-campaign-id" value="{{ old('editing_campaign') }}">
                        <div class="ad-form-grid">
                            <label class="ad-field"><span>Campaign name</span><input id="campaign-name" name="name" value="{{ old('name') }}" maxlength="150" required></label>
                            <label class="ad-field"><span>Voucher code</span><input id="campaign-code" name="code" value="{{ old('code') }}" maxlength="80" required></label>
                            <label class="ad-field"><span>Discount type</span><select id="campaign-discount-type" name="discount_type" required><option value="PERCENT" @selected(old('discount_type', 'PERCENT') === 'PERCENT')>Percent</option><option value="FIXED" @selected(old('discount_type') === 'FIXED')>Fixed amount</option></select></label>
                            <label class="ad-field"><span>Discount value</span><input id="campaign-discount-value" name="discount_value" type="number" min="0.01" step="0.01" value="{{ old('discount_value', '10') }}" required></label>
                            <label class="ad-field"><span>Minimum spend</span><input id="campaign-minimum" name="minimum_order_amount" type="number" min="0" step="0.01" value="{{ old('minimum_order_amount', '0') }}"></label>
                            <label class="ad-field"><span>Maximum discount</span><input id="campaign-maximum" name="maximum_discount_amount" type="number" min="0.01" step="0.01" value="{{ old('maximum_discount_amount') }}"></label>
                            <label class="ad-field"><span>Total redemptions</span><input id="campaign-usage-limit" name="usage_limit" type="number" min="1" value="{{ old('usage_limit') }}"></label>
                            <label class="ad-field"><span>Uses per buyer</span><input id="campaign-per-user-limit" name="per_user_limit" type="number" min="1" value="{{ old('per_user_limit') }}"></label>
                            <label class="ad-field"><span>Starts at</span><input id="campaign-starts-at" name="starts_at" type="datetime-local" value="{{ old('starts_at') }}"></label>
                            <label class="ad-field"><span>Ends at</span><input id="campaign-ends-at" name="ends_at" type="datetime-local" value="{{ old('ends_at') }}"></label>
                        </div>
                        <div class="ad-btn-bar">
                            <button type="button" class="ad-btn ad-btn-secondary" data-marketing-modal-close>Cancel</button>
                            <button class="ad-btn ad-btn-primary" id="marketing-campaign-submit" type="submit">Create Campaign</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @else
        <section class="ad-card ad-card-body" id="announcements" aria-labelledby="marketing-announcements-heading">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Platform Updates</span>
                    <h2 id="marketing-announcements-heading">Announcements</h2>
                    <p>Scheduled messages are delivered through the existing in-app notification system.</p>
                </div>
            </header>

            @unless($audienceTargetingReady)
                <div class="ad-note is-warning" role="status">Audience targeting is not installed yet. Announcements currently go to all active users. The prepared additive migration enables buyer, seller, and logistics targeting.</div>
            @endunless

            <div class="ad-marketing-announcement-layout">
                <form method="POST" action="{{ route('admin.marketing.announcements.store') }}" class="ad-marketing-announcement-form">
                    @csrf
                    <h3>New Announcement</h3>
                    <label class="ad-field"><span>Title</span><input name="title" value="{{ old('title') }}" maxlength="255" required></label>
                    <label class="ad-field"><span>Message</span><textarea name="body" rows="6" required>{{ old('body') }}</textarea></label>
                    @if($audienceTargetingReady)
                        <label class="ad-field"><span>Audience</span><select name="target_audience" required><option value="ALL" @selected(old('target_audience', 'ALL') === 'ALL')>All active users</option><option value="BUYERS" @selected(old('target_audience') === 'BUYERS')>Buyers</option><option value="SELLERS" @selected(old('target_audience') === 'SELLERS')>Sellers</option><option value="LOGISTICS" @selected(old('target_audience') === 'LOGISTICS')>Logistics and riders</option></select></label>
                    @else
                        <input type="hidden" name="target_audience" value="ALL">
                    @endif
                    <label class="ad-field"><span>Publish at <small>(leave blank to publish now)</small></span><input type="datetime-local" name="published_at" value="{{ old('published_at') }}"></label>
                    <label class="ad-field"><span>Expires at</span><input type="datetime-local" name="expires_at" value="{{ old('expires_at') }}"></label>
                    <button class="ad-btn ad-btn-primary" type="submit">Save Announcement</button>
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
                        @endphp
                        <article class="ad-marketing-announcement">
                            <div class="ad-marketing-announcement-title"><strong>{{ $announcement->title }}</strong><span class="ad-status {{ $announcementStatusClass }}">{{ $announcementStatus }}</span></div>
                            <p>{{ $announcement->body }}</p>
                            <div class="ad-marketing-announcement-meta">
                                <span>{{ str($announcement->target_audience ?? 'ALL')->replace('_', ' ')->headline() }}</span>
                                <span>{{ $announcement->published_at?->format('M j, Y H:i') ?? 'No publish time' }}@if($announcement->expires_at) · Expires {{ $announcement->expires_at->format('M j, Y H:i') }}@endif</span>
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
                discountType: document.getElementById('campaign-discount-type'),
                discountValue: document.getElementById('campaign-discount-value'),
                minimum: document.getElementById('campaign-minimum'),
                maximum: document.getElementById('campaign-maximum'),
                usageLimit: document.getElementById('campaign-usage-limit'),
                perUserLimit: document.getElementById('campaign-per-user-limit'),
                startsAt: document.getElementById('campaign-starts-at'),
                endsAt: document.getElementById('campaign-ends-at'),
            };
            const initial = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field.value]));
            const method = document.getElementById('marketing-campaign-method');
            const campaignId = document.getElementById('marketing-campaign-id');
            const title = document.getElementById('marketing-campaign-modal-title');
            const submit = document.getElementById('marketing-campaign-submit');
            let opener = null;

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
                    fields.discountType.value = button.dataset.discountType || 'PERCENT';
                    fields.discountValue.value = button.dataset.discountValue || '';
                    fields.minimum.value = button.dataset.minimum || '0';
                    fields.maximum.value = button.dataset.maximum || '';
                    fields.usageLimit.value = button.dataset.usageLimit || '';
                    fields.perUserLimit.value = button.dataset.perUserLimit || '';
                    fields.startsAt.value = button.dataset.startsAt || '';
                    fields.endsAt.value = button.dataset.endsAt || '';
                } else {
                    Object.entries(fields).forEach(([key, field]) => { field.value = initial[key]; });
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
            @if($errors->has('name') || $errors->has('code') || $errors->has('discount_type') || $errors->has('discount_value') || $errors->has('minimum_order_amount') || $errors->has('maximum_discount_amount') || $errors->has('usage_limit') || $errors->has('per_user_limit') || $errors->has('starts_at') || $errors->has('ends_at'))
                const failedCampaignId = @json(old('editing_campaign'));
                const editButton = Array.from(document.querySelectorAll('[data-marketing-campaign-open="edit"]'))
                    .find((button) => button.dataset.campaignId === String(failedCampaignId));
                const createButton = document.querySelector('[data-marketing-campaign-open="create"]');
                const failedButton = editButton || createButton;
                if (failedButton) {
                    open(failedButton);
                    Object.entries(fields).forEach(([key, field]) => { field.value = initial[key]; });
                }
            @endif
        })();
    </script>
@endif
@endsection
