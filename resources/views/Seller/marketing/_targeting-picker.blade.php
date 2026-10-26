<label class="sl-field"><span>Applies to</span>
    <select name="applies_to" data-voucher-target>
        <option value="STORE">Entire store</option>
        <option value="PRODUCTS" @disabled(!$voucherProductTargetingReady)>Selected products</option>
        <option value="VARIANTS" @disabled(!$voucherVariantTargetingReady)>Selected variants only</option>
    </select>
</label>
<div class="sl-field sl-span-2" data-voucher-products-field hidden>
    <div class="sl-table-toolbar"><div><span class="sl-field-label">Eligible products</span><p>Select any number of products. All active variants of those products qualify.</p></div><span class="sl-status is-neutral"><span data-selected-count="products">0</span> selected</span></div>
    <label class="sl-field"><span>Search products</span><input type="search" autocomplete="off" data-voucher-search="products" placeholder="Search your products"></label>
    <div class="sl-toolbar-group sl-picker-actions"><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-select-visible="products">Select visible</button><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-clear-selection="products">Clear selection</button></div>
    <div class="sl-marketing-picker" data-voucher-product-list>
        @forelse($eligibleProducts as $product)
            <label class="sl-marketing-check-row" data-voucher-product-row data-search="{{ mb_strtolower($product->name) }}">
                <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" data-voucher-product-checkbox @checked(in_array((int) $product->id, $selectedProductIds ?? [], true))>
                <span><strong>{{ $product->name }}</strong><small>{{ $product->variants->count() }} active variant{{ $product->variants->count() === 1 ? '' : 's' }}</small></span>
            </label>
        @empty
            <p class="sl-empty-state">Add and publish an active product before creating a targeted voucher.</p>
        @endforelse
    </div>
    <small>Only selected products count toward this voucher and its minimum spend.</small>
</div>

<div class="sl-field sl-span-2" data-voucher-variants-field hidden>
    <div class="sl-table-toolbar"><div><span class="sl-field-label">Eligible variants</span><p>Expand a product and choose the exact variants that qualify.</p></div><span class="sl-status is-neutral"><span data-selected-count="variants">0</span> selected</span></div>
    <label class="sl-field"><span>Search products or variants</span><input type="search" autocomplete="off" data-voucher-search="variants" placeholder="Search product name, variant, or SKU"></label>
    <div class="sl-marketing-picker" data-voucher-variant-list>
        @forelse($eligibleProducts as $product)
            <details class="sl-marketing-variant-product" data-voucher-variant-product data-product-id="{{ $product->id }}" data-search="{{ mb_strtolower($product->name) }}">
                <summary><strong>{{ $product->name }}</strong><span>{{ $product->variants->count() }} variants</span></summary>
                <div class="sl-toolbar-group sl-picker-actions"><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-select-product-variants>Select all variants</button></div>
                @forelse($product->variants as $variant)
                    <label class="sl-marketing-check-row" data-voucher-variant-row data-search="{{ mb_strtolower($product->name.' '.$variant->description.' '.$variant->sku) }}">
                        <input type="checkbox" name="variant_ids[]" value="{{ $variant->id }}" data-voucher-variant-checkbox data-price="{{ number_format((float) $variant->final_price, 2, '.', '') }}" @checked(in_array((int) $variant->id, $selectedVariantIds ?? [], true))>
                        <span><strong>{{ $variant->description ?: $variant->sku }}</strong><small>{{ $variant->sku }} &middot; PHP {{ number_format((float) $variant->final_price, 2) }} &middot; {{ number_format((int) $variant->stock) }} in stock</small></span>
                    </label>
                @empty
                    <p class="sl-empty-state">No active variants.</p>
                @endforelse
            </details>
        @empty
            <p class="sl-empty-state">Add and publish an active product before creating a variant voucher.</p>
        @endforelse
    </div>
    <small>Only the checked variant IDs qualify. Other variants remain unaffected.</small>
</div>
