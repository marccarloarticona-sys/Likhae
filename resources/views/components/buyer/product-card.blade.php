@props([
    'product',
    'compact' => false,
    'guest' => false,
    'deal' => false,
    'catalogListing' => false,
    'unavailable' => false,
])

@php
    $id = data_get($product, 'id');
    $availabilityRestricted = (bool) data_get($product, 'availability_restricted', false);
    $unavailable = (bool) $unavailable || (bool) data_get($product, 'unavailable', false);

    $slug = data_get($product, 'slug')
        ?? $id
        ?? 'product';

    $name = $availabilityRestricted ? 'Unavailable product' : (string) data_get($product, 'name', 'Product Name');

    $categoryValue = data_get($product, 'category.name') ?? 'Local Find';

    $category = $availabilityRestricted
        ? 'Saved item'
        : (is_scalar($categoryValue) ? (string) $categoryValue : 'Local Find');

    $sellerValue = data_get($product, 'seller.business_name') ?? 'LIKHAE Seller';

    $seller = $availabilityRestricted
        ? 'Unavailable'
        : (is_scalar($sellerValue) ? (string) $sellerValue : 'LIKHAE Seller');

    $sellerSlug = data_get(
        $product,
        'seller_slug',
        \Illuminate\Support\Str::slug($seller)
    );

    $dealVariant = $deal ? data_get($product, 'deal_variant') : null;
    $price = max(0, (float) ($deal
        ? data_get($dealVariant, 'price', 0)
        : data_get($product, 'min_price', 0)));

    $oldPrice = max(
        0,
        (float) ($deal
            ? data_get($dealVariant, 'original_price', 0)
            : data_get($product, 'old_price', 0))
    );

    $rating = min(
        5,
        max(0, (float) data_get($product, 'rating', 4.8))
    );

    $reviews = max(
        0,
        (int) data_get($product, 'reviews', 0)
    );

    $sold = max(
        0,
        (int) data_get($product, 'sold', 0)
    );

    $image = $availabilityRestricted
        ? 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800&q=80'
        : (data_get($product, 'primary_image.url') ?? 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800&q=80');

    $detailsUrl = $availabilityRestricted
        ? null
        : ($guest ? route('products.show', ['slug' => $slug]) : route('buyer.product-details', ['slug' => $slug]));
    $variants = collect(data_get($product, 'variants', []));
    $hasVariations = $variants->count() > 1;
    $singleVariant = $hasVariations ? null : $variants->first();
    $sellerOnVacation = $catalogListing && (bool) data_get($product, 'seller.vacation_mode', false);
    $catalogStock = max(0, (int) data_get($product, 'stock', $variants->sum('stock')));
    $outOfStock = $catalogListing && $catalogStock < 1;
    $canAddToCart = ! $unavailable && ! $availabilityRestricted && ! $sellerOnVacation && data_get($singleVariant, 'id') && (int) data_get($singleVariant, 'stock', 0) > 0;
    $isWishlistSaved = false;
    if (! $guest && auth()->check() && $id !== null) {
        $wishlistUserId = request()->attributes->get('buyer_wishlist_user_id');
        $wishlistProductIds = request()->attributes->get('buyer_wishlist_product_ids');
        if ((int) $wishlistUserId !== (int) auth()->id() || ! is_array($wishlistProductIds)) {
            $wishlistProductIds = auth()->user()->wishlistItems()->pluck('product_id')->map(fn ($productId) => (string) $productId)->all();
            request()->attributes->set('buyer_wishlist_user_id', auth()->id());
            request()->attributes->set('buyer_wishlist_product_ids', $wishlistProductIds);
        }
        $isWishlistSaved = in_array((string) $id, $wishlistProductIds, true);
    }
    $discountVariant = $deal
        ? $dealVariant
        : $variants
            ->filter(fn ($variant) => (float) data_get($variant, 'discount_amount', 0) > 0)
            ->sortByDesc(fn ($variant) => (float) data_get($variant, 'discount_amount', 0))
            ->first();
    $discountType = strtolower((string) data_get($discountVariant, 'discount_type', data_get($product, 'discount_type', 'none')));
    $discountValue = (float) data_get($discountVariant, 'discount_value', data_get($product, 'discount_value', 0));
    $discount = $discountVariant
        ? $discountValue
        : ($oldPrice > $price && $oldPrice > 0
            ? (int) round((($oldPrice - $price) / $oldPrice) * 100)
            : (int) data_get($product, 'discount', 0));
    $discountLabel = $discountType === 'fixed'
        ? '₱'.number_format($discountValue, 2).' OFF'
        : '-'.min(100, $discount).'%';

    $storeUrl = $guest
        ? route('products.show', ['slug' => $slug])
        : route('buyer.shop', ['seller' => $sellerSlug]);
@endphp

@once
<style>
    :root {
        --lk-bg: #FBF7F2;
        --lk-bg-soft: #F6EFE7;
        --lk-bg-alt: #EFE7DE;
        --lk-card: #FFFDF9;

        --lk-border: #EADCCC;
        --lk-border-strong: #DBCEC1;

        --lk-maroon: #561C17;
        --lk-maroon-2: #642920;
        --lk-maroon-light: #7A2A22;
        --lk-maroon-dark: #3E130F;

        --lk-text: #3B211B;
        --lk-brown: #6C4936;
        --lk-muted: #987865;
        --lk-muted-2: #A99386;

        --lk-tan: #C19771;
        --lk-gold: #C88418;

        --lk-shadow-soft: 0 8px 24px rgba(86, 28, 23, 0.045);
        --lk-shadow-card: 0 16px 36px rgba(86, 28, 23, 0.10);
    }

    .lk-product-card.lk-product-card--palette {
        position: relative !important;

        display: flex !important;
        min-width: 0 !important;
        height: 100% !important;
        overflow: hidden !important;
        flex-direction: column !important;

        border: 1px solid var(--lk-border) !important;
        border-radius: 20px !important;

        background:
            radial-gradient(circle at 92% 8%, rgba(193, 151, 113, 0.13), transparent 28%),
            linear-gradient(180deg, #FFFDF9 0%, #FFF9F2 100%) !important;

        color: var(--lk-text) !important;

        box-shadow: var(--lk-shadow-soft) !important;

        transition:
            transform 180ms ease,
            border-color 180ms ease,
            box-shadow 180ms ease !important;
    }

    .lk-product-card.lk-product-card--palette:hover {
        transform: translateY(-4px) !important;
        border-color: var(--lk-tan) !important;
        box-shadow: var(--lk-shadow-card) !important;
    }

    .lk-product-card--palette .lk-product-media {
        position: relative !important;

        aspect-ratio: 1 / 0.92 !important;
        overflow: hidden !important;

        background:
            radial-gradient(circle at 50% 20%, rgba(255, 255, 255, 0.85), transparent 34%),
            linear-gradient(135deg, #F3ECE4 0%, #EADCCC 100%) !important;
    }

    .lk-product-card--palette .lk-product-image-link {
        display: block !important;
        width: 100% !important;
        height: 100% !important;

        text-decoration: none !important;
    }

    .lk-product-card--palette .lk-product-image-link img {
        display: block !important;
        width: 100% !important;
        height: 100% !important;

        object-fit: cover !important;

        transition:
            transform 360ms ease,
            filter 180ms ease !important;
    }

    .lk-product-card--palette:hover .lk-product-image-link img {
        transform: scale(1.045) !important;
        filter: saturate(1.03) contrast(1.02) !important;
    }

    .lk-product-card--palette .lk-product-media::after {
        position: absolute !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;

        height: 42% !important;

        background: linear-gradient(
            to top,
            rgba(59, 33, 27, 0.26),
            transparent
        ) !important;

        pointer-events: none !important;
        content: "" !important;
    }

    .lk-product-card--palette .lk-product-discount {
        position: absolute !important;
        top: 12px !important;
        left: 12px !important;
        z-index: 4 !important;

        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;

        min-height: 25px !important;
        padding: 0 9px !important;

        border-radius: 999px !important;

        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;

        box-shadow: 0 8px 18px rgba(59, 33, 27, 0.14) !important;

        font-size: 9px !important;
        font-weight: 900 !important;
        letter-spacing: 0.06em !important;
    }

    .lk-product-card--palette .lk-product-palette-tag {
        position: absolute !important;
        left: 12px !important;
        bottom: 12px !important;
        z-index: 4 !important;

        display: inline-flex !important;
        align-items: center !important;

        min-height: 25px !important;
        padding: 0 10px !important;

        border: 1px solid rgba(234, 220, 204, 0.86) !important;
        border-radius: 999px !important;

        background: rgba(255, 253, 249, 0.92) !important;
        color: var(--lk-maroon) !important;

        backdrop-filter: blur(10px) !important;

        font-size: 9px !important;
        font-weight: 900 !important;
        letter-spacing: 0.09em !important;
        text-transform: uppercase !important;
    }

    .lk-product-card--palette .lk-product-heart {
        position: absolute !important;
        top: 12px !important;
        right: 12px !important;
        z-index: 5 !important;

        display: inline-flex !important;
        width: 36px !important;
        height: 36px !important;
        align-items: center !important;
        justify-content: center !important;

        padding: 0 !important;

        border: 1px solid rgba(234, 220, 204, 0.95) !important;
        border-radius: 999px !important;

        background: rgba(255, 253, 249, 0.92) !important;
        color: var(--lk-brown) !important;

        box-shadow: 0 8px 18px rgba(59, 33, 27, 0.11) !important;
        backdrop-filter: blur(10px) !important;

        cursor: pointer !important;

        transition:
            transform 160ms ease,
            background 160ms ease,
            color 160ms ease !important;
    }

    .lk-product-card--palette .lk-product-heart:hover,
    .lk-product-card--palette .lk-product-heart.is-active {
        transform: scale(1.06) !important;
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;
    }

    .lk-product-card--palette .lk-product-heart svg {
        width: 17px !important;
        height: 17px !important;

        fill: none !important;
        stroke: currentColor !important;
        stroke-width: 1.85 !important;
        stroke-linecap: round !important;
        stroke-linejoin: round !important;
    }

    .lk-product-card--palette .lk-product-heart.is-active svg {
        fill: currentColor !important;
    }

    .lk-product-card--palette .lk-product-body {
        display: flex !important;
        min-width: 0 !important;
        flex: 1 !important;
        flex-direction: column !important;

        padding: 15px !important;

        background: transparent !important;
    }

    .lk-product-card--palette .lk-product-category {
        display: inline-flex !important;
        width: fit-content !important;
        max-width: 100% !important;

        overflow: hidden !important;

        color: var(--lk-brown) !important;

        font-size: 9px !important;
        font-weight: 900 !important;
        letter-spacing: 0.15em !important;
        text-transform: uppercase !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    .lk-product-card--palette .lk-product-title-link {
        display: block !important;

        margin-top: 7px !important;

        color: inherit !important;
        text-decoration: none !important;
    }

    .lk-product-card--palette .lk-product-name {
        display: -webkit-box !important;
        min-height: 40px !important;
        overflow: hidden !important;

        margin: 0 !important;

        color: var(--lk-text) !important;

        font-size: 14px !important;
        font-weight: 900 !important;
        line-height: 1.35 !important;
        letter-spacing: -0.025em !important;

        -webkit-box-orient: vertical !important;
        -webkit-line-clamp: 2 !important;

        transition: color 160ms ease !important;
    }

    .lk-product-card--palette .lk-product-title-link:hover .lk-product-name {
        color: var(--lk-maroon) !important;
    }

    .lk-product-card--palette .lk-product-seller {
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;

        width: fit-content !important;
        max-width: 100% !important;

        margin-top: 8px !important;

        overflow: hidden !important;

        color: var(--lk-muted) !important;

        font-size: 10px !important;
        font-weight: 600 !important;
        text-decoration: none !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;

        transition: color 160ms ease !important;
    }

    .lk-product-card--palette .lk-product-seller::before {
        width: 6px !important;
        height: 6px !important;
        flex: 0 0 6px !important;

        border-radius: 999px !important;

        background: var(--lk-tan) !important;

        content: "" !important;
    }

    .lk-product-card--palette .lk-product-seller:hover {
        color: var(--lk-maroon) !important;
    }

    .lk-product-card--palette .lk-product-meta {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;

        margin-top: 12px !important;

        color: var(--lk-muted) !important;

        font-size: 10px !important;
    }

    .lk-product-card--palette .lk-stars {
        display: inline-flex !important;
        min-width: 0 !important;
        align-items: center !important;
        gap: 4px !important;

        color: var(--lk-gold) !important;

        font-size: 10px !important;
        font-weight: 900 !important;
    }

    .lk-product-card--palette .lk-stars svg {
        width: 13px !important;
        height: 13px !important;
        flex: 0 0 13px !important;

        fill: currentColor !important;
        stroke: currentColor !important;
        stroke-width: 1.6 !important;
    }

    .lk-product-card--palette .lk-stars strong {
        color: var(--lk-text) !important;

        font-size: 10px !important;
        font-weight: 900 !important;
    }

    .lk-product-card--palette .lk-stars small {
        color: var(--lk-muted-2) !important;

        font-size: 9px !important;
        font-weight: 700 !important;
    }

    .lk-product-card--palette .lk-product-sold {
        flex: 0 0 auto !important;

        color: var(--lk-muted) !important;

        font-size: 9px !important;
        font-weight: 700 !important;
        white-space: nowrap !important;
    }

    .lk-product-card--palette .lk-product-price-row {
        display: flex !important;
        min-height: 33px !important;
        align-items: baseline !important;
        flex-wrap: wrap !important;
        gap: 7px !important;

        margin-top: 10px !important;
    }

    .lk-product-card--palette .lk-product-price-row strong {
        color: var(--lk-maroon) !important;

        font-size: 19px !important;
        font-weight: 950 !important;
        line-height: 1 !important;
        letter-spacing: -0.03em !important;
    }

    .lk-product-card--palette .lk-product-price-row del {
        color: var(--lk-muted-2) !important;

        font-size: 10px !important;
        font-weight: 700 !important;
    }

    .lk-product-card--palette .lk-card-actions {
        display: grid !important;
        grid-template-columns: 0.82fr 1.18fr !important;
        gap: 9px !important;

        margin-top: auto !important;
        padding-top: 13px !important;
    }

    .lk-product-card--palette .lk-card-actions > form .lk-add-cart {
        width: 100%;
        height: 100%;
    }

    .lk-product-card--palette .lk-add-cart:disabled {
        opacity: 0.5;
        cursor: not-allowed !important;
    }

    .lk-product-card--palette .lk-btn-view,
    .lk-product-card--palette .lk-add-cart {
        display: inline-flex !important;
        min-height: 38px !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 7px !important;

        padding: 0 11px !important;

        border-radius: 12px !important;

        font-size: 10px !important;
        font-weight: 900 !important;
        line-height: 1 !important;
        text-decoration: none !important;

        cursor: pointer !important;

        transition:
            transform 160ms ease,
            background 160ms ease,
            border-color 160ms ease,
            color 160ms ease !important;
    }

    .lk-product-card--palette .lk-btn-view {
        border: 1px solid var(--lk-tan) !important;
        background: rgba(255, 253, 249, 0.72) !important;
        color: var(--lk-maroon) !important;
    }

    .lk-product-card--palette .lk-btn-view:hover {
        transform: translateY(-1px) !important;
        border-color: var(--lk-maroon) !important;
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;
    }

    .lk-product-card--palette .lk-add-cart {
        border: 1px solid var(--lk-maroon) !important;
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;

        box-shadow: 0 8px 18px rgba(86, 28, 23, 0.14) !important;
    }

    .lk-product-card--palette .lk-add-cart:hover {
        transform: translateY(-1px) !important;
        border-color: var(--lk-maroon-dark) !important;
        background: var(--lk-maroon-dark) !important;
        color: #FFFFFF !important;
    }

    .lk-product-card--palette .lk-add-cart svg {
        width: 14px !important;
        height: 14px !important;
        flex: 0 0 14px !important;

        fill: none !important;
        stroke: currentColor !important;
        stroke-width: 1.9 !important;
        stroke-linecap: round !important;
        stroke-linejoin: round !important;
    }

    .lk-product-card--palette.is-guest .lk-add-cart {
        border-color: var(--lk-tan) !important;
        background: rgba(255, 253, 249, 0.78) !important;
        color: var(--lk-maroon) !important;
        box-shadow: none !important;
    }

    .lk-product-card--palette.is-guest .lk-add-cart:hover {
        border-color: var(--lk-maroon) !important;
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;
    }

    .lk-product-card--palette.is-compact {
        display: grid !important;
        grid-template-columns: 180px minmax(0, 1fr) !important;
    }

    .lk-product-card--palette.is-compact .lk-product-media {
        height: 100% !important;
        min-height: 190px !important;
        aspect-ratio: auto !important;
    }

    .lk-product-card--palette.is-compact .lk-product-body {
        padding: 18px !important;
    }

    .lk-product-card--palette.is-compact .lk-product-name {
        min-height: auto !important;
        font-size: 16px !important;
    }

    .lk-product-card--palette.is-compact .lk-card-actions {
        max-width: 320px !important;
    }

    @media (max-width: 640px) {
        .lk-product-card--palette .lk-card-actions {
            grid-template-columns: 1fr !important;
        }

        .lk-product-card--palette.is-compact {
            grid-template-columns: 1fr !important;
        }

        .lk-product-card--palette.is-compact .lk-product-media {
            min-height: 220px !important;
        }
    }

    html.dark .lk-product-card.lk-product-card--palette {
        background:
            radial-gradient(circle at 92% 8%, rgba(193, 151, 113, 0.08), transparent 30%),
            linear-gradient(180deg, #241A17 0%, #1E1714 100%) !important;

        border-color: #49342B !important;
        color: #FFF8F2 !important;
    }

    html.dark .lk-product-card--palette .lk-product-media {
        background: linear-gradient(135deg, #2A1E1A, #382820) !important;
    }

    html.dark .lk-product-card--palette .lk-product-category,
    html.dark .lk-product-card--palette .lk-product-seller,
    html.dark .lk-product-card--palette .lk-product-sold,
    html.dark .lk-product-card--palette .lk-stars small {
        color: #C8B7AD !important;
    }

    html.dark .lk-product-card--palette .lk-product-name,
    html.dark .lk-product-card--palette .lk-stars strong {
        color: #FFF8F2 !important;
    }

    html.dark .lk-product-card--palette .lk-product-title-link:hover .lk-product-name {
        color: #EBA99D !important;
    }

    html.dark .lk-product-card--palette .lk-product-price-row strong {
        color: #EBA99D !important;
    }

    html.dark .lk-product-card--palette .lk-btn-view,
    html.dark .lk-product-card--palette.is-guest .lk-add-cart {
        background: #2A1E1A !important;
        border-color: #60463A !important;
        color: #EBA99D !important;
    }

    html.dark .lk-product-card--palette .lk-btn-view:hover,
    html.dark .lk-product-card--palette.is-guest .lk-add-cart:hover {
        background: #8A3A2F !important;
        border-color: #8A3A2F !important;
        color: #FFFFFF !important;
    }
</style>
@endonce

<article
    class="lk-product-card lk-product-card--palette {{ $compact ? 'is-compact' : '' }} {{ $guest ? 'is-guest' : '' }}"
    data-product-card
    data-product-id="{{ $id ?? $slug }}"
    data-category="{{ \Illuminate\Support\Str::slug($category) }}"
    data-price="{{ $price }}"
    data-rating="{{ $rating }}"
    data-sold="{{ $sold }}"
    data-search="{{ mb_strtolower($name . ' ' . $category . ' ' . $seller) }}"
>
    <div class="lk-product-media">
        @if($detailsUrl)
            <a href="{{ $detailsUrl }}" class="lk-product-image-link" aria-label="View {{ $name }}">
                <img src="{{ $image }}" alt="{{ $name }}" loading="lazy" decoding="async">
            </a>
        @else
            <div class="lk-product-image-link" aria-hidden="true">
                <img src="{{ $image }}" alt="" loading="lazy" decoding="async">
            </div>
        @endif

        @if($discount > 0)
            <span class="lk-product-discount">
                {{ $discountLabel }}
            </span>
        @endif

        <span class="lk-product-palette-tag">
            LIKHAE Pick
        </span>

        @if($unavailable || $availabilityRestricted)
            <span class="lk-product-unavailable-badge">Unavailable</span>
        @endif

        <button
            type="button"
            class="lk-product-heart {{ ! $guest && $isWishlistSaved ? 'is-active' : '' }}"
            @if($guest)
                data-auth-required
                data-auth-message="Sign in to save {{ $name }} to your wishlist."
            @else
                data-wishlist
                data-product-id="{{ $id }}"
                data-product-name="{{ $name }}"
                data-wishlist-url="{{ route('buyer.wishlist.toggle', ['product' => $id]) }}"
                aria-pressed="{{ $isWishlistSaved ? 'true' : 'false' }}"
            @endif
            aria-label="{{ $guest || ! $isWishlistSaved ? 'Save' : 'Remove' }} {{ $name }} {{ $guest || ! $isWishlistSaved ? 'to' : 'from' }} wishlist"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z"/>
            </svg>
        </button>
    </div>

    <div class="lk-product-body">
        <span class="lk-product-category">
            {{ $category }}
        </span>

        @if($detailsUrl)<a href="{{ $detailsUrl }}" class="lk-product-title-link">@endif
            <h3 class="lk-product-name">
                {{ $name }}
            </h3>
        @if($detailsUrl)</a>@endif

        @if(! $availabilityRestricted)
            <a href="{{ $storeUrl }}" class="lk-product-seller">{{ $seller }}</a>
        @endif

        @if(! $availabilityRestricted)
        <div class="lk-product-meta">
            <span
                class="lk-stars"
                aria-label="{{ number_format($rating, 1) }} out of 5 stars"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z"/>
                </svg>

                <strong>
                    {{ number_format($rating, 1) }}
                </strong>

                <small>
                    ({{ number_format($reviews) }})
                </small>
            </span>

            <span class="lk-product-sold">
                {{ number_format($sold) }} sold
            </span>
        </div>
        @endif

        <div class="lk-product-price-row">
            @if($availabilityRestricted)
                <strong>Unavailable</strong>
            @else
            <strong>
                ₱{{ number_format($price, 2) }}
            </strong>

            @if($oldPrice > $price)
                <del>
                    ₱{{ number_format($oldPrice, 2) }}
                </del>
            @endif
            @endif
        </div>

        <div class="lk-card-actions {{ $catalogListing ? 'lk-card-actions--catalog' : '' }}">
            @if($catalogListing)
                @if($guest)
                    <button type="button" class="lk-catalog-buy-now" data-auth-required data-auth-message="Sign in or create a Buyer account to purchase {{ $name }}.">Buy Now</button>
                    <button type="button" class="lk-add-cart lk-catalog-add-cart" data-auth-required data-auth-message="Sign in or create a Buyer account to add {{ $name }} to your cart.">Add to Cart</button>
                @elseif($availabilityRestricted || $unavailable || $sellerOnVacation || $outOfStock)
                    <button type="button" class="lk-catalog-buy-now" disabled aria-disabled="true">{{ $sellerOnVacation ? 'Seller on Vacation' : 'Unavailable' }}</button>
                    <button type="button" class="lk-add-cart lk-catalog-add-cart" disabled aria-disabled="true">{{ $sellerOnVacation ? 'Seller on Vacation' : 'Out of Stock' }}</button>
                @elseif($hasVariations)
                    <a href="{{ $detailsUrl }}" class="lk-catalog-buy-now">Buy Now</a>
                    <a href="{{ $detailsUrl }}" class="lk-add-cart lk-catalog-add-cart">Add to Cart</a>
                @else
                    <form method="POST" action="{{ route('buyer.buy-now', ['product' => $id ?? $slug]) }}" data-catalog-buy-now-form>
                        @csrf
                        <input type="hidden" name="product_variant_id" value="{{ data_get($singleVariant, 'id') }}">
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="lk-catalog-buy-now" @disabled(! $canAddToCart)>{{ $canAddToCart ? 'Buy Now' : 'Out of Stock' }}</button>
                    </form>
                    <form method="POST" action="{{ route('buyer.cart.add', ['product' => $id ?? $slug]) }}" data-cart-add-form>
                        @csrf
                        <input type="hidden" name="product_variant_id" value="{{ data_get($singleVariant, 'id') }}">
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="lk-add-cart lk-catalog-add-cart" @disabled(! $canAddToCart)>Add to Cart</button>
                    </form>
                @endif
            @else
                @if($detailsUrl)
                    <a href="{{ $detailsUrl }}" class="lk-btn-view">View</a>
                @else
                    <button type="button" class="lk-btn-view" disabled aria-disabled="true">Unavailable</button>
                @endif

                @if($guest)
                    <button type="button" class="lk-add-cart" data-auth-required data-auth-message="Sign in or create a Buyer account to add {{ $name }} to your cart.">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 6h15l-2 8H8zM6 6 5 3H2"/>
                            <circle cx="9" cy="20" r="1"/>
                            <circle cx="18" cy="20" r="1"/>
                        </svg>
                        <span>Add to Cart</span>
                    </button>
                @elseif($availabilityRestricted || $unavailable)
                    <button type="button" class="lk-add-cart" disabled aria-disabled="true">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 6h15l-2 8H8zM6 6 5 3H2"/>
                            <circle cx="9" cy="20" r="1"/>
                            <circle cx="18" cy="20" r="1"/>
                        </svg>
                        <span>Unavailable</span>
                    </button>
                @elseif($hasVariations)
                    <a href="{{ $detailsUrl }}" class="lk-add-cart">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 6h15l-2 8H8zM6 6 5 3H2"/>
                            <circle cx="9" cy="20" r="1"/>
                            <circle cx="18" cy="20" r="1"/>
                        </svg>
                        <span>Add to Cart</span>
                    </a>
                @else
                    <form method="POST" action="{{ route('buyer.cart.add', ['product' => $id ?? $slug]) }}" data-cart-add-form>
                        @csrf
                        <input type="hidden" name="product_variant_id" value="{{ data_get($singleVariant, 'id') }}">
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="lk-add-cart" @disabled(! $canAddToCart)>
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M6 6h15l-2 8H8zM6 6 5 3H2"/>
                                <circle cx="9" cy="20" r="1"/>
                                <circle cx="18" cy="20" r="1"/>
                            </svg>
                            <span>{{ $canAddToCart ? 'Add to Cart' : 'Out of Stock' }}</span>
                        </button>
                    </form>
                @endif
            @endif
        </div>

        @if($catalogListing)
            <div class="lk-catalog-list-purchase" data-catalog-purchase>
                <div class="lk-catalog-list-quantity">
                    <label for="catalog-quantity-{{ \Illuminate\Support\Str::slug((string) $slug) }}">Quantity</label>
                    <div class="lk-catalog-quantity-control">
                        <button type="button" data-catalog-quantity-step="-1" aria-label="Decrease quantity" @disabled($sellerOnVacation || $outOfStock || $availabilityRestricted || $unavailable)>−</button>
                        <input
                            id="catalog-quantity-{{ \Illuminate\Support\Str::slug((string) $slug) }}"
                            type="number"
                            min="1"
                            max="{{ max(1, $catalogStock) }}"
                            value="1"
                            inputmode="numeric"
                            aria-label="Quantity for {{ $name }}"
                            data-catalog-quantity
                            @disabled($sellerOnVacation || $outOfStock || $availabilityRestricted || $unavailable)
                        >
                        <button type="button" data-catalog-quantity-step="1" aria-label="Increase quantity" @disabled($sellerOnVacation || $outOfStock || $availabilityRestricted || $unavailable)>+</button>
                    </div>
                </div>

                <div class="lk-catalog-list-actions">
                    @if($guest)
                        <button type="button" class="lk-catalog-buy-now" data-auth-required data-auth-message="Sign in or create a Buyer account to purchase {{ $name }}.">Buy Now</button>
                        <button type="button" class="lk-add-cart lk-catalog-add-cart" data-auth-required data-auth-message="Sign in or create a Buyer account to add {{ $name }} to your cart.">Add to Cart</button>
                    @elseif($availabilityRestricted || $unavailable || $sellerOnVacation || $outOfStock)
                        <button type="button" class="lk-catalog-buy-now" disabled aria-disabled="true">{{ $sellerOnVacation ? 'Seller on Vacation' : 'Unavailable' }}</button>
                        <button type="button" class="lk-add-cart lk-catalog-add-cart" disabled aria-disabled="true">{{ $sellerOnVacation ? 'Seller on Vacation' : 'Out of Stock' }}</button>
                    @elseif($hasVariations)
                        <a href="{{ $detailsUrl }}" class="lk-catalog-buy-now">Buy Now</a>
                        <a href="{{ $detailsUrl }}" class="lk-add-cart lk-catalog-add-cart">Add to Cart</a>
                    @else
                        <form method="POST" action="{{ route('buyer.buy-now', ['product' => $id ?? $slug]) }}" data-catalog-buy-now-form>
                            @csrf
                            <input type="hidden" name="product_variant_id" value="{{ data_get($singleVariant, 'id') }}">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="lk-catalog-buy-now" @disabled(! $canAddToCart)>Buy Now</button>
                        </form>
                        <form method="POST" action="{{ route('buyer.cart.add', ['product' => $id ?? $slug]) }}" data-cart-add-form>
                            @csrf
                            <input type="hidden" name="product_variant_id" value="{{ data_get($singleVariant, 'id') }}">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="lk-add-cart lk-catalog-add-cart" @disabled(! $canAddToCart)>Add to Cart</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</article>
