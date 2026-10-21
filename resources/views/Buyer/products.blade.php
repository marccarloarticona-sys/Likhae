@extends('layouts.buyer.app')

@section('title', 'Products')
@section('active', 'products')
@section('subtitle', 'Find products from trusted local sellers.')

@section('content')
    <x-buyer.product-catalog :products="$buyerProducts" :categories="$categories" :catalog-total="$catalogTotal" :paginator="$products" :catalog-locations="$catalogLocations" :search-suggestions="$searchSuggestions" :catalog-filter-values="$catalogFilterValues" :catalog-query-parameters="$catalogQueryParameters" :catalog-errors="$catalogErrors" :catalog-price-range="$catalogPriceRange" />
@endsection
