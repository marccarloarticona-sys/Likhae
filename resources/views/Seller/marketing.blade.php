@extends('layouts.seller')

@section('title', 'Marketing')
@section('active', 'marketing')
@section('subtitle', 'Manage store vouchers and review marketplace campaigns.')

@section('content')
<div class="sl-page">
    @if(session('status'))<div class="sl-compliance-feedback" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-compliance-feedback is-warning" role="alert">{{ $errors->first() }}</div>@endif
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Growth Tools</span><h2>Voucher Center</h2><p>Create, pause, and monitor store vouchers.</p></div><button type="button" class="sl-btn sl-btn-primary" data-modal-open="campaignModal"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Create Voucher</button></div>

    <section class="sl-mini-stats">
        <div><span>Vouchers</span><strong>{{ number_format($voucherStats['total']) }}</strong></div>
        <div><span>Active</span><strong>{{ number_format($voucherStats['active']) }}</strong></div>
        <div><span>Redemptions</span><strong>{{ number_format($voucherStats['redemptions']) }}</strong></div>
        <div><span>Capped Vouchers</span><strong>{{ number_format($voucherStats['capped']) }}</strong></div>
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
        <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Name / Code</th><th>Benefit</th><th>Discount Cap</th><th>Minimum Spend</th><th>Usage Limits</th><th>Validity</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($vouchers as $voucher)
                <tr>
                    <td><strong>{{ $voucher->name }}</strong><small class="sl-code">{{ $voucher->code }}</small></td>
                    <td>{{ $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 2).'%' : 'PHP '.number_format((float) $voucher->discount_value, 2) }} off</td>
                    <td>{{ $voucher->maximum_discount_amount !== null ? 'PHP '.number_format((float) $voucher->maximum_discount_amount, 2) : 'No cap' }}</td>
                    <td>PHP {{ number_format((float) $voucher->minimum_order_amount, 2) }}</td>
                    <td>
                        <strong>{{ number_format((int) $voucher->redemption_count) }} / {{ $voucher->usage_limit !== null ? number_format((int) $voucher->usage_limit) : 'Unlimited' }}</strong>
                        <small>{{ $voucher->remaining_capacity !== null ? number_format((int) $voucher->remaining_capacity).' uses left' : 'Unlimited total uses' }}</small>
                        <small>{{ $voucher->per_user_limit !== null ? number_format((int) $voucher->per_user_limit).' per buyer' : 'No per-buyer limit' }}</small>
                    </td>
                    <td>{{ $voucher->starts_at?->format('M d, Y H:i') ?: 'Now' }} - {{ $voucher->ends_at?->format('M d, Y H:i') ?: 'No end' }}</td>
                    <td><span class="sl-status {{ $voucher->marketing_status === 'Active' ? 'is-success' : ($voucher->marketing_status === 'Upcoming' ? 'is-info' : ($voucher->marketing_status === 'Paused' ? 'is-neutral' : 'is-warning')) }}">{{ $voucher->marketing_status }}</span></td>
                    <td class="sl-inline-actions">
                        <button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-modal-open="editVoucherModal" data-voucher-edit="true"
                            data-update-url="{{ route('seller.marketing.campaigns.update', $voucher) }}"
                            data-voucher-id="{{ $voucher->id }}" data-voucher-name="{{ $voucher->name }}" data-voucher-code="{{ $voucher->code }}"
                            data-discount-type="{{ $voucher->discount_type }}" data-discount-value="{{ $voucher->discount_value }}"
                            data-minimum-order-amount="{{ $voucher->minimum_order_amount }}" data-maximum-discount-amount="{{ $voucher->maximum_discount_amount }}"
                            data-usage-limit="{{ $voucher->usage_limit }}" data-per-user-limit="{{ $voucher->per_user_limit }}"
                            data-starts-at="{{ $voucher->starts_at?->format('Y-m-d\\TH:i') ?? '' }}" data-ends-at="{{ $voucher->ends_at?->format('Y-m-d\\TH:i') ?? '' }}">Edit</button>
                        <form method="POST" action="{{ route('seller.marketing.campaigns.toggle', $voucher) }}">@csrf @method('PATCH')<button type="submit" class="sl-btn sl-btn-ghost sl-btn-sm">{{ $voucher->is_active ? 'Pause' : 'Activate' }}</button></form>
                    </td>
                </tr>
            @empty<tr><td colspan="8">No vouchers yet. Create one to get started.</td></tr>@endforelse
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
                <label class="sl-field"><span>Discount value</span><input name="discount_value" type="number" min="0.01" step="0.01" value="10" required></label>
            <label class="sl-field"><span>Minimum order amount</span><input name="minimum_order_amount" type="number" min="0" step="0.01" value="0"></label>
                <label class="sl-field"><span>Maximum discount</span><input name="maximum_discount_amount" type="number" min="0.01" step="0.01" placeholder="No cap"></label>
                <label class="sl-field"><span>Usage limit</span><input name="usage_limit" type="number" min="1"></label>
                <label class="sl-field"><span>Uses per buyer</span><input name="per_user_limit" type="number" min="1" placeholder="No limit"></label>
                <label class="sl-field"><span>Start date</span><input name="starts_at" type="datetime-local"></label>
                <label class="sl-field"><span>End date</span><input name="ends_at" type="datetime-local"></label>
            </div>
            <footer><button type="button" class="sl-btn sl-btn-ghost" data-modal-close>Cancel</button><button type="submit" class="sl-btn sl-btn-primary">Create Voucher</button></footer>
        </form>
    </div>
</div>
<div class="sl-modal" data-modal="editVoucherModal" hidden>
    <div class="sl-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="editVoucherTitle">
        <header><div><span class="sl-eyebrow">Marketing Tool</span><h2 id="editVoucherTitle">Edit Voucher</h2></div><button type="button" class="sl-icon-btn" data-modal-close aria-label="Close">Ã—</button></header>
        <form method="POST" action="#" data-voucher-edit-form>@csrf @method('PATCH')
            <input type="hidden" name="edit_voucher_id" value="">
            <div class="sl-form-grid">
                <label class="sl-field sl-span-2"><span>Voucher name</span><input name="name" type="text" maxlength="150" required></label>
                <label class="sl-field"><span>Code</span><input name="code" type="text" maxlength="80" required></label>
                <label class="sl-field"><span>Discount type</span><select name="discount_type" required><option value="PERCENT">Percent</option><option value="FIXED">Fixed amount</option></select></label>
                <label class="sl-field"><span>Discount value</span><input name="discount_value" type="number" min="0.01" step="0.01" required></label>
                <label class="sl-field"><span>Minimum order amount</span><input name="minimum_order_amount" type="number" min="0" step="0.01"></label>
                <label class="sl-field"><span>Maximum discount</span><input name="maximum_discount_amount" type="number" min="0.01" step="0.01" placeholder="No cap"></label>
                <label class="sl-field"><span>Usage limit</span><input name="usage_limit" type="number" min="1"></label>
                <label class="sl-field"><span>Uses per buyer</span><input name="per_user_limit" type="number" min="1"></label>
                <label class="sl-field"><span>Start date</span><input name="starts_at" type="datetime-local"></label>
                <label class="sl-field"><span>End date</span><input name="ends_at" type="datetime-local"></label>
            </div>
            <footer><button type="button" class="sl-btn sl-btn-ghost" data-modal-close>Cancel</button><button type="submit" class="sl-btn sl-btn-primary">Save Changes</button></footer>
        </form>
    </div>
</div>
@endsection
