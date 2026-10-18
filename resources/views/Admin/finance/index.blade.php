@extends('layouts.admin')
@section('title','Commission Management')
@section('subtitle','Payments and the PDF-defined 10% platform commission.')
@section('active','finance')
@section('content')
@if($financialTotals['schema_ready'])
<div class="ad-card ad-card-body">
    <div class="ad-page-head"><div><span class="ad-overline">Step 6 / Step 7 control ledger</span><h2>COD, allocation, and settlement overview</h2><p>New financial records are immutable snapshots; seller payout remains manually authorized.</p></div></div>
    <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px">
        <div><small>COD Expected</small><h3>PHP {{ number_format($financialTotals['expected_cod'], 2) }}</h3></div>
        <div><small>Cash Collected</small><h3>PHP {{ number_format($financialTotals['cash_collected'], 2) }}</h3></div>
        <div><small>Cash Submitted</small><h3>PHP {{ number_format($financialTotals['cash_submitted'], 2) }}</h3></div>
        <div><small>Cash Verified</small><h3>PHP {{ number_format($financialTotals['cash_verified'], 2) }}</h3></div>
        <div><small>Unresolved Seller Settlements</small><h3>{{ number_format($financialTotals['unresolved_settlements']) }}</h3></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px">
        <div><small>Platform Commission</small><h3>PHP {{ number_format($financialTotals['platform_commission'], 2) }}</h3></div>
        <div><small>Seller Payable</small><h3>PHP {{ number_format($financialTotals['seller_payable'], 2) }}</h3></div>
        <div><small>Logistics Allocation</small><h3>PHP {{ number_format($financialTotals['logistics_allocation'], 2) }}</h3></div>
        <div><small>Rider Allocation</small><h3>PHP {{ number_format($financialTotals['rider_allocation'], 2) }}</h3></div>
    </div>
</div>
@else
<div class="ad-card ad-card-body"><strong>Step 6 / Step 7 finance tables are not installed yet.</strong><p>Additive migration is prepared; existing finance pages continue to show historical commission and payment records.</p></div>
@endif
<div style="display:grid;gap:16px"><div class="ad-page-head"><div><span class="ad-overline">Finance</span><h2>Commission Management</h2><p>Commission records are calculated when Buyers confirm receipt.</p></div><a class="ad-btn ad-btn-secondary" href="{{ route('admin.finance.export') }}">Export PDF</a></div><div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px"><div class="ad-card ad-card-body"><small>Paid Payments</small><h2>PHP {{ number_format($totals['payments'],2) }}</h2></div><div class="ad-card ad-card-body"><small>Total Commission</small><h2>PHP {{ number_format($totals['commission'],2) }}</h2></div><div class="ad-card ad-card-body"><small>Pending Commission</small><h2>PHP {{ number_format($totals['pending_commission'],2) }}</h2></div></div><div class="ad-card"><div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Order</th><th>Seller</th><th>Rate</th><th>Base</th><th>Commission</th><th>Status</th></tr></thead><tbody>@forelse($commissions as $commission)<tr><td>{{ $commission->sellerOrder?->seller_order_number }}</td><td>{{ $commission->sellerOrder?->sellerProfile?->business_name }}</td><td>{{ number_format((float)$commission->commission_rate*100,0) }}%</td><td>PHP {{ number_format((float)$commission->commissionable_amount,2) }}</td><td>PHP {{ number_format((float)$commission->commission_amount,2) }}</td><td>{{ str($commission->status)->headline() }}</td></tr>@empty<tr><td colspan="6">No commission transactions.</td></tr>@endforelse</tbody></table></div>{{ $commissions->links() }}</div></div>
@if($sellerSettlements)
<div class="ad-card"><div class="ad-card-head"><div><span class="ad-overline">Seller settlement authorization</span><h2>Manual payout queue</h2></div></div><div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Seller</th><th>Order</th><th>Payable</th><th>Status</th><th>Action</th></tr></thead><tbody>@forelse($sellerSettlements as $settlement)<tr><td>{{ $settlement->sellerProfile?->business_name }}</td><td>{{ $settlement->sellerOrder?->seller_order_number }}</td><td>{{ $settlement->seller_payable === null ? 'Review required' : 'PHP '.number_format((float) $settlement->seller_payable, 2) }}</td><td>{{ str($settlement->payout_status)->headline() }}</td><td>@if($settlement->payout_status === 'PENDING')<form method="POST" action="{{ route('admin.finance.seller-settlements.authorize', $settlement) }}" data-confirm-title="Authorize seller payout?" data-confirm-message="Authorize the payout for {{ $settlement->sellerProfile?->business_name }} on order {{ $settlement->sellerOrder?->seller_order_number }}? Verify the payable amount before continuing." data-confirm-label="Authorize payout">@csrf<button class="ad-btn ad-btn-secondary">Authorize</button></form>@elseif($settlement->payout_status === 'AUTHORIZED')<form method="POST" action="{{ route('admin.finance.seller-settlements.pay', $settlement) }}" style="display:flex;gap:6px" data-confirm-title="Record seller payment?" data-confirm-message="Record PHP {{ number_format((float) $settlement->seller_payable, 2) }} as paid to {{ $settlement->sellerProfile?->business_name }} for order {{ $settlement->sellerOrder?->seller_order_number }}? This updates the payout record." data-confirm-label="Record payment">@csrf<input type="number" name="amount" min="0" step="0.01" value="{{ number_format((float) $settlement->seller_payable, 2, '.', '') }}" required><button class="ad-btn ad-btn-primary">Record payment</button></form>@else<span>—</span>@endif</td></tr>@empty<tr><td colspan="5">No new seller settlements.</td></tr>@endforelse</tbody></table></div>{{ $sellerSettlements->links() }}</div>
@endif
@endsection
