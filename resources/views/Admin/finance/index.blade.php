@extends('layouts.admin.app')
@section('title', 'Finance')
@section('subtitle', 'Admin overview, seller payouts, and Logistics settlements in one finance workspace.')
@section('active', 'finance')

@section('content')
<div class="ad-finance-page">
<div class="ad-page-head">
    <div><span class="ad-overline">Finance control ledger</span><h2>Finance overview</h2><p>Review settlement activity, seller payables, and Logistics payouts from one place.</p></div>
    <a class="ad-btn ad-btn-secondary" href="{{ route('admin.finance.export') }}">Export PDF</a>
</div>

<nav class="ad-finance-nav" aria-label="Finance page sections">
    <a href="#finance-overview">Admin overview</a>
    @if($parcelSettlementReady && $productProceeds)<a href="#finance-settlements">COD settlements</a>@endif
    @if($logisticsPayouts || $logisticsPayoutReceipts)<a href="#finance-logistics">Logistics payouts</a>@endif
    <a href="#finance-sellers">Seller payouts</a>
</nav>

<section id="finance-overview" class="ad-finance-section" aria-labelledby="finance-overview-title">
    <header class="ad-finance-section__head"><div><span class="ad-overline">Admin control</span><h2 id="finance-overview-title">Marketplace overview</h2><p>Start with balances and settlement totals, then review each payout workflow below.</p></div></header>
<section class="ad-finance-guide" aria-labelledby="finance-payout-guide-title">
    <div class="ad-finance-guide-heading"><span class="ad-overline">Payout workflow</span><h2 id="finance-payout-guide-title">How to pay a seller or Logistics partner</h2><p>Recipient payout details are saved from their account pages. This page tracks authorization, transfer references, and receipt acknowledgment.</p></div>
    <ol class="ad-finance-guide-steps">
        <li><span>1</span><div><strong>Check the destination</strong><p>Confirm the saved GCash, Maya, or bank details in the payout queue.</p></div></li>
        <li><span>2</span><div><strong>Authorize the eligible amount</strong><p>Approve a seller request or a Logistics share after its parcel prerequisites are met.</p></div></li>
        <li><span>3</span><div><strong>Transfer and attach the receipt</strong><p>After sending funds outside Likhae, enter the external reference and proof. The recipient can review the receipt and confirm arrival.</p></div></li>
    </ol>
</section>

