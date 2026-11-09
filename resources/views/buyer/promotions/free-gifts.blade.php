@extends('layouts.buyer.app')

@section('title', 'Free Gifts')
@section('active', 'products')
@section('subtitle', 'Find Buy X Get Y offers from LIKHAE sellers.')

@section('content')
<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-7 flex flex-wrap items-end justify-between gap-4">
        <div>
            <span class="lk-kicker">Seller promotions</span>
            <h1 class="mt-1 font-serif text-4xl text-[#561C17]">Free Gifts &amp; Rewards</h1>
            <p class="mt-2 max-w-2xl text-sm text-[#987865]">See what qualifies, choose eligible reward variations, and add the offer through the existing cart flow.</p>
        </div>
        <a href="{{ route('buyer.products') }}" class="text-sm font-semibold text-[#6C4936] hover:text-[#561C17]">Browse products <span aria-hidden="true">→</span></a>
    </div>

    @if($promotions->isNotEmpty())
        <x-buyer.seller-promotions :promotions="$promotions" heading="Available Buy X Get Y offers" eyebrow="Free gifts and reward discounts" />
    @else
        <div class="rounded-2xl border border-dashed border-[#DBCEC1] bg-[#FFFDF9] px-6 py-14 text-center" role="status">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#F4E6DF] text-[#561C17]" aria-hidden="true">✦</div>
            <h2 class="mt-4 text-lg font-semibold text-[#3B211B]">No free gift offers are available right now</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-[#987865]">Seller reward offers will appear here when their qualifying products, reward stock, and schedule are available.</p>
            <a href="{{ route('buyer.products') }}" class="mt-5 inline-flex rounded-xl bg-[#561C17] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#3E130F]">Browse Products</a>
        </div>
    @endif
</div>
@endsection
