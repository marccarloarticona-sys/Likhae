@extends('layouts.admin')

@section('title', 'Product Details')
@section('subtitle', 'Catalog inspection with authorized moderation actions.')
@section('active', 'products')
@php
    $productCategory = $product->category;
    $registeredCategory = $product->sellerProfile?->primaryCategory;
    $sellerUser = $product->sellerProfile?->user;
    $categoryLabel = static function ($category): string {
        if (! $category) {
            return 'Not provided';
        }

        return $category->parent?->name
            ? $category->parent->name.' / '.$category->name
            : $category->name;
    };
    $variants = $product->variants;
    $priceValues = $variants->pluck('price')->filter(fn ($price): bool => $price !== null)->map(fn ($price): float => (float) $price)->values();
    $discountedValues = $variants
        ->filter(fn ($variant): bool => (float) $variant->final_price < (float) $variant->price)
        ->map(fn ($variant): float => (float) $variant->final_price)
        ->filter(fn (float $price): bool => $price > 0)
        ->values();
    $formatRange = static function ($values): string {
        if ($values->isEmpty()) {
            return 'Not provided';
        }

        $min = (float) $values->min();
        $max = (float) $values->max();

        return $min === $max
            ? 'PHP '.number_format($min, 2)
            : 'PHP '.number_format($min, 2).' – PHP '.number_format($max, 2);
    };
    $gallery = $imageGallery;
    $primaryImage = $gallery->firstWhere('is_primary', true) ?: $gallery->first();
    $primaryImageUrl = $primaryImage['url'] ?? asset('images/product-placeholder.svg');
    $hasImages = $gallery->isNotEmpty();
    $skus = $variants->pluck('sku')->filter()->unique()->implode(', ');
    $complianceCases = $product->complianceCases->sortByDesc(fn ($case) => $case->created_at?->timestamp ?? 0)->values();
    $backQuery = collect($backQuery)->filter(fn ($value): bool => filled($value))->all();
@endphp

