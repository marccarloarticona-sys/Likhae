@extends('layouts.buyer.app')

@section('title', 'Flash Deals')
@section('active', 'products')
@section('subtitle', 'Shop active flash sales and set reminders for upcoming offers.')

@push('head')
<style>
    .lk-deals-page {
        min-height: 100%;
        padding: 40px clamp(18px, 2.4vw, 36px) 56px;
        color: #3B211B;
    }

    .lk-deals-heading {
        margin: 0;
        color: #561C17;
        font-family: "Instrument Serif", Georgia, serif;
        font-size: clamp(34px, 3vw, 46px);
        font-weight: 400;
        letter-spacing: -0.035em;
        line-height: 1;
    }

    .lk-deals-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 20px;
    }

    @media (max-width: 1023px) {
        .lk-deals-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 639px) {
        .lk-deals-page {
            padding-top: 28px;
        }

        .lk-deals-grid {
            grid-template-columns: minmax(0, 1fr);
        }
    }
</style>
@endpush

@section('content')
<div class="lk-deals-page">
    <section aria-labelledby="buyer-deals-heading">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <span class="mb-2 inline-flex text-[10px] font-extrabold uppercase tracking-[0.22em] text-[#561C17]">
                    Flash Sales
                </span>
                <h1 id="buyer-deals-heading" class="lk-deals-heading">
                    Flash Deals
                </h1>
                <p class="mt-2 text-sm text-[#987865]">
                    Active seller flash sales with promotional stock and authoritative sale end times.
                </p>
            </div>

            <a href="{{ route('buyer.products') }}" class="text-sm font-semibold text-[#6C4936] hover:text-[#561C17]">
                Browse all products <span aria-hidden="true">→</span>
            </a>
        </div>

        @if ($dealProducts->isNotEmpty())
            <div class="lk-deals-grid">
                @foreach ($dealProducts as $product)
                    <x-buyer.product-card :product="$product" deal />
                @endforeach
            </div>

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @elseif($upcomingDealProducts->isEmpty() && $soldOutDealProducts->isEmpty())
            <div class="rounded-2xl border border-dashed border-[#DBCEC1] bg-[#FFFDF9] px-6 py-12 text-center">
                <h2 class="text-base font-semibold text-[#3B211B]">
                    No active flash sales are available right now
                </h2>
                <p class="mt-2 text-sm text-[#987865]">
                    Check back later for active seller flash sales.
                </p>
                <a href="{{ route('buyer.products') }}" class="mt-5 inline-flex rounded-xl bg-[#561C17] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#3E130F]">
                    Browse Products
                </a>
            </div>
        @endif
    </section>

    @if($upcomingDealProducts->isNotEmpty())
        <section class="mt-12" aria-labelledby="upcoming-flash-sales-heading">
            <div class="mb-6">
                <span class="mb-2 inline-flex text-[10px] font-extrabold uppercase tracking-[0.22em] text-amber-800">Coming soon</span>
                <h2 id="upcoming-flash-sales-heading" class="lk-deals-heading">Upcoming flash sales</h2>
                <p class="mt-2 text-sm text-[#987865]">Set a reminder and we’ll let you know about 10 minutes before a sale begins.</p>
            </div>
            <div class="lk-deals-grid">
                @foreach($upcomingDealProducts as $product)
                    <x-buyer.product-card :product="$product" deal flash-reminder :reminder-active="isset($flashSaleReminderStates[$product['id']])" :reminder-available="$product['flash_sale_reminder_available']" />
                @endforeach
            </div>
        </section>
    @endif

    @if($soldOutDealProducts->isNotEmpty())
        <section class="mt-12" aria-labelledby="sold-out-flash-sales-heading">
            <div class="mb-6">
                <span class="mb-2 inline-flex text-[10px] font-extrabold uppercase tracking-[0.22em] text-[#987865]">Allocation reached</span>
                <h2 id="sold-out-flash-sales-heading" class="lk-deals-heading">Flash sales sold out</h2>
                <p class="mt-2 text-sm text-[#987865]">These promotional units are gone. Product pages show the current price and stock for other available units.</p>
            </div>
            <div class="lk-deals-grid">
                @foreach($soldOutDealProducts as $product)
                    <x-buyer.product-card :product="$product" deal />
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
