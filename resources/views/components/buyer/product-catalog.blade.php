@props(['products', 'categories' => collect(), 'catalogTotal' => null, 'paginator' => null, 'catalogLocations' => collect(), 'searchSuggestions' => [], 'catalogFilterValues' => [], 'catalogQueryParameters' => [], 'catalogErrors' => null, 'catalogPriceRange' => [], 'guest' => false, 'title' => 'Products', 'subtitle' => 'Explore quality products from trusted local sellers.'])

@php
    $collection = collect($products ?? []);
    $routeName = $guest ? 'home' : 'buyer.products';
    $catalogFilterValues = (array) ($catalogFilterValues ?? []);
    $activeCategory = strtolower((string) ($catalogFilterValues['category'] ?? 'all'));
    $activeSort = strtolower((string) ($catalogFilterValues['sort'] ?? 'latest'));
    $activeView = in_array($catalogFilterValues['view'] ?? null, ['grid', 'list'], true) ? $catalogFilterValues['view'] : 'grid';
    $query = mb_strtolower(trim((string) ($catalogFilterValues['q'] ?? '')));
    $minimumPrice = $catalogFilterValues['min_price'] ?? '';
    $maximumPrice = $catalogFilterValues['max_price'] ?? '';
    $minimumRating = min(5, max(0, (float) ($catalogFilterValues['rating'] ?? 0)));
    $activeProvince = trim((string) ($catalogFilterValues['province'] ?? ''));
    $activeMunicipality = trim((string) ($catalogFilterValues['municipality'] ?? ''));
    $availableLocations = collect($catalogLocations ?? []);
    $availableProvinces = $availableLocations->pluck('province')->filter()->unique()->sort()->values();
    $availableMunicipalities = $availableLocations->pluck('municipality')->filter()->unique()->sort()->values();
    $searchSuggestions = collect($searchSuggestions ?? []);
    $queryParameters = (array) ($catalogQueryParameters ?? []);
    $generalErrorKey = collect(['q', 'search', 'category', 'sort', 'view', 'page'])
        ->first(fn ($key) => $catalogErrors?->has($key));
    $productCategorySlug = fn ($product) => (string) (data_get($product, 'category.slug') ?: 'uncategorized');
    $categoryCollection = collect($categories ?? []);
    $childrenByParent = $categoryCollection->whereNotNull('parent_id')->groupBy('parent_id');
    $categoryRows = $categoryCollection->whereNull('parent_id')->map(function ($category) use ($childrenByParent, $activeCategory) {
        $children = $childrenByParent->get($category->id, collect())->map(fn ($child) => [
            'slug' => $child->slug,
            'label' => $child->name,
            'count' => (int) $child->products_count,
        ])->values()->all();
        $descendantCount = collect($children)->sum('count');

        return [
            'slug' => $category->slug,
            'label' => $category->name,
            'count' => (int) $category->products_count + $descendantCount,
            'children' => $children,
            'open' => $activeCategory === $category->slug || collect($children)->contains(fn ($child) => $child['slug'] === $activeCategory),
        ];
    })->sortBy('label')->values();
    $visibleProducts = $collection;
    $totalCatalogProducts = $catalogTotal ?? $collection->count();
    $resultCount = $paginator ? $paginator->total() : $collection->count();
    $catalogUrl = fn (array $changes = []) => route($routeName, array_filter(array_merge($queryParameters, $changes), fn ($value) => $value !== '' && $value !== null));
@endphp

