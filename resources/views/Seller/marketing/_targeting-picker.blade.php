<label class="sl-field"><span>Applies to</span>
    <select name="applies_to" data-voucher-target>
        <option value="STORE">Entire store</option>
        <option value="PRODUCTS" @disabled(!$voucherProductTargetingReady)>Selected products</option>
        <option value="VARIANTS" @disabled(!$voucherVariantTargetingReady)>Selected variants only</option>
    </select>
</label>
@php
    $productsWithOffers = $eligibleProducts->filter(fn ($product) => ! empty($promotionConflicts['products'][$product->id] ?? []));
@endphp
<div class="sl-field sl-span-2 sl-compliance-feedback is-warning sl-promotion-conflict" role="note">
    Seller vouchers reduce the seller’s proceeds. Product discounts and Bundle or Buy X Get Y offers can conflict with voucher eligibility. Flash Sales can combine with Seller vouchers, and the checkout caps each item discount to keep its price positive.
    @if($productsWithOffers->isNotEmpty())
        <details class="sl-promotion-conflict__products" data-voucher-conflict-details>
            <summary>Review <span data-voucher-conflict-count>{{ $productsWithOffers->count() }}</span> product{{ $productsWithOffers->count() === 1 ? '' : 's' }} with existing offers</summary>
            <ul>
                @foreach($productsWithOffers as $product)
                    <li data-voucher-conflict-product><strong>{{ $product->name }}</strong><ul>
                        @foreach($promotionConflicts['products'][$product->id] as $conflict)
                            <li data-voucher-conflict-entry data-voucher-conflict-kind="{{ $conflict['kind'] }}" data-voucher-conflict-id="{{ $conflict['id'] ?? '' }}">{{ $conflict['label'] }} · {{ $conflict['status'] }}</li>
                        @endforeach
                    </ul></li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
<div class="sl-field sl-span-2" data-voucher-products-field hidden>
    <div class="sl-table-toolbar"><div><span class="sl-field-label">Eligible products</span><p>Select any number of products. All active variants of those products qualify.</p></div><span class="sl-status is-neutral"><span data-selected-count="products">0</span> selected</span></div>
    <label class="sl-field"><span>Search products</span><input type="search" autocomplete="off" data-voucher-search="products" placeholder="Search your products"></label>
    <div class="sl-toolbar-group sl-picker-actions"><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-select-visible="products">Select visible</button><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-clear-selection="products">Clear selection</button></div>
    <div class="sl-marketing-picker" data-voucher-product-list>
        @if($eligibleProducts->isEmpty())
            <p class="sl-empty-state">Add and publish an active product before creating a targeted voucher.</p>
        @else
        @foreach($eligibleProducts as $product)
            <label class="sl-marketing-check-row" data-voucher-product-row data-search="{{ mb_strtolower($product->name) }}">
                <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" data-voucher-product-checkbox @checked(in_array((int) $product->id, $selectedProductIds ?? [], true))>
                <span><strong>{{ $product->name }}</strong><small>{{ $product->variants->count() }} active variant{{ $product->variants->count() === 1 ? '' : 's' }}</small>
                    @foreach(($promotionConflicts['products'][$product->id] ?? []) as $conflict)
                        <small class="sl-promotion-conflict-hint" data-voucher-conflict-kind="{{ $conflict['kind'] }}" data-voucher-conflict-id="{{ $conflict['id'] ?? '' }}">⚠ {{ $conflict['label'] }} · {{ $conflict['status'] }}</small>
                    @endforeach
                </span>
            </label>
        @endforeach
        @endif
    </div>
    <small>Only selected products count toward this voucher and its minimum spend.</small>
</div>

<div class="sl-field sl-span-2" data-voucher-variants-field hidden>
    <div class="sl-table-toolbar"><div><span class="sl-field-label">Eligible variants</span><p>Expand a product and choose the exact variants that qualify.</p></div><span class="sl-status is-neutral"><span data-selected-count="variants">0</span> selected</span></div>
    <label class="sl-field"><span>Search products or variants</span><input type="search" autocomplete="off" data-voucher-search="variants" placeholder="Search product name, variant, or SKU"></label>
    <div class="sl-marketing-picker" data-voucher-variant-list>
        @if($eligibleProducts->isEmpty())
            <p class="sl-empty-state">Add and publish an active product before creating a variant voucher.</p>
        @else
        @foreach($eligibleProducts as $product)
            <details class="sl-marketing-variant-product" data-voucher-variant-product data-product-id="{{ $product->id }}" data-search="{{ mb_strtolower($product->name) }}">
                <summary><strong>{{ $product->name }}</strong><span>{{ $product->variants->count() }} variants</span></summary>
                <div class="sl-toolbar-group sl-picker-actions"><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-select-product-variants>Select all variants</button></div>
                @if($product->variants->isEmpty())
                    <p class="sl-empty-state">No active variants.</p>
                @else
                @foreach($product->variants as $variant)
                    <label class="sl-marketing-check-row" data-voucher-variant-row data-search="{{ mb_strtolower($product->name.' '.$variant->description.' '.$variant->sku) }}">
                        <input type="checkbox" name="variant_ids[]" value="{{ $variant->id }}" data-voucher-variant-checkbox data-price="{{ number_format((float) ($variant->active_flash_sale_price ?? $variant->final_price), 2, '.', '') }}" @checked(in_array((int) $variant->id, $selectedVariantIds ?? [], true))>
                        <span><strong>{{ $variant->description ?: $variant->sku }}</strong><small>{{ $variant->sku }} &middot; PHP {{ number_format((float) ($variant->active_flash_sale_price ?? $variant->final_price), 2) }}{{ $variant->active_flash_sale_price !== null ? ' Flash Sale price' : '' }} &middot; {{ number_format((int) $variant->stock) }} in stock</small>
                            @foreach(($promotionConflicts['variants'][$variant->id] ?? []) as $conflict)
                                <small class="sl-promotion-conflict-hint" data-voucher-conflict-kind="{{ $conflict['kind'] }}" data-voucher-conflict-id="{{ $conflict['id'] ?? '' }}">⚠ {{ $conflict['label'] }} · {{ $conflict['status'] }}</small>
                            @endforeach
                        </span>
                    </label>
                @endforeach
                @endif
            </details>
        @endforeach
        @endif
    </div>
    <small>Only the checked variant IDs qualify. Other variants remain unaffected.</small>
</div>
