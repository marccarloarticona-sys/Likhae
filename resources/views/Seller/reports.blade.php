@extends('layouts.seller')

@php
    $currentReport = $report ?? request('report', 'sales');
    $reportTypes = [
        'sales' => ['Sales Report', 'Revenue, average order value, and completion health'],
        'profit' => ['Profit Report', 'Commission and net earnings in one printable brief'],
        'orders' => ['Order Report', 'Fulfillment, completion, and cancellation performance'],
        'products' => ['Product Performance', 'High, steady, low, and no-sales products'],
    ];
@endphp

@section('title', 'Reports')
@section('active', 'reports')
@section('subtitle', 'Turn your sales data into practical product and fulfillment decisions.')

@section('content')
<div class="sl-page sl-bi-page">
    <div class="sl-page-toolbar">
        <div>
            <span class="sl-eyebrow">Business Intelligence</span>
            <h2>Report Center</h2>
            <p>Analyze your whole catalog or one product, then download a polished PDF brief to share or keep.</p>
        </div>
        <div class="sl-bi-scope"><span>Current scope</span><strong>{{ $reportScope }}</strong></div>
    </div>

    <section class="sl-stat-grid sl-stat-grid-4">
        <x-seller.stat-card label="Orders in range" :value="$reportSummary['orders']" icon="orders" />
        <x-seller.stat-card label="Gross sales" :value="'₱'.number_format($reportSummary['gross'], 2)" icon="sales" />
        <x-seller.stat-card label="Net earnings" :value="'₱'.number_format($reportSummary['net'], 2)" icon="revenue" />
        <x-seller.stat-card label="Completion rate" :value="$reportSummary['completion_rate'].'%'" icon="finance" />
    </section>

    @php($settlementDataReady = $reportSummary['finance_schema_ready'] ?? false)
    <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Settlement ledger</span><h2>Seller payable and payout status</h2><p>If an order has an unresolved discount, its payout stays under review until an authorized financial actor assigns funding.@unless($settlementDataReady) Settlement figures are unavailable until the finance schema is installed.@endunless</p></div></header><div class="sl-mini-stats"><div><span>Original product gross</span><strong>@if($settlementDataReady)₱{{ number_format($reportSummary['original_product_amount'], 2) }}@else Unavailable @endif</strong></div><div><span>Discounts</span><strong>@if($settlementDataReady)₱{{ number_format($reportSummary['discounts'], 2) }}@else Unavailable @endif</strong></div><div><span>Commission</span><strong>₱{{ number_format($reportSummary['commission'] ?? 0, 2) }}</strong></div><div><span>Seller payable</span><strong>@if($settlementDataReady)₱{{ number_format($reportSummary['seller_payable'], 2) }}@else Unavailable @endif</strong></div><div><span>Settled payouts</span><strong>@if($settlementDataReady)₱{{ number_format($reportSummary['settled_payouts'], 2) }}@else Unavailable @endif</strong></div><div><span>Outstanding</span><strong>@if($settlementDataReady)₱{{ number_format($reportSummary['outstanding_balance'], 2) }}@else Unavailable @endif</strong></div><div><span>Pending settlements</span><strong>@if($settlementDataReady){{ number_format($reportSummary['pending_settlement']) }}@else Unavailable @endif</strong></div></div></section>

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
                <p>PDF includes metrics, product tiers, and focused next actions - ready for printing or sharing.</p>
            </div>
            <div class="sl-form-grid sl-bi-form-grid">
                <label class="sl-field"><span>From date</span><input name="from" type="date" value="{{ $reportFrom->toDateString() }}" required></label>
                <label class="sl-field"><span>To date</span><input name="to" type="date" value="{{ $reportTo->toDateString() }}" required></label>
                <label class="sl-field"><span>Product scope</span><select name="product_id"><option value="">All catalog products</option>@foreach($sellerProducts as $product)<option value="{{ $product->id }}" @selected((int) $selectedProductId === (int) $product->id)>{{ $product->name }}</option>@endforeach</select></label>
            </div>
            <div class="sl-bi-actions">
                <button type="submit" formaction="{{ route('seller.reports') }}" class="sl-btn sl-btn-secondary">Update analysis</button>
                <button type="submit" class="sl-btn sl-btn-primary">Download PDF brief</button>
            </div>
        </form>
    </section>

    <section class="sl-bi-performance">
        <article class="sl-card sl-bi-panel">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Product performance</span><h2>Where your catalog stands</h2><p>Product revenue and units are based on completed orders in this date range.</p></div></header>
            <div class="sl-bi-tier-grid">
                <div class="is-high"><span>High performers</span><strong>{{ $reportPerformance['high'] }}</strong><small>Top revenue group</small></div>
                <div class="is-mid"><span>Steady sellers</span><strong>{{ $reportPerformance['mid'] }}</strong><small>Middle revenue group</small></div>
                <div class="is-low"><span>Low sales</span><strong>{{ $reportPerformance['low'] }}</strong><small>Needs attention</small></div>
                <div class="is-none"><span>No sales</span><strong>{{ $reportPerformance['no_sales'] }}</strong><small>No completed units</small></div>
            </div>
        </article>
        <article class="sl-card sl-bi-panel sl-bi-insights">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Recommended focus</span><h2>Useful signals, not just totals</h2></div></header>
            <ul>@foreach($reportInsights as $insight)<li>{{ $insight }}</li>@endforeach</ul>
        </article>
    </section>

    <section class="sl-card sl-bi-table-card">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Product detail</span><h2>{{ $reportScope }}</h2><p>Use this view to compare multiple products or focus the report on a single listing.</p></div></header>
        <div class="sl-bi-table-wrap">
            <table class="sl-bi-table">
                <thead><tr><th>Product</th><th>Performance</th><th>Completed units</th><th>Completed orders</th><th>Completed revenue</th></tr></thead>
                <tbody>
                    @forelse($reportProducts as $product)
                        <tr><td><strong>{{ $product['name'] }}</strong></td><td><span class="sl-bi-pill is-{{ $product['tier'] }}">{{ str($product['tier'])->replace('_', ' ')->headline() }}</span></td><td>{{ number_format($product['units']) }}</td><td>{{ number_format($product['orders']) }}</td><td>₱{{ number_format($product['revenue'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="sl-bi-empty">There are no products in this scope yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Range health</span><h2>{{ $reportFrom->format('M d, Y') }} - {{ $reportTo->format('M d, Y') }}</h2><p>Order values reflect orders created in the selected range.</p></div></header><div class="sl-mini-stats"><div><span>Completed</span><strong>{{ $reportSummary['completed'] }}</strong></div><div><span>Cancelled</span><strong>{{ $reportSummary['cancelled'] }}</strong></div><div><span>Commission</span><strong>₱{{ number_format($reportSummary['commission'], 2) }}</strong></div><div><span>Average order</span><strong>₱{{ number_format($reportSummary['average_order'], 2) }}</strong></div></div></section>
</div>
@endsection