@if($financialTotals['schema_ready'])
    <div class="ad-card ad-card-body">
        <div class="ad-card-head"><div><span class="ad-overline">Admin payout overview</span><h2>Recipient payout balances</h2><p>Follow each amount from available, through request and transfer, to recipient confirmation.</p></div></div>
        <div class="ad-finance-grid ad-finance-grid--four">
            <div class="ad-finance-metric ad-finance-metric--available"><small>Available to request</small><h3>PHP {{ number_format($financialTotals['available_payouts'], 2) }}</h3></div>
            <div class="ad-finance-metric ad-finance-metric--requested"><small>Requested by recipients</small><h3>PHP {{ number_format($financialTotals['requested_payouts'], 2) }}</h3></div>
            <div class="ad-finance-metric ad-finance-metric--awaiting"><small>Awaiting recipient confirmation</small><h3>PHP {{ number_format($financialTotals['awaiting_payout_ack'], 2) }}</h3></div>
            <div class="ad-finance-metric ad-finance-metric--paid"><small>Confirmed paid</small><h3>PHP {{ number_format($financialTotals['paid_payouts'], 2) }}</h3></div>
        </div>
    </div>
    <div class="ad-card ad-card-body ad-finance-overview-breakdown">
        <section class="ad-finance-metric-group" aria-labelledby="finance-cashflow-title">
            <header class="ad-finance-metric-group__head"><div><span class="ad-overline">COD collection</span><h3 id="finance-cashflow-title">Cash collection progress</h3></div><p>Compare buyer amounts with cash submitted and verified.</p></header>
            <div class="ad-finance-grid ad-finance-grid--five">
                <div><small>Expected from buyers</small><h3>PHP {{ number_format($financialTotals['expected_cod'], 2) }}</h3></div>
                <div><small>Collected</small><h3>PHP {{ number_format($financialTotals['cash_collected'], 2) }}</h3></div>
                <div><small>Submitted to Admin</small><h3>PHP {{ number_format($financialTotals['cash_submitted'], 2) }}</h3></div>
                <div><small>Verified by Admin</small><h3>PHP {{ number_format($financialTotals['cash_verified'], 2) }}</h3></div>
                <div class="ad-finance-metric ad-finance-metric--attention"><small>Unresolved seller settlements</small><h3>{{ number_format($financialTotals['unresolved_settlements']) }}</h3><span>Needs review</span></div>
            </div>
        </section>
        <section class="ad-finance-metric-group" aria-labelledby="finance-allocation-title">
            <header class="ad-finance-metric-group__head"><div><span class="ad-overline">Parcel allocation</span><h3 id="finance-allocation-title">How proceeds are allocated</h3></div><p>Breakdown across the platform, Seller, Logistics, and Rider.</p></header>
            <div class="ad-finance-grid ad-finance-grid--four">
                <div><small>Total delivery charges collected</small><h3>PHP {{ number_format($financialTotals['delivery_charges_collected'], 2) }}</h3></div>
                <div><small>Base shipping portion</small><h3>PHP {{ number_format($financialTotals['base_shipping_charges'], 2) }}</h3></div>
                <div><small>Additional Care portion</small><h3>PHP {{ number_format($financialTotals['additional_care_charges'], 2) }}</h3></div>
                <div><small>Platform commission</small><h3>PHP {{ number_format($financialTotals['platform_commission'], 2) }}</h3></div>
                <div><small>Seller payable</small><h3>PHP {{ number_format($financialTotals['seller_payable'], 2) }}</h3></div>
                <div><small>Logistics share</small><h3>PHP {{ number_format($financialTotals['logistics_allocation'], 2) }}</h3></div>
                <div><small>Rider share</small><h3>PHP {{ number_format($financialTotals['rider_allocation'], 2) }}</h3></div>
                <div><small>Pending recipient / policy allocation</small><h3>PHP {{ number_format($financialTotals['pending_allocations'], 2) }}</h3></div>
                <div><small>Refund adjustments posted</small><h3>PHP {{ number_format($financialTotals['refund_adjustments'], 2) }}</h3></div>
            </div>
        </section>
        <section class="ad-finance-metric-group" aria-labelledby="finance-campaign-title">
            <header class="ad-finance-metric-group__head"><div><span class="ad-overline">Source-aware totals</span><h3 id="finance-campaign-title">Product funding and campaign position</h3></div><p>Seller reductions and LIKHAE campaign costs stay separate from seller payables.</p></header>
            <div class="ad-finance-grid ad-finance-grid--five">
                <div><small>Gross product sales</small><h3>PHP {{ number_format($financialTotals['gross_product_sales'], 2) }}</h3></div>
                <div><small>Seller markdowns and promotions</small><h3>PHP {{ number_format($financialTotals['seller_markdowns'] + $financialTotals['seller_promotions'], 2) }}</h3></div>
                <div><small>Seller-funded vouchers</small><h3>PHP {{ number_format($financialTotals['seller_voucher_discounts'], 2) }}</h3></div>
                <div><small>LIKHAE product vouchers</small><h3>PHP {{ number_format($financialTotals['platform_voucher_discounts'], 2) }}</h3></div>
                <div><small>LIKHAE shipping vouchers</small><h3>PHP {{ number_format($financialTotals['platform_shipping_voucher_discounts'], 2) }}</h3></div>
                <div><small>Reward points applied</small><h3>PHP {{ number_format($financialTotals['reward_points_discounts'], 2) }}</h3></div>
                <div><small>Buyer product charges (source-aware orders)</small><h3>PHP {{ number_format($financialTotals['net_buyer_product_charges'], 2) }}</h3></div>
                <div><small>Campaign commitments</small><h3>PHP {{ number_format($financialTotals['campaign_reserved'], 2) }}</h3></div>
                <div><small>Net campaign expense</small><h3>PHP {{ number_format($financialTotals['campaign_spent'], 2) }}</h3></div>
                <div><small>Available campaign funds</small><h3>PHP {{ number_format($financialTotals['campaign_available'], 2) }}</h3></div>
            </div>
            @if($financialTotals['campaign_setup_required'] > 0 || $financialTotals['campaign_reconciliation_mismatches'] > 0)
                <p class="ad-note is-warning" role="status">{{ $financialTotals['campaign_setup_required'] }} campaign(s) need an authorized budget setup. {{ $financialTotals['campaign_reconciliation_mismatches'] }} campaign(s) have a negative available balance and require finance review.</p>
            @endif
        </section>
    </div>
