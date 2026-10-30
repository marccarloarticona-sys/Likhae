@extends('layouts.buyer.app')

@section('title', 'Discounts')
@section('active', 'products')
@section('subtitle', 'Shop active product discounts from LIKHAE sellers.')

@push('head')
<style>
    .lk-deals-page { min-height: 100%; padding: 40px clamp(18px, 2.4vw, 36px) 56px; color: #3B211B; }
    .lk-deals-heading { margin: 0; color: #561C17; font-family: "Instrument Serif", Georgia, serif; font-size: clamp(34px, 3vw, 46px); font-weight: 400; letter-spacing: -.035em; line-height: 1; }
    .lk-deals-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; }
    .lk-deals-filters { display: grid; grid-template-columns: minmax(0, 1fr) 190px auto; gap: 10px; margin: 22px 0; }
    .lk-deals-filters input, .lk-deals-filters select { min-width: 0; min-height: 44px; border: 1px solid #DBCEC1; border-radius: 12px; background: #FFFDF9; padding: 0 13px; color: #3B211B; }
    .lk-deals-filters button { min-height: 44px; border: 0; border-radius: 12px; background: #561C17; padding: 0 18px; color: #fff; font-weight: 700; }
    @media (max-width: 1023px) { .lk-deals-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 639px) { .lk-deals-page { padding-top: 28px; } .lk-deals-grid { grid-template-columns: minmax(0, 1fr); } .lk-deals-filters { grid-template-columns: minmax(0, 1fr); } }
</style>
@endpush

@section('content')
<div class="lk-deals-page">
    <section aria-labelledby="buyer-discounts-heading">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <span class="mb-2 inline-flex text-[10px] font-extrabold uppercase tracking-[0.22em] text-[#561C17]">Seller Discounts</span>
                <h1 id="buyer-discounts-heading" class="lk-deals-heading">Discounts</h1>
                <p class="mt-2 text-sm text-[#987865]">Active prices on available products. Variant prices are resolved using the same rules as checkout.</p>
            </div>
            <a href="{{ route('buyer.products') }}" class="text-sm font-semibold text-[#6C4936] hover:text-[#561C17]">Browse all products <span aria-hidden="true">→</span></a>
        </div>

        <form class="lk-deals-filters" method="GET" action="{{ route('buyer.discounts') }}" role="search" aria-label="Search discounted products">
            <input type="search" name="q" value="{{ request('q') }}" maxlength="120" placeholder="Search discounted products" aria-label="Search discounted products">
            <select name="sort" aria-label="Sort discounted products">
                @foreach(['best-selling' => 'Best selling', 'latest' => 'Newest', 'price-low' => 'Price: low to high', 'price-high' => 'Price: high to low', 'best-rated' => 'Top rated', 'name' => 'Name'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('sort', 'best-selling') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit">Search</button>
        </form>

        @if($dealProducts->isNotEmpty())
            <div class="lk-deals-grid">
                @foreach($dealProducts as $product)<x-buyer.product-card :product="$product" deal />@endforeach
            </div>
            <div class="mt-8">{{ $products->links() }}</div>
        @else
            <div class="rounded-2xl border border-dashed border-[#DBCEC1] bg-[#FFFDF9] px-6 py-12 text-center" role="status">
                <h2 class="text-base font-semibold text-[#3B211B]">No active discounts match your search</h2>
                <p class="mt-2 text-sm text-[#987865]">Try another search or browse the full catalog for more finds.</p>
                <a href="{{ route('buyer.products') }}" class="mt-5 inline-flex rounded-xl bg-[#561C17] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#3E130F]">Browse Products</a>
            </div>
        @endif
    </section>
</div>
@endsection
