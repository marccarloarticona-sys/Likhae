@extends('layouts.seller')

@section('title', 'Dashboard')
@section('active', 'dashboard')
@section('subtitle', 'Your store performance and operational priorities at a glance.')

@section('content')
@php
    $firstName = data_get($seller, 'first_name') ?: str($seller->name)->before(' ');
    $stats = $dashboardStats;
    $allOrders = $stats['orders'];
    $peso = html_entity_decode('&#8369;');
@endphp
<div class="sl-page sl-seller-dashboard">
    <section class="sl-welcome-panel">
        <div>
            <span class="sl-eyebrow">{{ now()->format('l, F j') }}</span>
            <h2>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ $firstName }}.</h2>
            <p>Your live store data shows <strong>{{ $orderStatusCounts['placed'] }} new orders</strong> and <strong>{{ number_format($stats['inventory_alerts']) }} inventory alerts</strong> that may need attention.</p>
        </div>
        <div class="sl-welcome-actions">
            <a href="{{ route('seller.products', ['mode' => 'add']) }}" class="sl-btn sl-btn-white">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Add Product
            </a>
            <a href="{{ route('seller.orders', ['status' => 'placed']) }}" class="sl-btn sl-btn-blue-soft">Process Orders</a>
        </div>
    </section>

    <section class="sl-stat-grid" aria-label="Store summary">
        <x-seller.stat-card label="Orders Placed Today" :value="number_format($stats['orders_today'])" icon="orders" />
        <x-seller.stat-card label="Total Orders" :value="number_format($stats['orders'])" icon="orders" />
        <x-seller.stat-card label="All-time completed order value; shipping and Additional Care included" :value="'₱'.number_format($stats['completed_order_value_all_time'], 2)" icon="revenue" />
        <x-seller.stat-card label="Total Products" :value="number_format($stats['products_total'])" icon="products" />
        <x-seller.stat-card label="Ready for Pickup" :value="number_format($stats['pending_shipment'])" change="Needs action" direction="down" icon="shipping" />
    </section>

    <section class="sl-card" style="margin-top:16px">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Recognized financials</span><h2>Gross Sales, Commission &amp; Net Sales</h2><p>Original merchandise value recognized on delivered order records, less assessed commission. Shipping and Additional Care are excluded. Reporting period: {{ $financialFrom->format('M d, Y') }} – {{ $financialTo->format('M d, Y') }}.</p></div><a href="{{ route('seller.reports', ['from' => $financialFrom->toDateString(), 'to' => $financialTo->toDateString()]) }}" class="sl-text-link">Open matching report</a></header>
        <form method="GET" action="{{ route('seller.dashboard') }}" class="sl-dashboard-period sl-form-grid sl-form-grid-4" aria-label="Financial reporting period">
            <input type="hidden" name="period" value="{{ $orderValueChart['period'] }}">
            <label class="sl-field"><span>From</span><input name="from" type="date" value="{{ $financialFrom->toDateString() }}" required></label>
            <label class="sl-field"><span>To</span><input name="to" type="date" value="{{ $financialTo->toDateString() }}" required></label>
            <button class="sl-btn sl-btn-soft sl-btn-sm" type="submit">Apply period</button>
        </form>
        <div class="sl-stat-grid sl-stat-grid-3" style="margin-top:12px" aria-label="Seller financial totals">
            <x-seller.stat-card label="Gross Sales" :value="html_entity_decode('&#8369;').number_format($financialSummary['gross'], 2)" icon="sales" />
            <x-seller.stat-card label="Commission" :value="html_entity_decode('&#8369;').number_format($financialSummary['commission'], 2)" icon="finance" />
            <x-seller.stat-card label="Net Sales" :value="html_entity_decode('&#8369;').number_format($financialSummary['net'], 2)" icon="revenue" />
        </div>
    </section>

    <section class="sl-card" style="margin-top:16px">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Stock health</span><h2>Inventory Overview</h2><p>Stock indicators count active variants; each product appears once in the product totals.</p></div><a href="{{ route('seller.products', ['mode' => 'inventory']) }}" class="sl-text-link">Manage inventory</a></header>
        <div class="sl-mini-stats">
            <div><span>Visible products</span><strong>{{ number_format($inventorySummary['visible_products']) }}</strong><small>{{ number_format($inventorySummary['all_products']) }} total seller products</small></div>
            <div><span>Low-stock variants</span><strong>{{ number_format($inventorySummary['low_stock_variants']) }}</strong><small>Across {{ number_format($inventorySummary['low_stock_products']) }} products</small></div>
            <div><span>Out-of-stock variants</span><strong>{{ number_format($inventorySummary['out_of_stock_variants']) }}</strong><small>Across {{ number_format($inventorySummary['out_of_stock_products']) }} products</small></div>
            <div><span>Products with alerts</span><strong>{{ number_format($stats['inventory_alerts']) }}</strong><small>Low-stock or out-of-stock variants</small></div>
        </div>
    </section>

    <section class="sl-card" style="margin-top:16px">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Settlement ledger</span><h2>Seller payable status</h2><p>Commission is calculated from original product prices; discount-funded rows remain in review.</p></div><a href="{{ route('seller.reports', ['from' => $financialFrom->toDateString(), 'to' => $financialTo->toDateString()]) }}" class="sl-text-link">View reports</a></header>
        @if($settlementSummary['schema_ready'])
            <div class="sl-stat-grid" style="margin-top:0"><x-seller.stat-card label="Pending / Review" :value="'₱'.number_format($settlementSummary['pending'], 2)" icon="orders" /><x-seller.stat-card label="Authorized" :value="'₱'.number_format($settlementSummary['authorized'], 2)" icon="money" /><x-seller.stat-card label="Paid" :value="'₱'.number_format($settlementSummary['paid'], 2)" icon="revenue" /><x-seller.stat-card label="Outstanding" :value="'₱'.number_format($settlementSummary['outstanding'], 2)" icon="shipping" /></div>
        @else
            <p class="sl-empty-state">Settlement tables are prepared but not installed yet. Existing seller orders and reports remain available.</p>
        @endif
    </section>

    <section class="sl-card sl-order-value-panel" aria-labelledby="seller-order-value-title">
        <header class="sl-card-head">
            <div><span class="sl-eyebrow">Performance</span><h2 id="seller-order-value-title">Order value</h2><p>Non-cancelled order value for {{ $orderValueChart['period_label'] }}.</p></div>
            <form method="GET" action="{{ route('seller.dashboard') }}" class="sl-order-value-filter">
                <input type="hidden" name="from" value="{{ $financialFrom->toDateString() }}">
                <input type="hidden" name="to" value="{{ $financialTo->toDateString() }}">
                <select class="sl-select sl-select-sm" name="period" aria-label="Order value period" onchange="this.form.submit()">
                    <option value="7" @selected($orderValueChart['period'] === '7')>Last 7 days</option>
                    <option value="30" @selected($orderValueChart['period'] === '30')>Last 30 days</option>
                    <option value="quarter" @selected($orderValueChart['period'] === 'quarter')>This quarter</option>
                </select>
            </form>
        </header>
        <div class="sl-chart-summary sl-order-value-summary">
            <div><span>Gross order value</span><strong>{{ $peso }}{{ number_format($orderValueChart['gross'], 2) }}</strong><small>Non-cancelled seller orders placed during {{ $orderValueChart['period_label'] }} · {{ $orderValueChart['grouping_label'] }}</small></div>
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

    <div class="sl-dashboard-grid">
        <section class="sl-card sl-sales-panel">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Recognized sales</span><h2>Delivered sales overview</h2><p>Gross merchandise sales recognized over the last 7 days.</p></div>
                <a href="{{ route('seller.reports', ['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString()]) }}" class="sl-text-link">View reports</a>
            </header>
            <div class="sl-chart-summary">
                <div><span>Gross sales (last 7 days)</span><strong>₱{{ number_format($stats['weekly_revenue'], 2) }}</strong></div>
                <span class="sl-positive">Delivered orders</span>
            </div>
            <div class="sl-bar-chart" aria-label="Sales chart for the last seven days">
                @foreach ($salesChart as $point)
                    <div class="sl-bar-column" title="{{ $point['label'] }}: ₱{{ number_format($point['value'], 2) }}">
                        <span style="height: {{ $point['height'] }}%"></span>
                        <small>{{ $point['label'] }}</small>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="sl-card sl-order-status-panel">
            <header class="sl-card-head">
                <div><span class="sl-eyebrow">Fulfillment</span><h2>Order Status</h2><p>Current order distribution.</p></div>
                <a href="{{ route('seller.orders') }}" class="sl-text-link">View all</a>
            </header>
            <div class="sl-donut-wrap">
                <div class="sl-donut" aria-label="{{ $allOrders }} orders"><span><strong>{{ $allOrders }}</strong><small>Orders</small></span></div>
                <div class="sl-chart-legend">
                    <a href="{{ route('seller.orders', ['status' => 'placed']) }}"><i class="is-blue"></i><span>Placed</span><strong>{{ $orderStatusCounts['placed'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'confirmed']) }}"><i class="is-indigo"></i><span>Accepted</span><strong>{{ $orderStatusCounts['confirmed'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'preparing']) }}"><i class="is-indigo"></i><span>Preparing</span><strong>{{ $orderStatusCounts['preparing'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'ready-for-pickup']) }}"><i class="is-slate"></i><span>Ready for Pickup</span><strong>{{ $orderStatusCounts['ready-for-pickup'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'shipping']) }}"><i class="is-light"></i><span>Picked Up</span><strong>{{ $orderStatusCounts['picked-up'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'shipping']) }}"><i class="is-light"></i><span>In Transit</span><strong>{{ $orderStatusCounts['in-transit'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'shipping']) }}"><i class="is-light"></i><span>Delivered, awaiting completion</span><strong>{{ $orderStatusCounts['delivered'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'completed']) }}"><i class="is-green"></i><span>Completed</span><strong>{{ $orderStatusCounts['completed'] }}</strong></a>
                    <a href="{{ route('seller.orders', ['status' => 'cancelled']) }}"><i class="is-red"></i><span>Cancelled</span><strong>{{ $orderStatusCounts['cancelled'] }}</strong></a>
                    <a href="{{ route('seller.orders') }}"><i class="is-light"></i><span>Other</span><strong>{{ $orderStatusCounts['other'] }}</strong></a>
                </div>
            </div>
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
                                <td><strong>₱{{ number_format((float) data_get($order, 'total'), 2) }}</strong></td>
                                <td><span class="sl-status {{ $tone }}">{{ data_get($order, 'status') }}</span></td>
                                <td><a class="sl-icon-link" href="{{ route('seller.orders', ['mode' => 'show', 'order' => data_get($order, 'id')]) }}" aria-label="View order #{{ data_get($order, 'id') }}">→</a></td>
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
                <div><span class="sl-eyebrow">Stock Control</span><h2>Inventory Alerts</h2><p>Products requiring attention.</p></div>
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

    <section class="sl-card sl-flow-card">
        <header class="sl-card-head">
            <div><span class="sl-eyebrow">Fulfillment Workflow</span><h2>From Order to Delivery</h2><p>Every stage below is linked to Seller Center operations.</p></div>
        </header>
        <div class="sl-process-flow">
            @foreach ([
                ['1', 'New Order', 'Review details'],
                ['2', 'Prepare', 'Pack and print waybill'],
                ['3', 'Assign Courier', 'Choose logistics'],
                ['4', 'Pickup', 'Hand over parcel'],
                ['5', 'In Transit', 'Monitor shipment'],
                ['6', 'Delivered', 'Receive confirmation'],
            ] as $step)
                <div class="sl-process-step"><span>{{ $step[0] }}</span><div><strong>{{ $step[1] }}</strong><small>{{ $step[2] }}</small></div></div>
            @endforeach
        </div>
    </section>
</div>
@endsection
