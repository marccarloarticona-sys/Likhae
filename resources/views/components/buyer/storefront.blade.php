@props(['seller', 'products' => collect(), 'guest' => false, 'paginator' => null, 'storeProductCount' => null, 'storeFilterValues' => [], 'storeErrors' => null, 'storePriceRange' => []])

@php
    $sellerSlug = data_get($seller, 'id', 'store');
    $sellerName = data_get($seller, 'business_name', 'LIKHAE Store');
    $sellerAvatar = data_get($seller, 'avatar_path');
    $sellerAvatarUrl = $sellerAvatar ? (\Illuminate\Support\Str::startsWith($sellerAvatar, ['http://', 'https://']) ? $sellerAvatar : \Illuminate\Support\Facades\Storage::url($sellerAvatar)) : null;
    $sellerBanner = data_get($seller, 'banner_path');
    $sellerBannerUrl = $sellerBanner ? (\Illuminate\Support\Str::startsWith($sellerBanner, ['http://', 'https://']) ? $sellerBanner : \Illuminate\Support\Facades\Storage::url($sellerBanner)) : null;
    $storeProducts = collect($products)->filter(function ($product) use ($sellerSlug) {
        return (string) data_get($product, 'seller.id') === (string) $sellerSlug;
    })->values();
    $storeFilterValues = (array) ($storeFilterValues ?? []);
    $query = (string) ($storeFilterValues['q'] ?? '');
    $minimumPrice = $storeFilterValues['min_price'] ?? '';
    $maximumPrice = $storeFilterValues['max_price'] ?? '';
    $minimumRating = (float) ($storeFilterValues['rating'] ?? 0);
    $activeSort = $storeFilterValues['sort'] ?? 'latest';
    $searchAction = $guest ? route('home') : route('buyer.shop', ['seller' => $sellerSlug]);
    $isFollowing = (bool) data_get($seller, 'is_following', false);
    $followerCount = max(0, (int) data_get($seller, 'followers', 0));
    $sellerRating = data_get($seller, 'rating');
    $sellerRatingCount = max(0, (int) data_get($seller, 'rating_count', 0));
    $totalStoreProducts = max(0, (int) ($storeProductCount ?? $paginator?->total() ?? $storeProducts->count()));
@endphp

