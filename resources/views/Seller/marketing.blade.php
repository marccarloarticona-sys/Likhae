@extends('layouts.seller')

@php
    $activeVouchers = $vouchers->getCollection()->where('is_active', true);
@endphp

@section('title', 'Marketing')
@section('active', 'marketing')
@section('subtitle', 'Manage store vouchers and review marketplace campaigns.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Growth Tools</span><h2>Voucher Center</h2><p>Create, pause, and monitor store vouchers.</p></div><button type="button" class="sl-btn sl-btn-primary" data-modal-open="campaignModal"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Create Voucher</button></div>

    <section class="sl-mini-stats">
        <div><span>Vouchers</span><strong>{{ number_format($vouchers->total()) }}</strong></div>
        <div><span>Active</span><strong>{{ number_format($activeVouchers->count()) }}</strong></div>
        <div><span>Total Uses</span><strong>{{ number_format($vouchers->getCollection()->sum('seller_orders_count')) }}</strong></div>
        <div><span>Usage Limit</span><strong>{{ number_format($vouchers->getCollection()->sum('usage_limit')) }}</strong></div>
    </section>

    <section class="sl-card">
        <div class="sl-table-toolbar"><div><h3>LIKHAE Platform Campaigns</h3><p>Marketplace wide promotions available to buyers. These campaigns are managed by LIKHAE.</p></div></div>
        @if(!$platformCampaignsReady)
            <div style="padding:16px;color:#765b4c">Platform campaign storage is not installed yet. This section will show active marketplace campaigns after the prepared schema migration is applied.</div>
        @else
            <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Campaign / Code</th><th>Benefit</th><th>Minimum Spend</th><th>Redemptions</th><th>Validity</th><th>Access</th></tr></thead><tbody>
                @forelse($platformCampaigns as $campaign)
                    <tr><td><strong>{{ $campaign->name }}</strong><small class="sl-code">{{ $campaign->code }}</small></td><td>{{ $campaign->discount_type === 'PERCENT' ? number_format((float) $campaign->discount_value, 0).'%' : '₱'.number_format((float) $campaign->discount_value, 2) }} off</td><td>₱{{ number_format((float) $campaign->minimum_order_amount, 2) }}</td><td>{{ number_format((int) $campaign->redemption_count) }} / {{ $campaign->usage_limit ?: 'Unlimited' }}</td><td>{{ $campaign->starts_at?->format('M d') ?: 'Now' }} – {{ $campaign->ends_at?->format('M d, Y') ?: 'No end' }}</td><td><span class="sl-status is-neutral">Read only</span></td></tr>
                @empty<tr><td colspan="6">No active platform campaigns are available right now.</td></tr>@endforelse
            </tbody></table></div>
        @endif
    </section>

    <section class="sl-card">
        <div class="sl-table-toolbar"><div><h3>Store Vouchers</h3><p>Manage discounts available to your customers.</p></div><button type="button" class="sl-btn sl-btn-primary sl-btn-sm" data-modal-open="campaignModal">Create Voucher</button></div>
        <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Name / Code</th><th>Benefit</th><th>Minimum Spend</th><th>Usage</th><th>Validity</th><th>Status</th><th>Action</th></tr></thead><tbody>
            @forelse($vouchers as $voucher)
                <tr><td><strong>{{ $voucher->name }}</strong><small class="sl-code">{{ $voucher->code }}</small></td><td>{{ $voucher->discount_type==='PERCENT' ? number_format((float) $voucher->discount_value,0).'%' : '₱'.number_format((float) $voucher->discount_value,2) }} off</td><td>₱{{ number_format((float) $voucher->minimum_order_amount,2) }}</td><td>{{ $voucher->seller_orders_count }} / {{ $voucher->usage_limit ?: '∞' }}</td><td>{{ $voucher->starts_at?->format('M d') ?: 'Now' }} – {{ $voucher->ends_at?->format('M d, Y') ?: 'No end' }}</td><td><span class="sl-status {{ $voucher->is_active?'is-success':'is-neutral' }}">{{ $voucher->is_active?'Active':'Paused' }}</span></td><td><form method="POST" action="{{ route('seller.marketing.campaigns.toggle',$voucher) }}">@csrf @method('PATCH')<button type="submit" class="sl-btn sl-btn-ghost sl-btn-sm">{{ $voucher->is_active?'Pause':'Activate' }}</button></form></td></tr>
            @empty<tr><td colspan="7">No vouchers yet. Create one to get started.</td></tr>@endforelse
        </tbody></table></div>
        {{ $vouchers->links() }}
    </section>
</div>

<div class="sl-modal" data-modal="campaignModal" hidden>
    <div class="sl-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="campaignTitle">
        <header><div><span class="sl-eyebrow">Marketing Tool</span><h2 id="campaignTitle">Create Campaign</h2></div><button type="button" class="sl-icon-btn" data-modal-close aria-label="Close">×</button></header>
        <form method="POST" action="{{ route('seller.marketing.campaigns.store') }}">@csrf
            <div class="sl-form-grid">
            <label class="sl-field sl-span-2"><span>Voucher name</span><input name="name" type="text" required></label>
            <label class="sl-field"><span>Code</span><input name="code" maxlength="80" required></label>
            <label class="sl-field"><span>Discount type</span><select name="discount_type"><option value="PERCENT">Percent</option><option value="FIXED">Fixed amount</option></select></label>
                <label class="sl-field"><span>Discount value</span><input name="discount_value" type="number" min="0" step="0.01" value="10" required></label>
            <label class="sl-field"><span>Minimum order amount</span><input name="minimum_order_amount" type="number" min="0" step="0.01" value="0"></label>
                <label class="sl-field"><span>Usage limit</span><input name="usage_limit" type="number" min="1"></label>
                <label class="sl-field"><span>Start date</span><input name="starts_at" type="datetime-local"></label>
                <label class="sl-field"><span>End date</span><input name="ends_at" type="datetime-local"></label>
            </div>
            <footer><button type="button" class="sl-btn sl-btn-ghost" data-modal-close>Cancel</button><button type="submit" class="sl-btn sl-btn-primary">Create Voucher</button></footer>
        </form>
    </div>
</div>
@endsection
