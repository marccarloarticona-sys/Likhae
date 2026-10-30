@extends('layouts.seller.app')

@php
    $currentMode = $mode ?? request('mode', 'list');
    $editing = $currentMode === 'edit';
    $product = $editing ? $selectedProduct : null;
    $productImageFallback = asset('images/product-placeholder.svg');
    $pageTitle = match ($currentMode) { 'add' => 'Add Product', 'edit' => 'Edit Product', 'inventory' => 'Inventory', default => 'Products' };
    $variationRows = collect(old('variants', data_get($product, 'variation_rows', [])));
    if ($variationRows->isEmpty()) {
        $variationRows = collect([['id' => null, 'values' => '', 'sku' => '', 'price' => '', 'stock' => '']]);
    }
    $optionRows = collect(old('options', data_get($product, 'option_rows', [])))->take(3);
    if ($optionRows->isEmpty()) {
        $optionRows = collect([['name' => '', 'values' => '']]);
    }
    $storedHasVariations = collect(data_get($product, 'option_rows', []))->isNotEmpty() || collect(data_get($product, 'variation_rows', []))->count() > 1;
    $productType = old('product_type', $storedHasVariations ? 'variations' : 'single');
    $singleVariant = collect(data_get($product, 'variation_rows', []))->first();
    $listingStatus = old('listing_status', strtolower(data_get($product, 'status', 'draft')));
    $imageLibrary = collect(data_get($product, 'image_library', []));
    $singlePaymentMethod = old('payment_method', data_get($singleVariant, 'payment_method', 'cod_online')) ?: 'cod_online';
    $singleActive = filter_var(old('is_active', data_get($singleVariant, 'is_active', true)), FILTER_VALIDATE_BOOLEAN);
    $initialDescriptionValue = (string) old('description', data_get($product, 'description', ''));
    $initialDescriptionText = preg_replace('/\[\[(?:image|description-image):[^\]]+\]\]/', '', $initialDescriptionValue) ?? $initialDescriptionValue;
@endphp

@if(in_array($currentMode, ['add', 'edit'], true))
    @push('head')
        <script>document.documentElement.classList.add('has-seller-description-editor');</script>
    @endpush
@endif

@section('title', $pageTitle)
@section('active', 'products')
@section('subtitle', $currentMode === 'inventory' ? 'Monitor stock levels and prevent missed sales.' : ($editing ? 'Update listing information, pricing, and inventory.' : ($currentMode === 'add' ? 'Create a complete and buyer-ready product listing.' : 'Manage product listings, pricing, stock, and visibility.')))

