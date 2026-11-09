@extends('layouts.buyer.app')

@section('title', 'Product Details')
@section('active', 'products')
@section('subtitle', 'Review the product, seller, specifications, and ratings.')

@section('content')
    <x-buyer.product-detail :product="$product" :products="$relatedProducts ?? collect()" :product-vouchers="$productVouchers ?? collect()" :seller-promotions="$sellerPromotions ?? collect()" />
@endsection