@section('content')
    <div class="ad-product-details-page">
        <div class="ad-page-head">
            <div>
                <span class="ad-overline">Product #{{ $product->id }}</span>
                <h2>{{ $product->name }}</h2>
                <p>Catalog inspection with authorized moderation actions.</p>
            </div>
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.products', $backQuery) }}">Back to Products</a>
        </div>

        @if(session('status'))
            <div class="ad-note" role="status">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="ad-card ad-card-body ad-product-moderation-grid" aria-label="Product moderation">
            <div>
                <span class="ad-overline">Admin action</span>
                <h2>Moderate product</h2>
                <p class="ad-product-moderation-note">Suspend or reinstate this listing after reviewing the product and its compliance history.</p>
                @if(! in_array($product->status, ['ACTIVE', 'SUSPENDED'], true))
                    <p class="ad-product-moderation-note">This {{ str($product->status)->lower() }} listing remains Seller-managed.</p>
                @endif
            </div>
            @if(in_array($product->status, ['ACTIVE', 'SUSPENDED'], true))
                <form class="ad-product-moderation-form" method="POST" action="{{ route('admin.products.moderate', ['product' => $product->id]) }}" data-confirm-title="Apply moderation change?" data-confirm-message="This will update the product's marketplace availability." data-confirm-label="Apply change">
                    @csrf
                    <label class="ad-field">
                        <span>Moderation status</span>
                        <select name="status" aria-label="Moderation status for {{ $product->name }}">
                            @foreach(['ACTIVE', 'SUSPENDED'] as $moderationStatus)
                                <option value="{{ $moderationStatus }}" @selected($product->status === $moderationStatus)>{{ str($moderationStatus)->replace('_', ' ')->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field">
                        <span>Reason</span>
                        <input name="reason" value="{{ old('reason') }}" maxlength="1000" placeholder="Required for suspension or reinstatement" aria-label="Moderation reason for {{ $product->name }}">
                    </label>
                    <div class="ad-product-moderation-actions">
                        <button class="ad-btn ad-btn-primary" type="submit">Save moderation</button>
                    </div>
                </form>
            @endif
        </section>

        @if($product->status === 'ACTIVE')
            <section class="ad-card ad-card-body">
                <div class="ad-card-head" style="padding:0 0 12px;border-bottom:1px solid var(--ad-border);">
                    <div><span class="ad-overline">Seller compliance</span><h2>Warn Seller</h2><p>Record the correction required while keeping the product active.</p></div>
                </div>
                <form class="ad-product-warning-form" method="POST" action="{{ route('admin.products.warn', ['product' => $product->id]) }}" data-confirm-title="Send warning to seller?" data-confirm-message="Send this warning to the seller about the product listing?" data-confirm-label="Send warning" style="padding-top:14px;">
                    @csrf
                    <label class="ad-field">
                        <span>Warning category</span>
                        <select name="category" required>
                            <option value="">Select a category</option>
                            @foreach($warningCategories as $warningKey => $warningLabel)
                                <option value="{{ $warningKey }}" @selected(old('category') === $warningKey)>{{ $warningLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ad-field">
                        <span>Detailed warning reason</span>
                        <textarea name="reason" required minlength="10" maxlength="5000" placeholder="Explain the confirmed requirement or policy concern.">{{ old('reason') }}</textarea>
                    </label>
                    <label class="ad-field">
                        <span>Required corrective action</span>
                        <textarea name="corrective_action" required minlength="5" maxlength="5000" placeholder="Tell the Seller exactly what must be corrected.">{{ old('corrective_action') }}</textarea>
                    </label>
                    <label class="ad-field">
                        <span>Supporting evidence or reference (optional)</span>
                        <textarea name="evidence" maxlength="5000" placeholder="Add a case, policy, or evidence reference when available.">{{ old('evidence') }}</textarea>
                    </label>
                    <div class="ad-product-moderation-actions">
                        <button class="ad-btn ad-btn-warning-soft" type="submit">Confirm Warning</button>
                    </div>
                </form>
            </section>
        @elseif($product->status === 'SUSPENDED')
            <section class="ad-card ad-card-body">
                <p class="ad-product-moderation-note">This product is suspended and unavailable to buyers. Resolve the compliance case before reinstating it.</p>
            </section>
        @endif

        <div class="ad-product-detail-grid">
            <section class="ad-card ad-card-body ad-product-gallery" aria-label="Product images">
                <div class="ad-product-gallery-main">
                    <a href="{{ $primaryImageUrl }}" target="_blank" rel="noopener" aria-label="Open primary product image in a new tab">
                        <img src="{{ $primaryImageUrl }}" alt="{{ $primaryImage['alt'] ?? $product->name }}" loading="eager">
                    </a>
                    <span class="ad-product-gallery-hint">Open image</span>
                    @unless($hasImages)
                        <span class="ad-product-gallery-placeholder">No product image provided. Showing the LIKHAE placeholder.</span>
                    @endunless
                </div>
                @if($gallery->isNotEmpty())
                    <div class="ad-product-gallery-thumbs" aria-label="Additional product images">
                        @foreach($gallery as $image)
                            <a class="ad-product-gallery-thumb {{ $image['is_primary'] ? 'is-primary' : '' }}" href="{{ $image['url'] }}" target="_blank" rel="noopener" aria-label="Open {{ $image['alt'] }} in a new tab">
                                <img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}" loading="lazy">
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <span class="ad-overline">Product information</span>
                        <h2>{{ $product->name }}</h2>
                    </div>
                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($product->status) }}">{{ str($product->status)->replace('_', ' ')->headline() }}</span>
                </header>
                <div class="ad-card-body">
                    <dl class="ad-product-detail-meta">
                        <div><dt>Product ID</dt><dd>#{{ $product->id }}</dd></div>
                        <div><dt>SKU</dt><dd>{{ $skus ?: 'Not provided' }}</dd></div>
                        <div><dt>Product category</dt><dd>{{ $categoryLabel($productCategory) }}</dd></div>
                        <div><dt>Price</dt><dd>{{ $formatRange($priceValues) }}</dd></div>
                        <div><dt>Discounted price</dt><dd>{{ $discountedValues->isNotEmpty() ? $formatRange($discountedValues) : 'Not provided' }}</dd></div>
                        <div><dt>Available stock</dt><dd>{{ $variants->isNotEmpty() ? number_format((int) $variants->sum('stock')) : 'Not provided' }}</dd></div>
                        <div><dt>Date created</dt><dd>{{ $product->created_at?->format('M d, Y H:i') ?? 'Not provided' }}</dd></div>
                        <div><dt>Last updated</dt><dd>{{ $product->updated_at?->format('M d, Y H:i') ?? 'Not provided' }}</dd></div>
                    </dl>
                </div>
            </section>
        </div>

        <section class="ad-card">
            <header class="ad-card-head"><div><h2>Description</h2><p>Seller-provided product description.</p></div></header>
            <div class="ad-card-body ad-product-description">{{ $product->description ?: 'Not provided' }}</div>
        </section>

        <section class="ad-card">
            <header class="ad-card-head"><div><h2>Available variations</h2><p>Read-only variant, pricing, and stock information.</p></div></header>
            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead><tr><th>Variation</th><th>SKU</th><th>Price</th><th>Discounted price</th><th>Stock</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($variants as $variant)
                            @php
                                $variation = $variant->optionValues->map(fn ($value): string => ($value->option?->name ? $value->option->name.': ' : '').$value->value)->filter()->implode(' · ');
                                $hasDiscount = (float) $variant->final_price < (float) $variant->price;
                            @endphp
                            <tr>
                                <td><strong>{{ $variation ?: 'Default variation' }}</strong></td>
                                <td>{{ $variant->sku ?: 'Not provided' }}</td>
                                <td>PHP {{ number_format((float) $variant->price, 2) }}</td>
                                <td>{{ $hasDiscount ? 'PHP '.number_format((float) $variant->final_price, 2) : 'Not provided' }}</td>
                                <td>{{ number_format((int) $variant->stock) }}</td>
                                <td><span class="ad-status {{ $variant->is_active ? 'is-active' : 'is-suspended' }}">{{ $variant->is_active ? 'Active' : 'Inactive' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ad-product-table-muted">No variations recorded.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ad-card">
            <header class="ad-card-head"><div><h2>Seller information</h2><p>Seller account and category compliance context.</p></div></header>
            <div class="ad-card-body">
                <dl class="ad-product-seller-grid">
                    <div><dt class="ad-product-detail-label">Seller / shop name</dt><dd>{{ $product->sellerProfile?->business_name ?: 'Not provided' }}</dd></div>
                    <div><dt class="ad-product-detail-label">Seller account reference</dt><dd>@if($sellerUser)<a class="ad-link" href="{{ route('admin.users.show', ['user' => $sellerUser->id]) }}">USR-{{ $sellerUser->id }}</a>@else Not provided @endif</dd></div>
                    <div><dt class="ad-product-detail-label">Registered business category</dt><dd>{{ $categoryLabel($registeredCategory) }}</dd></div>
                    <div><dt class="ad-product-detail-label">Product category</dt><dd>{{ $categoryLabel($productCategory) }}</dd></div>
                    <div><dt class="ad-product-detail-label">Category compliance</dt><dd><span class="ad-product-compliance-result {{ ! $registeredCategory || ! $productCategory ? 'is-muted' : ($product->admin_category_mismatch ? 'is-warning' : '') }}">{{ ! $registeredCategory || ! $productCategory ? 'Category data unavailable' : ($product->admin_category_mismatch ? 'Not compliant' : 'Compliant') }}</span></dd></div>
                </dl>
                @if($registeredCategory && $productCategory && ! $product->admin_category_mismatch)
                    <p class="ad-product-table-muted" style="margin:18px 0 0;font-size:13px;">The product uses the Seller's registered category or an active permitted subcategory.</p>
                @elseif($product->admin_category_mismatch)
                    <p class="ad-product-table-muted" style="margin:18px 0 0;font-size:13px;">The product category is outside the Seller's registered category and active permitted subcategories.</p>
                @endif
            </div>
        </section>

        <section class="ad-card" id="compliance">
            <header class="ad-card-head">
                <div><h2>Seller compliance</h2><p>Existing product moderation warnings, cases, and actions.</p></div>
                @if($complianceCases->isNotEmpty())<a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.compliance') }}">Open Compliance</a>@endif
            </header>
            <div class="ad-card-body">
                <p><strong>Current moderation status:</strong> <span class="ad-status is-{{ \Illuminate\Support\Str::slug($product->status) }}">{{ str($product->status)->replace('_', ' ')->headline() }}</span></p>
                @if($complianceCases->isEmpty())
                    <p class="ad-product-table-muted">No recorded compliance violations.</p>
                @else
                    <div class="ad-compliance-list">
                        @foreach($complianceCases as $case)
                            <article class="ad-compliance-case" id="compliance-case-{{ $case->id }}">
                                <div class="ad-compliance-case-head">
                                    <div><h3>{{ $case->case_number }}</h3><p>{{ str($case->violation_type)->replace('_', ' ')->headline() }} - Opened {{ $case->opened_at?->format('M d, Y H:i') ?? 'Not provided' }} by {{ $case->openedByAdmin?->name ?: 'System' }}</p></div>
                                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($case->status) }}">{{ str($case->status)->replace('_', ' ')->headline() }}</span>
                                </div>
                                <p>{{ $case->description ?: 'No violation reason recorded.' }}</p>
                                @if($case->actions->isNotEmpty())
                                    <div class="ad-compliance-actions">
                                        <strong>Moderation history</strong>
                                        @foreach($case->actions as $action)
                                            <span>{{ $action->performed_at?->format('M d, Y H:i') ?? 'Not provided' }} · {{ str($action->action_type)->replace('_', ' ')->headline() }} · {{ $action->performedBy?->name ?: 'System' }}{{ $action->reason ? ' — '.$action->reason : '' }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>
@endsection