<div class="lk-page">
    <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
        <div class="h-24 bg-gradient-to-r from-red-950 via-red-900 to-stone-900" @if($sellerBannerUrl) style="background-image:linear-gradient(90deg,rgba(40,10,10,.32),rgba(40,10,10,.08)),url('{{ $sellerBannerUrl }}');background-position:center;background-size:cover" @endif></div>
        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-end sm:p-6">
            @if($sellerAvatarUrl)<img class="-mt-14 h-24 w-24 rounded-2xl border-4 border-white bg-white object-cover shadow-sm" src="{{ $sellerAvatarUrl }}" alt="{{ $sellerName }}">@else<div class="-mt-14 grid h-24 w-24 place-items-center rounded-2xl border-4 border-white bg-red-900 text-xl font-bold text-white shadow-sm">{{ collect(explode(' ', $sellerName))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</div>@endif
            <div class="min-w-0 flex-1"><span class="text-[10px] font-bold uppercase tracking-widest text-red-800">Verified LIKHAE store</span><h1 class="truncate text-2xl font-bold text-stone-950">{{ $sellerName }}</h1>@if(data_get($seller, 'tagline'))<p class="mt-1 text-sm text-stone-600">{{ data_get($seller, 'tagline') }}</p>@endif<p class="mt-1 text-xs text-stone-500">{{ data_get($seller, 'location') }} · Joined {{ data_get($seller, 'joined') }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if($guest)
                    <a href="{{ route('login') }}" class="lk-btn lk-btn-light">Follow</a>
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
                    <button type="button" class="lk-btn lk-btn-red" data-auth-required data-auth-message="Sign in to message this store.">Message</button>
                @else
                    <a href="{{ route('buyer.messages', ['seller' => $sellerSlug]) }}" class="lk-btn lk-btn-red">Message</a>
                @endif
            </div>
        </div>
        <dl class="grid grid-cols-3 divide-x divide-stone-200 border-t border-stone-200 bg-stone-50 text-center">
            <div class="px-2 py-3 sm:px-4" data-store-stat="products"><dd class="text-lg font-bold text-stone-900">{{ number_format($totalStoreProducts) }}</dd><dt class="text-[11px] font-medium text-stone-500">Products</dt></div>
            <div class="px-2 py-3 sm:px-4" data-store-stat="rating"><dd class="text-lg font-bold text-stone-900">{{ $sellerRating !== null ? number_format((float) $sellerRating, 1) : '—' }}</dd><dt class="text-[11px] font-medium text-stone-500">{{ number_format($sellerRatingCount) }} {{ $sellerRatingCount === 1 ? 'rating' : 'ratings' }}</dt></div>
            <div class="px-2 py-3 sm:px-4" data-store-stat="followers"><dd class="text-lg font-bold text-stone-900" data-store-follower-count>{{ number_format($followerCount) }}</dd><dt class="text-[11px] font-medium text-stone-500">{{ $followerCount === 1 ? 'follower' : 'followers' }}</dt></div>
        </dl>
    </section>

    <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
        <form method="GET" action="{{ $searchAction }}" class="mt-4" aria-label="Filter this store's products" data-live-filters>
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"><div><span class="lk-kicker">Store collection</span><h2 class="text-xl font-bold text-stone-900">Products by {{ $sellerName }}</h2><p class="text-xs text-stone-500">Only products published by this seller are shown.</p></div><div class="flex min-w-0 gap-2"><label class="sr-only" for="storeSearch">Search this store</label><input id="storeSearch" name="q" value="{{ $query }}" class="min-w-0 rounded-xl border border-stone-300 px-3 py-2 text-xs outline-none focus:border-red-800" placeholder="Search this store" autocomplete="off"><button class="lk-btn lk-btn-light" type="submit">Search</button></div></div>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <x-buyer.price-range-filter prefix="store" :minimum="$minimumPrice" :maximum="$maximumPrice" :price-range="$storePriceRange" :errors="$storeErrors" class="sm:col-span-2 lg:col-span-2" />
            <div class="min-w-0"><label class="lk-field-label" for="storeRating">Minimum rating</label><select id="storeRating" name="rating" class="lk-filter-select"><option value="0" @selected($minimumRating == 0)>All Ratings</option>@foreach([4,3,2,1] as $rating)<option value="{{ $rating }}" @selected($minimumRating == $rating)>{{ $rating }} Stars &amp; Up</option>@endforeach</select></div>
            <div class="min-w-0"><label class="lk-field-label" for="storeSort">Sort by</label><select id="storeSort" name="sort" class="lk-filter-select"><option value="latest" @selected($activeSort === 'latest')>Latest</option><option value="price-low" @selected($activeSort === 'price-low')>Price: Low to High</option><option value="price-high" @selected($activeSort === 'price-high')>Price: High to Low</option><option value="best-rated" @selected($activeSort === 'best-rated')>Best Rated</option><option value="best-selling" @selected($activeSort === 'best-selling')>Best Selling</option></select></div>
                @if($storeErrors?->has('min_price') || $storeErrors?->has('max_price'))<p class="lk-filter-error sm:col-span-2 lg:col-span-6" role="alert">{{ $storeErrors->first('min_price') ?: $storeErrors->first('max_price') }}</p>@endif
                @if($storeErrors?->has('q') || $storeErrors?->has('rating') || $storeErrors?->has('sort') || $storeErrors?->has('page'))<p class="lk-filter-error sm:col-span-2 lg:col-span-6" role="alert">{{ $storeErrors->first('q') ?: $storeErrors->first('rating') ?: $storeErrors->first('sort') ?: $storeErrors->first('page') }}</p>@endif
                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-6"><a class="lk-btn lk-btn-light" href="{{ route('buyer.shop', ['seller' => $sellerSlug]) }}">Clear Filters</a></div>
            </div>
        </form>
        <p class="mt-4 text-xs text-stone-500" aria-live="polite">{{ $paginator?->total() ?? $storeProducts->count() }} products found</p>
        @if($storeProducts->isNotEmpty())
            <div class="lk-product-grid mt-3">@foreach($storeProducts as $product)<x-buyer.product-card :product="$product" :guest="$guest" />@endforeach</div>
        @else
            <div class="mt-5"><x-buyer.empty-state title="No store products found" message="Try another keyword or adjust the price or rating filters." /></div>
        @endif
        @if($paginator && $paginator->hasPages())<div class="mt-5">{{ $paginator->links() }}</div>@endif
    </section>

    <section class="mt-5 grid gap-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm md:grid-cols-[1fr_300px]"><div><h2 class="text-base font-bold text-stone-900">About this store</h2><p class="mt-2 text-sm leading-6 text-stone-600">{{ data_get($seller, 'description') }}</p></div><dl class="space-y-2 text-xs"><div class="flex justify-between gap-3"><dt class="text-stone-500">Location</dt><dd class="font-medium text-stone-800">{{ data_get($seller, 'location') }}</dd></div><div class="flex justify-between gap-3"><dt class="text-stone-500">Business hours</dt><dd class="font-medium text-stone-800">{{ data_get($seller, 'business_hours') }}</dd></div></dl></section>
</div>
