@props([
    'product',
    'products' => collect(),
    'guest' => false,
])

@php
    $productId = data_get($product, 'db_id', data_get($product, 'id'));

    $slug = data_get($product, 'slug', data_get($product, 'id', 'product'));

    $name = (string) data_get($product, 'name', 'Product');

    $categoryValue = data_get($product, 'category.name') ?? 'Local Find';

    $category = is_scalar($categoryValue)
        ? (string) $categoryValue
        : 'Local Find';

    $sellerValue = data_get($product, 'seller.business_name') ?? 'LIKHAE Seller';

    $seller = is_scalar($sellerValue)
        ? (string) $sellerValue
        : 'LIKHAE Seller';
    $sellerOnVacation = (bool) data_get($product, 'seller.vacation_mode', false);

    $sellerSlug = data_get(
        $product,
        'seller_slug',
        \Illuminate\Support\Str::slug($seller)
    );
    $sellerUserId = (int) data_get($product, 'seller.user_id', 0);

    $sellerAvatar = data_get(
        $product,
        'seller_avatar',
        'https://ui-avatars.com/api/?name=' . urlencode($seller) . '&background=561C17&color=fff'
    );

    $images = collect(data_get($product, 'images', []))->pluck('url')
        ->filter()
        ->values();

    if ($images->isEmpty()) {
        $mainImage = data_get($product, 'primary_image.url');

        $images = collect([$mainImage])
            ->filter()
            ->values();
    }

    $price = max(
        0,
        (float) data_get($product, 'min_price', 0)
    );

    $oldPrice = max(
        0,
        (float) data_get(
            $product,
            'old_price',
            data_get($product, 'original_price', 0)
        )
    );

    $discount = $oldPrice > $price && $price > 0
        ? (int) round((($oldPrice - $price) / $oldPrice) * 100)
        : (int) data_get($product, 'discount', 0);
    $discountType = strtolower((string) data_get($product, 'discount_type', $discount > 0 ? 'percentage' : 'none'));
    $discountValue = (float) data_get($product, 'discount_value', $discount);
    if (! in_array($discountType, ['none', 'percentage', 'fixed'], true)) {
        $discountType = $discountValue > 0 ? 'percentage' : 'none';
    }

    $rating = (float) data_get($product, 'rating', 0);

    $reviews = collect(data_get($product, 'customer_reviews', []));

    $reviewCount = (int) data_get(
        $product,
        'reviews',
        $reviews->count()
    );

    $sold = (int) data_get($product, 'sold', 0);

    $stock = max(
        0,
        (int) data_get($product, 'stock', 0)
    );

    $specs = collect(data_get($product, 'specs', []));

    $variants = collect(data_get($product, 'variants', []))->values();

    $reviewPreviewLimit = 5;
    $reviewVariants = $reviews
        ->pluck('variant')
        ->filter()
        ->unique()
        ->values();

    $defaultVariant = $variants->firstWhere('is_default', true) ?? $variants->first();

    $variationOptionGroups = $variants
        ->flatMap(fn ($variant) => collect(data_get($variant, 'option_values', [])))
        ->filter(fn ($option) => filled(data_get($option, 'option')) && filled(data_get($option, 'value')))
        ->groupBy(fn ($option) => (string) data_get($option, 'option'))
        ->map(fn ($options) => $options->pluck('value')->unique()->values())
        ->sortBy(fn ($values, $option) => match (strtolower((string) $option)) {
            'size' => 0,
            'color', 'colour' => 1,
            default => 2,
        });
    $hasStructuredVariations = $variationOptionGroups->isNotEmpty()
        && $variants->every(fn ($variant) => collect(data_get($variant, 'option_values', []))->isNotEmpty());

    if ($defaultVariant) {
        $price = (float) data_get($defaultVariant, 'price', $price);
        $oldPrice = (float) data_get($defaultVariant, 'original_price', $oldPrice);
        $discountType = strtolower((string) data_get($defaultVariant, 'discount_type', 'none'));
        $discountValue = (float) data_get($defaultVariant, 'discount_value', 0);
        if (! in_array($discountType, ['none', 'percentage', 'fixed'], true)) {
            $discountType = $discountValue > 0 ? 'percentage' : 'none';
        }
        $discount = $discountType === 'percentage' ? $discountValue : 0;
    }

    $discountAmount = max(0, (float) data_get($defaultVariant, 'discount_amount', $oldPrice - $price));
    $discountBadgeValue = $discountType === 'fixed'
        ? $discountAmount
        : (float) data_get($defaultVariant, 'discount_percentage', $discountValue);

    $variantStock = $variants
        ->mapWithKeys(fn ($variant) => [(string) data_get($variant, 'id') => (int) data_get($variant, 'stock', 0)])
        ->all();

    $hasBuyerShopRoute = \Illuminate\Support\Facades\Route::has('buyer.shop');

    $storeUrl = $guest
        ? route('home')
        : ($hasBuyerShopRoute ? route('buyer.shop', ['seller' => $sellerSlug]) : '#');

    $hasMessagesRoute = \Illuminate\Support\Facades\Route::has('buyer.messages');

    $messageUrl = $hasMessagesRoute && $sellerUserId > 0
        ? route('buyer.messages', [
            'seller_id' => $sellerUserId,
            'product' => $slug,
        ])
        : '#';

    $related = collect($products)
        ->filter(function ($item) use ($category, $slug) {
            $itemCategoryValue = data_get($item, 'category.name')
                ?? data_get($item, 'category');

            $itemCategory = is_scalar($itemCategoryValue)
                ? (string) $itemCategoryValue
                : '';

            return $itemCategory === $category
                && data_get($item, 'slug') !== $slug;
        })
        ->take(4);

    if ($related->count() < 4) {
        $related = collect($products)
            ->filter(fn ($item) => data_get($item, 'slug') !== $slug)
            ->take(4);
    }
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
        --lk-text-dark: #1C160F;
        --lk-brown: #6C4936;
        --lk-muted: #987865;
        --lk-muted-2: #A99386;

        --lk-tan: #C19771;
        --lk-gold: #C19771;
        --lk-star: #C88418;

        --lk-shadow: 0 18px 46px rgba(86, 28, 23, 0.08);
        --lk-shadow-soft: 0 8px 24px rgba(86, 28, 23, 0.045);
    }

    body,
    .lk-buyer-body,
    .lk-guest-body {
        background: var(--lk-bg) !important;
        color: var(--lk-text) !important;
    }

    .lk-detail-inspo-page {
        width: min(100%, 1480px);
        margin: 0 auto;
        padding: 30px 32px 58px;
        color: var(--lk-text);
    }

    .lk-detail-breadcrumb {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        margin-bottom: 18px;

        color: var(--lk-muted);
        font-size: 11px;
        font-weight: 600;
    }

    .lk-detail-breadcrumb a {
        color: var(--lk-brown);
        text-decoration: none;
    }

    .lk-detail-breadcrumb a:hover {
        color: var(--lk-maroon);
    }

    .lk-detail-breadcrumb strong {
        color: var(--lk-text);
        font-weight: 800;
    }

    .lk-detail-main {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(380px, 0.92fr);
        gap: 24px;
        align-items: stretch;
    }

    .lk-detail-gallery-card,
    .lk-detail-info-card,
    .lk-detail-seller-card,
    .lk-detail-panel,
    .lk-detail-review-card {
        border: 1px solid var(--lk-border);
        border-radius: 24px;
        background: var(--lk-card);
        box-shadow: var(--lk-shadow-soft);
    }

    .lk-detail-gallery-card {
        display: flex;
        height: 100%;
        flex-direction: column;
        overflow: hidden;
        padding: 18px;
        background:
            radial-gradient(circle at 12% 10%, rgba(193, 151, 113, 0.18), transparent 24%),
            linear-gradient(135deg, #FFFDF9, #F6EFE7);
    }

    .lk-detail-gallery-main {
        position: relative;
        display: flex;
        min-height: 0;
        flex: 1 1 auto;
        aspect-ratio: 1 / 0.92;
        align-items: center;
        justify-content: center;
        overflow: hidden;

        border: 1px solid var(--lk-border);
        border-radius: 20px;

        background: #F3ECE4;
    }

    .lk-detail-gallery-main::before {
        position: absolute;
        inset: auto auto 28px 28px;
        z-index: 1;

        color: rgba(86, 28, 23, 0.22);

        font-family: "Caveat", cursive;
        font-size: clamp(26px, 3vw, 42px);
        line-height: 0.95;
        white-space: pre-line;

        content: "Good\A finds\A everyday";
        transform: rotate(-8deg);
        pointer-events: none;
    }

    .lk-detail-gallery-main img {
        position: relative;
        z-index: 2;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .lk-detail-image-placeholder {
        position: relative;
        z-index: 2;

        display: grid;
        place-items: center;
        gap: 10px;

        width: 100%;
        height: 100%;

        color: var(--lk-muted);
        text-align: center;
    }

    .lk-detail-image-placeholder svg {
        width: 64px;
        height: 64px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.4;
    }

    .lk-detail-thumbs {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
        margin-top: 13px;
    }

    .lk-detail-thumb {
        overflow: hidden;
        aspect-ratio: 1;

        padding: 2px;

        border: 2px solid transparent;
        border-radius: 14px;

        background: #F3ECE4;

        transition: 160ms ease;
        cursor: pointer;
    }

    .lk-detail-thumb:hover,
    .lk-detail-thumb.is-active {
        border-color: var(--lk-maroon);
        box-shadow: 0 0 0 3px rgba(86, 28, 23, 0.08);
    }

    .lk-detail-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 10px;
    }

    .lk-detail-info-card {
        position: relative;
        height: 100%;
        box-sizing: border-box;
        overflow: hidden;
        padding: 26px;
    }

    .lk-detail-info-card::before {
        position: absolute;
        top: -120px;
        right: -120px;
        width: 260px;
        height: 260px;

        border-radius: 999px;
        background: rgba(193, 151, 113, 0.18);

        content: "";
        pointer-events: none;
    }

    .lk-detail-badges {
        position: relative;
        z-index: 2;

        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .lk-detail-badge {
        display: inline-flex;
        align-items: center;
        min-height: 28px;

        padding: 0 10px;

        border-radius: 999px;

        font-size: 9px;
        font-weight: 900;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .lk-detail-badge-maroon {
        background: #F5ECEA;
        color: var(--lk-maroon);
    }

    .lk-detail-badge-tan {
        background: #F6E6D2;
        color: #7A4E31;
    }

    .lk-detail-title {
        position: relative;
        z-index: 2;

        margin: 16px 0 0;

        color: var(--lk-text);

        font-family: "Instrument Serif", Georgia, serif;
        font-size: clamp(38px, 4vw, 58px);
        font-weight: 400;
        line-height: 0.94;
        letter-spacing: -0.045em;
    }

    .lk-detail-title span {
        color: var(--lk-maroon);
        font-style: italic;
    }

    .lk-detail-rating-row {
        position: relative;
        z-index: 2;

        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0;

        margin-top: 18px;

        color: var(--lk-muted);
        font-size: 11px;
    }

    .lk-detail-rating-row > * {
        display: inline-flex;
        align-items: center;
        gap: 6px;

        padding: 0 12px;

        border-left: 1px solid var(--lk-border);
    }

    .lk-detail-rating-row > *:first-child {
        padding-left: 0;
        border-left: 0;
    }

    .lk-detail-stars {
        color: var(--lk-star);
        font-weight: 900;
    }

    .lk-detail-rating-row a {
        color: var(--lk-brown);
        text-decoration: none;
    }

    .lk-detail-rating-row a:hover {
        color: var(--lk-maroon);
    }

    .lk-detail-price-box {
        position: relative;
        z-index: 2;

        margin-top: 24px;
        padding: 18px;

        border: 1px solid #E5CBAA;
        border-radius: 18px;

        background:
            radial-gradient(circle at 92% 18%, rgba(193, 151, 113, 0.18), transparent 28%),
            #F8EDE3;
    }

    .lk-detail-price-row {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 10px;
    }

    .lk-detail-price {
        color: var(--lk-maroon);

        font-size: clamp(30px, 3vw, 42px);
        font-weight: 950;
        line-height: 1;
        letter-spacing: -0.04em;
    }

    .lk-detail-old-price {
        padding-bottom: 4px;

        color: var(--lk-muted-2);

        font-size: 13px;
    }

    .lk-detail-price-note {
        margin: 8px 0 0;

        color: var(--lk-brown);

        font-size: 11px;
        line-height: 1.6;
    }

    .lk-detail-options {
        position: relative;
        z-index: 2;

        display: grid;
        gap: 18px;

        margin-top: 22px;
    }

    .lk-detail-option-label {
        display: block;
        margin-bottom: 9px;

        color: var(--lk-text);

        font-size: 11px;
        font-weight: 900;
    }

    .lk-detail-option-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .lk-detail-option-btn {
        min-height: 38px;

        padding: 0 14px;

        border: 1px solid var(--lk-border-strong);
        border-radius: 12px;

        background: #FFFFFF;
        color: var(--lk-brown);

        font-size: 11px;
        font-weight: 800;

        transition: 160ms ease;
        cursor: pointer;
    }

    .lk-detail-option-btn:hover,
    .lk-detail-option-btn.is-selected,
    .lk-detail-option-btn[aria-pressed="true"] {
        border-color: var(--lk-maroon);
        background: #F5ECEA;
        color: var(--lk-maroon);
    }

    .lk-detail-qty-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;

        padding-top: 18px;
        border-top: 1px solid #EFE1D5;
    }

    .lk-detail-qty-copy strong {
        display: block;

        color: var(--lk-text);

        font-size: 12px;
        font-weight: 900;
    }

    .lk-detail-qty-copy span {
        display: block;
        margin-top: 3px;

        color: var(--lk-muted);

        font-size: 10px;
    }

    .lk-detail-qty-control {
        display: inline-flex;
        height: 40px;
        overflow: hidden;

        border: 1px solid var(--lk-border-strong);
        border-radius: 13px;

        background: #FFFFFF;
    }

    .lk-detail-qty-control button {
        width: 40px;

        border: 0;
        background: #FFFFFF;
        color: var(--lk-brown);

        font-size: 16px;
        font-weight: 800;

        cursor: pointer;
        transition: 160ms ease;
    }

    .lk-detail-qty-control button:hover:not(:disabled) {
        background: #F6EFE7;
        color: var(--lk-maroon);
    }

    .lk-detail-qty-control button:disabled {
        cursor: not-allowed;
        opacity: 0.38;
    }

    .lk-detail-qty-control input {
        width: 54px;
        height: 100%;

        border: 0;
        border-left: 1px solid var(--lk-border);
        border-right: 1px solid var(--lk-border);

        background: #FFFFFF;
        color: var(--lk-text);

        font-size: 13px;
        font-weight: 900;
        text-align: center;
        outline: 0;

        appearance: textfield;
    }

    .lk-detail-qty-control input::-webkit-inner-spin-button,
    .lk-detail-qty-control input::-webkit-outer-spin-button {
        margin: 0;
        appearance: none;
    }

    .lk-detail-actions {
        position: relative;
        z-index: 2;

        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;

        margin-top: 22px;
    }

    .lk-detail-actions .lk-btn {
        min-height: 46px !important;
        border-radius: 13px !important;
        font-size: 12px !important;
        font-weight: 900 !important;
    }

    .lk-detail-actions .lk-btn-red {
        background: var(--lk-maroon) !important;
        border-color: var(--lk-maroon) !important;
        color: #FFFFFF !important;
        box-shadow: 0 10px 22px rgba(86, 28, 23, 0.16) !important;
    }

    .lk-detail-actions .lk-btn-red:hover {
        background: var(--lk-maroon-dark) !important;
        border-color: var(--lk-maroon-dark) !important;
    }

    .lk-detail-actions .lk-btn-light {
        background: #FFFFFF !important;
        border-color: var(--lk-tan) !important;
        color: var(--lk-maroon) !important;
    }

    .lk-detail-actions .lk-btn-light:hover {
        background: #F5ECEA !important;
    }

    .lk-detail-actions .lk-detail-wide {
        grid-column: 1 / -1;
    }

    .lk-detail-protection {
        position: relative;
        z-index: 2;

        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;

        margin-top: 22px;
        padding: 16px;

        border: 1px solid var(--lk-border);
        border-radius: 18px;

        background: #FFFDF9;
    }

    .lk-detail-protection strong {
        display: block;

        color: var(--lk-text);

        font-size: 11px;
        font-weight: 900;
    }

    .lk-detail-protection span {
        display: block;
        margin-top: 4px;

        color: var(--lk-muted);

        font-size: 10px;
        line-height: 1.55;
    }

    .lk-detail-seller-card {
        display: flex;
        align-items: center;
        gap: 16px;

        margin-top: 22px;
        padding: 20px;
    }

    .lk-detail-seller-avatar {
        width: 70px;
        height: 70px;
        flex: 0 0 70px;

        overflow: hidden;

        border: 3px solid #FFFFFF;
        border-radius: 18px;

        background: var(--lk-maroon);
        box-shadow: 0 8px 18px rgba(86, 28, 23, 0.14);
    }

    .lk-detail-seller-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .lk-detail-seller-copy {
        min-width: 0;
        flex: 1;
    }

    .lk-detail-seller-kicker {
        color: var(--lk-maroon);

        font-size: 9px;
        font-weight: 900;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .lk-detail-seller-copy h2 {
        overflow: hidden;

        margin: 4px 0 0;

        color: var(--lk-text);

        font-size: 20px;
        font-weight: 900;
        letter-spacing: -0.035em;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-detail-seller-copy p {
        margin: 5px 0 0;

        color: var(--lk-muted);

        font-size: 11px;
        line-height: 1.55;
    }

    .lk-detail-seller-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 9px;
    }

    .lk-detail-seller-actions .lk-btn {
        min-height: 40px !important;
        border-radius: 12px !important;
        font-size: 11px !important;
        font-weight: 900 !important;
    }

    .lk-detail-panels {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 370px;
        gap: 22px;

        margin-top: 22px;
    }

    .lk-detail-panel {
        padding: 24px;
    }

    .lk-detail-panel h2 {
        margin: 0;

        color: var(--lk-text);

        font-size: 20px;
        font-weight: 900;
        letter-spacing: -0.035em;
    }

    .lk-detail-description {
        margin-top: 12px;

        color: var(--lk-brown);

        font-size: 13px;
        line-height: 1.85;
        white-space: pre-line;
    }

    .lk-detail-spec-list {
        margin-top: 12px;
    }

    .lk-detail-spec-row {
        display: grid;
        grid-template-columns: 120px minmax(0, 1fr);
        gap: 14px;

        padding: 11px 0;

        border-bottom: 1px solid #EFE1D5;

        font-size: 11px;
    }

    .lk-detail-spec-row:last-child {
        border-bottom: 0;
    }

    .lk-detail-spec-row dt {
        color: var(--lk-muted);
    }

    .lk-detail-spec-row dd {
        margin: 0;
        color: var(--lk-text);
        font-weight: 800;
    }

    .lk-detail-empty-copy {
        margin-top: 12px;

        color: var(--lk-muted);

        font-size: 12px;
    }

    .lk-detail-review-card {
        margin-top: 22px;
        padding: 24px;
    }

    .lk-detail-review-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;

        padding-bottom: 20px;
        border-bottom: 1px solid #EFE1D5;
    }

    .lk-detail-review-kicker {
        color: var(--lk-maroon);

        font-size: 9px;
        font-weight: 900;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .lk-detail-review-head h2 {
        margin: 5px 0 0;

        color: var(--lk-text);

        font-size: 20px;
        font-weight: 900;
        letter-spacing: -0.035em;
    }

    .lk-detail-review-score {
        text-align: right;
    }

    .lk-detail-review-score strong {
        color: var(--lk-maroon);
        font-size: 38px;
        font-weight: 950;
        line-height: 1;
    }

    .lk-detail-review-score span {
        color: var(--lk-muted-2);
        font-size: 14px;
    }

    .lk-detail-review-score div {
        margin-top: 4px;
        color: var(--lk-star);
        font-size: 13px;
        letter-spacing: 1px;
    }

    .lk-detail-review-score small {
        display: block;
        margin-top: 3px;

        color: var(--lk-muted);

        font-size: 10px;
    }

    .lk-detail-review-list {
        display: grid;
    }

    .lk-detail-review-controls {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin: 20px 0 0;
        padding: 14px;
        border: 1px solid #EFE1D5;
        border-radius: 14px;
        background: #FFF9F5;
    }

    .lk-detail-review-filter {
        display: grid;
        flex: 1 1 180px;
        gap: 5px;
        color: var(--lk-muted);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .lk-detail-review-filter select {
        width: 100%;
        min-height: 38px;
        border: 1px solid #E4CFC1;
        border-radius: 9px;
        background: #fff;
        color: var(--lk-text);
        font: inherit;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: normal;
        text-transform: none;
    }

    .lk-detail-review-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        margin-top: 4px;
        padding-top: 18px;
    }

    .lk-detail-review-count {
        color: var(--lk-muted);
        font-size: 11px;
        font-weight: 700;
    }

    .lk-detail-review-more {
        border: 1px solid #D5A895;
        border-radius: 10px;
        background: #fff;
        color: var(--lk-maroon);
        cursor: pointer;
        padding: 10px 14px;
        font-size: 11px;
        font-weight: 900;
        transition: background 160ms ease, border-color 160ms ease;
    }

    .lk-detail-review-more:hover {
        border-color: var(--lk-maroon);
        background: #FFF1E9;
    }

    .lk-detail-review-item {
        padding: 20px 0;
        border-bottom: 1px solid #EFE1D5;
    }

    .lk-detail-review-item:last-child {
        border-bottom: 0;
    }

    .lk-detail-review-inner {
        display: flex;
        align-items: flex-start;
        gap: 13px;
    }

    .lk-detail-review-avatar {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;

        border-radius: 999px;
        object-fit: cover;
    }

    .lk-detail-review-body {
        min-width: 0;
        flex: 1;
    }

    .lk-detail-review-meta {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .lk-detail-review-meta strong {
        display: block;

        color: var(--lk-text);

        font-size: 13px;
        font-weight: 900;
    }

    .lk-detail-review-stars {
        margin-top: 3px;

        color: var(--lk-star);

        font-size: 12px;
        letter-spacing: 1px;
    }

    .lk-detail-review-date {
        color: var(--lk-muted-2);
        font-size: 10px;
        white-space: nowrap;
    }

    .lk-detail-review-comment {
        margin: 9px 0 0;

        color: var(--lk-brown);

        font-size: 13px;
        line-height: 1.75;
    }

    .lk-detail-review-variant {
        display: inline-flex;
        margin-top: 9px;

        color: var(--lk-muted);

        font-size: 10px;
    }

    .lk-detail-related {
        margin-top: 28px;
    }

    .lk-detail-related .lk-section-head h2 {
        color: var(--lk-text) !important;
        font-family: "Instrument Serif", Georgia, serif !important;
        font-size: clamp(32px, 3vw, 46px) !important;
        font-weight: 400 !important;
        letter-spacing: -0.04em !important;
    }

    .lk-detail-related .lk-kicker {
        color: var(--lk-maroon) !important;
    }

    @media (max-width: 1100px) {
        .lk-detail-main,
        .lk-detail-panels {
            grid-template-columns: 1fr;
        }

        .lk-detail-main {
            align-items: start;
        }

        .lk-detail-gallery-card,
        .lk-detail-info-card {
            height: auto;
        }

        .lk-detail-info-card {
            padding: 22px;
        }

        .lk-detail-seller-card {
            align-items: flex-start;
            flex-direction: column;
        }

        .lk-detail-seller-actions {
            width: 100%;
            justify-content: flex-start;
        }
    }

    @media (max-width: 700px) {
        .lk-detail-inspo-page {
            padding: 22px 14px 44px;
        }

        .lk-detail-main {
            gap: 16px;
        }

        .lk-detail-title {
            font-size: 38px;
        }

        .lk-detail-actions,
        .lk-detail-protection {
            grid-template-columns: 1fr;
        }

        .lk-detail-qty-row,
        .lk-detail-item-bottom,
        .lk-detail-review-head,
        .lk-detail-review-meta {
            align-items: flex-start;
            flex-direction: column;
        }

        .lk-detail-thumbs {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .lk-detail-spec-row {
            grid-template-columns: 1fr;
            gap: 4px;
        }
    }

    html.dark .lk-detail-inspo-page {
        background: #171210;
        color: #FFF8F2;
    }

    html.dark .lk-detail-gallery-card,
    html.dark .lk-detail-info-card,
    html.dark .lk-detail-seller-card,
    html.dark .lk-detail-panel,
    html.dark .lk-detail-review-card {
        background: #241A17;
        border-color: #49342B;
        color: #FFF8F2;
    }

    html.dark .lk-detail-gallery-card {
        background:
            radial-gradient(circle at 12% 10%, rgba(193, 151, 113, 0.08), transparent 24%),
            linear-gradient(135deg, #241A17, #1C1512);
    }

    html.dark .lk-detail-gallery-main,
    html.dark .lk-detail-product-image,
    html.dark .lk-detail-thumb,
    html.dark .lk-detail-price-box,
    html.dark .lk-detail-protection {
        background: #1E1714;
        border-color: #49342B;
    }

    html.dark .lk-detail-title,
    html.dark .lk-detail-product-title h2,
    html.dark .lk-detail-panel h2,
    html.dark .lk-detail-seller-copy h2,
    html.dark .lk-detail-review-head h2,
    html.dark .lk-detail-review-meta strong,
    html.dark .lk-detail-spec-row dd,
    html.dark .lk-detail-qty-copy strong {
        color: #FFF8F2;
    }

    html.dark .lk-detail-breadcrumb,
    html.dark .lk-detail-price-note,
    html.dark .lk-detail-description,
    html.dark .lk-detail-review-comment,
    html.dark .lk-detail-seller-copy p,
    html.dark .lk-detail-protection span,
    html.dark .lk-detail-qty-copy span,
    html.dark .lk-detail-empty-copy {
        color: #C8B7AD;
    }

    html.dark .lk-detail-option-btn,
    html.dark .lk-detail-qty-control,
    html.dark .lk-detail-qty-control button,
    html.dark .lk-detail-qty-control input {
        background: #1E1714;
        border-color: #49342B;
        color: #FFF8F2;
    }

    html.dark .lk-detail-review-controls {
        border-color: #49342B;
        background: #1E1714;
    }

    html.dark .lk-detail-review-filter select,
    html.dark .lk-detail-review-more {
        border-color: #604238;
        background: #241A17;
        color: #FFF8F2;
    }

    html.dark .lk-detail-option-btn:hover,
    html.dark .lk-detail-option-btn.is-selected,
    html.dark .lk-detail-option-btn[aria-pressed="true"] {
        background: #361B17;
        border-color: #A85D50;
        color: #F2B8AA;
    }
</style>
@endonce

<div class="lk-detail-inspo-page">
    <nav class="lk-detail-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ $guest ? route('home') : route('buyer.products') }}">
            Products
        </a>

        <span>/</span>

        <span>{{ $category }}</span>

        <span>/</span>

        <strong>{{ $name }}</strong>
    </nav>

    <section class="lk-detail-main">
        <div
            class="lk-detail-gallery-card"
            data-product-gallery
            data-gallery-base-images='@json($images->all())'
        >
            <div class="lk-detail-gallery-main">
                @if($images->isNotEmpty())
                    <img src="{{ $images->first() }}" alt="{{ $name }}" data-gallery-main>
                @else
                    <div class="lk-detail-image-placeholder">
                        <svg
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path d="M4 5h16v14H4z"/>
                            <path d="m4 15 4-4 4 4 3-3 5 5"/>
                            <circle cx="15.5" cy="8.5" r="1.5"/>
                        </svg>

                        <span>No product image available</span>
                    </div>
                @endif
            </div>

            @if($images->count() > 1)
                <div class="lk-detail-thumbs">
                    @foreach($images as $index => $galleryImage)
                        <button
                            type="button"
                            class="lk-detail-thumb {{ $index === 0 ? 'is-active' : '' }}"
                            data-gallery-thumb
                            data-image="{{ $galleryImage }}"
                            aria-label="View image {{ $index + 1 }}"
                        >
                            <img
                                src="{{ $galleryImage }}"
                                alt="{{ $name }} view {{ $index + 1 }}"
                            >
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div
            class="lk-detail-info-card"
            data-seller-vacation="{{ $sellerOnVacation ? '1' : '0' }}"
            @if($variantStock)
                data-variant-stock-product
                data-variant-stocks='@json($variantStock)'
            @endif
        >
            <div class="lk-detail-badges">
                <span class="lk-detail-badge lk-detail-badge-maroon">
                    {{ $category }}
                </span>

                <span class="lk-detail-badge lk-detail-badge-tan" data-variation-discount-badge-wrapper @if($discountAmount <= 0) hidden @endif>
                    <span data-variation-discount-badge>{{ $discountType === 'fixed' ? '₱'.number_format($discountAmount, 2).' OFF' : number_format($discountBadgeValue, 2).'% OFF' }}</span>
                </span>
            </div>

            <h1 class="lk-detail-title">
                {{ $name }}
                <span>.</span>
            </h1>

            <div class="lk-detail-rating-row">
                <strong class="lk-detail-stars">
                    ★ {{ number_format($rating, 1) }}
                </strong>

                <a href="#reviews">
                    {{ number_format($reviewCount) }} reviews
                </a>

                <span>
                    {{ number_format($sold) }} sold
                </span>
            </div>

            <div class="lk-detail-price-box">
                <div class="lk-detail-price-row">
                    <strong class="lk-detail-price">
                        ₱{{ number_format($price, 2) }}
                    </strong>

                    @if($oldPrice > $price && $discountAmount > 0)
                        <del class="lk-detail-old-price" data-variation-old-price>
                            ₱{{ number_format($oldPrice, 2) }}
                        </del>
                    @else
                        <del class="lk-detail-old-price" data-variation-old-price hidden></del>
                    @endif
                </div>

                <p class="lk-detail-price-note">
                    VAT included · Secure LIKHAE checkout · Protected payment
                </p>
                @if(!$guest && \Illuminate\Support\Facades\Route::has('buyer.rewards'))
                    <a class="lk-detail-price-note" href="{{ route('buyer.rewards') }}">View voucher codes in Rewards</a>
                @endif
                @if($sellerOnVacation)
                    <p class="lk-detail-price-note" role="status">This seller is currently on vacation and is not accepting purchases.</p>
                @endif
            </div>

            <div class="lk-detail-options">
                @if($variants->count() > 1)
                    <div>
                        <span class="lk-detail-option-label">Choose a variation</span>
                        @if($hasStructuredVariations)
                            <div class="space-y-3" data-variation-picker>
                                @foreach($variationOptionGroups as $optionName => $optionValues)
                                    @php
                                        $optionKey = strtolower((string) $optionName);
                                    @endphp
                                    <div data-variation-choice-group data-option-key="{{ $optionKey }}">
                                        <span class="lk-detail-option-label">{{ $optionName }}</span>
                                        <div class="lk-detail-option-group">
                                            @foreach($optionValues as $optionValue)
                                                @php
                                                    $selectedChoice = false;
                                                @endphp
                                                <button type="button" class="lk-detail-option-btn {{ $selectedChoice ? 'is-selected' : '' }}" data-variation-choice data-option-key="{{ $optionKey }}" data-option-value="{{ $optionValue }}" aria-pressed="{{ $selectedChoice ? 'true' : 'false' }}">{{ $optionValue }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="lk-detail-option-group" data-variation-group @if($hasStructuredVariations) hidden @endif>
                            @foreach($variants as $variant)
                                @php
                                    $selected = $variants->count() === 1 && (string) data_get($variant, 'id') === (string) data_get($defaultVariant, 'id');
                                    $variantOptionValues = collect(data_get($variant, 'option_values', []))
                                        ->mapWithKeys(fn ($option) => [strtolower((string) data_get($option, 'option')) => (string) data_get($option, 'value')])
                                        ->all();
                                @endphp
                                <button type="button" class="lk-detail-option-btn {{ $selected ? 'is-selected' : '' }}" data-variation-option data-variation-id="{{ data_get($variant, 'id') }}" data-variation-value="{{ data_get($variant, 'description', data_get($variant, 'sku')) }}" data-variation-stock="{{ data_get($variant, 'stock', 0) }}" data-variation-price="{{ data_get($variant, 'price', 0) }}" data-variation-original-price="{{ data_get($variant, 'original_price', data_get($variant, 'price', 0)) }}" data-variation-discount-type="{{ data_get($variant, 'discount_type', 'none') }}" data-variation-discount-value="{{ data_get($variant, 'discount_type') === 'percentage' ? data_get($variant, 'discount_percentage', data_get($variant, 'discount_value', 0)) : data_get($variant, 'discount_value', 0) }}" data-variation-discount="{{ data_get($variant, 'discount_type') === 'percentage' ? data_get($variant, 'discount_percentage', data_get($variant, 'discount_value', 0)) : data_get($variant, 'discount_value', 0) }}" data-variation-discount-amount="{{ data_get($variant, 'discount_amount', 0) }}" data-variation-images='@json(data_get($variant, "image_urls", []))' data-option-values='@json($variantOptionValues)' aria-pressed="{{ $selected ? 'true' : 'false' }}">{{ data_get($variant, 'description', data_get($variant, 'sku')) }}</button>
                            @endforeach
                        </div>
                    </div>
                @elseif($defaultVariant)
                    <button type="button" hidden data-variation-option data-variation-id="{{ data_get($defaultVariant, 'id') }}" data-variation-value="{{ data_get($defaultVariant, 'description', 'Default') }}" data-variation-stock="{{ data_get($defaultVariant, 'stock', 0) }}" data-variation-price="{{ data_get($defaultVariant, 'price', 0) }}" data-variation-original-price="{{ data_get($defaultVariant, 'original_price', data_get($defaultVariant, 'price', 0)) }}" data-variation-discount-type="{{ data_get($defaultVariant, 'discount_type', 'none') }}" data-variation-discount-value="{{ data_get($defaultVariant, 'discount_type') === 'percentage' ? data_get($defaultVariant, 'discount_percentage', data_get($defaultVariant, 'discount_value', 0)) : data_get($defaultVariant, 'discount_value', 0) }}" data-variation-discount="{{ data_get($defaultVariant, 'discount_type') === 'percentage' ? data_get($defaultVariant, 'discount_percentage', data_get($defaultVariant, 'discount_value', 0)) : data_get($defaultVariant, 'discount_value', 0) }}" data-variation-discount-amount="{{ data_get($defaultVariant, 'discount_amount', 0) }}" data-variation-images='@json(data_get($defaultVariant, "image_urls", []))' aria-pressed="true">Default</button>
                @endif

                <div class="lk-detail-qty-row">
                    <div class="lk-detail-qty-copy">
                        <strong>Quantity</strong>

                        <span data-variant-availability>
                            {{ $stock }} available
                        </span>
                    </div>

                    <div
                        class="lk-detail-qty-control"
                        data-quantity-control
                    >
                        <button
                            type="button"
                            data-quantity-minus
                            aria-label="Decrease quantity"
                        >
                            −
                        </button>

                        <input
                            id="detailQuantity"
                            type="number"
                            value="1"
                            min="1"
                            max="{{ max(1, $stock) }}"
                            data-quantity-input
                            aria-label="Quantity"
                        >

                        <button
                            type="button"
                            data-quantity-plus
                            aria-label="Increase quantity"
                        >
                            +
                        </button>
                    </div>
                </div>
            </div>

            <div class="lk-detail-actions">
                @if($guest)
                    <button
                        type="button"
                        class="lk-btn lk-btn-light lk-btn-full"
                        data-auth-required
                        data-auth-message="Sign in to add {{ $name }} to your cart."
                    >
                        Add to Cart
                    </button>

                    <button
                        type="button"
                        class="lk-btn lk-btn-red lk-btn-full"
                        data-auth-required
                        data-auth-message="Create a Buyer account or sign in to purchase this product."
                    >
                        Buy Now
                    </button>

                    <button
                        type="button"
                        class="lk-btn lk-btn-light lk-btn-full lk-detail-wide"
                        data-auth-required
                        data-auth-message="Sign in to save products to your wishlist."
                    >
                        ♡ Save to Wishlist
                    </button>
                @else
                    <button
                        type="button"
                        class="lk-btn lk-btn-light lk-btn-full"
                        data-test-add-cart
                        data-cart-url="{{ route('buyer.cart.add', ['product' => $productId]) }}"
                        data-product-purchase
                    >
                        Add to Cart
                    </button>

                    <form method="POST" action="{{ route('buyer.buy-now', ['product' => $productId]) }}" style="display: contents" data-buy-now-form>
                        @csrf
                        <input type="hidden" name="product_variant_id" value="{{ $variants->count() === 1 ? data_get($defaultVariant, 'id') : '' }}">
                        <input type="hidden" name="quantity" value="1">
                        <button
                            type="submit"
                            class="lk-btn lk-btn-red lk-btn-full"
                            data-test-buy-now
                            data-cart-url="{{ route('buyer.buy-now', ['product' => $productId]) }}"
                            data-checkout="1"
                            data-product-purchase
                        >
                            Buy Now
                        </button>
                    </form>

                    <button
                        type="button"
                        class="lk-btn lk-btn-light lk-btn-full lk-detail-wide"
                        data-wishlist
                        data-product-id="{{ $productId ?? $slug }}"
                    >
                        ♡ Save to Wishlist
                    </button>
                @endif
            </div>

            <div class="lk-detail-protection">
                <div>
                    <strong>Delivery</strong>

                    <span>
                        {{ data_get($product, 'shipping', 'Metro Manila: 1–3 days') }}
                    </span>
                </div>

                <div>
                    <strong>Buyer protection</strong>

                    <span>
                        Payment is held until receipt confirmation.
                    </span>
                </div>
            </div>
        </div>
    </section>

    <section class="lk-detail-seller-card">
        <div class="lk-detail-seller-avatar">
            <img
                src="{{ $sellerAvatar }}"
                alt="{{ $seller }}"
            >
        </div>

        <div class="lk-detail-seller-copy">
            <span class="lk-detail-seller-kicker">
                Official seller
            </span>

            <h2>{{ $seller }}</h2>

            <p>
                {{ data_get($product, 'location', 'Philippines') }}
                · {{ data_get($product, 'seller_rating', '4.8') }} seller rating
                · {{ data_get($product, 'seller_products', '24') }} products
            </p>
        </div>

        <div class="lk-detail-seller-actions">
            <a
                href="{{ $storeUrl }}"
                class="lk-btn lk-btn-light"
                @if(!$guest && !$hasBuyerShopRoute) onclick="return false;" @endif
            >
                View Store
            </a>

            @if($guest)
                <button
                    class="lk-btn lk-btn-red"
                    type="button"
                    data-auth-required
                    data-auth-message="Sign in to message this seller."
                >
                    Message
                </button>
            @else
                <a
                    href="{{ $messageUrl }}"
                    class="lk-btn lk-btn-red"
                    @if(!$hasMessagesRoute) onclick="return false;" @endif
                >
                    Message
                </a>
            @endif
        </div>
    </section>

    <div class="lk-detail-panels">
        <section class="lk-detail-panel">
            <h2>Description</h2>

            <div class="lk-detail-description">
                {{ data_get($product, 'description', 'A carefully selected product from a trusted LIKHAE seller. Built for everyday use and covered by marketplace buyer protection.') }}
            </div>
        </section>

        @if($specs->isNotEmpty())
            <section class="lk-detail-panel">
                <h2>Specifications</h2>

                <dl class="lk-detail-spec-list">
                    @foreach($specs as $label => $value)
                    <div class="lk-detail-spec-row">
                        <dt>{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    </div>
                    @endforeach
                </dl>
            </section>
        @endif
    </div>

    <section
        id="reviews"
        class="lk-detail-review-card"
        data-product-reviews
        data-preview-limit="{{ $reviewPreviewLimit }}"
    >
        <div class="lk-detail-review-head">
            <div>
                <span class="lk-detail-review-kicker">
                    Customer feedback
                </span>

                <h2>Ratings & Reviews</h2>
            </div>

            <div class="lk-detail-review-score">
                <strong>{{ number_format($rating, 1) }}</strong>
                <span>/ 5</span>

                <div>★★★★★</div>

                <small>
                    {{ number_format($reviewCount) }} verified ratings
                </small>
            </div>
        </div>

        @if($reviews->isNotEmpty())
            <div class="lk-detail-review-controls" data-review-controls @if($reviews->count() > $reviewPreviewLimit) hidden @endif>
                <label class="lk-detail-review-filter">
                    Rating
                    <select data-review-rating aria-label="Filter reviews by rating">
                        <option value="">All ratings</option>
                        @foreach(range(5, 1) as $filterRating)
                            <option value="{{ $filterRating }}">{{ $filterRating }} star{{ $filterRating === 1 ? '' : 's' }}</option>
                        @endforeach
                    </select>
                </label>

                @if($reviewVariants->isNotEmpty())
                    <label class="lk-detail-review-filter">
                        Product variant
                        <select data-review-variant aria-label="Filter reviews by product variant">
                            <option value="">All variants</option>
                            @foreach($reviewVariants as $reviewVariant)
                                <option value="{{ $reviewVariant }}">{{ $reviewVariant }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
        @endif

        <div class="lk-detail-review-list">
            @forelse($reviews as $review)
                @php
                    $reviewRating = (int) data_get($review, 'rating', 5);
                    $reviewVariant = (string) data_get($review, 'variant', 'Standard');
                @endphp
                <article
                    class="lk-detail-review-item"
                    data-review-item
                    data-rating="{{ $reviewRating }}"
                    data-variant="{{ $reviewVariant }}"
                    @if($loop->index >= $reviewPreviewLimit) hidden @endif
                >
                    <div class="lk-detail-review-inner">
                        <img
                            class="lk-detail-review-avatar"
                            src="{{ data_get($review, 'avatar', 'https://ui-avatars.com/api/?name=' . urlencode(data_get($review, 'name', 'Buyer')) . '&background=EADCCC&color=3B211B') }}"
                            alt=""
                        >

                        <div class="lk-detail-review-body">
                            <div class="lk-detail-review-meta">
                                <div>
                                    <strong>
                                        {{ data_get($review, 'name', 'Verified Buyer') }}
                                    </strong>

                                    <div class="lk-detail-review-stars">
                                        {{ str_repeat('★', $reviewRating) }}
                                        <span>
                                            {{ str_repeat('★', 5 - $reviewRating) }}
                                        </span>
                                    </div>
                                </div>

                                <time class="lk-detail-review-date">
                                    {{ data_get($review, 'date', 'Recently') }}
                                </time>
                            </div>

                            <p class="lk-detail-review-comment">
                                {{ data_get($review, 'comment') }}
                            </p>

                            @if(data_get($review, 'image_url'))
                                <img class="mt-3 max-h-64 max-w-full rounded-lg object-contain" src="{{ data_get($review, 'image_url') }}" alt="Photo shared with buyer review" loading="lazy">
                            @endif

                            @if(data_get($review, 'variant'))
                                <span class="lk-detail-review-variant">
                                    Variation: {{ data_get($review, 'variant') }}
                                </span>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="lk-detail-empty-copy">
                    No written reviews yet.
                </div>
            @endforelse
        </div>

        @if($reviews->count() > $reviewPreviewLimit)
            <div class="lk-detail-review-footer">
                <span class="lk-detail-review-count" data-review-result-count>
                    Showing {{ $reviewPreviewLimit }} of {{ number_format($reviews->count()) }} reviews
                </span>
                <button type="button" class="lk-detail-review-more" data-review-toggle aria-expanded="false">
                    View all {{ number_format($reviews->count()) }} reviews
                </button>
            </div>
        @endif
    </section>

    <script>
        (() => {
            const reviewRoot = document.querySelector('[data-product-reviews]');
            if (!reviewRoot || reviewRoot.dataset.reviewFiltersReady) return;

            reviewRoot.dataset.reviewFiltersReady = 'true';

            const items = Array.from(reviewRoot.querySelectorAll('[data-review-item]'));
            const toggle = reviewRoot.querySelector('[data-review-toggle]');
            const controls = reviewRoot.querySelector('[data-review-controls]');
            const ratingFilter = reviewRoot.querySelector('[data-review-rating]');
            const variantFilter = reviewRoot.querySelector('[data-review-variant]');
            const resultCount = reviewRoot.querySelector('[data-review-result-count]');
            const previewLimit = Number(reviewRoot.dataset.previewLimit || 5);
            let expanded = !toggle;

            const applyFilters = () => {
                const selectedRating = ratingFilter?.value || '';
                const selectedVariant = variantFilter?.value || '';
                let visibleIndex = 0;
                let matches = 0;

                items.forEach((item) => {
                    const matchesRating = !selectedRating || item.dataset.rating === selectedRating;
                    const matchesVariant = !selectedVariant || item.dataset.variant === selectedVariant;
                    const matchesFilters = matchesRating && matchesVariant;

                    if (matchesFilters) {
                        matches += 1;
                        visibleIndex += 1;
                    }

                    item.hidden = !matchesFilters || (!expanded && visibleIndex > previewLimit);
                });

                if (resultCount) {
                    resultCount.textContent = expanded
                        ? `${matches} review${matches === 1 ? '' : 's'} shown`
                        : `Showing ${Math.min(matches, previewLimit)} of ${matches} reviews`;
                }
            };

            toggle?.addEventListener('click', () => {
                expanded = true;
                toggle.hidden = true;
                toggle.setAttribute('aria-expanded', 'true');
                if (controls) controls.hidden = false;
                applyFilters();
            });

            [ratingFilter, variantFilter].forEach((filter) => filter?.addEventListener('change', () => {
                expanded = true;
                if (toggle) toggle.hidden = true;
                if (controls) controls.hidden = false;
                applyFilters();
            }));

            applyFilters();
        })();
    </script>

    @if($related->isNotEmpty())
        <section class="lk-section lk-detail-related">
            <div class="lk-section-head">
                <div>
                    <span class="lk-kicker">
                        Keep exploring
                    </span>

                    <h2>
                        Related Products
                    </h2>

                    <p>
                        More finds you may like.
                    </p>
                </div>
            </div>

            <div class="lk-product-grid">
                @foreach($related as $relatedProduct)
                    <x-buyer.product-card
                        :product="$relatedProduct"
                        :guest="$guest"
                    />
                @endforeach
            </div>
        </section>
    @endif
</div>
