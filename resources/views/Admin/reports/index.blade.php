@extends('layouts.admin.app')

@section('title', 'Reports')
@section('subtitle', 'Explore sales and platform commission by shop, category, and product.')
@section('active', 'reports')

@section('content')
<div class="ad-page ad-report-page">
    <header class="ad-page-head ad-report-page-head">
        <div>
            <span class="ad-overline">Marketplace performance</span>
            <h2>Sales and commission</h2>
            <p>Compare top-selling shops, product categories, and individual products.</p>
        </div>
        <a class="ad-btn ad-btn-secondary" href="{{ route('admin.reports.export', $filters) }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v4h14v-4"/></svg>
            Download filtered PDF
        </a>
    </header>

    <nav class="ad-report-tabs" aria-label="Sales report sections">
        <a href="#reports-overview">Overview</a>
        <a href="#reports-rankings">Rankings</a>
        <a href="#reports-transactions">Transactions <span>{{ number_format($orderItemRecords->total()) }}</span></a>
    </nav>

    <section id="reports-overview" class="ad-report-tab-panel" aria-label="Report overview and filters">
    <section class="ad-report-summary" aria-label="Filtered sales summary">
        <article class="ad-report-stat is-sales">
            <span class="ad-report-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2v20m5-15.5C17 4.6 14.9 3.5 12 3.5S7 4.9 7 7s1.7 3.1 5 4 5 2 5 4.2-2.2 3.3-5 3.3-5-1.2-5-3.3"/></svg></span>
            <span class="ad-report-stat-copy"><span class="ad-report-stat-label">Item sales</span><strong>PHP {{ number_format((float) ($summary->sales ?? 0), 2) }}</strong><small>After seller vouchers, excluding shipping</small></span>
        </article>
        <article class="ad-report-stat is-commission">
            <span class="ad-report-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V5m0 14h16M7 15l4-4 3 2 5-6"/><path d="M16 7h3v3"/></svg></span>
            <span class="ad-report-stat-copy"><span class="ad-report-stat-label">Platform commission</span><strong>PHP {{ number_format((float) ($summary->commission ?? 0), 2) }}</strong><small>Calculated commission; voids excluded</small></span>
        </article>
        <article class="ad-report-stat is-units">
            <span class="ad-report-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7M12 11v10M8 5l8 4"/></svg></span>
            <span class="ad-report-stat-copy"><span class="ad-report-stat-label">Units sold</span><strong>{{ number_format((int) ($summary->units_sold ?? 0)) }}</strong><small>Across matching product rows</small></span>
        </article>
        <article class="ad-report-stat is-orders">
            <span class="ad-report-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14v17H5zM8 8h8M8 12h8M8 16h5"/><path d="M9 4V2h6v2"/></svg></span>
            <span class="ad-report-stat-copy"><span class="ad-report-stat-label">Seller orders</span><strong>{{ number_format((int) ($summary->order_count ?? 0)) }}</strong><small>Distinct orders in this report</small></span>
        </article>
    </section>

    <section class="ad-card ad-report-filter-card">
        <header class="ad-card-head">
            <div><span class="ad-overline">Narrow your results</span><h2>Report filters</h2><p>Combine shop, category, product, order status, and date.</p></div>
            <a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.reports') }}#reports-overview">Clear filters</a>
        </header>
        <form method="GET" action="{{ route('admin.reports') }}#reports-overview">
            <div class="ad-card-body">
                <div class="ad-report-filter-grid">
                    <label class="ad-field"><span>Shop</span>
                        <select name="seller_profile_id">
                            <option value="">All shops</option>
                            @foreach($shopOptions as $shopOption)
                                <option value="{{ $shopOption->id }}" @selected((string) ($filters['seller_profile_id'] ?? '') === (string) $shopOption->id)>{{ $shopOption->business_name }} - {{ $shopOption->primaryCategory?->name ?: 'No category' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field"><span>Category</span>
                        <select name="category_id">
                            <option value="">All categories</option>
                            @foreach($categoryOptions as $categoryOption)
                                <option value="{{ $categoryOption->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $categoryOption->id)>{{ $categoryOption->parent?->name ? $categoryOption->parent->name.' / ' : '' }}{{ $categoryOption->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field"><span>Product</span>
                        <select name="product_id">
                            <option value="">All products</option>
                            @foreach($productOptions as $productOption)
                                <option value="{{ $productOption->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $productOption->id)>{{ $productOption->name }} - {{ $productOption->sellerProfile?->business_name ?: 'Shop unavailable' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field"><span>Order status</span>
                        <select name="status">
                            <option value="">All statuses</option>
                            @foreach(['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP', 'COMPLETED', 'CANCELLED'] as $statusOption)
                                <option value="{{ $statusOption }}" @selected(($filters['status'] ?? '') === $statusOption)>{{ str($statusOption)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field"><span>From date</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
                    <label class="ad-field"><span>To date</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
                    <label class="ad-field"><span>Rank results by</span>
                        <select name="sort">
                            <option value="sales" @selected(($filters['sort'] ?? 'sales') === 'sales')>Highest item sales</option>
                            <option value="units" @selected(($filters['sort'] ?? '') === 'units')>Most units sold</option>
                            <option value="commission" @selected(($filters['sort'] ?? '') === 'commission')>Highest commission</option>
                            <option value="orders" @selected(($filters['sort'] ?? '') === 'orders')>Most orders</option>
                        </select>
                    </label>
                </div>
                <div class="ad-report-filter-footer">
                    <p class="ad-report-filter-hint">All order statuses are included until you select one.</p>
                    <button class="ad-btn ad-btn-primary" type="submit">Apply filters</button>
                </div>
            </div>
        </form>
    </section>
    </section>

    <section id="reports-rankings" class="ad-report-rankings-section ad-report-tab-panel" aria-label="Shop, category, and product rankings">
        <header class="ad-report-section-head">
            <div><span class="ad-overline">Rankings</span><h2>Top performers</h2><p>Top 10 within this report, ranked by {{ match($filters['sort'] ?? 'sales') { 'units' => 'units sold', 'commission' => 'commission', 'orders' => 'orders', default => 'item sales' } }}.</p></div>
        </header>
        <div class="ad-report-rankings">
            <article class="ad-card ad-report-rank-card">
                <header class="ad-card-head"><div><h3>Shops</h3><p>Matching product sales by seller</p></div><span class="ad-report-rank-badge">01</span></header>
                <div class="ad-table-wrap"><table class="ad-table ad-report-table"><thead><tr><th>Shop</th><th>Orders</th><th>Units</th><th>Item sales</th><th>Commission</th></tr></thead><tbody>
                    @forelse($shops as $shop)<tr><td><strong>{{ $shop->label }}</strong><small>{{ $shop->shop_category ?: 'No category' }}</small></td><td>{{ number_format((int) $shop->order_count) }}</td><td>{{ number_format((int) $shop->units_sold) }}</td><td>PHP {{ number_format((float) $shop->sales, 2) }}</td><td>PHP {{ number_format((float) $shop->commission, 2) }}</td></tr>
                    @empty<tr><td colspan="5" class="ad-report-empty">No shop sales match these filters.</td></tr>@endforelse
                </tbody></table></div>
            </article>

            <article class="ad-card ad-report-rank-card">
                <header class="ad-card-head"><div><h3>Categories</h3><p>Sales by current product category</p></div><span class="ad-report-rank-badge">02</span></header>
                <div class="ad-table-wrap"><table class="ad-table ad-report-table"><thead><tr><th>Category</th><th>Orders</th><th>Units</th><th>Item sales</th><th>Commission</th></tr></thead><tbody>
                    @forelse($categories as $category)<tr><td><strong>{{ $category->parent_category_name ? $category->parent_category_name.' / ' : '' }}{{ $category->category_name }}</strong></td><td>{{ number_format((int) $category->order_count) }}</td><td>{{ number_format((int) $category->units_sold) }}</td><td>PHP {{ number_format((float) $category->sales, 2) }}</td><td>PHP {{ number_format((float) $category->commission, 2) }}</td></tr>
                    @empty<tr><td colspan="5" class="ad-report-empty">No category sales match these filters.</td></tr>@endforelse
                </tbody></table></div>
            </article>

            <article class="ad-card ad-report-rank-card is-wide">
                <header class="ad-card-head"><div><h3>Products</h3><p>Best-selling individual products across shops</p></div><span class="ad-report-rank-badge">03</span></header>
                <div class="ad-table-wrap"><table class="ad-table ad-report-table"><thead><tr><th>Product</th><th>Shop</th><th>Category</th><th>Orders</th><th>Units</th><th>Item sales</th><th>Commission</th></tr></thead><tbody>
                    @forelse($products as $product)<tr><td><strong>{{ $product->label }}</strong></td><td>{{ $product->shop_name }}</td><td>{{ $product->parent_category_name ? $product->parent_category_name.' / ' : '' }}{{ $product->category_name }}</td><td>{{ number_format((int) $product->order_count) }}</td><td>{{ number_format((int) $product->units_sold) }}</td><td>PHP {{ number_format((float) $product->sales, 2) }}</td><td>PHP {{ number_format((float) $product->commission, 2) }}</td></tr>
                    @empty<tr><td colspan="7" class="ad-report-empty">No product sales match these filters.</td></tr>@endforelse
                </tbody></table></div>
            </article>
        </div>
        <p class="ad-report-footnote">Commission is recorded when a seller order is completed. Product and category commission is allocated by item share; void commissions are excluded. Category labels reflect the product's current category.</p>
    </section>

    <section id="reports-transactions" class="ad-card ad-report-detail-card ad-report-tab-panel" aria-label="Matching product sales transactions">
        <header class="ad-card-head"><div><span class="ad-overline">Transactions</span><h2>Matching product sales</h2><p>One row per order item. Sales exclude shipping and distribute seller voucher discounts proportionally.</p></div><span class="ad-report-row-count">{{ number_format($orderItemRecords->total()) }} rows</span></header>
        <div class="ad-table-wrap"><table class="ad-table ad-report-table ad-report-detail-table"><thead><tr><th>Seller order</th><th>Buyer</th><th>Shop</th><th>Product</th><th>Category</th><th>Qty</th><th>Item sales</th><th>Commission</th><th>Status</th><th>Date</th></tr></thead><tbody>
            @forelse($orderItemRecords as $item)
                @php($buyerName = collect([$item->buyer_first_name, $item->buyer_middle_initial, $item->buyer_last_name])->filter()->implode(' '))
                <tr>
                    <td><strong>{{ $item->seller_order_number }}</strong></td>
                    <td>{{ $buyerName ?: '-' }}</td>
                    <td>{{ $item->business_name }}</td>
                    <td><strong>{{ $item->product_name }}</strong><small>SKU {{ $item->sku }}</small></td>
                    <td>{{ $item->parent_category_name ? $item->parent_category_name.' / ' : '' }}{{ $item->category_name ?: 'Uncategorized' }}</td>
                    <td>{{ number_format((int) $item->quantity) }}</td>
                    <td class="ad-report-number">PHP {{ number_format((float) $item->sales, 2) }}</td>
                    <td class="ad-report-number">PHP {{ number_format((float) $item->commission, 2) }}<small>{{ $item->commission_status ? str($item->commission_status)->headline() : 'Not calculated' }}</small></td>
                    <td><span class="ad-status is-{{ \Illuminate\Support\Str::slug($item->status) }}">{{ str($item->status)->replace('_', ' ')->headline() }}</span></td>
                    <td>{{ $item->created_at ? \Illuminate\Support\Carbon::parse($item->created_at)->format('M d, Y H:i') : '-' }}</td>
                </tr>
            @empty<tr><td colspan="10" class="ad-report-empty">No product sales match these filters.</td>@endforelse
        </tbody></table></div>
        <div class="ad-card-body ad-report-pagination">{{ $orderItemRecords->links() }}</div>
    </section>
</div>
@endsection
