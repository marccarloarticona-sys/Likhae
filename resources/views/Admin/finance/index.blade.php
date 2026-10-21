@extends('layouts.admin.app')
@section('title', 'Finance')
@section('subtitle', 'Parcel COD, shipping allocation, product proceeds, and seller payout receipts.')
@section('active', 'finance')

@section('content')
<div class="ad-page-head">
    <div><span class="ad-overline">Finance control ledger</span><h2>COD and parcel settlements</h2><p>New parcel funds move only after each handoff has a recorded acknowledgment.</p></div>
    <a class="ad-btn ad-btn-secondary" href="{{ route('admin.finance.export') }}">Export PDF</a>
</div>
<div class="ad-card ad-card-body" style="margin-bottom:16px;border-left:4px solid #d19a34"><strong>Demo payout mode:</strong> GCash, Maya, Bank Transfer, and Other create simulated payout references only. No money is transferred. Xendit is the selected provider for future real payouts; live transfers are not configured yet.</div>

@if($financialTotals['schema_ready'])
    <div class="ad-card ad-card-body">
        <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px">
            <div><small>COD Expected</small><h3>PHP {{ number_format($financialTotals['expected_cod'], 2) }}</h3></div>
            <div><small>Cash Collected</small><h3>PHP {{ number_format($financialTotals['cash_collected'], 2) }}</h3></div>
            <div><small>Cash Submitted</small><h3>PHP {{ number_format($financialTotals['cash_submitted'], 2) }}</h3></div>
            <div><small>Cash Verified</small><h3>PHP {{ number_format($financialTotals['cash_verified'], 2) }}</h3></div>
            <div><small>Unresolved Seller Settlements</small><h3>{{ number_format($financialTotals['unresolved_settlements']) }}</h3></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px">
            <div><small>Platform Fee</small><h3>PHP {{ number_format($financialTotals['platform_commission'], 2) }}</h3></div>
            <div><small>Seller Payable</small><h3>PHP {{ number_format($financialTotals['seller_payable'], 2) }}</h3></div>
            <div><small>Logistics Shipping Share</small><h3>PHP {{ number_format($financialTotals['logistics_allocation'], 2) }}</h3></div>
            <div><small>Rider Shipping Share</small><h3>PHP {{ number_format($financialTotals['rider_allocation'], 2) }}</h3></div>
        </div>
    </div>
@else
    <div class="ad-card ad-card-body"><strong>Step 6 / Step 7 finance tables are not installed yet.</strong><p>Existing finance pages continue to show historical commission and payment records.</p></div>
@endif

<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0">
    <div class="ad-card ad-card-body"><small>Paid Payments</small><h2>PHP {{ number_format($totals['payments'], 2) }}</h2></div>
    <div class="ad-card ad-card-body"><small>Total Commission</small><h2>PHP {{ number_format($totals['commission'], 2) }}</h2></div>
    <div class="ad-card ad-card-body"><small>Pending Commission</small><h2>PHP {{ number_format($totals['pending_commission'], 2) }}</h2></div>
</div>

<div class="ad-card">
    <div class="ad-card-head"><div><span class="ad-overline">Historical and current commission</span><h2>Commission transactions</h2></div></div>
    <div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Order</th><th>Seller</th><th>Rate</th><th>Original product base</th><th>Commission</th><th>Status</th></tr></thead><tbody>
        @forelse($commissions as $commission)
            <tr><td>{{ $commission->sellerOrder?->seller_order_number }}</td><td>{{ $commission->sellerOrder?->sellerProfile?->business_name }}</td><td>{{ number_format((float) $commission->commission_rate * 100, 0) }}%</td><td>PHP {{ number_format((float) $commission->commissionable_amount, 2) }}</td><td>PHP {{ number_format((float) $commission->commission_amount, 2) }}</td><td>{{ str($commission->status)->replace('_', ' ')->headline() }}</td></tr>
        @empty<tr><td colspan="6">No commission transactions.</td></tr>@endforelse
    </tbody></table></div>{{ $commissions->links() }}
</div>

