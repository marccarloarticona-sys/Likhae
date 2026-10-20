@extends('layouts.seller')

@section('title', 'Dashboard')
@section('active', 'dashboard')
@section('subtitle', 'Your store performance and operational priorities at a glance.')

@section('content')
@php
    $firstName = data_get($seller, 'first_name') ?: str($seller->name)->before(' ');
    $stats = $dashboardStats;
    $peso = html_entity_decode('&#8369;');
    $shippingOrders = $orderStatusCounts['picked-up'] + $orderStatusCounts['in-transit'];
@endphp
<div class="sl-page sl-seller-dashboard">
    <section class="sl-welcome-panel">
        <div>
            <span class="sl-eyebrow">{{ now()->format('l, F j') }}</span>
            <h2>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ $firstName }}.</h2>
            <p>Your store activity and the tasks that need attention are below.</p>
        </div>
        <div class="sl-welcome-actions">
            <a href="{{ route('seller.products', ['mode' => 'add']) }}" class="sl-btn sl-btn-white">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Add Product
            </a>
            <a href="{{ route('seller.orders') }}" class="sl-btn sl-btn-blue-soft">All Orders</a>
        </div>
    </section>

    <section class="sl-dashboard-priorities" aria-labelledby="seller-priorities-title">
        <header class="sl-dashboard-priorities__header">
            <div><span class="sl-eyebrow">Start here</span><h2 id="seller-priorities-title">Needs attention</h2></div>
            <span>Actionable tasks</span>
        </header>
        <div class="sl-dashboard-priorities__grid">
            <a class="sl-dashboard-priority {{ $orderStatusCounts['placed'] > 0 ? 'is-urgent' : 'is-clear' }}" href="{{ route('seller.orders', ['status' => 'placed']) }}">
                <span class="sl-dashboard-priority__icon" aria-hidden="true">{{ $orderStatusCounts['placed'] > 0 ? '!' : '✓' }}</span>
                <span class="sl-dashboard-priority__copy"><strong>New orders</strong><small>{{ $orderStatusCounts['placed'] > 0 ? 'Review and confirm placed orders' : 'No placed orders to review' }}</small></span>
                <strong class="sl-dashboard-priority__count">{{ number_format($orderStatusCounts['placed']) }}</strong>
                <span class="sl-dashboard-priority__action">Review orders <span aria-hidden="true">→</span></span>
            </a>
            <a class="sl-dashboard-priority {{ $stats['pending_shipment'] > 0 ? 'is-urgent' : 'is-clear' }}" href="{{ route('seller.orders', ['status' => 'ready-for-pickup']) }}">
                <span class="sl-dashboard-priority__icon" aria-hidden="true">{{ $stats['pending_shipment'] > 0 ? '!' : '✓' }}</span>
                <span class="sl-dashboard-priority__copy"><strong>Ready for pickup</strong><small>{{ $stats['pending_shipment'] > 0 ? 'Prepare parcels for courier handoff' : 'No parcels waiting for pickup' }}</small></span>
                <strong class="sl-dashboard-priority__count">{{ number_format($stats['pending_shipment']) }}</strong>
                <span class="sl-dashboard-priority__action">View pickups <span aria-hidden="true">→</span></span>
            </a>
            <a class="sl-dashboard-priority {{ $stats['inventory_alerts'] > 0 ? 'is-urgent' : 'is-clear' }}" href="{{ route('seller.products', ['mode' => 'inventory']) }}">
                <span class="sl-dashboard-priority__icon" aria-hidden="true">{{ $stats['inventory_alerts'] > 0 ? '!' : '✓' }}</span>
                <span class="sl-dashboard-priority__copy"><strong>Inventory alerts</strong><small>{{ $stats['inventory_alerts'] > 0 ? 'Review low and out-of-stock products' : 'No stock alerts right now' }}</small></span>
                <strong class="sl-dashboard-priority__count">{{ number_format($stats['inventory_alerts']) }}</strong>
                <span class="sl-dashboard-priority__action">Manage stock <span aria-hidden="true">→</span></span>
            </a>
        </div>
    </section>

    <header class="sl-dashboard-section-heading">
        <div><span class="sl-eyebrow">Performance</span><h2>Sales &amp; finances</h2></div>
        <p>Order totals and recognized merchandise sales are shown separately.</p>
    </header>
    <div class="sl-dashboard-performance">
        <section class="sl-card sl-dashboard-trend" aria-labelledby="seller-order-value-title">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Sales trend</span><h2 id="seller-order-value-title">Order value</h2><p>Buyer order totals for {{ $orderValueChart['period_label'] }}. Includes shipping and Additional Care; this is not your payout.</p></div>
                <form method="GET" action="{{ route('seller.dashboard') }}" class="sl-order-value-filter">
                    <input type="hidden" name="from" value="{{ $financialFrom->toDateString() }}">
                    <input type="hidden" name="to" value="{{ $financialTo->toDateString() }}">
                    <select class="sl-select sl-select-sm" name="period" aria-label="Order value period" onchange="this.form.submit()">
                        <option value="7" @selected($orderValueChart['period'] === '7')>7 days</option>
                        <option value="30" @selected($orderValueChart['period'] === '30')>30 days</option>
                        <option value="quarter" @selected($orderValueChart['period'] === 'quarter')>This quarter</option>
                    </select>
                </form>
            </header>
            <div class="sl-chart-summary sl-order-value-summary">
                <div><span>Order value</span><strong>{{ $peso }}{{ number_format($orderValueChart['gross'], 2) }}</strong><small>Non-cancelled orders · {{ $orderValueChart['grouping_label'] }}</small></div>
                <span class="sl-order-value-legend"><i></i>Order value</span>
            </div>
            @if ($orderValueChart['has_orders'])
                <div class="sl-order-value-chart">
                    <div class="sl-order-value-y-axis" aria-hidden="true">
                        @foreach ($orderValueChart['scale']['ticks'] as $tick)
                            <span>{{ $peso }}{{ $tick >= 1000 ? number_format($tick / 1000, 1).'k' : number_format($tick, 0) }}</span>
                        @endforeach
                    </div>
                    <div class="sl-order-value-chart-area">
                        <div class="sl-order-value-bars" style="--chart-points: {{ $orderValueChart['chart']->count() }}" role="group" aria-label="Seller order value chart for {{ $orderValueChart['period_label'] }} grouped as {{ $orderValueChart['grouping_label'] }}">
                            @foreach ($orderValueChart['chart'] as $point)
                                <div class="sl-order-value-bar" style="--bar-height: {{ $point['height'] }}%" role="img" tabindex="0" title="{{ $point['date'] }}: {{ $point['exact'] }}" aria-label="{{ $point['date'] }}: {{ $point['exact'] }}">
                                    <i aria-hidden="true"></i>
                                    <strong>{{ $point['compact'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                        <div class="sl-order-value-x-axis" style="--chart-points: {{ $orderValueChart['chart']->count() }}">
                            @foreach ($orderValueChart['chart'] as $point)
                                <span title="{{ $point['date'] }}">{{ $point['label'] }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="sl-order-value-empty" role="status">No non-cancelled order value in this period.</div>
            @endif
        </section>

        <section class="sl-card sl-dashboard-financials" aria-labelledby="seller-financials-title">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Recognized financials</span><h2 id="seller-financials-title">Sales after commission</h2></div>
                <a href="{{ route('seller.reports', ['from' => $financialFrom->toDateString(), 'to' => $financialTo->toDateString()]) }}" class="sl-text-link">Report</a>
            </header>
            <p class="sl-dashboard-financials__note">Recognized merchandise sales from delivered orders. Shipping and Additional Care are excluded.</p>
            <div class="sl-dashboard-financials__period">{{ $financialFrom->format('M d') }} – {{ $financialTo->format('M d, Y') }}</div>
            <div class="sl-dashboard-financials__totals" aria-label="Recognized sales totals">
                <div><span><strong>Gross sales</strong><small>Merchandise value before commission</small></span><strong>{{ $peso }}{{ number_format($financialSummary['gross'], 2) }}</strong></div>
                <div><span><strong>Commission</strong><small>Platform commission on those sales</small></span><strong>{{ $peso }}{{ number_format($financialSummary['commission'], 2) }}</strong></div>
                <div class="is-net"><span><strong>Net sales</strong><small>Gross sales minus commission</small></span><strong>{{ $peso }}{{ number_format($financialSummary['net'], 2) }}</strong></div>
            </div>
            <form method="GET" action="{{ route('seller.dashboard') }}" class="sl-dashboard-period sl-form-grid sl-form-grid-4" aria-label="Financial reporting period">
                <input type="hidden" name="period" value="{{ $orderValueChart['period'] }}">
                <label class="sl-field"><span>From</span><input name="from" type="date" value="{{ $financialFrom->toDateString() }}" required></label>
                <label class="sl-field"><span>To</span><input name="to" type="date" value="{{ $financialTo->toDateString() }}" required></label>
                <button class="sl-btn sl-btn-soft sl-btn-sm" type="submit">Apply</button>
            </form>
        </section>
    </div>

    <section class="sl-card sl-dashboard-stages" aria-labelledby="seller-order-stages-title">
        <header class="sl-card-head">
            <div><span class="sl-eyebrow">Fulfillment</span><h2 id="seller-order-stages-title">Orders by stage</h2></div>
            <a href="{{ route('seller.orders') }}" class="sl-text-link">All orders</a>
        </header>
        <div class="sl-dashboard-stage-list">
            <a href="{{ route('seller.orders', ['status' => 'placed']) }}"><span>Placed</span><strong>{{ number_format($orderStatusCounts['placed']) }}</strong></a>
            <a href="{{ route('seller.orders', ['status' => 'confirmed']) }}"><span>Accepted</span><strong>{{ number_format($orderStatusCounts['confirmed']) }}</strong></a>
            <a href="{{ route('seller.orders', ['status' => 'preparing']) }}"><span>Preparing</span><strong>{{ number_format($orderStatusCounts['preparing']) }}</strong></a>
            <a href="{{ route('seller.orders', ['status' => 'ready-for-pickup']) }}"><span>Ready for pickup</span><strong>{{ number_format($orderStatusCounts['ready-for-pickup']) }}</strong></a>
            <a href="{{ route('seller.orders', ['status' => 'shipping']) }}"><span>With courier</span><strong>{{ number_format($shippingOrders) }}</strong></a>
            <a href="{{ route('seller.orders', ['status' => 'shipping']) }}"><span>Delivered</span><strong>{{ number_format($orderStatusCounts['delivered']) }}</strong></a>
            <a href="{{ route('seller.orders', ['status' => 'completed']) }}"><span>Completed</span><strong>{{ number_format($orderStatusCounts['completed']) }}</strong></a>
            @if ($orderStatusCounts['other'] > 0)
                <a href="{{ route('seller.orders') }}"><span>Other</span><strong>{{ number_format($orderStatusCounts['other']) }}</strong></a>
            @endif
            @if ($orderStatusCounts['cancelled'] > 0)
                <a class="is-cancelled" href="{{ route('seller.orders', ['status' => 'cancelled']) }}"><span>Cancelled</span><strong>{{ number_format($orderStatusCounts['cancelled']) }}</strong></a>
            @endif
        </div>
    </section>

    <div class="sl-dashboard-overviews">
        <section class="sl-card sl-dashboard-overview-card">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Stock health</span><h2>Inventory overview</h2></div>
                <a href="{{ route('seller.products', ['mode' => 'inventory']) }}" class="sl-text-link">Manage</a>
            </header>
            <p class="sl-dashboard-overview-card__note">Active variants; each product is counted once.</p>
            <div class="sl-dashboard-stock-grid">
                <div><span>Visible products</span><strong>{{ number_format($inventorySummary['visible_products']) }}<small> / {{ number_format($inventorySummary['all_products']) }}</small></strong></div>
                <div class="is-warning"><span>Low stock</span><strong>{{ number_format($inventorySummary['low_stock_variants']) }}</strong><small>{{ number_format($inventorySummary['low_stock_products']) }} products</small></div>
                <div class="is-critical"><span>Out of stock</span><strong>{{ number_format($inventorySummary['out_of_stock_variants']) }}</strong><small>{{ number_format($inventorySummary['out_of_stock_products']) }} products</small></div>
                <div><span>Products with alerts</span><strong>{{ number_format($stats['inventory_alerts']) }}</strong></div>
            </div>
        </section>

        <section class="sl-card sl-dashboard-overview-card">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Settlement ledger</span><h2>Seller payable</h2></div>
                <a href="{{ route('seller.reports', ['from' => $financialFrom->toDateString(), 'to' => $financialTo->toDateString()]) }}" class="sl-text-link">Reports</a>
            </header>
            <p class="sl-dashboard-overview-card__note">Commission uses original product prices; discount-funded rows may be under review.</p>
            @if ($settlementSummary['schema_ready'])
                <div class="sl-dashboard-payable-grid" aria-label="Seller payable amounts">
                    <div><span>Pending / review</span><strong>{{ $peso }}{{ number_format($settlementSummary['pending'], 2) }}</strong></div>
                    <div><span>Authorized</span><strong>{{ $peso }}{{ number_format($settlementSummary['authorized'], 2) }}</strong></div>
                    <div><span>Paid</span><strong>{{ $peso }}{{ number_format($settlementSummary['paid'], 2) }}</strong></div>
                    <div class="is-outstanding"><span>Outstanding</span><strong>{{ $peso }}{{ number_format($settlementSummary['outstanding'], 2) }}</strong></div>
                </div>
            @else
                <p class="sl-empty-state">Settlement tables are prepared but not installed yet. Existing orders and reports remain available.</p>
            @endif
        </section>
    </div>

    <div class="sl-dashboard-grid sl-dashboard-grid-lower">
        <section class="sl-card">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Operations</span><h2>Recent Orders</h2><p>Latest purchases in your workflow.</p></div>
                <a href="{{ route('seller.orders') }}" class="sl-text-link">Manage orders</a>
            </header>
            <div class="sl-table-wrap">
                <table class="sl-table sl-recent-table">
                    <thead><tr><th>Order</th><th>Buyer</th><th>Product</th><th>Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($sellerOrders as $order)
                            @php
                                $tone = match (data_get($order, 'status_key')) {
                                    'completed', 'delivered' => 'is-success',
                                    'shipping', 'picked-up', 'in-transit', 'ready-for-pickup' => 'is-info',
                                    'to-prepare' => 'is-warning',
                                    'returns', 'cancelled' => 'is-danger',
                                    default => 'is-neutral',
                                };
                            @endphp
                            <tr>
                                <td><strong>#{{ data_get($order, 'id') }}</strong></td>
                                <td>{{ data_get($order, 'buyer') }}</td>
                                <td><span class="sl-table-product">{{ data_get($order, 'product') }}</span></td>
                                <td><strong>{{ $peso }}{{ number_format((float) data_get($order, 'total'), 2) }}</strong></td>
                                <td><span class="sl-status {{ $tone }}">{{ data_get($order, 'status') }}</span></td>
                                <td><a class="sl-icon-link" href="{{ route('seller.orders', ['mode' => 'show', 'order' => data_get($order, 'id')]) }}" aria-label="View order #{{ data_get($order, 'id') }}">&rarr;</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="sl-empty-state"><h3>Your orders will appear here</h3><p>When a buyer places an order, you can review it and manage fulfillment from this page.</p><a href="{{ route('seller.products', ['mode' => 'add']) }}" class="sl-btn sl-btn-primary sl-btn-sm">Add a product</a></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="sl-card">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Stock control</span><h2>Inventory alerts</h2><p>Products requiring attention.</p></div>
                <a href="{{ route('seller.products', ['mode' => 'inventory']) }}" class="sl-text-link">Inventory</a>
            </header>
            <div class="sl-alert-list">
                @forelse ($inventoryAlerts as $product)
                    <article class="sl-stock-alert {{ $product['out_count'] > 0 ? 'is-critical' : 'is-warning' }}">
                        <span class="sl-stock-icon">{{ $product['out_count'] + $product['low_count'] }}</span>
                        <div><small>INVENTORY NEEDS ATTENTION</small><strong>{{ $product['name'] }}</strong><span>{{ $product['out_count'] }} out of stock · {{ $product['low_count'] }} low stock variants</span></div>
                        <a href="{{ route('seller.products', ['mode' => 'inventory']) }}" class="sl-btn sl-btn-ghost sl-btn-sm">Update</a>
                    </article>
                @empty
                    <div class="sl-empty-state"><h3>Inventory is healthy</h3><p>No low-stock products right now.</p></div>
                @endforelse
            </div>
        </aside>
    </div>
</div>
@endsection
