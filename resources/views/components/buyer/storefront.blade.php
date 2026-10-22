@props(['seller', 'products' => collect(), 'guest' => false, 'paginator' => null, 'storeProductCount' => null, 'storeFilterValues' => [], 'storeErrors' => null, 'storePriceRange' => [], 'storeCategories' => collect(), 'storeSubcategories' => collect(), 'storeVouchers' => collect()])

@php
    $sellerSlug = data_get($seller, 'id', 'store');
    $sellerName = data_get($seller, 'business_name', 'LIKHAE Store');
    $sellerAvatar = data_get($seller, 'avatar_path');
    $sellerBanner = data_get($seller, 'banner_path');
    $storeProducts = collect($products)->filter(function ($product) use ($sellerSlug) {
        return (string) data_get($product, 'seller.id') === (string) $sellerSlug;
    })->values();
    $storeFilterValues = (array) ($storeFilterValues ?? []);
    $query = (string) ($storeFilterValues['q'] ?? '');
    $minimumPrice = $storeFilterValues['min_price'] ?? '';
    $maximumPrice = $storeFilterValues['max_price'] ?? '';
    $minimumRating = (float) ($storeFilterValues['rating'] ?? 0);
    $activeSort = $storeFilterValues['sort'] ?? 'latest';
    $searchAction = $guest ? route('shops.show', ['seller' => $sellerSlug]) : route('buyer.shop', ['seller' => $sellerSlug]);
    $isFollowing = (bool) data_get($seller, 'is_following', false);
    $followerCount = max(0, (int) data_get($seller, 'followers', 0));
    $sellerRating = data_get($seller, 'rating');
    $sellerRatingCount = max(0, (int) data_get($seller, 'rating_count', 0));
    $totalStoreProducts = max(0, (int) ($storeProductCount ?? $paginator?->total() ?? $storeProducts->count()));
@endphp