@if($parcelSettlementReady && $productProceeds)
    <div class="ad-card" style="margin-top:16px">
        <div class="ad-card-head"><div><span class="ad-overline">Logistics to Admin receipt</span><h2>Product proceeds awaiting Admin</h2><p>Expected amount includes product proceeds plus the Logistics share of shipping. The Rider share is paid separately by Logistics.</p></div></div>
        <div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Receipt / parcel</th><th>Order</th><th>Sender</th><th>Expected</th><th>Submitted</th><th>Received</th><th>Discrepancy</th><th>Action</th></tr></thead><tbody>
        @forelse($productProceeds as $entry)
            @php($receipt = (array) $entry->metadata)
            <tr>
                <td>{{ $receipt['receipt_reference'] ?? 'Awaiting handoff' }}<br>{{ $entry->shipment?->tracking_number ?? 'Parcel unavailable' }}</td>
                <td>{{ $entry->shipment?->sellerOrder?->order?->order_number ?? 'Order unavailable' }}</td>
                <td>{{ data_get($receipt, 'sender.role', 'LOGISTICS') }} #{{ data_get($receipt, 'sender.logistics_center_id', '—') }}<br>{{ $receipt['submitted_at'] ?? 'Not submitted' }}</td>
                <td>PHP {{ number_format((float) $entry->amount, 2) }}</td>
                <td>{{ isset($receipt['amount_submitted']) ? 'PHP '.number_format((float) $receipt['amount_submitted'], 2) : '—' }}</td>
                <td>{{ isset($receipt['amount_received']) ? 'PHP '.number_format((float) $receipt['amount_received'], 2) : 'Awaiting' }}<br>{{ $receipt['acknowledged_at'] ?? '' }}</td>
                <td>PHP {{ number_format((float) ($receipt['discrepancy_amount'] ?? 0), 2) }}<br>{{ str($entry->status)->headline() }}</td>
                <td>
                    @if($entry->status === 'SUBMITTED')
                        <form method="POST" action="{{ route('admin.finance.product-proceeds.acknowledge', $entry) }}" class="grid gap-1" data-confirm-title="Record product proceeds received?" data-confirm-message="Enter the actual amount received. An amount mismatch will remain an open discrepancy." data-confirm-label="Confirm receipt">
                            @csrf<input name="received_amount" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required><input name="notes" maxlength="1000" placeholder="Receipt notes"><button class="ad-btn ad-btn-primary">Acknowledge</button>
                        </form>
                    @elseif($entry->status === 'DISCREPANCY')
                        <form method="POST" action="{{ route('admin.finance.product-proceeds.resolve', $entry) }}" class="grid gap-1" data-confirm-title="Resolve proceeds discrepancy?" data-confirm-message="Confirm the final received amount equals product proceeds plus the Logistics shipping share and document the resolution." data-confirm-label="Resolve discrepancy">
                            @csrf<input name="final_received_amount" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required><input name="resolution" maxlength="2000" placeholder="Resolution notes" required><button class="ad-btn ad-btn-secondary">Resolve</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty<tr><td colspan="8">No Logistics product proceeds are waiting for acknowledgment.</td></tr>@endforelse
        </tbody></table></div>{{ $productProceeds->links() }}
    </div>

    <div class="ad-card" style="margin-top:16px">
        <div class="ad-card-head"><div><span class="ad-overline">Open buyer collection discrepancies</span><h2>COD discrepancies awaiting resolution</h2><p>Resolution is recorded as a separate audit event; original collection values remain visible in history.</p></div></div>
        <div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Parcel / order</th><th>Rider handoff</th><th>Buyer expected</th><th>Buyer recorded</th><th>Discrepancy</th><th>Action</th></tr></thead><tbody>
        @forelse($codDiscrepancies as $collection)
            <tr>
                <td>{{ $collection->shipment?->tracking_number }}<br>{{ $collection->order?->order_number }}</td>
                <td>{{ $collection->remittanceItem?->remittance?->remittance_reference }}<br>{{ str($collection->remittanceItem?->remittance?->status ?? '—')->headline() }}</td>
                <td>PHP {{ number_format((float) $collection->expected_amount, 2) }}</td>
                <td>PHP {{ number_format((float) $collection->collected_amount, 2) }}</td>
                <td>PHP {{ number_format(abs((float) $collection->expected_amount - (float) $collection->collected_amount), 2) }}</td>
                <td><form method="POST" action="{{ route('admin.finance.cod-collections.resolve', $collection) }}" class="grid gap-1" data-confirm-title="Resolve buyer COD discrepancy?" data-confirm-message="This records that the parcel collection has been reconciled to the expected amount. Provide a resolution reason." data-confirm-label="Resolve COD discrepancy">
                    @csrf<input name="resolution" maxlength="2000" required placeholder="Resolution notes"><button class="ad-btn ad-btn-secondary">Resolve</button>
                </form></td>
            </tr>
        @empty<tr><td colspan="6">No buyer COD discrepancies are awaiting resolution.</td></tr>@endforelse
        </tbody></table></div>{{ $codDiscrepancies?->links() }}
    </div>

    @if($logisticsPayouts)
        <div class="ad-card" style="margin-top:16px">
            <div class="ad-card-head"><div><span class="ad-overline">Logistics shipping share</span><h2>Logistics payouts ready</h2><p>These 40% (or currently approved) shipping shares are payable after Admin acknowledges the COD proceeds. Online payout records are simulated only; no money is transferred.</p></div></div>
            <div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Logistics</th><th>Parcel / order</th><th>Share</th><th>Status</th><th>Action</th></tr></thead><tbody>
                @forelse($logisticsPayouts as $pool)
                    <tr>
                        <td>{{ $pool->logisticsCenter?->business_name ?? 'Logistics center' }}
                            @if($payoutDestinationReady && $pool->logisticsCenter?->payout_account_number)
                                <br><small>{{ $pool->logisticsCenter->payout_method }}@if($pool->logisticsCenter->payout_bank_name) · {{ $pool->logisticsCenter->payout_bank_name }}@endif · {{ $pool->logisticsCenter->payout_account_name }} · {{ $pool->logisticsCenter->payout_account_number }}</small>
                            @elseif(!$payoutDestinationReady)
                                <br><small>Saved payout details unavailable until the migration is installed.</small>
                            @endif
                        </td>
                        <td>{{ $pool->shipment?->tracking_number ?? 'Parcel' }}<br>{{ $pool->shipment?->sellerOrder?->order?->order_number ?? 'Order' }}</td>
                        <td>PHP {{ number_format((float) $pool->total_amount, 2) }}</td>
                        <td>{{ str($pool->status)->replace('_', ' ')->headline() }}</td>
                        <td><form method="POST" action="{{ route('admin.finance.logistics-pools.pay', $pool) }}" class="grid gap-1" data-confirm-title="Record Logistics payout?" data-confirm-message="Record this shipping share as a manual or online payout. Logistics must acknowledge receipt." data-confirm-label="Record payout">
                            @csrf
                            <input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $pool->total_amount, 2, '.', '') }}" required>
                            <select name="payout_method" required><option value="">Select payout method</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Manual', 'Other'] as $method)<option value="{{ $method }}" @selected($pool->logisticsCenter?->payout_method === $method)>{{ $method }}{{ $method !== 'Manual' ? ' (simulated)' : '' }}</option>@endforeach</select>
                            <input name="payout_external_reference" maxlength="150" placeholder="Manual reference (online demo generates one)">
                            <input name="notes" maxlength="1000" placeholder="Notes">
                            <button class="ad-btn ad-btn-primary">Record / simulate payout</button>
                        </form></td>
                    </tr>
                @empty<tr><td colspan="5">No Logistics payouts are ready for recording.</td></tr>@endforelse
            </tbody></table></div>{{ $logisticsPayouts->links() }}
        </div>
    @endif
