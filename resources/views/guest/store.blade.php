@extends('layouts.guest.app')

@section('title', data_get($seller, 'business_name', 'Seller Store'))

@section('content')
    <x-buyer.storefront
        :seller="$seller"
        :products="$buyerProducts"
        :paginator="$products"
        :store-product-count="$storeProductCount"
        :store-filter-values="$storeFilterValues"
        :store-errors="$storeErrors"
        :store-price-range="$storePriceRange"
        :store-categories="$storeCategories"
        :store-subcategories="$storeSubcategories"
        :store-vouchers="$storeVouchers"
        :seller-promotions="$sellerPromotions"
        :guest="true"
    />
@endsection