@else
    <div class="ad-card ad-card-body"><strong>Step 6 / Step 7 finance tables are not installed yet.</strong><p>Existing finance pages continue to show historical commission and payment records.</p></div>
@endif

</section>

<section id="finance-sellers" class="ad-finance-section" aria-labelledby="finance-sellers-title">
    <header class="ad-finance-section__head"><div><span class="ad-overline">Seller finance</span><h2 id="finance-sellers-title">Seller payouts and commissions</h2><p>Review commission history and move each seller payout through authorization, transfer, and receipt confirmation.</p></div></header>
<div class="ad-finance-grid ad-finance-grid--three ad-finance-total-grid">
    <div class="ad-card ad-card-body"><small>Paid order payments</small><h2>PHP {{ number_format($totals['payments'], 2) }}</h2></div>
    <div class="ad-card ad-card-body"><small>Platform commission earned</small><h2>PHP {{ number_format($totals['commission'], 2) }}</h2></div>
    <div class="ad-card ad-card-body"><small>Commission awaiting settlement</small><h2>PHP {{ number_format($totals['pending_commission'], 2) }}</h2></div>
</div>

<div class="ad-card">
    <div class="ad-card-head"><div><span class="ad-overline">Commission ledger</span><h2>Commission transactions</h2></div></div>
    <div class="ad-table-wrap"><table class="ad-table ad-finance-table--commission"><thead><tr><th>Order</th><th>Seller</th><th>Rate</th><th>Original product base</th><th>Commission</th><th>Status</th></tr></thead><tbody>
        @forelse($commissions as $commission)
            <tr><td>{{ $commission->sellerOrder?->seller_order_number }}</td><td>{{ $commission->sellerOrder?->sellerProfile?->business_name }}</td><td>{{ number_format((float) $commission->commission_rate * 100, 0) }}%</td><td>PHP {{ number_format((float) $commission->commissionable_amount, 2) }}</td><td>PHP {{ number_format((float) $commission->commission_amount, 2) }}</td><td>{{ str($commission->status)->replace('_', ' ')->headline() }}</td></tr>
        @empty<tr><td colspan="6">No commission transactions.</td></tr>@endforelse
    </tbody></table></div>{{ $commissions->links() }}
</div>