@section('content')
<div class="sl-page">
    @if(session('product_saved'))
        @php
            $savedProduct = session('product_saved');
            $savedStatus = strtoupper((string) data_get($savedProduct, 'status'));
            $saveConfirmationTitle = match ($savedStatus) {
                'ACTIVE' => 'Product published successfully',
                'SUSPENDED' => 'Changes saved; product remains suspended',
                default => 'Draft saved successfully',
            };
            $saveConfirmationMessage = match ($savedStatus) {
                'ACTIVE' => 'is now published and available to buyers.',
                'SUSPENDED' => 'was updated but remains hidden from buyers until Admin reviews it.',
                default => 'has been saved as a draft and is not visible to buyers.',
            };
        @endphp
        <dialog class="sl-product-save-dialog" data-product-save-dialog aria-labelledby="product-save-title" aria-describedby="product-save-message">
            <span class="sl-product-save-dialog__icon" aria-hidden="true">✓</span>
            <h2 id="product-save-title">{{ $saveConfirmationTitle }}</h2>
            <p id="product-save-message"><strong>{{ data_get($savedProduct, 'name') }}</strong> {{ $saveConfirmationMessage }}</p>
            <form method="dialog"><button class="sl-btn sl-btn-primary" autofocus>Continue editing</button></form>
        </dialog>
        <script>
            (() => {
                const dialog = document.querySelector('[data-product-save-dialog]');
                if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            })();
        </script>
    @elseif(session('status'))
        <div class="sl-alert sl-alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any() && in_array($currentMode, ['add', 'edit'], true))
        <dialog class="sl-product-save-dialog sl-product-save-error-dialog" data-product-save-error-dialog aria-labelledby="product-save-error-title" aria-describedby="product-save-error-intro product-save-error-list">
            <span class="sl-product-save-dialog__icon is-error" aria-hidden="true">!</span>
            <h2 id="product-save-error-title">Product could not be saved</h2>
            <p id="product-save-error-intro">Fix the following and try again:</p>
            <ul class="sl-product-save-dialog__errors" id="product-save-error-list">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <form method="dialog"><button class="sl-btn sl-btn-primary" autofocus>Close and review form</button></form>
        </dialog>
        <script>
            (() => {
                const dialog = document.querySelector('[data-product-save-error-dialog]');
                if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
            })();
        </script>
    @endif
    @if($currentMode === 'inventory' && $errors->any())
        <div class="sl-form-errors" role="alert"><strong>Stock was not updated.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if (in_array($currentMode, ['add', 'edit'], true))
        <div class="sl-page-toolbar">
            <div><a href="{{ route('seller.products') }}" class="sl-editor-back">&larr; Back to Products</a><h2>{{ $editing ? 'Edit '.data_get($product, 'name') : 'Create New Product' }}</h2><p>Add product information, pricing, images, and availability.</p></div>
            @if($editing && data_get($product, 'db_id'))
                <a href="{{ route('seller.marketing', ['section' => 'product-discounts', 'product_id' => data_get($product, 'db_id')]) }}" class="sl-btn sl-btn-ghost">Manage Discounts</a>
                <a href="{{ route('seller.marketing', ['section' => 'flash-sales', 'product_id' => data_get($product, 'db_id')]) }}" class="sl-btn sl-btn-ghost">Manage Flash Sale</a>
            @endif
        </div>

        <form class="sl-editor-layout" method="POST" enctype="multipart/form-data" action="{{ $editing ? route('seller.products.update', data_get($product, 'db_id')) : route('seller.products.store') }}" data-saved-variant-prices='@json($savedVariantPrices)'>
            @csrf
            @if($editing) @method('PUT') @endif
            @unless($editing)<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $submissionToken) }}">@endunless
            <input type="hidden" name="listing_status" value="draft" data-listing-status-field>
            <div class="sl-editor-main">
                <section class="sl-card sl-form-section">
                    <header class="sl-form-section-head"><span>1</span><div><h3>Product Details</h3><p>Add the details buyers need to understand your product.</p></div></header>
                    <div class="sl-form-grid">
                        <label class="sl-field"><span>Purchase Limit Per Buyer</span><input name="purchase_limit_per_buyer" type="number" min="1" max="4294967295" step="1" value="{{ old('purchase_limit_per_buyer', data_get($product, 'purchase_limit_per_buyer')) }}" placeholder="Unlimited"><small>Leave blank for unlimited purchases. Applies to cumulative product units across all variations.</small>@error('purchase_limit_per_buyer')<small class="sl-error">{{ $message }}</small>@enderror</label>
                        <label class="sl-field sl-span-2"><span>Product name <b>*</b></span><input name="name" type="text" value="{{ old('name', data_get($product, 'name')) }}" required></label>
                        <label class="sl-field"><span>Line of Business</span><input value="{{ $lineOfBusinessCategory?->name ?? 'Not assigned' }}" readonly></label>
                        <label class="sl-field"><span>Subcategory <b>*</b></span><select name="category_id" required><option value="">Select subcategory</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', data_get($product, 'category_id')) === (string) $category->id)>{{ $category->name }}{{ $category->is_unavailable_assignment ?? false ? ' (currently assigned · unavailable for new listings)' : '' }}</option>@endforeach</select>@if($editing && ($categories->firstWhere('id', data_get($product, 'category_id'))?->is_unavailable_assignment ?? false))<small class="sl-compliance-warning">This product keeps its historical category assignment. Choose an active category in your registered line of business when correcting it.</small>@endif</label>
                        <div class="sl-field sl-span-2">
                            <span id="product-description-label">Description <b>* for publishing</b></span>
                            <textarea id="product-description" name="description" rows="7" maxlength="11000" placeholder="Describe the product, materials, dimensions, care instructions, and inclusions." data-description-value>{{ $initialDescriptionValue }}</textarea>
                            <div class="sl-description-editor" data-description-visual>
                                <div class="sl-description-editor__toolbar"><button type="button" class="sl-btn sl-btn-soft sl-btn-sm" data-description-insert-image disabled>Add picture</button><small>Description pictures are separate from the product gallery · JPG, PNG, WebP · 5 MB max each · 10 pictures max.</small></div>
                                <div class="sl-description-editor__content" contenteditable="false" role="textbox" aria-multiline="true" aria-labelledby="product-description-label" data-description-content data-placeholder="Describe the product, then insert pictures where buyers should see them.">{{ $initialDescriptionText }}</div>
                            </div>
                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple data-description-image-picker hidden>
                            <input type="file" name="description_images[]" accept="image/jpeg,image/png,image/webp" multiple data-description-images-input hidden>
                            <div data-description-image-library hidden>
                                @foreach(data_get($product, 'description_image_library', []) as $descriptionImage)
                                    <span data-description-image-item data-description-image-ref="{{ data_get($descriptionImage, 'ref') }}" data-image-url="{{ data_get($descriptionImage, 'url') }}" data-image-label="{{ data_get($descriptionImage, 'alt', 'Product description image') }}"></span>
                                @endforeach
                            </div>
                            <small><span data-character-count>{{ mb_strlen($initialDescriptionText) }}</span>/10000 characters. Inserted pictures do not count toward the text limit.</small>
                            @error('description')<small class="sl-error">{{ $message }}</small>@enderror
                        </div>
                        <div class="sl-field sl-span-2"><span>Primary product image <b>* for publishing</b></span><label class="sl-upload-box"><input name="image" type="file" accept="image/jpeg,image/png,image/webp" data-image-input><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M4 15v5h16v-5"/></svg><strong>Upload primary product image</strong><small>Required to publish. JPG, PNG, or WebP · maximum 5 MB. Description pictures do not count.</small></label><div class="sl-image-preview" data-image-preview>@if(data_get($product,'image'))<img src="{{ data_get($product,'image') }}" alt="">@endif</div>@error('image')<small class="sl-error">{{ $message }}</small>@enderror</div>
                        <div class="sl-field sl-span-2"><span>Additional images</span><label class="sl-upload-box"><input name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-images-input><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M4 15v5h16v-5"/></svg><strong>Upload multiple product images</strong><small>JPG, PNG, or WebP · maximum 5 MB each, 10 photos per product including the primary image</small></label>
                            <small class="sl-upload-help">Selected images appear in the uploaded product image library below.</small>
                            <div class="sl-product-image-library" data-product-image-library>
                                <div class="sl-product-image-library__head"><strong>Uploaded product image library</strong><small>These images are uploaded once and can be assigned to many variants below.</small></div>
                                <div class="sl-product-image-library__items" data-saved-product-image-library>
                                    @foreach($imageLibrary as $libraryImage)
                                        <span class="sl-saved-image sl-product-image-library__item" data-library-image data-saved-image data-image-ref="{{ data_get($libraryImage, 'ref') }}" data-image-url="{{ data_get($libraryImage, 'url') }}" data-image-label="#{{ data_get($libraryImage, 'number') }}" data-image-id="{{ data_get($libraryImage, 'id') }}">
                                            <span class="sl-image-number">#{{ data_get($libraryImage, 'number') }}@if(data_get($libraryImage, 'is_primary')) · Primary @endif</span>
                                            <img src="{{ data_get($libraryImage, 'url') }}" alt="{{ data_get($libraryImage, 'alt', data_get($product, 'name')) }}" loading="lazy">
                                            <button type="button" class="sl-image-remove" data-remove-saved-image data-image-id="{{ data_get($libraryImage, 'id') }}">Remove</button>
                                        </span>
                                    @endforeach
                                </div>
                                <div class="sl-product-image-library__items" data-new-product-image-library></div>
                            </div>
                            @error('images')<small class="sl-error">{{ $message }}</small>@enderror
                            @if($editing && data_get($product, 'image'))<small>Uploaded images are stored with this product. The primary image is shown in the catalog.</small>@endif
                        </div>
                    </div>
                </section>

                <section class="sl-card sl-form-section">
                    <header class="sl-form-section-head"><span>2</span><div><h3>Pricing &amp; Variations</h3><p>Set one price or add options such as size and color.</p></div></header>
                    @php
                        $currentProductId = data_get($product, 'db_id') ?? data_get($product, 'id');
                        $currentProductConflicts = $editing ? ($promotionConflicts['products'][$currentProductId] ?? []) : [];
                    @endphp
                    @if(!empty($currentProductConflicts))
                        <div class="sl-compliance-feedback is-warning sl-promotion-conflict" role="status">
                            <strong>Existing offers affect this product</strong>
                            <ul>
                                @foreach($currentProductConflicts as $conflict)
                                    <li>{{ $conflict['label'] }} · {{ $conflict['status'] }}</li>
                                @endforeach
                            </ul>
                            <p>Discounts, flash sales, vouchers, and bundle deals are managed in Marketing. Base price changes can affect active promotions, so review them before changing a saved price.</p>
                        </div>
                    @else
                        <div class="sl-compliance-feedback is-warning sl-promotion-conflict" role="note">Manage Seller discounts and schedules in Marketing. Product pricing here is the regular catalog price.</div>
                    @endif
                    <div class="sl-compliance-feedback is-warning sl-promotion-conflict" data-price-reduction-warning role="status" hidden><strong>Price reduction detected</strong><p data-price-reduction-message></p><p>The amount below the saved catalog price is Seller-funded.</p></div>
                    @if($editing)
                        @php
                            $savedDiscountRows = collect(data_get($product, 'variation_rows', []))->filter(fn ($row) => strtolower((string) data_get($row, 'discount_type', 'none')) !== 'none' && (float) data_get($row, 'discount_value', 0) > 0);
                            $savedDiscountStatus = ! (bool) data_get($product, 'discounts_enabled', true) ? 'Paused'
                                : (data_get($product, 'discount_starts_at') && \Illuminate\Support\Carbon::parse(data_get($product, 'discount_starts_at'))->isFuture() ? 'Scheduled'
                                    : (data_get($product, 'discount_ends_at') && \Illuminate\Support\Carbon::parse(data_get($product, 'discount_ends_at'))->isPast() ? 'Ended' : 'Active'));
                        @endphp
                        <div class="sl-compliance-feedback" role="status">
                            <strong>Product discounts · {{ $savedDiscountStatus }}</strong>
                            @if($savedDiscountRows->isEmpty())
                                <p>No product discounts are configured.</p>
                            @else
                                <ul>@foreach($savedDiscountRows as $discountRow)<li>{{ data_get($discountRow, 'description') ?: data_get($discountRow, 'sku') }}: {{ data_get($discountRow, 'discount_type') === 'fixed' ? 'PHP '.number_format((float) data_get($discountRow, 'discount_value'), 2).' off' : number_format((float) data_get($discountRow, 'discount_value'), 2).'% off' }}</li>@endforeach</ul>
                            @endif
                            <p>Discount settings are read-only here. <a href="{{ route('seller.marketing', ['section' => 'product-discounts', 'product_id' => data_get($product, 'db_id')]) }}">Manage Discounts in Marketing</a>.</p>
                        </div>
                    @endif
                    <div class="sl-product-type-grid" data-product-type-editor><label class="sl-product-type-card"><input type="radio" name="product_type" value="single" @checked($productType === 'single')><strong>Single Product</strong><small>One price, stock, and SKU.</small></label><label class="sl-product-type-card"><input type="radio" name="product_type" value="variations" @checked($productType === 'variations')><strong>Has Variations</strong><small>Different sizes, colors, prices, or stock.</small></label></div>
                    <div data-single-product-fields>
                    <input type="hidden" name="single_variant_id" value="{{ data_get($singleVariant, 'id') }}">
                    <div class="sl-form-grid sl-form-grid-4">
                        <label class="sl-field"><span>Price <b>* for publishing</b></span><div class="sl-input-prefix"><i>₱</i><input name="price" type="number" min="0" step="0.01" data-saved-price="{{ $savedVariantPrices[data_get($singleVariant, 'id')] ?? '' }}" data-marketing-discount-type="{{ data_get($singleVariant, 'discount_type', 'none') }}" data-marketing-discount-value="{{ data_get($singleVariant, 'discount_value', 0) }}" data-marketing-discount-active="{{ data_get($singleVariant, 'discount_active', false) ? 'true' : 'false' }}" data-marketing-flash-price="{{ data_get($singleVariant, 'flash_sale_price', '') }}" data-marketing-flash-active="{{ data_get($singleVariant, 'flash_sale_active', false) ? 'true' : 'false' }}" data-marketing-flash-remaining="{{ data_get($singleVariant, 'flash_sale_remaining', 0) }}" value="{{ old('price', data_get($product, 'base_price')) }}"></div>@error('price')<small class="sl-error">{{ $message }}</small>@enderror</label>
                        <label class="sl-field"><span>Stock <b>* for publishing</b></span><input name="stock" type="number" min="0" value="{{ old('stock', data_get($product, 'stock')) }}">@error('stock')<small class="sl-error">{{ $message }}</small>@enderror</label>
                        <label class="sl-field"><span>Weight (kg)</span><input name="weight_kg" type="number" min="0.001" max="9999999.999" step="0.001" value="{{ old('weight_kg', data_get($singleVariant, 'weight_kg')) }}" placeholder="e.g. 0.500"><small>Weight of one item, before packing.</small></label>
                        <label class="sl-field sl-span-2"><span>SKU</span><input name="sku" maxlength="100" value="{{ old('sku', data_get($singleVariant, 'sku')) }}" placeholder="Generated automatically when blank"><small>Leave blank to create a SKU automatically.</small></label>
                        <label class="sl-field"><span>Accepted payment</span><select name="payment_method"><option value="cod_online" @selected($singlePaymentMethod === 'cod_online')>COD + Online</option><option value="cod" @selected($singlePaymentMethod === 'cod')>COD only</option><option value="online" @selected($singlePaymentMethod === 'online')>Online only</option></select></label>
                        <label class="sl-field"><span>Availability</span><select name="is_active"><option value="1" @selected((bool) $singleActive)>Available</option><option value="0" @selected(! (bool) $singleActive)>Unavailable</option></select></label>
                    </div>
                    </div>
                    <div data-variation-product-fields>
                    <div class="sl-option-list" data-option-list data-max-options="3">@foreach($optionRows as $index => $option)<div class="sl-option-row"><label class="sl-field"><span>Variation type</span><input name="options[{{ $index }}][name]" value="{{ data_get($option, 'name') }}" placeholder="e.g. Size"></label><label class="sl-field"><span>Options</span><input name="options[{{ $index }}][values]" value="{{ data_get($option, 'values') }}" placeholder="Small, Medium, Large"></label><button type="button" class="sl-icon-btn" data-remove-option aria-label="Remove variation type">&times;</button></div>@endforeach</div>
                    <div class="sl-option-actions"><button type="button" class="sl-btn sl-btn-soft sl-btn-sm" data-add-option>Add option type</button><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-generate-variants>Generate variation cards</button></div>
                    <div class="sl-variation-head">
                        <div><strong>Variation Details</strong><small>Each row maps directly to product_variants. Remove a combination to intentionally make it unavailable.</small></div>
                    </div>
                    <div class="sl-variation-bulk" data-variation-bulk>
                        <label><span>Apply to</span><select data-bulk-scope><option value="all">All variations</option><option value="selected">Selected variations</option></select></label>
                        <label><span>Image</span><button type="button" class="sl-bulk-image-button" data-bulk-image-trigger><span class="sl-variant-image-thumb" data-bulk-image-thumb></span><span data-bulk-image-label>Choose image</span></button><input type="hidden" data-bulk-image-ref></label>
                        <label><span>Payment</span><select data-bulk-payment><option value="cod_online">COD + Online</option><option value="cod">COD only</option><option value="online">Online only</option></select></label>
                        <label><span>Availability</span><select data-bulk-active><option value="1">Available</option><option value="0">Unavailable</option></select></label>
                        <button type="button" class="sl-btn sl-btn-soft sl-btn-sm" data-apply-bulk>Apply</button>
                    </div>
                    <div class="sl-variation-table" data-variation-list>
                        <div class="sl-variation-row sl-variation-labels" aria-hidden="true"><span>Select</span><span>Option values</span><span>Image</span><span>SKU</span><span>Price</span><span>Stock</span><span>Weight (kg)</span><span>Payment</span><span>Availability</span><span></span></div>
                        @foreach($variationRows as $index => $row)
                            <div class="sl-variation-row">
                                <label class="sl-variation-check"><input type="checkbox" data-variant-select aria-label="Select variation"></label>
                                <input name="variants[{{ $index }}][id]" type="hidden" value="{{ data_get($row, 'id') }}">
                                <input name="variants[{{ $index }}][values]" value="{{ data_get($row, 'values') }}" placeholder="e.g. Small, Red" aria-label="Option values">
                                <input name="variants[{{ $index }}][product_image_ref]" type="hidden" value="{{ data_get($row, 'product_image_ref') }}" data-variant-image-ref>
                                <button type="button" class="sl-variant-image-button" data-variant-image-trigger data-image-ref="{{ data_get($row, 'product_image_ref') }}" aria-label="Choose image for {{ data_get($row, 'values', 'variation') }}"><span class="sl-variant-image-thumb" data-variant-image-thumb></span><span data-variant-image-label>Choose image</span></button>
                                <input name="variants[{{ $index }}][sku]" value="{{ data_get($row, 'sku') }}" placeholder="Auto-generated SKU" aria-label="Variant SKU">
                                <input name="variants[{{ $index }}][price]" type="number" min="0" step="0.01" data-saved-price="{{ $savedVariantPrices[data_get($row, 'id')] ?? '' }}" data-marketing-discount-type="{{ data_get($row, 'discount_type', 'none') }}" data-marketing-discount-value="{{ data_get($row, 'discount_value', 0) }}" data-marketing-discount-active="{{ data_get($row, 'discount_active', false) ? 'true' : 'false' }}" data-marketing-flash-price="{{ data_get($row, 'flash_sale_price', '') }}" data-marketing-flash-active="{{ data_get($row, 'flash_sale_active', false) ? 'true' : 'false' }}" data-marketing-flash-remaining="{{ data_get($row, 'flash_sale_remaining', 0) }}" value="{{ data_get($row, 'price') }}" placeholder="Price" aria-label="Variant price">
                                <input name="variants[{{ $index }}][stock]" type="number" min="0" value="{{ data_get($row, 'stock') }}" aria-label="Variant stock">
                                <input name="variants[{{ $index }}][weight_kg]" type="number" min="0.001" max="9999999.999" step="0.001" value="{{ data_get($row, 'weight_kg') }}" placeholder="kg" aria-label="Weight per item in kilograms">
                                <select name="variants[{{ $index }}][payment_method]" aria-label="Payment method"><option value="cod_online" @selected(data_get($row, 'payment_method', 'cod_online') === 'cod_online')>COD + Online</option><option value="cod" @selected(data_get($row, 'payment_method') === 'cod')>COD</option><option value="online" @selected(data_get($row, 'payment_method') === 'online')>Online</option></select>
                                <select name="variants[{{ $index }}][is_active]" aria-label="Availability"><option value="1" @selected(filter_var(data_get($row, 'is_active', true), FILTER_VALIDATE_BOOLEAN))>Available</option><option value="0" @selected(! filter_var(data_get($row, 'is_active', true), FILTER_VALIDATE_BOOLEAN))>Unavailable</option></select>
                                <button type="button" class="sl-icon-btn" data-remove-variant aria-label="Remove variation">&times;</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="sl-variation-images" data-variation-image-editor>
                        <div class="sl-variation-head"><div><strong>Variant image assignment</strong><small>Assign images already uploaded above. One image can be reused by many variants; no files are uploaded here.</small></div></div>
                        <div class="sl-variation-assignment-help">Choose an image in a row, or use the Image control below to apply the same existing image to all or selected variants.</div>
                    </div>
                    </div>
                </section>
            </div>
            <aside class="sl-editor-side">
                <section class="sl-card sl-publish-card sl-publish-card-current" data-product-status-card><h3>Product Status</h3><p class="sl-sidebar-help">{{ $editing && data_get($product, 'status') === 'SUSPENDED' ? 'Correct the listing while it remains suspended. Admin must authorize reinstatement.' : 'Complete the required information before publishing.' }}</p><div class="sl-completion-list"><p data-check="name"><span></span> Product information</p><p data-check="description"><span></span> Product description</p><p data-check="category"><span></span> Subcategory</p><p data-check="image"><span></span> Primary image</p><p data-check="pricing"><span></span> Pricing</p><p data-check="inventory"><span></span> Inventory</p><p data-check="variations"><span></span> Variations</p></div><button type="submit" data-listing-status="draft" onclick="this.form.elements.listing_status.value='draft'" class="sl-btn sl-btn-ghost sl-btn-block" data-confirm-title="{{ $editing ? 'Save product changes?' : 'Save product as draft?' }}" data-confirm-message="Save this product listing as a draft?" data-confirm-label="{{ $editing ? 'Save changes' : 'Save draft' }}">{{ $editing && data_get($product, 'status') === 'SUSPENDED' ? 'Save Corrections' : 'Save as Draft' }}</button>@if(!($editing && data_get($product, 'status') === 'SUSPENDED'))<button type="submit" data-listing-status="active" onclick="this.form.elements.listing_status.value='active'" class="sl-btn sl-btn-primary sl-btn-block" data-publish-product data-confirm-title="{{ $editing ? 'Save product changes?' : 'Publish product?' }}" data-confirm-message="{{ $editing ? 'Save and publish the changes to this listing?' : 'Publish this product to your catalog so buyers can find it?' }}" data-confirm-label="{{ $editing ? 'Save changes' : 'Publish product' }}">{{ $editing && $listingStatus === 'active' ? 'Save Changes' : 'Publish Product' }}</button>@endif<p class="sl-product-save-progress" data-product-save-progress role="status" aria-live="polite" hidden></p></section>
                <section class="sl-card sl-buyer-preview" data-seller-buyer-preview><header><h3>Buyer Preview</h3><p>This is how your product may appear to customers.</p><small>Preview only - your product is not published yet.</small></header><img src="{{ data_get($product, 'image', $productImageFallback) }}" alt="Product preview" data-preview-image data-preview-default-src="{{ data_get($product, 'image', $productImageFallback) }}"><div class="sl-preview-gallery" data-preview-gallery aria-label="Product image preview" @if($imageLibrary->isEmpty()) hidden @endif>@foreach($imageLibrary as $previewIndex => $previewLibraryImage)<button type="button" class="sl-preview-gallery-button {{ $previewIndex === 0 ? 'is-selected' : '' }}" data-preview-saved-image data-image-url="{{ data_get($previewLibraryImage, 'url') }}" data-image-label="Preview image {{ $previewIndex + 1 }}" aria-label="Preview image {{ $previewIndex + 1 }}" aria-pressed="{{ $previewIndex === 0 ? 'true' : 'false' }}"><img src="{{ data_get($previewLibraryImage, 'url') }}" alt="{{ data_get($previewLibraryImage, 'alt', data_get($product, 'name', 'Product image')) }}"></button>@endforeach</div><div><span data-preview-category>Subcategory</span><h4 data-preview-name>{{ old('name', data_get($product, 'name', 'Your product name')) ?: 'Your product name' }}</h4><strong data-preview-price>&#8369;0.00</strong><span class="sl-preview-discount" data-preview-discount hidden></span><p data-preview-stock>0 available</p><div class="sl-preview-options" data-preview-options></div><button type="button" disabled>Add to Cart</button><button type="button" disabled>Buy Now</button></div></section>
            </aside>
            <dialog class="sl-image-assignment-dialog" data-image-assignment-dialog aria-labelledby="image-assignment-title">
                <header><div><strong id="image-assignment-title">Choose an uploaded product image</strong><small data-image-assignment-context>Select an image for this variant.</small></div><button type="button" class="sl-icon-btn" data-image-picker-close aria-label="Close image picker">&times;</button></header>
                <div class="sl-image-picker-options" data-image-picker-options></div>
                <footer><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-image-picker-clear>Use product image fallback</button></footer>
            </dialog>
        </form>

    @elseif ($currentMode === 'inventory')
        <div class="sl-inventory-page">
            <header class="sl-inventory-heading">
                <div>
                    <span class="sl-eyebrow">Stock control</span>
                    <h2>Inventory Overview</h2>
                    <p>Review stock levels and update quantities per product variant.</p>
                </div>
                <a href="{{ route('seller.products.inventory.preview') }}" class="sl-btn sl-btn-primary" data-inventory-preview>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v6h5M10 14h6M10 17h6"/></svg>
                    Preview PDF
                </a>
            </header>

            <section class="sl-inventory-stats" aria-label="Inventory overview">
                <article class="sl-inventory-stat" aria-label="Total SKUs: {{ $inventoryStats['total'] }}">
                    <div class="sl-inventory-stat__top"><span class="sl-inventory-stat__icon is-neutral" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></svg></span></div>
                    <div class="sl-inventory-stat__content"><span class="sl-inventory-stat__label">Total SKUs</span><strong>{{ $inventoryStats['total'] }}</strong><small>Active variants</small></div>
                </article>
                <article class="sl-inventory-stat" aria-label="In Stock: {{ $inventoryStats['in_stock'] }}">
                    <div class="sl-inventory-stat__top"><span class="sl-inventory-stat__icon is-neutral" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M12 12v9M4.5 7.8 12 12l7.5-4.2"/></svg></span></div>
                    <div class="sl-inventory-stat__content"><span class="sl-inventory-stat__label">In Stock</span><strong>{{ $inventoryStats['in_stock'] }}</strong><small>SKUs with units available</small></div>
                </article>
                <article class="sl-inventory-stat is-low" aria-label="Low Stock (1–10): {{ $inventoryStats['low_stock'] }}">
                    <div class="sl-inventory-stat__top"><span class="sl-inventory-stat__icon is-low" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 22 20H2L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg></span></div>
                    <div class="sl-inventory-stat__content"><span class="sl-inventory-stat__label">Low Stock (1–10)</span><strong>{{ $inventoryStats['low_stock'] }}</strong><small>Needs review</small></div>
                </article>
                <article class="sl-inventory-stat is-out" aria-label="Out of Stock: {{ $inventoryStats['out_of_stock'] }}">
                    <div class="sl-inventory-stat__top"><span class="sl-inventory-stat__icon is-out" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/></svg></span></div>
                    <div class="sl-inventory-stat__content"><span class="sl-inventory-stat__label">Out of Stock</span><strong>{{ $inventoryStats['out_of_stock'] }}</strong><small>Needs restocking</small></div>
                </article>
            </section>

            <section class="sl-card sl-inventory-panel" aria-labelledby="inventory-products-title">
                <header class="sl-inventory-panel__head">
                    <div class="sl-inventory-panel__title">
                        <h3 id="inventory-products-title">Inventory by product</h3>
                        <p>Manage quantities and stock status for each variant.</p>
                    </div>
                    <form method="GET" action="{{ route('seller.products') }}" class="sl-inventory-toolbar">
                        <input type="hidden" name="mode" value="inventory">
                        <label class="sl-search-input"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><span class="sr-only">Search inventory</span><input type="search" name="search" value="{{ $inventorySearch }}" placeholder="Search product, variant, or SKU" data-product-search></label>
                        <label class="sl-inventory-filter"><span class="sr-only">Filter variants by stock level</span><select class="sl-select" name="status" data-product-status-filter><option value="all" @selected($inventoryStatus === 'all')>All stock levels</option><option value="low" @selected($inventoryStatus === 'low')>Low Stock (1–10)</option><option value="out" @selected($inventoryStatus === 'out')>Out of Stock</option><option value="healthy" @selected($inventoryStatus === 'healthy')>Healthy (11+)</option></select></label>
                        <button class="sl-btn sl-btn-soft sl-btn-sm" type="submit">Apply</button>
                    </form>
                </header>
                <div class="sl-inventory-groups" data-inventory-groups>
                @if($inventoryGroups->isEmpty())
                    <div class="sl-empty-state"><h3>No inventory records yet</h3><p>Active product variants will appear here after they are saved.</p></div>
                @else
                @foreach($inventoryGroups as $group)
                    @php
                        $isExpanded = (int) $expandedInventoryProductId === (int) $group['db_id'];
                    @endphp
                    <article class="sl-inventory-product" data-inventory-product data-product-id="{{ $group['db_id'] }}" data-product-name="{{ mb_strtolower($group['name']) }}">
                        <header class="sl-inventory-product__summary">
                            <img class="sl-inventory-product__image" src="{{ $group['image'] ?: $productImageFallback }}" alt="{{ $group['name'] }}" loading="lazy" onerror="this.onerror=null;this.src='{{ $productImageFallback }}'">
                            <div class="sl-inventory-product__identity"><h3>{{ $group['name'] }}</h3><span>{{ $group['variant_count'] }} active {{ \Illuminate\Support\Str::plural('variant', $group['variant_count']) }} · {{ $group['total_stock'] }} total units</span><span class="sl-status {{ strtoupper($group['listing_status']) === 'ACTIVE' ? 'is-success' : 'is-neutral' }}">{{ str($group['listing_status'])->replace('_', ' ')->title() }} listing</span></div>
                            <div class="sl-inventory-product__badges" aria-label="Stock summary">
                                <span class="sl-inventory-badge is-low"><strong>{{ $group['low_stock_count'] }}</strong> Low Stock</span>
                                <span class="sl-inventory-badge is-out"><strong>{{ $group['out_of_stock_count'] }}</strong> Out of Stock</span>
                                <span class="sl-inventory-badge is-healthy"><strong>{{ $group['variant_count'] - $group['low_stock_count'] - $group['out_of_stock_count'] }}</strong> Healthy</span>
                            </div>
                            <button class="sl-btn sl-btn-soft sl-btn-sm sl-inventory-toggle" type="button" data-inventory-toggle aria-expanded="{{ $isExpanded ? 'true' : 'false' }}" aria-controls="inventory-variants-{{ $group['db_id'] }}">
                                <span data-inventory-toggle-label>{{ $isExpanded ? 'Collapse' : 'Manage' }} variants</span><span aria-hidden="true" class="sl-inventory-toggle__icon">{{ $isExpanded ? '−' : '+' }}</span>
                            </button>
                        </header>
                        <div class="sl-inventory-variants" id="inventory-variants-{{ $group['db_id'] }}" data-inventory-variants @unless($isExpanded) hidden @endunless>
                            @foreach($group['variants'] as $item)
                                <article class="sl-inventory-variant" data-inventory-variant data-product-status="{{ $item['stock_level'] }}" data-variant-search="{{ mb_strtolower($item['variant'].' '.$item['sku']) }}">
                                    <div class="sl-inventory-variant__field is-variant"><span>Variant</span><strong>{{ $item['variant'] }}</strong></div>
                                    <div class="sl-inventory-variant__field is-sku"><span>SKU</span><strong>{{ $item['sku'] ?: 'Not assigned' }}</strong></div>
                                    <div class="sl-inventory-variant__field is-price"><span>Price</span><strong>₱{{ number_format($item['price'], 2) }}</strong></div>
                                    <div class="sl-inventory-variant__field is-available"><span>Available</span><strong>{{ $item['stock'] }}</strong></div>
                                    <div class="sl-inventory-variant__field is-status"><span>Status</span><span class="sl-status {{ $item['stock_level'] === 'out' ? 'is-danger' : ($item['stock_level'] === 'low' ? 'is-warning' : 'is-success') }}">{{ $item['stock_status'] }}</span></div>
                                    <form method="POST" action="{{ route('seller.products.variants.stock', ['product' => $item['db_id'], 'variant' => $item['variant_id']]) }}" class="sl-inline-stock sl-inventory-variant__update">@csrf @method('PATCH')<label class="sr-only" for="inventory-stock-{{ $item['variant_id'] }}">Update stock for {{ $item['variant'] }}</label><input id="inventory-stock-{{ $item['variant_id'] }}" name="stock" type="number" min="0" max="999999" required value="{{ $item['stock'] }}"><button class="sl-btn sl-btn-soft sl-btn-sm" type="submit">Update stock</button></form>
                                </article>
                            @endforeach
                        </div>
                    </article>
                @endforeach
                @endif
                </div>
                @if($inventoryPage && $inventoryPage->hasPages())
                    <footer class="sl-table-footer"><span>Showing {{ $inventoryPage->firstItem() }}–{{ $inventoryPage->lastItem() }} of {{ $inventoryPage->total() }} products</span>{{ $inventoryPage->links() }}</footer>
                @endif
                <div class="sl-no-results" data-product-empty hidden>No products or variants match your filters.</div>
            </section>
        </div>
    @else
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Catalog</span><h2>Product Management</h2><p>Create, edit, archive, restore, filter, and export listings.</p></div><a href="{{ route('seller.products', ['mode' => 'add']) }}" class="sl-btn sl-btn-primary"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add Product</a></div>
        <section class="sl-mini-stats"><div><span>All Products</span><strong>{{ $productStats['total'] }}</strong></div><div><span>Active</span><strong>{{ $productStats['active'] }}</strong></div><div><span>Low-stock products</span><strong>{{ $productStats['low_stock'] }}</strong></div><div><span>Archived</span><strong>{{ $productStats['archived'] }}</strong></div></section>
        @if(($complianceCases ?? collect())->isNotEmpty())
            <section class="sl-card sl-product-compliance-notices"><header class="sl-card-head"><div><span class="sl-eyebrow">Seller compliance</span><h2>Compliance Notices</h2><p>Warnings and Admin decisions connected to your products.</p></div><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.compliance') }}">View All</a></header><div class="sl-compliance-notice-list">@foreach($complianceCases->take(3) as $case)<article><div><strong>{{ $case->case_number }}</strong><span>{{ $case->product?->name ?: 'Account-level seller case' }} · {{ str($case->violation_type)->replace('_', ' ')->headline() }}</span><small>{{ $case->opened_at?->format('M d, Y') ?? 'Date unavailable' }}</small></div><div><span class="sl-status {{ in_array($case->status, ['RESOLVED', 'CLOSED'], true) ? 'is-success' : ($case->status === 'UNDER_REVIEW' ? 'is-info' : 'is-warning') }}">{{ str($case->status)->replace('_', ' ')->headline() }}</span><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.compliance.show', $case) }}">View Details</a></div></article>@endforeach</div></section>
        @endif
        <section class="sl-card">
            <form method="GET" action="{{ route('seller.products') }}" class="sl-table-toolbar"><input type="hidden" name="mode" value="list"><div class="sl-search-input"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="search" value="{{ $productSearch }}" placeholder="Search product or SKU" data-product-search></div><div class="sl-toolbar-group"><select class="sl-select" name="status" data-product-status-filter><option value="all" @selected($productStatus === 'all')>All status</option><option value="active" @selected($productStatus === 'active')>Active</option><option value="archived" @selected($productStatus === 'archived')>Archived</option><option value="draft" @selected($productStatus === 'draft')>Draft</option></select><button type="submit" class="sl-btn sl-btn-ghost">Apply filters</button><a href="{{ route('seller.products.trash') }}" class="sl-btn sl-btn-ghost">Recently Deleted</a><a href="{{ route('seller.products.export') }}" class="sl-btn sl-btn-ghost">Download PDF</a></div></form>
            <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Sold</th><th>Rating</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                @foreach($sellerProducts as $item)
                    <tr data-product-row data-product-name="{{ mb_strtolower($item['name'].' '.$item['sku']) }}" data-product-status="{{ mb_strtolower($item['status_key']) }}"><td><div class="sl-product-cell">@if($item['image'])<img src="{{ $item['image'] }}" alt="{{ $item['name'] }}">@endif<div><strong>{{ $item['name'] }}</strong><small>{{ $item['sku'] }}</small></div></div></td><td>{{ $item['category'] }}</td><td><strong>₱{{ number_format($item['price'],2) }}</strong></td><td>{{ $item['stock'] }}</td><td>{{ $item['sold'] }}</td><td>★ {{ number_format($item['rating'],1) }}</td><td><span class="sl-status {{ $item['status_class'] }}">{{ $item['status_label'] }}</span></td><td><div class="sl-table-actions"><a href="{{ route('seller.products',['mode'=>'edit','product'=>$item['id']]) }}" class="sl-icon-btn" title="Edit">✎</a><form method="POST" action="{{ route('seller.products.duplicate', $item['db_id']) }}">@csrf<button class="sl-icon-btn" type="submit" title="Duplicate as draft" aria-label="Duplicate {{ $item['name'] }} as draft">⧉</button></form>@if($item['status_key'] !== 'SUSPENDED')<form method="POST" action="{{ route('seller.products.toggle',$item['db_id']) }}" data-confirm-title="{{ $item['status_key']==='ARCHIVED'?'Restore product?':'Archive product?' }}" data-confirm-message="{{ $item['status_key']==='ARCHIVED'?'Restore this product to your catalog?':'Archive this product? It will no longer be available to buyers.' }}" data-confirm-label="{{ $item['status_key']==='ARCHIVED'?'Restore':'Archive' }}" data-confirm-details="{{ $item['name'] }} | SKU {{ $item['sku'] }}">@csrf @method('PATCH')<button class="sl-icon-btn" type="submit" title="{{ $item['status_key']==='ARCHIVED'?'Restore':'Archive' }}">{{ $item['status_key']==='ARCHIVED'?'↺':'□' }}</button></form>@endif<form method="POST" action="{{ route('seller.products.destroy',$item['db_id']) }}" data-confirm-title="Move product to Recently Deleted?" data-confirm-message="This product can be restored for 30 days." data-confirm-label="Move to Recently Deleted" data-confirm-tone="danger" data-confirm-details="{{ $item['name'] }} | SKU {{ $item['sku'] }} | Restorable for 30 days">@csrf @method('DELETE')<button class="sl-icon-btn" type="submit" title="Move to Recently Deleted">×</button></form></div></td></tr>
                @endforeach
            </tbody></table></div><div class="sl-no-results" data-product-empty hidden>No products match your filters.</div><footer class="sl-table-footer"><span>Showing {{ $productsPage?->firstItem() ?? 0 }}–{{ $productsPage?->lastItem() ?? 0 }} of {{ $productsPage?->total() ?? 0 }} products</span>@if($productsPage?->hasPages()){{ $productsPage->links() }}@endif</footer>
        </section>
    @endif
</div>
@endsection