@endif

@if($sellerSettlements)
    <div class="ad-card" style="margin-top:16px">
        <div class="ad-card-head"><div><span class="ad-overline">Admin payout control</span><h2>Per parcel seller payout queue</h2><p>Seller requests appear here with their saved payment destination. Authorize the amount, record the transfer, and the seller can acknowledge the receipt.</p></div></div>
        <div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Seller</th><th>Order / parcel</th><th>Product / fee</th><th>Seller amount</th><th>Payment destination</th><th>Status</th><th>Payout receipt / acknowledgment</th><th>Action</th></tr></thead><tbody>
        @forelse($sellerSettlements as $settlement)
            <tr>
                <td>{{ $settlement->sellerProfile?->business_name }}</td>
                <td>{{ $settlement->sellerOrder?->seller_order_number }}<br>{{ $settlement->allocation?->shipment?->tracking_number ?? 'Historical order settlement' }}</td>
                <td>{{ $settlement->seller_payable === null ? 'Review required' : 'Product PHP '.number_format((float) $settlement->allocation?->product_amount, 2).' · fee PHP '.number_format((float) $settlement->platform_commission, 2) }}</td>
                <td>{{ $settlement->seller_payable === null ? 'Review required' : 'PHP '.number_format((float) $settlement->seller_payable, 2) }}</td>
                <td>@if($payoutDestinationReady && $settlement->sellerProfile?->payout_account_number){{ $settlement->sellerProfile->payout_method }}@if($settlement->sellerProfile->payout_bank_name)<br>{{ $settlement->sellerProfile->payout_bank_name }}@endif<br>{{ $settlement->sellerProfile->payout_account_name }}<br>{{ $settlement->sellerProfile->payout_account_number }}@elseif(!$payoutDestinationReady)Details unavailable until migration@else Not provided @endif</td>
                <td>{{ str($settlement->payout_status)->replace('_', ' ')->headline() }}@if($settlement->payout_failure_reason)<br><small>{{ $settlement->payout_failure_reason }}</small>@endif</td>
                        <td>{{ $settlement->settlement_reference ?? '—' }}<br>{{ $settlement->payout_method ?? '' }} {{ $settlement->payout_external_reference ?? '' }}<br>@if(\Illuminate\Support\Str::startsWith((string) $settlement->payout_external_reference, 'SIM-'))<strong>SIMULATED — NO MONEY TRANSFERRED</strong><br>@endif{{ $settlement->paid_at?->format('M d, Y g:i A') ?? '' }}<br>{{ $settlement->seller_acknowledged_at?->format('M d, Y g:i A') ?? 'Awaiting seller acknowledgment' }}</td>
                <td>
                    @if(in_array($settlement->payout_status, ['PENDING', 'PAYOUT_REQUESTED', 'FAILED'], true) && $settlement->seller_payable !== null)
                        <form method="POST" action="{{ route('admin.finance.seller-settlements.authorize', $settlement) }}" data-confirm-title="Authorize parcel payout?" data-confirm-message="Authorize PHP {{ number_format((float) $settlement->seller_payable, 2) }} to {{ $settlement->sellerProfile?->business_name }} for parcel {{ $settlement->allocation?->shipment?->tracking_number ?? '—' }}?" data-confirm-label="Authorize payout">
                            @csrf<button class="ad-btn ad-btn-secondary">{{ $settlement->payout_status === 'FAILED' ? 'Retry authorization' : ($settlement->payout_status === 'PAYOUT_REQUESTED' ? 'Approve request' : 'Authorize') }}</button>
                        </form>
                    @elseif($settlement->payout_status === 'AUTHORIZED')
                        <form method="POST" action="{{ route('admin.finance.seller-settlements.pay', $settlement) }}" class="grid gap-1" data-confirm-title="Record seller payout?" data-confirm-message="Record this exact payout and wait for the seller's acknowledgment." data-confirm-label="Record payout">
                            @csrf<input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $settlement->seller_payable, 2, '.', '') }}" required><select name="payout_method" required>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Manual', 'Other'] as $method)<option value="{{ $method }}" @selected($settlement->sellerProfile?->payout_method === $method)>{{ $method }}{{ in_array($method, ['GCash', 'Maya', 'Bank Transfer', 'Other'], true) ? ' (simulated)' : '' }}</option>@endforeach</select><input name="payout_external_reference" maxlength="150" placeholder="Manual reference (online demo generates one)"><button class="ad-btn ad-btn-primary">Record / simulate payout</button>
                        </form>
                        <form method="POST" action="{{ route('admin.finance.seller-settlements.fail', $settlement) }}" class="mt-2 grid gap-1">
                            @csrf<input name="reason" maxlength="2000" placeholder="Failure reason" required><button class="ad-btn ad-btn-secondary">Record failure</button>
                        </form>
                    @elseif($settlement->payout_status === 'PAYOUT_PENDING_ACK')
                        <span>Awaiting seller</span>
                        <form method="POST" action="{{ route('admin.finance.seller-settlements.fail', $settlement) }}" class="mt-2 grid gap-1">
                            @csrf<input name="reason" maxlength="2000" placeholder="Failure / dispute reason" required><button class="ad-btn ad-btn-secondary">Record failure</button>
                        </form>
                    @else — @endif
                </td>
            </tr>
        @empty<tr><td colspan="8">No seller settlement records.</td></tr>@endforelse
        </tbody></table></div>{{ $sellerSettlements->links() }}
    </div>
@endif
@endsection