<div class="lk-page">
    <header class="lk-page-title">
        <div><span class="lk-kicker">Marketplace</span><h1>{{ $title }}</h1><p>{{ $subtitle }}</p></div>
        @if($guest)<div class="lk-note-chip">Browse freely &middot; Sign in only when you are ready to buy</div>@endif
    </header>

    <div class="lk-products-layout">
        <aside class="lk-products-sidebar" aria-label="Product filters">
            <form action="{{ route($routeName) }}" method="GET" class="grid gap-3" data-live-filters>
                @foreach($queryParameters as $key => $value)
                    @if(in_array($key, ['q', 'category', 'sort', 'view'], true))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <div class="lk-filter-card"><div class="lk-filter-heading"><h2>Categories</h2></div><nav class="lk-category-list"><a href="{{ $catalogUrl(['category' => 'all']) }}" class="lk-category-item {{ $activeCategory === 'all' ? 'is-active' : '' }}"><span>All Products</span><small>{{ $totalCatalogProducts }}</small></a>@foreach($categoryRows as $category)<details class="lk-category-group" @if($category['open']) open @endif><summary class="lk-category-item {{ $activeCategory === $category['slug'] ? 'is-active' : '' }}"><span>{{ $category['label'] }}</span><small>{{ $category['count'] }}</small></summary><div class="lk-category-sublist"><a href="{{ $catalogUrl(['category' => $category['slug']]) }}" class="lk-category-subitem {{ $activeCategory === $category['slug'] ? 'is-active' : '' }}">All {{ $category['label'] }}</a>@foreach($category['children'] as $child)<a href="{{ $catalogUrl(['category' => $child['slug']]) }}" class="lk-category-subitem {{ $activeCategory === $child['slug'] ? 'is-active' : '' }}"><span>{{ $child['label'] }}</span><small>{{ $child['count'] }}</small></a>@endforeach</div></details>@endforeach</nav></div>
                <x-buyer.price-range-filter prefix="catalog" :minimum="$minimumPrice" :maximum="$maximumPrice" :price-range="$catalogPriceRange" :errors="$catalogErrors" />
                <div class="lk-filter-card">
                    <label class="lk-field-label" for="catalogRating">Minimum rating</label>
                    <select id="catalogRating" name="rating" class="lk-filter-select"><option value="0">Any rating</option>@foreach([4.5,4,3] as $rating)<option value="{{ $rating }}" @selected($minimumRating == $rating)>{{ number_format($rating,1) }}&#9733; and above</option>@endforeach</select>
                    @if($catalogErrors?->has('rating'))<p class="lk-filter-error" role="alert">{{ $catalogErrors->first('rating') }}</p>@endif
                </div>
                <div class="lk-filter-card">
                    <div class="lk-filter-heading"><h2>Store location</h2></div>
                    <label class="lk-field-label" for="catalogProvince">Province</label>
                    <input id="catalogProvince" name="province" type="search" list="catalogProvinces" value="{{ $activeProvince }}" placeholder="Any province" class="lk-filter-select" autocomplete="off">
                    <datalist id="catalogProvinces">@foreach($availableProvinces as $province)<option value="{{ $province }}">@endforeach</datalist>
                    @if($catalogErrors?->has('province'))<p class="lk-filter-error" role="alert">{{ $catalogErrors->first('province') }}</p>@endif
                    <label class="lk-field-label mt-3" for="catalogMunicipality">Town or city</label>
                    <input id="catalogMunicipality" name="municipality" type="search" list="catalogMunicipalities" value="{{ $activeMunicipality }}" placeholder="Any town or city" class="lk-filter-select" autocomplete="off">
                    <datalist id="catalogMunicipalities">@foreach($availableMunicipalities as $municipality)<option value="{{ $municipality }}">@endforeach</datalist>
                    @if($catalogErrors?->has('municipality'))<p class="lk-filter-error" role="alert">{{ $catalogErrors->first('municipality') }}</p>@endif
                </div>
                <div class="lk-filter-card grid gap-2">
                    <a class="lk-btn lk-btn-light lk-btn-full" href="{{ route($routeName) }}">Clear Filters</a>
                </div>
            </form>
        </aside>

        <main class="lk-products-main">
            <div class="lk-products-toolbar">
                <div class="lk-toolbar-info"><strong>{{ $resultCount }}</strong><span> products found</span>@if($query)<small> for "{{ $catalogFilterValues['q'] }}"</small>@endif</div>
                <div class="lk-toolbar-actions">
                    <form action="{{ route($routeName) }}" method="GET" class="lk-catalog-sort">@foreach($queryParameters as $key => $value)@if($key !== 'sort')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<select name="sort" onchange="this.form.submit()"><option value="latest" @selected($activeSort === 'latest')>Latest</option><option value="price-low" @selected($activeSort === 'price-low')>Price: Low to High</option><option value="price-high" @selected($activeSort === 'price-high')>Price: High to Low</option><option value="best-rated" @selected($activeSort === 'best-rated')>Best Rated</option><option value="best-selling" @selected($activeSort === 'best-selling')>Best Selling</option></select></form>
                    <a href="{{ $catalogUrl(['view' => 'grid']) }}" class="lk-view-toggle {{ $activeView === 'grid' ? 'active' : '' }}" aria-label="Grid view"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></a><a href="{{ $catalogUrl(['view' => 'list']) }}" class="lk-view-toggle {{ $activeView === 'list' ? 'active' : '' }}" aria-label="List view"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></a>
                </div>
            </div>
            @if($generalErrorKey)
                <p class="lk-filter-error" role="alert">{{ $catalogErrors->first($generalErrorKey) }}</p>
            @endif
            @if($visibleProducts->isNotEmpty())
                <div class="lk-product-grid {{ $activeView === 'list' ? 'is-list-view' : '' }}">@foreach($visibleProducts as $product)<x-buyer.product-card :product="$product" :guest="$guest" catalog-listing />@endforeach</div>
            @else
                <x-buyer.empty-state title="No products found" message="Try another category, search term, price, or rating filter." />
                @if($query && $searchSuggestions->isNotEmpty())
                    <p class="lk-search-suggestions" aria-live="polite">Did you mean: @foreach($searchSuggestions as $index => $suggestion)<a href="{{ $catalogUrl(['q' => $suggestion]) }}">{{ $suggestion }}</a>{{ $index < $searchSuggestions->count() - 1 ? ', ' : '' }}@endforeach</p>
                @endif
            @endif
            @if($paginator && $paginator->hasPages())
                <div class="mt-5">{{ $paginator->links() }}</div>
            @endif
        </main>
    </div>
</div>
