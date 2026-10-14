@extends('layouts.buyer')

@section('title', 'Wishlist — LIKHAE')
@section('active', 'wishlist')

@php
    /*
    |--------------------------------------------------------------------------
    | Wishlist collection
    |--------------------------------------------------------------------------
    */

    if (isset($wishlistProducts)) {
        if (method_exists($wishlistProducts, 'items')) {
            $wishlist = collect($wishlistProducts->items());
        } else {
            $wishlist = collect($wishlistProducts);
        }
    } elseif (isset($buyerProducts)) {
        if (method_exists($buyerProducts, 'items')) {
            $wishlist = collect($buyerProducts->items())->take(4);
        } else {
            $wishlist = collect($buyerProducts)->take(4);
        }
    } else {
        $wishlist = collect();
    }

    /*
    |--------------------------------------------------------------------------
    | Search and sorting
    |--------------------------------------------------------------------------
    */

    $search = trim((string) request('search', ''));

    $allowedSorts = [
        'recent',
        'price-low',
        'price-high',
        'best-rated',
    ];

    $requestedSort = strtolower((string) request('sort', 'recent'));

    $activeSort = in_array($requestedSort, $allowedSorts, true)
        ? $requestedSort
        : 'recent';

    $sortOptions = [
        'recent' => 'Recently Added',
        'price-low' => 'Price: Low to High',
        'price-high' => 'Price: High to Low',
        'best-rated' => 'Best Rated',
    ];

    $visibleWishlist = $wishlist->filter(function ($product) use ($search) {
        if ($search === '') {
            return true;
        }

        $categoryValue = data_get($product, 'category.name')
            ?? data_get($product, 'category')
            ?? '';

        $sellerValue = data_get($product, 'seller.store_name')
            ?? data_get($product, 'seller.shop_name')
            ?? data_get($product, 'seller.name')
            ?? data_get($product, 'seller')
            ?? '';

        $category = is_scalar($categoryValue)
            ? (string) $categoryValue
            : '';

        $seller = is_scalar($sellerValue)
            ? (string) $sellerValue
            : '';

        $searchableText = mb_strtolower(
            trim(
                (string) data_get($product, 'name', '') . ' ' .
                $category . ' ' .
                $seller
            )
        );

        return str_contains(
            $searchableText,
            mb_strtolower($search)
        );
    });

    $visibleWishlist = match ($activeSort) {
        'price-low' => $visibleWishlist->sortBy(
            fn ($product) => (float) data_get($product, 'price', 0)
        ),

        'price-high' => $visibleWishlist->sortByDesc(
            fn ($product) => (float) data_get($product, 'price', 0)
        ),

        'best-rated' => $visibleWishlist->sortByDesc(
            fn ($product) => (float) data_get($product, 'rating', 0)
        ),

        default => $visibleWishlist->sortByDesc(
            fn ($product) =>
                data_get($product, 'wishlist_added_at')
                ?? data_get($product, 'created_at')
                ?? data_get($product, 'id', 0)
        ),
    };

    $visibleWishlist = $visibleWishlist->values();
@endphp

