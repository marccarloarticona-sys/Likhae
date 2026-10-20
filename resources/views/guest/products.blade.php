@extends('layouts.guest')

@section('title', 'Products - LIKHAE Marketplace')

@push('head')
<style>
    .lk-guest-catalog-filters{display:flex;flex-wrap:wrap;align-items:end;gap:8px;padding:8px 10px;border:1px solid #eadbce;border-radius:14px;background:#fffdf9}
    .lk-guest-catalog-filters label{display:grid;gap:4px;color:#705c54;font-size:10px;font-weight:700}
    .lk-guest-catalog-filters input,.lk-guest-catalog-filters select{box-sizing:border-box;min-width:100px;min-height:36px;border:1px solid #d8c9bf;border-radius:9px;background:#fff;padding:7px 9px;color:#321d17;font:12px Poppins,system-ui,sans-serif}
    .lk-guest-catalog-filters label:first-of-type select{max-width:180px}
    .lk-guest-filter-clear{padding:9px 4px;color:#8d382e;font-size:11px;font-weight:700;text-decoration:underline}
    .lk-guest-catalog-filters button{min-height:36px}
    @media (max-width: 900px) {
        .lk-guest-products-page .lk-guest-products-layout {
            display: block;
        }

        .lk-guest-products-page .lk-guest-products-sidebar {
            position: relative;
            z-index: 2;
            display: block;
            width: 100%;
            margin-bottom: 16px;
        }

        .lk-guest-products-page .lk-guest-category-card {
            overflow: visible;
            padding: 14px;
        }

        .lk-guest-products-page .lk-guest-category-list {
            display: flex !important;
            grid-template-columns: none !important;
            gap: 8px;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 2px 2px 10px;
            scroll-snap-type: x proximity;
            -webkit-overflow-scrolling: touch;
        }

        .lk-guest-products-page .lk-guest-category-item {
            flex: 0 0 auto;
            min-width: 138px;
            min-height: 44px;
            scroll-snap-align: start;
            white-space: nowrap;
        }

        .lk-guest-products-page .lk-category-group {
            flex: 0 0 210px;
            scroll-snap-align: start;
        }

        .lk-guest-products-page .lk-category-group .lk-guest-category-item {
            width: 100%;
        }

        .lk-guest-products-page .lk-category-sublist {
            margin: 6px 0 0;
            padding-left: 0;
            border-left: 0;
        }
    }

    @media (max-width: 560px) {
        .lk-guest-products-page .lk-guest-category-card {
            padding: 12px;
        }

        .lk-guest-products-page .lk-guest-category-list {
            margin-inline: -2px;
        }

        .lk-guest-products-page .lk-guest-category-item {
            min-width: 128px;
            padding-inline: 12px;
        }

        .lk-guest-products-page .lk-toolbar-actions,
        .lk-guest-products-page .lk-catalog-sort,
        .lk-guest-products-page .lk-catalog-sort select {
            width: 100%;
        }

        .lk-guest-products-page .lk-guest-catalog-filters,
        .lk-guest-products-page .lk-guest-catalog-filters label,
        .lk-guest-products-page .lk-guest-catalog-filters input,
        .lk-guest-products-page .lk-guest-catalog-filters select {
            width: 100%;
        }

        .lk-guest-products-page .lk-guest-catalog-filters label:first-of-type select {
            max-width: none;
        }
    }
</style>
@endpush

@section('content')
@php
    $collection = collect($buyerProducts ?? []);
    $activeCategory = strtolower((string) request('category', 'all'));
    $activeSort = strtolower((string) request('sort', 'latest'));
    $activeView = in_array(request('view'), ['grid', 'list'], true) ? request('view') : 'grid';
    $query = trim((string) request('q', ''));
    $activeSeller = (string) request('seller_profile_id', '');
    $minimumPrice = request('min_price', '');
    $maximumPrice = request('max_price', '');
    $catalogRouteName = request()->routeIs('guest.home') ? 'guest.home' : 'products';
    $categoryCollection = collect($categories ?? []);
    $childrenByParent = $categoryCollection->whereNotNull('parent_id')->groupBy('parent_id');
    $categoryRows = $categoryCollection->whereNull('parent_id')->map(function ($category) use ($childrenByParent, $activeCategory) {
        $children = $childrenByParent->get($category->id, collect())->map(fn ($child) => [
            'slug' => $child->slug,
            'label' => $child->name,
            'count' => (int) $child->products_count,
        ])->values()->all();

        return [
            'slug' => $category->slug,
            'label' => $category->name,
            'count' => (int) $category->products_count + collect($children)->sum('count'),
            'children' => $children,
            'open' => $activeCategory === $category->slug || collect($children)->contains(fn ($child) => $child['slug'] === $activeCategory),
        ];
    })->sortBy('label')->values();
    $visibleProducts = $collection;
    $resultCount = $products->total();
    $catalogUrl = fn(array $changes = []) => route($catalogRouteName, array_filter(array_merge(request()->only(['q','category','seller_profile_id','min_price','max_price','sort','view']), $changes), fn($value) => $value !== '' && $value !== null));
@endphp

<div class="lk-page lk-guest-products-page">
    <header class="lk-page-title">
        <div><span class="lk-kicker">Marketplace</span><h1>Browse Products</h1><p>Find quality products from trusted local sellers.</p></div>
        <div class="lk-note-chip">Guest browsing only</div>
    </header>

    <div class="lk-products-layout lk-guest-products-layout">
        <aside class="lk-products-sidebar lk-guest-products-sidebar" aria-label="Product filters">
            <form action="{{ route($catalogRouteName) }}" method="GET" class="grid gap-3">
                <div class="lk-filter-card lk-guest-category-card"><div class="lk-filter-heading"><h2 id="categories-heading">Categories</h2></div><nav class="lk-category-list lk-guest-category-list"><a href="{{ $catalogUrl(['category' => 'all']) }}" class="lk-category-item lk-guest-category-item {{ $activeCategory === 'all' ? 'is-active' : '' }}"><span>All Products</span><small>{{ $products->total() }}</small></a>@foreach($categoryRows as $category)<details class="lk-category-group" @if($category['open']) open @endif><summary class="lk-category-item lk-guest-category-item {{ $activeCategory === $category['slug'] ? 'is-active' : '' }}"><span>{{ $category['label'] }}</span><small>{{ $category['count'] }}</small></summary><div class="lk-category-sublist"><a href="{{ $catalogUrl(['category' => $category['slug']]) }}" class="lk-category-subitem {{ $activeCategory === $category['slug'] ? 'is-active' : '' }}">All {{ $category['label'] }}</a>@foreach($category['children'] as $child)<a href="{{ $catalogUrl(['category' => $child['slug']]) }}" class="lk-category-subitem {{ $activeCategory === $child['slug'] ? 'is-active' : '' }}"><span>{{ $child['label'] }}</span><small>{{ $child['count'] }}</small></a>@endforeach</div></details>@endforeach</nav></div>
            </form>
        </aside>

        <main class="lk-products-main">
            <div class="lk-products-toolbar">
                <div class="lk-toolbar-info"><strong>{{ $resultCount }}</strong><span> products found</span>@if($query)<small> for "{{ $query }}"</small>@endif</div>
                <div class="lk-toolbar-actions">
                    <form action="{{ route($catalogRouteName) }}" method="GET" class="lk-guest-catalog-filters">
                        @foreach(request()->only(['q', 'category', 'sort', 'view']) as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                        <label><span>Seller</span><select name="seller_profile_id"><option value="">All sellers</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected($activeSeller === (string) $seller->id)>{{ $seller->business_name }}</option>@endforeach</select></label>
                        <label><span>Min price</span><input name="min_price" type="number" min="0" step="0.01" inputmode="decimal" value="{{ $minimumPrice }}" placeholder="Any"></label>
                        <label><span>Max price</span><input name="max_price" type="number" min="0" step="0.01" inputmode="decimal" value="{{ $maximumPrice }}" placeholder="Any"></label>
                        <button type="submit" class="lk-btn lk-btn-light">Apply</button>
                        @if($activeSeller !== '' || $minimumPrice !== '' || $maximumPrice !== '')<a href="{{ $catalogUrl(['seller_profile_id' => '', 'min_price' => '', 'max_price' => '']) }}" class="lk-guest-filter-clear">Clear</a>@endif
                    </form>
                    <form action="{{ route($catalogRouteName) }}" method="GET" class="lk-catalog-sort">@foreach(request()->except('sort', 'page') as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach<select name="sort" aria-label="Sort products" onchange="this.form.submit()"><option value="latest" @selected($activeSort === 'latest')>Newest</option><option value="oldest" @selected($activeSort === 'oldest')>Oldest</option><option value="price-low" @selected($activeSort === 'price-low')>Price: Low to High</option><option value="price-high" @selected($activeSort === 'price-high')>Price: High to Low</option><option value="best-rated" @selected($activeSort === 'best-rated')>Best Rated</option><option value="best-selling" @selected($activeSort === 'best-selling')>Best Selling</option><option value="name" @selected($activeSort === 'name')>Name</option></select></form>
                    <a href="{{ $catalogUrl(['view' => 'grid']) }}" class="lk-view-toggle {{ $activeView === 'grid' ? 'active' : '' }}" aria-label="Grid view"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></a><a href="{{ $catalogUrl(['view' => 'list']) }}" class="lk-view-toggle {{ $activeView === 'list' ? 'active' : '' }}" aria-label="List view"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></a>
                </div>
            </div>
            @if($visibleProducts->isNotEmpty())
                <div class="lk-product-grid {{ $activeView === 'list' ? 'is-list-view' : '' }}">@foreach($visibleProducts as $product)<x-buyer.product-card :product="$product" guest />@endforeach</div>
                @if($products->hasPages())<div class="mt-5">{{ $products->links() }}</div>@endif
            @else
                <x-buyer.empty-state title="No products found" message="Try another category, seller, price range, or search term." />
            @endif
        </main>
    </div>
</div>
@endsection
