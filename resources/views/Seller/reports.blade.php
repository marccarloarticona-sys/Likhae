@extends('layouts.seller.app')

@php
    $currentReport = $report ?? request('report', 'sales');
    $currentTab = request('tab');
    if (!in_array($currentTab, ['analytics', 'reports'], true)) {
        $currentTab = request()->has('report') ? 'reports' : 'analytics';
    }
    $currency = html_entity_decode('&#8369;');
    $reportTypes = [
        'sales' => ['Sales Report', 'Recognized gross, commission, and order health'],
        'profit' => ['Profit Report', 'Assessed commission and net sales'],
        'orders' => ['Order Report', 'Fulfillment, completion, and cancellation performance'],
        'products' => ['Product Performance', 'Recognized sales by product'],
    ];
@endphp

@section('title', $currentTab === 'analytics' ? 'Analytics' : 'Reports')
@section('active', 'reports')
@section('subtitle', $currentTab === 'analytics' ? 'Monitor your store’s sales, products, orders, and settlement health.' : 'Create focused PDF reports from your store’s recognized activity.')

@section('content')
<div class="sl-page sl-bi-page">
    <div class="sl-page-toolbar">
        <div>
            <span class="sl-eyebrow">Business Intelligence</span>
            <h2>{{ $currentTab === 'analytics' ? 'Store Analytics' : 'Report Center' }}</h2>
            <p>{{ $currentTab === 'analytics' ? 'Review how your store is performing across the selected period.' : 'Choose a report, set its period and product scope, then preview or download the PDF.' }}</p>
        </div>
        <div class="sl-bi-scope"><span>Current scope</span><strong>{{ $reportScope }}</strong></div>
    </div>

    <nav class="sl-tabs sl-bi-tabs" aria-label="Business intelligence sections">
        <a href="{{ route('seller.reports', ['tab' => 'analytics', 'from' => $reportFrom->toDateString(), 'to' => $reportTo->toDateString(), 'product_id' => $selectedProductId]) }}" class="{{ $currentTab === 'analytics' ? 'is-active' : '' }}" @if($currentTab === 'analytics') aria-current="page" @endif>Analytics</a>
        <a href="{{ route('seller.reports', ['tab' => 'reports', 'report' => $currentReport, 'from' => $reportFrom->toDateString(), 'to' => $reportTo->toDateString(), 'product_id' => $selectedProductId]) }}" class="{{ $currentTab === 'reports' ? 'is-active' : '' }}" @if($currentTab === 'reports') aria-current="page" @endif>Reports</a>
    </nav>

    @if($currentTab === 'analytics')
    <form class="sl-card sl-report-controls sl-bi-filter-card" method="GET" action="{{ route('seller.reports') }}">
        <input type="hidden" name="tab" value="analytics">
        <div>
            <span class="sl-eyebrow">Overview filters</span>
            <h2>Store activity</h2>
            <p>Choose the period and catalog scope for the analytics overview and its PDF.</p>
        </div>
        <div class="sl-form-grid sl-bi-form-grid">
            <label class="sl-field"><span>From date</span><input name="from" type="date" value="{{ $reportFrom->toDateString() }}" required></label>
            <label class="sl-field"><span>To date</span><input name="to" type="date" value="{{ $reportTo->toDateString() }}" required></label>
            <label class="sl-field"><span>Product scope</span><select name="product_id"><option value="">All catalog products</option>@foreach($sellerProducts as $product)<option value="{{ $product->id }}" @selected((int) $selectedProductId === (int) $product->id)>{{ $product->name }}@if($product->trashed()) (Archived)@endif</option>@endforeach</select></label>
        </div>
        <div class="sl-bi-actions">
            <button type="submit" class="sl-btn sl-btn-secondary">Update analytics</button>
            <button type="submit" formaction="{{ route('seller.reports.preview') }}" formtarget="_blank" class="sl-btn sl-btn-ghost">Preview Analytics PDF</button>
            <button type="submit" formaction="{{ route('seller.reports.download') }}" class="sl-btn sl-btn-primary">Download Analytics PDF</button>
        </div>
    </form>

    <section class="sl-card sl-bi-overview-section" aria-labelledby="bi-overview-heading">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Overview</span><h2 id="bi-overview-heading">Store performance</h2><p>{{ $reportFrom->format('M d, Y') }} – {{ $reportTo->format('M d, Y') }} <span aria-hidden="true">·</span> {{ $reportScope }}</p></div><span class="sl-bi-completion">{{ $reportSummary['completion_rate'] }}% completed</span></header>
        <div class="sl-bi-kpi-grid" aria-label="Store performance metrics">
            <article class="sl-bi-kpi is-orders"><span>Orders created</span><strong>{{ number_format($reportSummary['orders']) }}</strong><small>In selected period</small></article>
            <article class="sl-bi-kpi is-gross"><span>Gross sales</span><strong>{{ $currency.number_format($reportSummary['gross'], 2) }}</strong><small>Recognized merchandise</small></article>
            <article class="sl-bi-kpi is-commission"><span>Commission</span><strong>{{ $currency.number_format($reportSummary['commission'], 2) }}</strong><small>Assessed by ledger</small></article>
            <article class="sl-bi-kpi is-net"><span>Net sales</span><strong>{{ $currency.number_format($reportSummary['net'], 2) }}</strong><small>Gross less commission</small></article>
        </div>
        <details class="sl-bi-method-note"><summary>How these figures are calculated</summary><p>Gross sales is original merchandise gross from recognized commission ledger records. Net sales is gross sales less assessed commission. Shipping and Additional Care are excluded. Financial totals use the delivery recognition date in {{ config('app.timezone', 'UTC') }}; order activity counts use order creation date.</p></details>
    </section>

    <section class="sl-bi-trends" aria-label="Sales and order trends">
        <article class="sl-card sl-bi-trend-panel">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Sales trend · {{ $reportTrends['grouping_label'] }}</span><h2>Recognized sales over time</h2><p>Gross sales, net sales, and commission by delivery recognition date.</p></div></header>
            @if($reportTrends['has_gross'])
            <div class="sl-bi-line-chart-wrap">
                <div class="sl-bi-line-axis"><span>{{ $currency.number_format($reportTrends['gross_maximum'], 0) }}</span><span>{{ $currency.number_format($reportTrends['gross_maximum'] / 2, 0) }}</span><span>{{ $currency }}0</span></div>
                <svg class="sl-bi-line-chart" viewBox="0 0 1000 210" role="img" aria-label="Recognized gross sales, net sales, and commission trend">
                    <line x1="0" y1="30" x2="1000" y2="30" class="sl-bi-grid-line" />
                    <line x1="0" y1="110" x2="1000" y2="110" class="sl-bi-grid-line" />
                    <line x1="0" y1="190" x2="1000" y2="190" class="sl-bi-grid-line" />
                    <path d="{{ $reportTrends['gross_area_path'] }}" class="sl-bi-gross-area" />
                    <polyline points="{{ $reportTrends['gross_path'] }}" class="sl-bi-line is-gross" />
                    <polyline points="{{ $reportTrends['net_path'] }}" class="sl-bi-line is-net" />
                    <polyline points="{{ $reportTrends['commission_path'] }}" class="sl-bi-line is-commission" />
                    @foreach($reportTrends['chart'] as $point)
                        <circle cx="{{ $point['x'] }}" cy="{{ $point['gross_y'] }}" r="3.5" class="sl-bi-point is-gross"><title>{{ $point['label'] }} · Gross {{ $point['gross_exact'] }}</title></circle>
                    @endforeach
                </svg>
            </div>
            <div class="sl-bi-trend-labels" style="--chart-points: {{ count($reportTrends['chart']) }}">
                @foreach($reportTrends['chart'] as $point)<span title="{{ $point['label'] }}">{{ $point['label'] }}</span>@endforeach
            </div>
            <div class="sl-bi-chart-legend"><span><i class="is-gross"></i>Gross Sales</span><span><i class="is-net"></i>Net Sales</span><span><i class="is-commission"></i>Commission</span></div>
            @else
                <div class="sl-bi-empty-chart"><span class="sl-bi-empty-chart__icon" aria-hidden="true">₱</span><strong>No recognized sales yet</strong><p>Sales trends will appear here after a delivered order is recognized in this period.</p></div>
            @endif
        </article>

        <article class="sl-card sl-bi-trend-panel">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Order activity · {{ $reportTrends['grouping_label'] }}</span><h2>Orders created</h2><p>Counts follow the order creation date and selected product scope.</p></div></header>
            @if($reportTrends['has_orders'])
            <div class="sl-bi-order-chart" style="--chart-points: {{ count($reportTrends['chart']) }}">
                @foreach($reportTrends['chart'] as $point)
                    <div class="sl-bi-order-column" title="{{ $point['label'] }} · {{ $point['orders'] }} orders · {{ $point['completed'] }} completed · {{ $point['cancelled'] }} cancelled">
                        <strong>{{ $point['orders'] }}</strong>
                        <div class="sl-bi-order-track"><span style="height: {{ $point['order_height'] }}%"></span></div>
                        <small>{{ $point['label'] }}</small>
                    </div>
                @endforeach
            </div>
            @else
                <div class="sl-bi-empty-chart is-compact"><span class="sl-bi-empty-chart__icon" aria-hidden="true">0</span><strong>No orders in this period</strong><p>New order activity will show as bars here.</p></div>
            @endif
            <div class="sl-bi-order-summary" aria-label="Order health details">
                <div><span>Completed</span><strong>{{ number_format($reportSummary['completed']) }}</strong></div>
                <div><span>Cancelled</span><strong>{{ number_format($reportSummary['cancelled']) }}</strong></div>
                <div><span>Recognized orders</span><strong>{{ number_format($reportSummary['financial_orders']) }}</strong></div>
                <div><span>Avg. recognized sale</span><strong>{{ $currency.number_format($reportSummary['average_order'], 2) }}</strong></div>
            </div>
        </article>
    </section>

    @php($settlementDataReady = $reportSummary['finance_schema_ready'] ?? false)
    <section class="sl-card sl-bi-settlement">
        <header class="sl-card-head">
            <div>
                <span class="sl-eyebrow">Settlement ledger</span>
                <h2>Seller settlement</h2>
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
        <div class="sl-bi-settlement-kpis">
            <article><span>Seller payable before payout</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['seller_payable'], 2) }}@else Unavailable @endif</strong><small>Not a wallet balance</small></article>
            <article><span>Outstanding unpaid</span><strong>@if($settlementDataReady){{ $currency.number_format($reportSummary['outstanding_balance'], 2) }}@else Unavailable @endif</strong><small>Pending or authorized payouts</small></article>
            <article><span>Open records</span><strong>@if($settlementDataReady){{ number_format($reportSummary['pending_settlement']) }}@else Unavailable @endif</strong><small>Settlement records in progress</small></article>
            <article><span>Needs review</span><strong>@if($settlementDataReady){{ number_format($reportSummary['review_required_count']) }}@else Unavailable @endif</strong><small>Unresolved funding or payout</small></article>
        </div>
        <details class="sl-bi-settlement-details">
            <summary>View full settlement breakdown</summary>
            <div class="sl-bi-settlement-groups">
                <section><h3>Settlement basis</h3><dl><div><dt>Original product gross</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['original_product_amount'], 2) }}@else Unavailable @endif</dd></div><div><dt>Discounts</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['discounts'], 2) }}@else Unavailable @endif</dd></div><div><dt>Assessed commission</dt><dd>{{ $currency.number_format($reportSummary['commission'], 2) }}</dd></div><div><dt>Seller payable before payout</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['seller_payable'], 2) }}@else Unavailable @endif</dd></div></dl></section>
                <section><h3>Payout status</h3><dl><div><dt>Unpaid · pending / requested / review</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['pending_balance'], 2) }}@else Unavailable @endif</dd></div><div><dt>Authorized · unpaid</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['authorized_balance'], 2) }}@else Unavailable @endif</dd></div><div><dt>Paid out · awaiting confirmation</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['awaiting_ack_balance'] ?? 0, 2) }}@else Unavailable @endif</dd></div><div><dt>Received and confirmed</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['paid_balance'], 2) }}@else Unavailable @endif</dd></div></dl></section>
                <section><h3>Adjustments</h3><dl><div><dt>Seller refund adjustments</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['seller_refund_adjustment'], 2) }}@else Unavailable @endif</dd></div><div><dt>Commission reversals</dt><dd>@if($settlementDataReady){{ $currency.number_format($reportSummary['commission_refund_reversal'], 2) }}@else Unavailable @endif</dd></div></dl></section>
            </div>
            <p class="sl-muted-note">Refund reversals use their posted date and remain separate; they do not change gross sales, assessed commission, or net sales. Outstanding settlement is not the same as net sales. Unresolved discount funding stays in review until an authorized finance actor assigns it.</p>
        </details>
    </section>
    @endif

    @if($currentTab === 'reports')
    <section class="sl-card sl-report-builder">
        <nav class="sl-report-types" aria-label="Report types">
            @foreach($reportTypes as $key => $item)
                <a href="{{ route('seller.reports', ['tab' => 'reports', 'report' => $key, 'from' => $reportFrom->toDateString(), 'to' => $reportTo->toDateString(), 'product_id' => $selectedProductId]) }}" class="{{ $currentReport === $key ? 'is-active' : '' }}">
                    <span><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
                    <div><strong>{{ $item[0] }}</strong><small>{{ $item[1] }}</small></div><i>→</i>
                </a>
            @endforeach
        </nav>

        <form class="sl-report-controls" method="GET" action="{{ route('seller.reports') }}">
            <input type="hidden" name="tab" value="reports">
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
                <button type="submit" class="sl-btn sl-btn-secondary">Update report</button>
                <button type="submit" formaction="{{ route('seller.reports.preview') }}" formtarget="_blank" class="sl-btn sl-btn-ghost">Preview PDF</button>
                <button type="submit" formaction="{{ route('seller.reports.download') }}" class="sl-btn sl-btn-primary">Download PDF brief</button>
            </div>
        </form>
    </section>

    <section class="sl-card sl-bi-report-snapshot">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Report snapshot</span><h2>{{ $reportTypes[$currentReport][0] }}</h2><p>{{ $reportTypes[$currentReport][1] }} · {{ $reportScope }} · {{ $reportFrom->format('M d, Y') }} – {{ $reportTo->format('M d, Y') }}</p></div></header>
        <div class="sl-mini-stats">
            <div><span>Orders created</span><strong>{{ number_format($reportSummary['orders']) }}</strong></div>
            <div><span>Gross Sales</span><strong>{{ $currency.number_format($reportSummary['gross'], 2) }}</strong></div>
            <div><span>Commission</span><strong>{{ $currency.number_format($reportSummary['commission'], 2) }}</strong></div>
            <div><span>Net Sales</span><strong>{{ $currency.number_format($reportSummary['net'], 2) }}</strong></div>
        </div>
        <div class="sl-bi-insights"><ul>@foreach($reportInsights as $insight)<li>{{ $insight }}</li>@endforeach</ul></div>
    </section>
    @endif

    @if($currentTab === 'analytics')
    <section class="sl-bi-performance">
        <article class="sl-card sl-bi-panel">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Catalog health</span><h2>Product performance</h2><p>Recognized gross sales by product in the selected period.</p></div></header>
            <div class="sl-bi-tier-grid">
                <div class="is-high"><span>High performers</span><strong>{{ $reportPerformance['high'] }}</strong><small>Top recognized gross group</small></div>
                <div class="is-mid"><span>Steady sellers</span><strong>{{ $reportPerformance['mid'] }}</strong><small>Middle recognized gross group</small></div>
                <div class="is-low"><span>Low sales</span><strong>{{ $reportPerformance['low'] }}</strong><small>Needs attention</small></div>
                <div class="is-none"><span>No sales</span><strong>{{ $reportPerformance['no_sales'] }}</strong><small>No recognized gross</small></div>
            </div>
        </article>
        <article class="sl-card sl-bi-panel sl-bi-insights">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Next steps</span><h2>Recommended focus</h2><p>Actions suggested by this period’s results.</p></div></header>
            <ul>@foreach($reportInsights as $insight)<li>{{ $insight }}</li>@endforeach</ul>
        </article>
    </section>

    <section class="sl-card sl-bi-table-card">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Product detail · {{ number_format(count($reportProducts)) }} products</span><h2>Sales by product</h2><p>Units, orders, gross sales, commission, and net sales for the selected catalog scope.</p></div></header>
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
        <details class="sl-bi-method-note"><summary>About product totals</summary><p>Inactive and archived products remain available for historical reports. Product totals use historical order item snapshots. If an order line lacks a product reference or original price snapshot, its financial value is not attributed to an individual product; those sales remain in the seller-wide ledger totals.</p></details>
    </section>
    @endif
</div>
@endsection
