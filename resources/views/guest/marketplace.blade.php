@extends('layouts.guest.app')

@section('title', isset($storeMode) ? data_get($seller, 'name', 'Store') : 'Marketplace')

@section('content')
@php
    $products = collect($buyerProducts ?? []);
    $query = mb_strtolower(trim((string) request('q', '')));

    if (isset($storeMode) && $storeMode) {
        $sellerSlug = data_get($seller, 'slug', '');
        $products = $products->filter(function ($product) use ($sellerSlug) {
            $slug = data_get($product, 'seller_slug', \Illuminate\Support\Str::slug((string) data_get($product, 'seller', '')));
            return $slug === $sellerSlug;
        })->values();
    }

    if ($query !== '') {
        $products = $products->filter(function ($product) use ($query) {
            $haystack = mb_strtolower(implode(' ', [
                data_get($product, 'name'),
                data_get($product, 'category'),
                data_get($product, 'seller'),
                data_get($product, 'location'),
            ]));

            return str_contains($haystack, $query);
        })->values();
    }
@endphp

<div class="lk-page">
    <header class="lk-page-title">
        <div>
            <span class="lk-kicker">Marketplace</span>
            <h1>{{ isset($storeMode) && $storeMode ? data_get($seller, 'name', 'Store') : 'Explore LIKHAE' }}</h1>
            <p>Browse products and information as a guest.</p>
        </div>
        <div class="lk-note-chip">Guest browsing only</div>
    </header>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
        @if(isset($storeMode) && $storeMode)
            <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center">
                <img class="h-16 w-16 rounded-xl object-cover" src="{{ data_get($seller, 'avatar') }}" alt="{{ data_get($seller, 'name', 'Store') }}">
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-red-800">Verified LIKHAE store</span>
                    <h2 class="text-xl font-bold text-stone-900">{{ data_get($seller, 'name', 'Store') }}</h2>
                    <p class="text-xs text-stone-500">{{ data_get($seller, 'location') }} &middot; Joined {{ data_get($seller, 'joined') }}</p>
                </div>
            </div>
        @endif

        <form action="{{ route('products') }}" method="GET" class="mb-5 flex flex-col gap-2 sm:flex-row">
            <input name="q" value="{{ request('q') }}" class="min-w-0 flex-1 rounded-xl border border-stone-300 px-3 py-2 text-xs outline-none focus:border-red-800" placeholder="Search products, categories, local sellers">
            <button class="lk-btn lk-btn-light" type="submit">Search</button>
        </form>

        @if($products->isNotEmpty())
            <div class="lk-product-grid">
                @foreach($products as $product)
                    @include('components.guest.product-card', ['product' => $product])
                @endforeach
            </div>
        @else
            <x-buyer.empty-state title="No products found" message="Try another search term or browse all products." />
        @endif
    </section>
</div>
@endsection