<div class="lk-page">
    <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
        <div class="lk-store-banner relative h-32 overflow-hidden bg-gradient-to-r from-red-950 via-red-900 to-stone-900"><x-shared.profile-image :path="$sellerBanner" role="store-banner" class="absolute inset-0 h-full w-full object-cover" :alt="$sellerName.' banner'" /><div class="lk-store-banner__scrim absolute inset-0 bg-gradient-to-r from-red-950/30 to-transparent" aria-hidden="true"></div></div>
        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-end sm:p-6">
            <x-shared.profile-image :path="$sellerAvatar" role="seller" class="-mt-14 h-24 w-24 rounded-2xl border-4 border-white bg-white object-cover shadow-sm" :alt="$sellerName" />
            <div class="min-w-0 flex-1"><span class="text-[10px] font-bold uppercase tracking-widest text-red-800">Verified LIKHAE store</span><h1 class="truncate text-2xl font-bold text-stone-950">{{ $sellerName }}</h1>@if(data_get($seller, 'tagline'))<p class="mt-1 text-sm text-stone-600">{{ data_get($seller, 'tagline') }}</p>@endif<p class="mt-1 text-xs text-stone-500">{{ data_get($seller, 'location') }} · Joined {{ data_get($seller, 'joined') }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if($guest)
                    <a href="{{ route('login') }}" class="lk-btn lk-btn-red">Follow</a>
                @else
                    <form method="POST" action="{{ route($isFollowing ? 'buyer.shop.unfollow' : 'buyer.shop.follow', ['seller' => $sellerSlug]) }}">
                        @csrf
                        @if($isFollowing) @method('DELETE') @endif
                        <button type="submit" class="lk-btn {{ $isFollowing ? 'lk-btn-light' : 'lk-btn-red' }}" aria-pressed="{{ $isFollowing ? 'true' : 'false' }}" data-store-follow>
                            {{ $isFollowing ? 'Unfollow' : 'Follow' }}
                        </button>
                    </form>
                @endif
                @if($guest)
                    <button type="button" class="lk-btn lk-btn-light" data-auth-required data-auth-message="Sign in to message this store.">Message</button>
                @else
                    <a href="{{ route('buyer.messages', ['seller' => $sellerSlug]) }}" class="lk-btn lk-btn-light">Message</a>
                @endif
            </div>
        </div>
        <dl class="grid grid-cols-3 divide-x divide-stone-200 border-t border-stone-200 bg-stone-50 text-center">
            <div class="px-2 py-3 sm:px-4" data-store-stat="products"><dd class="text-lg font-bold text-stone-900">{{ number_format($totalStoreProducts) }}</dd><dt class="text-[11px] font-medium text-stone-500">Products</dt></div>
            <div class="px-2 py-3 sm:px-4" data-store-stat="rating"><dd class="text-lg font-bold text-stone-900">{{ $sellerRating !== null ? number_format((float) $sellerRating, 1) : '—' }}</dd><dt class="text-[11px] font-medium text-stone-500">{{ number_format($sellerRatingCount) }} {{ $sellerRatingCount === 1 ? 'rating' : 'ratings' }}</dt></div>
            <div class="px-2 py-3 sm:px-4" data-store-stat="followers"><dd class="text-lg font-bold text-stone-900" data-store-follower-count>{{ number_format($followerCount) }}</dd><dt class="text-[11px] font-medium text-stone-500">{{ $followerCount === 1 ? 'follower' : 'followers' }}</dt></div>
        </dl>
        <div class="grid gap-4 border-t border-stone-200 p-5 md:grid-cols-[1fr_300px] md:p-6"><div><h2 class="text-base font-bold text-stone-900">About this store</h2><p class="mt-2 text-sm leading-6 text-stone-600">{{ data_get($seller, 'description') }}</p></div><dl class="space-y-2 text-xs"><div class="flex justify-between gap-3"><dt class="text-stone-500">Location</dt><dd class="font-medium text-stone-800">{{ data_get($seller, 'location') }}</dd></div><div class="flex justify-between gap-3"><dt class="text-stone-500">Business hours</dt><dd class="font-medium text-stone-800">{{ data_get($seller, 'business_hours') }}</dd></div></dl></div>
    </section>

    @if((bool) data_get($seller, 'vacation_mode'))
        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            This store is temporarily on vacation and is not accepting new orders. You can still browse its products.
        </div>
    @endif

    @if(collect($storeVouchers)->isNotEmpty())
        <section class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 sm:p-5" aria-label="Seller vouchers">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-red-800">Shop promotion</span>
                    <h2 class="mt-1 text-base font-bold text-red-950">Vouchers available for this store</h2>
                    <p class="mt-1 text-xs text-red-900/80">Voucher discounts are confirmed at checkout after eligible items and minimum spend are checked.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach($storeVouchers as $storeVoucher)
                        <span class="rounded-full border border-red-200 bg-white px-3 py-1.5 text-[11px] font-bold text-red-900">
                            @if(strtoupper((string) $storeVoucher->discount_type) === 'PERCENT')
                                {{ rtrim(rtrim(number_format((float) $storeVoucher->discount_value, 2, '.', ''), '0'), '.') }}% OFF
                            @else
                                &#8369;{{ number_format((float) $storeVoucher->discount_value, 2) }} OFF
                            @endif
                            voucher{{ ($storeVoucher->selected_for_store ?? false) ? ' selected' : ' available' }}
                            @if((float) $storeVoucher->minimum_order_amount > 0)
                                &middot; Min. &#8369;{{ number_format((float) $storeVoucher->minimum_order_amount, 2) }}
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="lk-store-catalog mt-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm sm:p-5">
        <form method="GET" action="{{ $searchAction }}" class="lk-store-filters" aria-label="Filter this store's products" data-live-filters data-marketplace-search data-suggestions-url="{{ route('marketplace.search-suggestions') }}" data-suggestion-seller="{{ $sellerSlug }}">
            <div class="lk-store-toolbar">
                <div class="lk-store-toolbar__copy">
                    <span class="lk-kicker">Store collection</span>
                    <h2>Products by {{ $sellerName }}</h2>
                </div>
                <div class="lk-store-search-anchor flex min-w-0 gap-2">
                    <label class="sr-only" for="storeSearch">Search this store</label>
                    <input id="storeSearch" type="search" name="q" value="{{ $query }}" class="min-w-0 rounded-xl border border-stone-300 px-3 py-2 text-xs outline-none focus:border-red-800" placeholder="Search this store" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="storeSearchSuggestions">
                    <button class="lk-btn lk-btn-light" type="submit">Search</button>
                    <div id="storeSearchSuggestions" class="lk-search-dropdown" data-market-search-dropdown hidden>
                        <p class="lk-search-dropdown__heading" data-market-search-heading></p>
                        <div class="lk-search-dropdown__options" role="listbox" aria-label="Store product suggestions" data-market-search-options></div>
                        <p class="lk-search-dropdown__empty" data-market-search-empty hidden></p>
                        <button type="button" class="lk-search-dropdown__clear" data-market-search-clear hidden>Clear recent searches</button>
                    </div>
                </div>
            </div>

            <div class="lk-store-filter-grid mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-buyer.price-range-filter prefix="store" :minimum="$minimumPrice" :maximum="$maximumPrice" :price-range="$storePriceRange" :errors="$storeErrors" class="sm:col-span-2 lg:col-span-2" />
                <div class="lk-filter-card lk-store-filter-field">
                    <label class="lk-field-label" for="storeRating">Minimum rating</label>
                    <select id="storeRating" name="rating" class="lk-filter-select">
                        <option value="0" @selected($minimumRating == 0)>All Ratings</option>
                        @foreach([4,3,2,1] as $rating)
                            <option value="{{ $rating }}" @selected($minimumRating == $rating)>{{ $rating }} Stars &amp; Up</option>
                        @endforeach
                    </select>
                </div>
                <div class="lk-filter-card lk-store-filter-field">
                    <label class="lk-field-label" for="storeSort">Sort by</label>
                    <select id="storeSort" name="sort" class="lk-filter-select">
                        <option value="latest" @selected($activeSort === 'latest')>Latest</option>
                        <option value="price-low" @selected($activeSort === 'price-low')>Price: Low to High</option>
                        <option value="price-high" @selected($activeSort === 'price-high')>Price: High to Low</option>
                        <option value="best-rated" @selected($activeSort === 'best-rated')>Best Rated</option>
                        <option value="best-selling" @selected($activeSort === 'best-selling')>Best Selling</option>
                    </select>
                </div>
                @if($storeErrors?->has('min_price') || $storeErrors?->has('max_price'))
                    <p class="lk-filter-error sm:col-span-2 lg:col-span-4" role="alert">{{ $storeErrors->first('min_price') ?: $storeErrors->first('max_price') }}</p>
                @endif
                @if($storeErrors?->has('q') || $storeErrors?->has('rating') || $storeErrors?->has('sort') || $storeErrors?->has('page'))
                    <p class="lk-filter-error sm:col-span-2 lg:col-span-4" role="alert">{{ $storeErrors->first('q') ?: $storeErrors->first('rating') ?: $storeErrors->first('sort') ?: $storeErrors->first('page') }}</p>
                @endif
            </div>
        </form>

        <div class="lk-store-filter-footer">
            <a class="lk-btn lk-btn-light" href="{{ $guest ? route('shops.show', ['seller' => $sellerSlug]) : route('buyer.shop', ['seller' => $sellerSlug]) }}">Clear filters</a>
            <p class="text-xs text-stone-500" aria-live="polite"><strong>{{ $paginator?->total() ?? $storeProducts->count() }}</strong> products found</p>
        </div>
        @if($storeProducts->isNotEmpty())
            <div class="lk-product-grid mt-3">@foreach($storeProducts as $product)<x-buyer.product-card :product="$product" :guest="$guest" :store-vouchers="$storeVouchers" />@endforeach</div>
        @else
            <div class="mt-5"><x-buyer.empty-state title="No store products found" message="Try another keyword or adjust the price or rating filters." /></div>
        @endif
        @if($paginator && $paginator->hasPages())<div class="mt-5">{{ $paginator->links() }}</div>@endif
    </section>

</div>
