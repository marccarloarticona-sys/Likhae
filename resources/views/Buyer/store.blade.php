@extends('layouts.buyer')

@section('title', 'Store')
@section('active', 'products')
@section('subtitle', 'Browse products from this trusted seller.')

@section('content')
    <x-buyer.storefront :seller="$seller" :products="$buyerProducts" :paginator="$products" :store-product-count="$storeProductCount" :store-filter-values="$storeFilterValues" :store-errors="$storeErrors" :store-price-range="$storePriceRange" />
@endsection