@if($sellerSettlements)
    <div class="ad-card">
        <div class="ad-card-head"><div><span class="ad-overline">Seller payout control</span><h2>Per parcel seller payout queue</h2><p>Filter by seller or the date a payout was requested. Review each seller’s saved payment destination, authorize the amount, record the transfer, and track receipt confirmation.</p></div></div>
        <form method="GET" action="{{ route('admin.finance') }}#finance-sellers" class="ad-finance-filters" aria-label="Filter seller payout queue" data-seller-payout-filter-form>
            <div class="ad-finance-filters__field">
                <label for="seller-payout-filter">Seller</label>
                <select id="seller-payout-filter" name="seller_profile_id">
                    <option value="">All sellers</option>
                    @foreach($sellerPayoutSellers as $sellerOption)
                        <option value="{{ $sellerOption->id }}" @selected((string) ($sellerFinanceFilters['seller_profile_id'] ?? '') === (string) $sellerOption->id)>{{ $sellerOption->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ad-finance-filters__field">
                <label for="seller-payout-requested-from">Requested from</label>
                <input id="seller-payout-requested-from" type="date" name="requested_from" value="{{ $sellerFinanceFilters['requested_from'] ?? '' }}">
            </div>
            <div class="ad-finance-filters__field">
                <label for="seller-payout-requested-to">Requested to</label>
                <input id="seller-payout-requested-to" type="date" name="requested_to" value="{{ $sellerFinanceFilters['requested_to'] ?? '' }}">
            </div>
            <div class="ad-finance-filters__actions">
                <button class="ad-btn ad-btn-primary" type="submit">Apply filters</button>
                <a class="ad-btn ad-btn-secondary" href="{{ route('admin.finance') }}#finance-sellers" data-clear-seller-payout-filters>Clear</a>
            </div>
        </form>
        <div class="ad-finance-payout-results" data-seller-payout-results aria-live="polite" aria-busy="false">
        <div class="ad-table-wrap"><table class="ad-table ad-finance-table--seller-payouts"><thead><tr><th>Seller</th><th>Order / parcel</th><th>Product / fee</th><th>Seller amount</th><th>Payment destination</th><th>Status</th><th>Payout receipt / acknowledgment</th><th>Action</th></tr></thead><tbody>
        @forelse($sellerSettlements as $settlement)
            @php($sellerPayoutType = \App\Support\PayoutAccountDetails::typeFor($settlement->sellerProfile))
            <tr>
                <td>{{ $settlement->sellerProfile?->business_name }}</td>
                <td>{{ $settlement->sellerOrder?->seller_order_number }}<br>{{ $settlement->allocation?->shipment?->tracking_number ?? 'Historical order settlement' }}</td>
                <td>{{ $settlement->seller_payable === null ? 'Review required' : 'Product PHP '.number_format((float) $settlement->allocation?->product_amount, 2).' · fee PHP '.number_format((float) $settlement->platform_commission, 2) }}</td>
                <td>{{ $settlement->seller_payable === null ? 'Review required' : 'PHP '.number_format((float) $settlement->seller_payable, 2) }}</td>
                <td>@if($payoutDestinationReady && $settlement->sellerProfile?->payout_account_number){{ $settlement->sellerProfile->payout_method }}@if($settlement->sellerProfile->payout_bank_name)<br>{{ $settlement->sellerProfile->payout_bank_name }}@endif<br>{{ $settlement->sellerProfile->payout_account_name }}<br>{{ \App\Support\PayoutAccountDetails::typeLabel($sellerPayoutType) }}: {{ \App\Support\PayoutAccountDetails::formatNumber($settlement->sellerProfile->payout_account_number, $sellerPayoutType) }}@elseif(!$payoutDestinationReady)Details unavailable until migration@else Not provided @endif</td>
                <td>{{ str($settlement->payout_status)->replace('_', ' ')->headline() }}@if($settlement->payout_failure_reason)<br><small>{{ $settlement->payout_failure_reason }}</small>@endif</td>
                        <td>{{ $settlement->settlement_reference }}<br>{{ $settlement->payout_method }} {{ $settlement->payout_external_reference }}<br>@if(\Illuminate\Support\Str::startsWith((string) $settlement->payout_external_reference, 'SIM-'))<strong>Legacy demo entry — no transfer proof</strong><br>@elseif($settlement->payout_proof_path)<a href="{{ route('admin.finance.seller-settlements.proof', $settlement) }}">View transfer proof</a><br>@endif{{ $settlement->paid_at?->format('M d, Y g:i A') ?? '' }}<br>{{ $settlement->seller_acknowledged_at?->format('M d, Y g:i A') ?? 'Awaiting seller acknowledgment' }}</td>
                <td>
                    @if(in_array($settlement->payout_status, ['PENDING', 'PAYOUT_REQUESTED', 'FAILED'], true) && $settlement->seller_payable !== null)
                        <form method="POST" action="{{ route('admin.finance.seller-settlements.authorize', $settlement) }}" data-confirm-title="Authorize parcel payout?" data-confirm-message="Authorize PHP {{ number_format((float) $settlement->seller_payable, 2) }} to {{ $settlement->sellerProfile?->business_name }} for parcel {{ $settlement->allocation?->shipment?->tracking_number ?? '—' }}?" data-confirm-label="Authorize payout">
                            @csrf<button class="ad-btn ad-btn-secondary">{{ $settlement->payout_status === 'FAILED' ? 'Retry authorization' : ($settlement->payout_status === 'PAYOUT_REQUESTED' ? 'Approve request' : 'Authorize') }}</button>
                        </form>
                    @elseif($settlement->payout_status === 'AUTHORIZED')
                        @if($payoutProofReady && $payoutDestinationReady && $settlement->sellerProfile?->payout_account_number)
                            <form method="POST" enctype="multipart/form-data" action="{{ route('admin.finance.seller-settlements.pay', $settlement) }}" class="grid gap-1" data-confirm-title="Record seller payout?" data-confirm-message="After transferring funds outside Likhae, enter the provider reference and attach proof. The seller can then confirm receipt." data-confirm-label="Record transfer">
                                @csrf<input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $settlement->seller_payable, 2, '.', '') }}" required>
                                <small>Destination: {{ $settlement->sellerProfile->payout_method }}@if($settlement->sellerProfile->payout_bank_name) · {{ $settlement->sellerProfile->payout_bank_name }}@endif · {{ $settlement->sellerProfile->payout_account_name }} · {{ \App\Support\PayoutAccountDetails::typeLabel($sellerPayoutType) }}: {{ \App\Support\PayoutAccountDetails::formatNumber($settlement->sellerProfile->payout_account_number, $sellerPayoutType) }}</small>
                                <input name="payout_external_reference" maxlength="150" placeholder="External transfer reference" required>
                                <input name="payout_proof" type="file" accept=".jpg,.jpeg,.png,.pdf" required aria-label="Upload payout proof">
                                <button class="ad-btn ad-btn-primary">Record manual transfer</button>
                            </form>
                        @elseif(!$payoutProofReady)
                            <span>Seller transfer receipts need the additive payout proof migration.</span>
                        @else
                            <span>Waiting for seller payout details.</span>
                        @endif
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
    </div>