@push('head')
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
        --lk-maroon-dark: #3E130F;

        --lk-text: #3B211B;
        --lk-brown: #6C4936;
        --lk-muted: #987865;
        --lk-muted-2: #A99386;

        --lk-tan: #C19771;
        --lk-danger: #B42318;

        --lk-shadow-soft: 0 8px 24px rgba(86, 28, 23, 0.055);
        --lk-shadow-card: 0 16px 40px rgba(86, 28, 23, 0.09);
    }

    .lk-wishlist-page {
        display: grid;
        gap: 22px;
    }

    .lk-wishlist-hero {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 22px;

        padding: 32px 36px;

        border: 1px solid var(--lk-border);
        border-radius: 24px;

        background:
            radial-gradient(circle at 92% 12%, rgba(193, 151, 113, 0.24), transparent 28%),
            linear-gradient(135deg, var(--lk-card) 0%, var(--lk-bg-soft) 58%, var(--lk-bg-alt) 100%);

        box-shadow: var(--lk-shadow-soft);
    }

    .lk-wishlist-hero .lk-kicker {
        color: var(--lk-maroon) !important;
    }

    .lk-wishlist-hero h1 {
        margin: 8px 0 0;

        color: var(--lk-text);

        font-family: "Instrument Serif", Georgia, serif;
        font-size: clamp(42px, 5vw, 70px);
        font-weight: 400;
        line-height: 0.92;
        letter-spacing: -0.045em;
    }

    .lk-wishlist-hero h1 span {
        color: var(--lk-maroon);
        font-style: italic;
    }

    .lk-wishlist-hero p {
        max-width: 560px;
        margin: 13px 0 0;

        color: var(--lk-muted);

        font-size: 13px;
        line-height: 1.75;
    }

    .lk-wishlist-summary {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 15px;

        padding: 18px 20px;

        border: 1px solid var(--lk-border);
        border-radius: 20px;

        background:
            radial-gradient(circle at 0% 0%, rgba(193, 151, 113, 0.15), transparent 28%),
            linear-gradient(135deg, #FFFDF9 0%, #FFF9F2 100%);

        box-shadow: var(--lk-shadow-soft);
    }

    .lk-wishlist-summary-icon,
    .lk-wishlist-empty-icon {
        display: grid;
        place-items: center;

        width: 56px;
        height: 56px;

        border-radius: 18px;

        background: #F1E4D7;
        color: var(--lk-maroon);
    }

    .lk-wishlist-summary-icon svg,
    .lk-wishlist-empty-icon svg {
        width: 25px;
        height: 25px;

        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .lk-wishlist-summary strong {
        display: block;

        color: var(--lk-text);

        font-size: 14px;
        font-weight: 950;
        letter-spacing: -0.025em;
    }

    .lk-wishlist-summary span {
        display: block;
        margin-top: 3px;

        color: var(--lk-muted);

        font-size: 12px;
        line-height: 1.55;
    }

    .lk-wishlist-clear {
        min-height: 40px;
        padding: 0 15px;

        border: 1px solid #E6B8AD;
        border-radius: 12px;

        background: #FFFDF9;
        color: var(--lk-danger);

        font-size: 11px;
        font-weight: 900;

        transition: 160ms ease;
    }

    .lk-wishlist-clear:hover {
        background: #F6E3DE;
        border-color: var(--lk-danger);
        transform: translateY(-1px);
    }

    .lk-wishlist-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;

        padding: 14px;

        border: 1px solid var(--lk-border);
        border-radius: 20px;

        background: var(--lk-card);
        box-shadow: var(--lk-shadow-soft);
    }

    .lk-wishlist-search {
        display: flex;
        min-width: 0;
        flex: 1;
        gap: 10px;
    }

    .lk-wishlist-search-field {
        position: relative;

        display: flex;
        min-width: 220px;
        max-width: 520px;
        flex: 1;
        align-items: center;

        min-height: 42px;
        padding: 0 42px 0 42px;

        border: 1px solid var(--lk-border);
        border-radius: 14px;

        background: var(--lk-bg-soft);

        transition: 160ms ease;
    }

    .lk-wishlist-search-field:focus-within {
        background: #FFFFFF;
        border-color: var(--lk-tan);
        box-shadow: 0 0 0 4px rgba(86, 28, 23, 0.08);
    }

    .lk-wishlist-search-field > svg {
        position: absolute;
        left: 14px;

        width: 17px;
        height: 17px;

        color: var(--lk-muted-2);

        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .lk-wishlist-search-field input {
        width: 100%;
        min-width: 0;

        border: 0;
        outline: 0;

        background: transparent;
        color: var(--lk-text);

        font-size: 12px;
        font-weight: 700;
    }

    .lk-wishlist-search-field input::placeholder {
        color: var(--lk-muted-2);
    }

    .lk-wishlist-search-clear {
        position: absolute;
        right: 10px;

        display: grid;
        place-items: center;

        width: 26px;
        height: 26px;

        border-radius: 999px;

        color: var(--lk-muted);
        text-decoration: none;

        transition: 160ms ease;
    }

    .lk-wishlist-search-clear:hover {
        background: #F3E4DE;
        color: var(--lk-maroon);
    }

    .lk-wishlist-search-clear svg {
        width: 15px;
        height: 15px;

        fill: none;
        stroke: currentColor;
        stroke-width: 1.9;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .lk-wishlist-sort {
        display: flex;
        align-items: center;
        gap: 9px;
    }

    .lk-wishlist-sort label {
        color: var(--lk-muted);

        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
    }

    .lk-wishlist-sort select {
        min-height: 42px;
        min-width: 170px;
        padding: 0 12px;

        border: 1px solid var(--lk-border);
        border-radius: 14px;

        background: var(--lk-bg-soft);
        color: var(--lk-text);

        font-size: 11px;
        font-weight: 800;
        outline: 0;
    }

    .lk-wishlist-sort select:focus {
        border-color: var(--lk-tan);
        box-shadow: 0 0 0 4px rgba(86, 28, 23, 0.08);
    }

    .lk-wishlist-sort-button {
        min-height: 42px;
        padding: 0 14px;

        border: 1px solid var(--lk-maroon);
        border-radius: 14px;

        background: var(--lk-maroon);
        color: #FFFFFF;

        font-size: 11px;
        font-weight: 900;

        transition: 160ms ease;
    }

    .lk-wishlist-sort-button:hover {
        background: var(--lk-maroon-dark);
        border-color: var(--lk-maroon-dark);
        transform: translateY(-1px);
    }

    .lk-wishlist-result {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;

        padding: 0 2px;

        color: var(--lk-muted);

        font-size: 12px;
        font-weight: 700;
    }

    .lk-wishlist-result strong {
        color: var(--lk-maroon);
        font-weight: 950;
    }

    .lk-wishlist-result span:last-child {
        color: var(--lk-brown);
    }

    .lk-wishlist-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }

    .lk-wishlist-item {
        min-width: 0;
        height: 100%;
    }

    .lk-wishlist-item .lk-product-heart {
        color: var(--lk-maroon) !important;
    }

    .lk-wishlist-empty {
        display: flex;
        min-height: 420px;
        align-items: center;
        justify-content: center;
        flex-direction: column;

        padding: 48px 24px;

        border: 1px dashed var(--lk-border-strong);
        border-radius: 24px;

        background:
            radial-gradient(circle at 50% 0%, rgba(193, 151, 113, 0.14), transparent 30%),
            var(--lk-card);

        text-align: center;
        box-shadow: var(--lk-shadow-soft);
    }

    .lk-wishlist-empty h2 {
        margin: 18px 0 0;

        color: var(--lk-text);

        font-size: 20px;
        font-weight: 950;
        letter-spacing: -0.035em;
    }

    .lk-wishlist-empty p {
        max-width: 460px;
        margin: 8px auto 0;

        color: var(--lk-muted);

        font-size: 13px;
        line-height: 1.7;
    }

    .lk-wishlist-empty .lk-btn {
        margin-top: 22px;
    }

    @media (max-width: 1180px) {
        .lk-wishlist-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 900px) {
        .lk-wishlist-hero {
            align-items: flex-start;
            flex-direction: column;
            padding: 28px 22px;
        }

        .lk-wishlist-toolbar {
            align-items: stretch;
            flex-direction: column;
        }

        .lk-wishlist-search,
        .lk-wishlist-sort {
            width: 100%;
        }

        .lk-wishlist-search-field {
            max-width: none;
        }

        .lk-wishlist-sort {
            justify-content: space-between;
        }

        .lk-wishlist-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .lk-wishlist-summary {
            grid-template-columns: auto minmax(0, 1fr);
        }

        .lk-wishlist-clear {
            grid-column: 1 / -1;
            width: 100%;
        }

        .lk-wishlist-search,
        .lk-wishlist-sort {
            align-items: stretch;
            flex-direction: column;
        }

        .lk-wishlist-sort select,
        .lk-wishlist-sort-button,
        .lk-wishlist-search .lk-btn {
            width: 100%;
        }

        .lk-wishlist-grid {
            grid-template-columns: 1fr;
        }
    }

    html.dark .lk-wishlist-hero,
    html.dark .lk-wishlist-summary,
    html.dark .lk-wishlist-toolbar,
    html.dark .lk-wishlist-empty {
        background: #211B17 !important;
        border-color: #3B2E27 !important;
        color: #F5EFE8 !important;
    }

    html.dark .lk-wishlist-hero h1,
    html.dark .lk-wishlist-summary strong,
    html.dark .lk-wishlist-empty h2 {
        color: #F5EFE8 !important;
    }

    html.dark .lk-wishlist-hero p,
    html.dark .lk-wishlist-summary span,
    html.dark .lk-wishlist-result,
    html.dark .lk-wishlist-empty p {
        color: #C8B7AD !important;
    }

    html.dark .lk-wishlist-summary-icon,
    html.dark .lk-wishlist-empty-icon {
        background: #2D1414 !important;
        color: #EBA99D !important;
    }

    html.dark .lk-wishlist-search-field,
    html.dark .lk-wishlist-sort select {
        background: #1E1A17 !important;
        border-color: #3B2E27 !important;
        color: #F5EFE8 !important;
    }

    html.dark .lk-wishlist-search-field input {
        color: #F5EFE8 !important;
    }

    html.dark .lk-wishlist-sort-button {
        background: #8A3A2F !important;
        border-color: #8A3A2F !important;
    }

    html.dark .lk-wishlist-clear {
        background: #1E1A17 !important;
        border-color: #60463A !important;
        color: #EBA99D !important;
    }
</style>
@endpush

@section('content')
<div class="lk-page lk-wishlist-page">
    <header class="lk-wishlist-hero">
        <div>
            <span class="lk-kicker">
                Saved Products
            </span>

            <h1>
                Wishlist<span>.</span>
            </h1>

            <p>
                Keep your favorite LIKHAE products in one place and revisit them anytime.
                Prices and availability may change while items are saved.
            </p>
        </div>

        <a
            href="{{ route('buyer.products') }}"
            class="lk-btn lk-btn-light"
        >
            <svg
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path d="M6 8h12l1 13H5z"/>
                <path d="M9 10V6a3 3 0 0 1 6 0v4"/>
            </svg>

            Continue Shopping
        </a>
    </header>

    @if ($wishlist->isNotEmpty())
        <section class="lk-wishlist-summary">
            <div class="lk-wishlist-summary-icon">
                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z"/>
                </svg>
            </div>

            <div>
                <strong>
                    {{ $wishlist->count() }}
                    {{ $wishlist->count() === 1 ? 'saved product' : 'saved products' }}
                </strong>

                <span>
                    Your saved products are ready whenever you want to compare, review, or purchase.
                </span>
            </div>

            <form method="POST" action="{{ route('buyer.wishlist.clear') }}" data-confirm-title="Clear wishlist?" data-confirm-message="All saved products will be removed from your wishlist." data-confirm-label="Clear wishlist" data-confirm-tone="danger">
                @csrf
                @method('DELETE')
                <button type="submit" class="lk-wishlist-clear">
                    Clear Wishlist
                </button>
            </form>
        </section>

        <section class="lk-wishlist-toolbar">
            <form
                method="GET"
                action="{{ route('buyer.wishlist') }}"
                class="lk-wishlist-search"
            >
                <input
                    type="hidden"
                    name="sort"
                    value="{{ $activeSort }}"
                >

                <div class="lk-wishlist-search-field">
                    <svg
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-3.5-3.5"/>
                    </svg>

                    <input
                        type="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search saved products..."
                    >

                    @if ($search !== '')
                        <a
                            href="{{ route('buyer.wishlist', ['sort' => $activeSort]) }}"
                            aria-label="Clear search"
                            class="lk-wishlist-search-clear"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path d="m6 6 12 12M18 6 6 18"/>
                            </svg>
                        </a>
                    @endif
                </div>

                <button
                    type="submit"
                    class="lk-btn lk-btn-red"
                >
                    Search
                </button>
            </form>

            <form
                method="GET"
                action="{{ route('buyer.wishlist') }}"
                class="lk-wishlist-sort"
            >
                @if ($search !== '')
                    <input
                        type="hidden"
                        name="search"
                        value="{{ $search }}"
                    >
                @endif

                <label for="wishlist-sort">
                    Sort by
                </label>

                <select
                    id="wishlist-sort"
                    name="sort"
                >
                    @foreach ($sortOptions as $sortKey => $sortLabel)
                        <option
                            value="{{ $sortKey }}"
                            @selected($activeSort === $sortKey)
                        >
                            {{ $sortLabel }}
                        </option>
                    @endforeach
                </select>

                <button
                    type="submit"
                    class="lk-wishlist-sort-button"
                >
                    Apply
                </button>
            </form>
        </section>

        <div class="lk-wishlist-result">
            <span>
                Showing
                <strong>{{ $visibleWishlist->count() }}</strong>
                of
                <strong>{{ $wishlist->count() }}</strong>
                saved products
            </span>

            @if ($search !== '')
                <span>
                    Results for “{{ $search }}”
                </span>
            @endif
        </div>

        @if ($visibleWishlist->isNotEmpty())
            <section
                class="lk-product-grid lk-wishlist-grid"
                aria-label="Saved products"
                data-wishlist-grid
            >
                @foreach ($visibleWishlist as $product)
                    <div
                        class="lk-wishlist-item"
                        data-wishlist-item
                        data-product-id="{{ data_get($product, 'id', data_get($product, 'slug', '')) }}"
                    >
                        <x-buyer.product-card
                            :product="$product"
                        />
                    </div>
                @endforeach
            </section>
        @else
            <section class="lk-wishlist-empty">
                <div class="lk-wishlist-empty-icon">
                    <svg
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-3.5-3.5"/>
                        <path d="M8.5 11h5"/>
                    </svg>
                </div>

                <h2>
                    No matching saved products
                </h2>

                <p>
                    We could not find a wishlist product matching “{{ $search }}”.
                </p>

                <a
                    href="{{ route('buyer.wishlist') }}"
                    class="lk-btn lk-btn-light"
                >
                    Clear Search
                </a>
            </section>
        @endif
    @else
        <section class="lk-wishlist-empty">
            <div class="lk-wishlist-empty-icon">
                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z"/>
                </svg>
            </div>

            <h2>
                Your wishlist is empty
            </h2>

            <p>
                Save products you love by selecting the heart icon on any product card.
            </p>

            <a
                href="{{ route('buyer.products') }}"
                class="lk-btn lk-btn-red"
            >
                Explore Products
            </a>
        </section>
    @endif
</div>
@endsection
