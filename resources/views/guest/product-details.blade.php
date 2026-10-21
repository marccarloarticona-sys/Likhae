@extends('layouts.guest.app')

@section('title', data_get($product, 'name', 'Product Details'))

@section('content')
@php
    $slug = data_get($product, 'slug', data_get($product, 'id', 'product'));
    $name = (string) data_get($product, 'name', 'Product');
    $category = (string) data_get($product, 'category.name', 'Local Find');
    $seller = (string) data_get($product, 'seller.business_name', 'LIKHAE Seller');
    $sellerAvatar = data_get($product, 'seller.avatar_path', data_get($product, 'seller_avatar'));
    $images = collect(data_get($product, 'images', []))->pluck('url')->filter()->values();
    if ($images->isEmpty()) $images = collect([data_get($product, 'primary_image.url')])->filter();
    $related = collect($relatedProducts ?? [])->take(4);
@endphp

<div class="lk-page">
    <div class="mb-4 flex flex-wrap items-center gap-2 text-xs text-stone-500">
        <a class="hover:text-red-800" href="{{ \Illuminate\Support\Facades\Route::has('guest.home') ? route('guest.home') : route('products') }}">Products</a>
        <span>/</span><span>{{ $category }}</span><span>/</span><strong class="text-stone-700">{{ $name }}</strong>
    </div>

    <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(360px,.9fr)]">
        <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm" data-product-gallery>
            <div class="aspect-square overflow-hidden rounded-xl bg-stone-50">
                <img class="h-full w-full object-cover" src="{{ $images->first() }}" alt="{{ $name }}" data-gallery-main>
            </div>
            @if($images->count() > 1)
                <div class="mt-3 grid grid-cols-5 gap-2">
                    @foreach($images as $index => $galleryImage)
                        <button type="button" class="overflow-hidden rounded-lg border-2 {{ $index === 0 ? 'border-red-800' : 'border-transparent' }} bg-stone-50" data-gallery-thumb data-image="{{ $galleryImage }}" aria-label="View image {{ $index + 1 }}">
                            <img class="aspect-square h-full w-full object-cover" src="{{ $galleryImage }}" alt="{{ $name }} view {{ $index + 1 }}">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-red-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-red-800">{{ $category }}</span>
            </div>
            <h1 class="mt-3 text-2xl font-bold leading-tight text-stone-950 sm:text-3xl">{{ $name }}</h1>
            @if(data_get($product, 'purchase_limit_per_buyer') !== null)
                <p class="mt-2 text-sm font-semibold text-stone-700">Purchase limit: {{ (int) data_get($product, 'purchase_limit_per_buyer') }} per Buyer</p>
            @endif

            <div class="mt-5 rounded-xl border border-red-100 bg-red-50 p-4">
                <strong class="block text-sm text-red-950">Login to view price and purchase</strong>
                <p class="mt-1 text-xs leading-5 text-red-900/80">Create or sign in to a Buyer account to see price, stock, variations, reviews, and checkout options.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('login') }}" class="lk-btn lk-btn-red">Sign In</a>
                    <a href="{{ route('register', ['role' => 'buyer']) }}" class="lk-btn lk-btn-light">Register as Buyer</a>
                </div>
            </div>

        </div>
    </section>

    <section class="mt-5 flex flex-col gap-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center">
        <x-shared.profile-image :path="$sellerAvatar" role="seller" class="h-16 w-16 rounded-xl object-cover" :alt="$seller" />
        <div class="min-w-0 flex-1"><span class="text-[10px] font-bold uppercase tracking-widest text-red-800">Official seller</span><h2 class="truncate text-lg font-bold text-stone-900">{{ $seller }}</h2><p class="text-xs text-stone-500">{{ data_get($product, 'location', 'Philippines') }}</p></div>
    </section>

    <div class="mt-5 grid gap-5">
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-bold text-stone-900">Description</h2>
            <div class="mt-3 whitespace-pre-line text-sm leading-7 text-stone-600">{{ data_get($product, 'description', 'A carefully selected product from a trusted LIKHAE seller. Built for everyday use and covered by marketplace buyer protection.') }}</div>
        </section>
    </div>

    @if($related->isNotEmpty())
        <section class="lk-section"><div class="lk-section-head"><div><span class="lk-kicker">Keep exploring</span><h2>Related Products</h2><p>More finds you may like.</p></div></div><div class="lk-product-grid">@foreach($related as $relatedProduct) @include('components.guest.product-card', ['product' => $relatedProduct]) @endforeach</div></section>
    @endif
</div>
@endsection