@endif

</section>

@if($parcelSettlementReady && $productProceeds)
    <section id="finance-settlements" class="ad-finance-section" aria-labelledby="finance-settlements-title">
    <header class="ad-finance-section__head"><div><span class="ad-overline">Admin settlement review</span><h2 id="finance-settlements-title">COD and parcel settlements</h2><p>Verify incoming parcel proceeds and resolve buyer collection differences with an auditable note.</p></div></header>
    <div class="ad-card" style="margin-top:16px">
        <div class="ad-card-head"><div><span class="ad-overline">Logistics to Admin receipt</span><h2>Product proceeds awaiting Admin</h2><p>Expected amount includes product proceeds plus the Logistics share of shipping. The Rider share is paid separately by Logistics.</p></div></div>
        <div class="ad-table-wrap"><table class="ad-table ad-finance-table--proceeds"><thead><tr><th>Receipt / parcel</th><th>Order</th><th>Sender</th><th>Expected</th><th>Submitted</th><th>Received</th><th>Discrepancy</th><th>Action</th></tr></thead><tbody>
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
        <div class="ad-table-wrap"><table class="ad-table ad-finance-table--cod"><thead><tr><th>Parcel / order</th><th>Rider handoff</th><th>Buyer expected</th><th>Buyer recorded</th><th>Discrepancy</th><th>Action</th></tr></thead><tbody>
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

    </section>
    <section id="finance-logistics" class="ad-finance-section" aria-labelledby="finance-logistics-title">
    <header class="ad-finance-section__head"><div><span class="ad-overline">Logistics finance</span><h2 id="finance-logistics-title">Logistics payouts and receipts</h2><p>Release eligible shipping shares and track each transfer until Logistics confirms receipt.</p></div></header>

    @if($logisticsPayouts)
        <div class="ad-card" style="margin-top:16px">
            <div class="ad-card-head"><div><span class="ad-overline">Logistics shipping share</span><h2>Logistics payouts ready</h2><p>These shipping shares are payable after Admin acknowledges the COD proceeds. The payout destination is saved in the Logistics account settings.</p></div></div>
            <div class="ad-table-wrap"><table class="ad-table ad-finance-table--logistics"><thead><tr><th>Logistics</th><th>Parcel / order</th><th>Share</th><th>Status</th><th>Action</th></tr></thead><tbody>
                @forelse($logisticsPayouts as $pool)
                    <tr>
                        <td>{{ $pool->logisticsCenter?->business_name ?? 'Logistics center' }}
                            @if($payoutDestinationReady && $pool->logisticsCenter?->payout_account_number)
                                @php($logisticsPayoutType = \App\Support\PayoutAccountDetails::typeFor($pool->logisticsCenter))
                                <br><small>{{ $pool->logisticsCenter->payout_method }}@if($pool->logisticsCenter->payout_bank_name) · {{ $pool->logisticsCenter->payout_bank_name }}@endif · {{ $pool->logisticsCenter->payout_account_name }} · {{ \App\Support\PayoutAccountDetails::typeLabel($logisticsPayoutType) }}: {{ \App\Support\PayoutAccountDetails::formatNumber($pool->logisticsCenter->payout_account_number, $logisticsPayoutType) }}</small>
                            @elseif(!$payoutDestinationReady)
                                <br><small>Saved payout details unavailable until the migration is installed.</small>
                            @endif
                        </td>
                        <td>{{ $pool->shipment?->tracking_number ?? 'Parcel' }}<br>{{ $pool->shipment?->sellerOrder?->order?->order_number ?? 'Order' }}</td>
                        <td>PHP {{ number_format((float) $pool->total_amount, 2) }}</td>
                        <td>{{ str($pool->status)->replace('_', ' ')->headline() }}</td>
                        <td>@if($pool->logisticsCenter && $payoutDestinationReady && $pool->logisticsCenter->payout_account_number)<form method="POST" enctype="multipart/form-data" action="{{ route('admin.finance.logistics-pools.pay', $pool) }}" class="grid gap-1" data-confirm-title="Record Logistics payout?" data-confirm-message="After transferring funds outside Likhae, record the external reference and attach proof. Logistics can then confirm receipt." data-confirm-label="Record transfer">
                            @csrf
                            <input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $pool->total_amount, 2, '.', '') }}" required>
                            <small>Destination: {{ $pool->logisticsCenter->payout_method }} · {{ $pool->logisticsCenter->payout_account_name }} · {{ \App\Support\PayoutAccountDetails::typeLabel($logisticsPayoutType ?? \App\Support\PayoutAccountDetails::typeFor($pool->logisticsCenter)) }}: {{ \App\Support\PayoutAccountDetails::formatNumber($pool->logisticsCenter->payout_account_number, $logisticsPayoutType ?? \App\Support\PayoutAccountDetails::typeFor($pool->logisticsCenter)) }}</small>
                            <input name="payout_external_reference" maxlength="150" placeholder="External transfer reference" required>
                            <input name="payout_proof" type="file" accept=".jpg,.jpeg,.png,.pdf" required aria-label="Upload payout proof">
                            <input name="notes" maxlength="1000" placeholder="Notes">
                            <button class="ad-btn ad-btn-primary">Record manual transfer</button>
                        </form>@else<p>Waiting for complete Logistics payout details.</p>@endif</td>
                    </tr>
                @empty<tr><td colspan="5">No Logistics payouts are ready for recording.</td></tr>@endforelse
            </tbody></table></div>{{ $logisticsPayouts->links() }}
        </div>
    @endif

    @if($logisticsPayoutReceipts)
    <div class="ad-card" style="margin-top:16px">
        <div class="ad-card-head"><div><span class="ad-overline">Manual transfer receipts</span><h2>Recent Logistics payouts</h2><p>Transfer references and proof remain available here and in the recipient wallet.</p></div></div>
        <div class="ad-table-wrap"><table class="ad-table ad-finance-table--receipts"><thead><tr><th>Logistics / parcel</th><th>Amount</th><th>Channel</th><th>Transfer reference</th><th>Status</th><th>Proof</th></tr></thead><tbody>
            @forelse($logisticsPayoutReceipts as $entry)
                @php($payout = (array) $entry->metadata)
                <tr><td>Logistics #{{ $payout['logistics_center_id'] ?? '—' }}<br>{{ $entry->shipment?->tracking_number ?? 'Parcel' }}</td><td>PHP {{ number_format((float) $entry->amount, 2) }}</td><td>{{ $payout['payout_method'] ?? '' }}</td><td>{{ $payout['payout_external_reference'] ?? '—' }}<br>{{ $payout['paid_at'] ?? '' }}</td><td>{{ str($entry->status)->replace('_', ' ')->headline() }}@if($entry->status === 'PAYOUT_PENDING_ACK')<br>Awaiting recipient confirmation @endif</td><td>@if(!empty($payout['payout_proof_path']))<a href="{{ route('admin.finance.logistics-payouts.proof', $entry) }}">View proof</a>@elseif($payout['is_simulated'] ?? false)<small>Legacy demo entry — no transfer proof</small> @else — @endif</td></tr>
            @empty<tr><td colspan="6">No Logistics payout receipts have been recorded.</td></tr>@endforelse
        </tbody></table></div>{{ $logisticsPayoutReceipts->links() }}
    </div>
    @endif
    </section>
@endif

</div>
@endsection
