@extends('layouts.seller.app')

@section('title', 'Inventory Report Preview')
@section('active', 'products')
@section('subtitle', 'Preview grouped inventory and variant stock levels.')

@section('content')
<div class="sl-page sl-inventory-report-page">
    <a class="sl-editor-back" href="{{ route('seller.products', ['mode' => 'inventory']) }}">&larr; Back to Inventory</a>

    <div class="sl-page-toolbar">
        <div>
            <span class="sl-eyebrow">Stock Control</span>
            <h2>Inventory Report Preview</h2>
            <p>Review this grouped report before downloading the PDF.</p>
        </div>
        <a class="sl-btn sl-btn-primary" href="{{ route('seller.products.inventory.download', ['search' => $appliedSearch ?: null, 'status' => $appliedStatus]) }}">Download PDF</a>
    </div>

    <section class="sl-inventory-report-meta" aria-label="Report details">
        <div><span>Seller</span><strong>{{ $sellerName }}</strong></div>
        <div><span>Generated</span><strong>{{ $generatedAt }}</strong></div>
        <div><span>Applied Search</span><strong>{{ $appliedSearch ?: 'All products and variants' }}</strong></div>
        <div><span>Applied Stock Filter</span><strong>{{ $appliedStatusLabel }}</strong></div>
    </section>

    <section aria-labelledby="inventory-report-overview">
        <div class="sl-inventory-report-section-head"><span class="sl-eyebrow">Overview</span><h3 id="inventory-report-overview">Stock levels</h3></div>
        <div class="sl-inventory-report-stats">
            <article aria-label="Total SKUs: {{ $inventoryStats['total'] }}"><span>Total SKUs</span><strong>{{ $inventoryStats['total'] }}</strong></article>
            <article aria-label="In Stock: {{ $inventoryStats['in_stock'] }}"><span>In Stock</span><strong>{{ $inventoryStats['in_stock'] }}</strong></article>
            <article class="is-low" aria-label="Low Stock: {{ $inventoryStats['low_stock'] }}"><span>Low Stock (1–10)</span><strong>{{ $inventoryStats['low_stock'] }}</strong></article>
            <article class="is-out" aria-label="Out of Stock: {{ $inventoryStats['out_of_stock'] }}"><span>Out of Stock</span><strong>{{ $inventoryStats['out_of_stock'] }}</strong></article>
            <article class="is-healthy" aria-label="Healthy: {{ $inventoryStats['healthy'] }}"><span>Healthy (11+)</span><strong>{{ $inventoryStats['healthy'] }}</strong></article>
        </div>
    </section>

    <section aria-labelledby="inventory-report-products">
        <div class="sl-inventory-report-section-head"><span class="sl-eyebrow">Product Inventory</span><h3 id="inventory-report-products">Products and variants</h3></div>
        <div class="sl-inventory-report-groups">
            @forelse($inventoryGroups as $group)
                <article class="sl-inventory-report-group">
                    <header>
                        <h4>{{ $group['name'] }}</h4>
                        <span>{{ $group['variant_count'] }} {{ \Illuminate\Support\Str::plural('variant', $group['variant_count']) }} · {{ $group['total_stock'] }} total units</span>
                    </header>
                    <div class="sl-inventory-report-table-wrap">
                        <table class="sl-inventory-report-table">
                            <thead><tr><th scope="col">Variant</th><th scope="col">SKU</th><th scope="col">Price</th><th scope="col">Stock</th><th scope="col">Status</th></tr></thead>
                            <tbody>
                                @foreach($group['variants'] as $variant)
                                    <tr>
                                        <td>{{ $variant['variant'] }}</td>
                                        <td>{{ $variant['sku'] ?: 'Not assigned' }}</td>
                                        <td>₱{{ number_format($variant['price'], 2) }}</td>
                                        <td>{{ $variant['stock'] }}</td>
                                        <td><span class="sl-inventory-report-status is-{{ $variant['stock_level'] }}">{{ $variant['stock_status'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            @empty
                <div class="sl-empty-state"><h3>No matching inventory</h3><p>No active variants match the applied search and stock filter.</p></div>
            @endforelse
        </div>
    </section>
</div>
@endsection
