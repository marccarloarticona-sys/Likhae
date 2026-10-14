@props(['product'])

@php
    $id = data_get($product, 'id');
    $dbId = data_get($product, 'db_id');
    $name = data_get($product, 'name', 'Product');
    $image = data_get($product, 'image');
    $stock = (int) data_get($product, 'stock', 0);
    $lowStockCount = (int) data_get($product, 'low_stock_count', 0);
    $outOfStockCount = (int) data_get($product, 'out_of_stock_count', 0);
    $status = data_get($product, 'status', 'Active');
    $stockTone = $stock === 0 && $outOfStockCount > 0 ? 'is-danger' : ($lowStockCount > 0 || $outOfStockCount > 0 ? 'is-warning' : 'is-ok');
@endphp

<article class="sl-mobile-product" data-product-row data-product-name="{{ mb_strtolower($name) }}" data-product-status="{{ mb_strtolower($status) }}">
    <div class="sl-mobile-product-head">
        <div class="sl-product-cell">
            @if ($image)
                <img src="{{ $image }}" alt="{{ $name }}" loading="lazy">
            @else
                <span class="sl-product-placeholder">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
            @endif
            <div><strong>{{ $name }}</strong><small>{{ data_get($product, 'sku', $id) }}</small></div>
        </div>
        <span class="sl-status {{ strtolower($status) === 'active' ? 'is-active' : 'is-neutral' }}">{{ $status }}</span>
    </div>
    <dl class="sl-mobile-metrics">
        <div><dt>Price</dt><dd>₱{{ number_format((float) data_get($product, 'price', 0), 2) }}</dd></div>
        <div><dt>Stock</dt><dd class="{{ $stockTone }}">{{ number_format($stock) }}</dd></div>
        <div><dt>Sold</dt><dd>{{ number_format((int) data_get($product, 'sold', 0)) }}</dd></div>
        <div><dt>Rating</dt><dd>★ {{ number_format((float) data_get($product, 'rating', 0), 1) }}</dd></div>
    </dl>
    <div class="sl-row-actions">
        <a href="{{ route('seller.products', ['mode' => 'edit', 'product' => $id]) }}" class="sl-btn sl-btn-soft sl-btn-sm">Edit</a>
        <form method="POST" action="{{ route('seller.products.toggle', $dbId) }}">@csrf @method('PATCH')<button type="submit" class="sl-btn sl-btn-ghost sl-btn-sm">{{ $status === 'Archived' ? 'Restore' : 'Archive' }}</button></form>
    </div>
</article>
