@extends('layouts.seller.app')

@php
    $currentReport = $report ?? request('report', 'sales');
    $currency = html_entity_decode('&#8369;');
    $reportTypes = [
        'sales' => ['Sales Report', 'Recognized gross, commission, and order health'],
        'profit' => ['Profit Report', 'Assessed commission and net sales'],
        'orders' => ['Order Report', 'Fulfillment, completion, and cancellation performance'],
        'products' => ['Product Performance', 'Recognized sales by product'],
    ];
@endphp

@section('title', 'Reports')
@section('active', 'reports')
@section('subtitle', 'Turn recognized sales data into practical product and fulfillment decisions.')

@section('content')
<div class="sl-page sl-bi-page">
    <div class="sl-page-toolbar">
        <div>
            <span class="sl-eyebrow">Business Intelligence</span>
            <h2>Report Center</h2>
            <p>Analyze all catalog products or focus on one product, then download a matching PDF brief.</p>
        </div>
        <div class="sl-bi-scope"><span>Current scope</span><strong>{{ $reportScope }}</strong></div>
    </div>

    <section class="sl-stat-grid">
        <x-seller.stat-card label="Orders created in range" :value="$reportSummary['orders']" icon="orders" />
        <x-seller.stat-card label="Gross Sales" :value="$currency.number_format($reportSummary['gross'], 2)" icon="sales" />
        <x-seller.stat-card label="Commission" :value="$currency.number_format($reportSummary['commission'], 2)" icon="finance" />
        <x-seller.stat-card label="Net Sales" :value="$currency.number_format($reportSummary['net'], 2)" icon="revenue" />
        <x-seller.stat-card label="Completion rate" :value="$reportSummary['completion_rate'].'%'" icon="finance" />
    </section>
    <p class="sl-muted-note">Gross Sales is original merchandise gross from recognized commission ledger records. Commission is the assessed ledger amount. Net Sales = Gross Sales − Commission. Shipping and Additional Care are excluded. Financial totals use the delivery recognition date in {{ config('app.timezone', 'UTC') }}; order activity counts use order creation date.</p>

    @php($settlementDataReady = $reportSummary['finance_schema_ready'] ?? false)
    <section class="sl-card">
        <header class="sl-card-head">
            <div>
                <span class="sl-eyebrow">Settlement ledger</span>
                <h2>Seller payable and payout status</h2>
                <p>
                    @if($selectedProductId)
                        These are full order-level settlement records for recognized orders containing the selected product.
                    @else
                        These settlement records belong to recognized seller orders in the selected period.
                    @endif
                    Unresolved discount funding stays in review until an authorized finance actor assigns it.
                    @unless($settlementDataReady) Settlement figures are unavailable until the finance schema is installed.@endunless
                </p>
            </div>
        </header>
        <div class="sl-mini-stats">
            <div><span>Original product gross</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['original_product_amount'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Discounts</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['discounts'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Assessed commission</span><strong>{{ $currency.number_format($reportSummary['commission'], 2) }}</strong></div>
            <div><span>Seller payable</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['seller_payable'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Pending / requested / review</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['pending_balance'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Authorized</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['authorized_balance'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Awaiting seller receipt</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['awaiting_ack_balance'] ?? 0, 2) }}@else Unavailable @endif</strong></div>
            <div><span>Paid</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['paid_balance'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Outstanding</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['outstanding_balance'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Open settlement records</span><strong>@if($settlementDataReady){{ number_format($reportSummary['pending_settlement']) }}@else Unavailable @endif</strong></div>
            <div><span>Review required</span><strong>@if($settlementDataReady){{ number_format($reportSummary['review_required_count']) }}@else Unavailable @endif</strong></div>
            <div><span>Seller refund adjustments</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['seller_refund_adjustment'], 2) }}@else Unavailable @endif</strong></div>
            <div><span>Commission reversals</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['commission_refund_reversal'], 2) }}@else Unavailable @endif</strong></div>
        </div>
        <p class="sl-muted-note">Refund reversals use their posted date and are shown as separate adjustments; they do not change Gross Sales, assessed Commission, or Net Sales in this report. Outstanding settlement balance is not the same as Net Sales.</p>
    </section>

    <section class="sl-card sl-report-builder">
        <nav class="sl-report-types" aria-label="Report types">
            @foreach($reportTypes as $key => $item)
                <a href="{{ route('seller.reports', ['report' => $key, 'from' => $reportFrom->toDateString(), 'to' => $reportTo->toDateString(), 'product_id' => $selectedProductId]) }}" class="{{ $currentReport === $key ? 'is-active' : '' }}">
                    <span><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
                    <div><strong>{{ $item[0] }}</strong><small>{{ $item[1] }}</small></div><i>→</i>
                </a>
            @endforeach
        </nav>

        <form class="sl-report-controls" method="GET" action="{{ route('seller.reports.download') }}">
            <input type="hidden" name="report" value="{{ $currentReport }}">
            <div>
                <span class="sl-eyebrow">Build your brief</span>
                <h2>{{ str($currentReport)->headline() }} intelligence report</h2>
                <p>The PDF uses the same period, seller scope, and recognized financial values as this page.</p>
            </div>
            <div class="sl-form-grid sl-bi-form-grid">
                <label class="sl-field"><span>From date</span><input name="from" type="date" value="{{ $reportFrom->toDateString() }}" required></label>
                <label class="sl-field"><span>To date</span><input name="to" type="date" value="{{ $reportTo->toDateString() }}" required></label>
                <label class="sl-field"><span>Product scope</span><select name="product_id"><option value="">All catalog products</option>@foreach($sellerProducts as $product)<option value="{{ $product->id }}" @selected((int) $selectedProductId === (int) $product->id)>{{ $product->name }}@if($product->trashed()) (Archived)@endif</option>@endforeach</select></label>
            </div>
            <div class="sl-bi-actions">
                <button type="submit" formaction="{{ route('seller.reports') }}" class="sl-btn sl-btn-secondary">Update analysis</button>
                <button type="submit" class="sl-btn sl-btn-primary">Download PDF brief</button>
            </div>
        </form>
    </section>

    <section class="sl-bi-performance">
        <article class="sl-card sl-bi-panel">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Product performance</span><h2>Recognized gross by product</h2><p>Sales, commission, and units use recognized ledger records and historical order item prices.</p></div></header>
            <div class="sl-bi-tier-grid">
                <div class="is-high"><span>High performers</span><strong>{{ $reportPerformance['high'] }}</strong><small>Top recognized gross group</small></div>
                <div class="is-mid"><span>Steady sellers</span><strong>{{ $reportPerformance['mid'] }}</strong><small>Middle recognized gross group</small></div>
                <div class="is-low"><span>Low sales</span><strong>{{ $reportPerformance['low'] }}</strong><small>Needs attention</small></div>
                <div class="is-none"><span>No sales</span><strong>{{ $reportPerformance['no_sales'] }}</strong><small>No recognized gross</small></div>
            </div>
        </article>
        <article class="sl-card sl-bi-panel sl-bi-insights">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Recommended focus</span><h2>Useful signals, not just totals</h2></div></header>
            <ul>@foreach($reportInsights as $insight)<li>{{ $insight }}</li>@endforeach</ul>
        </article>
    </section>

    <section class="sl-card sl-bi-table-card">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Product detail</span><h2>{{ $reportScope }}</h2><p>Inactive and archived products remain available for historical reports. Product gross and commission use historical order item snapshots.</p></div></header>
        <div class="sl-bi-table-wrap">
            <table class="sl-bi-table">
                <thead><tr><th>Product</th><th>Performance</th><th>Recognized units</th><th>Recognized orders</th><th>Gross Sales</th><th>Allocated Commission</th><th>Net Sales</th></tr></thead>
                <tbody>
                    @forelse($reportProducts as $product)
                        <tr>
                            <td><strong>{{ $product['name'] }}</strong></td>
                            <td><span class="sl-bi-pill is-{{ $product['tier'] }}">{{ str($product['tier'])->replace('_', ' ')->headline() }}</span></td>
                            <td>{{ number_format($product['units']) }}</td>
                            <td>{{ number_format($product['orders']) }}</td>
                            <td>{{ $currency.number_format($product['gross'], 2) }}</td>
                            <td>{{ $currency.number_format($product['commission'], 2) }}</td>
                            <td>{{ $currency.number_format($product['net'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="sl-bi-empty">There are no products in this scope yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="sl-muted-note">If an order line lacks a product reference or original price snapshot, its financial value is not attributed to individual products. Those sales remain in the seller-wide ledger totals.</p>
    </section>

    <section class="sl-card">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Range health</span><h2>{{ $reportFrom->format('M d, Y') }} - {{ $reportTo->format('M d, Y') }}</h2><p>Completion and cancellation counts use orders created in this range. Average recognized order is Gross Sales divided by recognized seller orders. Refund adjustments are separate.</p></div></header>
        <div class="sl-mini-stats">
            <div><span>Completed orders</span><strong>{{ $reportSummary['completed'] }}</strong></div>
            <div><span>Cancelled orders</span><strong>{{ $reportSummary['cancelled'] }}</strong></div>
            <div><span>Recognized seller orders</span><strong>{{ number_format($reportSummary['financial_orders']) }}</strong></div>
            <div><span>Average recognized Gross Sales</span><strong>{{ $currency.number_format($reportSummary['average_order'], 2) }}</strong></div>
        </div>
    </section>
</div>
@endsection
