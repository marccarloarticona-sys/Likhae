@extends('layouts.buyer.app')

@section('title', 'Store')
@section('active', 'products')
@section('subtitle', 'Browse products from this trusted seller.')

@section('content')
    <x-buyer.storefront :seller="$seller" :products="$buyerProducts" :paginator="$products" :store-product-count="$storeProductCount" :store-filter-values="$storeFilterValues" :store-errors="$storeErrors" :store-price-range="$storePriceRange" :store-categories="$storeCategories" :store-subcategories="$storeSubcategories" :store-vouchers="$storeVouchers" :store-claimable-vouchers="$storeClaimableVouchers" :seller-promotions="$sellerPromotions" />
@endsection
