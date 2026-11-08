@php
    $hasOffers = $dealProducts->isNotEmpty()
        || $flashDealProducts->isNotEmpty()
        || $bundleOffers->isNotEmpty()
        || $freeGiftOffers->isNotEmpty()
        || $homeVoucherPreviews->isNotEmpty();
@endphp

<section
    class="lk-section lk-home-offers"
    aria-labelledby="home-offers-heading"
    data-home-offers
    data-home-offers-url="{{ route('buyer.home.marketing') }}"
    data-home-offers-rotation="{{ $rotation }}"
    aria-busy="false"
>
    <div class="lk-home-offers__header">
        <div>
            <span class="lk-kicker">Save on your next find</span>
            <h2 id="home-offers-heading">Offers for You</h2>
            <p>Fresh picks across discounts, flash deals, bundles, gifts, and vouchers.</p>
        </div>
        <button class="lk-home-offers__refresh" type="button" data-home-offers-refresh @disabled(! $hasOffers)>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 7v5h-5"/><path d="M4.7 9A8 8 0 0 1 18 6l2 6M4 12l2 6a8 8 0 0 0 13.3-3"/>
            </svg>
            <span>Show other offers</span>
        </button>
    </div>

    <div class="lk-home-offers__grid" role="region" aria-label="A rotating selection of current offers">
        @foreach($dealProducts as $product)
            <div class="lk-home-offers__product">
                <span class="lk-home-offers__type">Discount for you</span>
                <x-buyer.product-card :product="$product" deal home />
            </div>
        @endforeach

        @foreach($flashDealProducts as $product)
            <div class="lk-home-offers__product">
                <span class="lk-home-offers__type">Limited-time price</span>
                <x-buyer.product-card :product="$product" deal home />
            </div>
        @endforeach

        @foreach($bundleOffers as $offer)
            @php
                $bundleGroups = $offer->components->groupBy(fn ($component) => filled($component->choice_group)
                    ? 'choice:'.$component->choice_group
                    : 'variant:'.$component->id);
                $usualTotal = (float) $bundleGroups->sum(function ($options): float {
                    $component = $options->first();
                    $available = $options
                        ->filter(fn ($option) => $option->productVariant && (int) $option->productVariant->stock >= (int) $option->quantity)
                        ->sortBy(fn ($option) => (float) $option->productVariant->price)
                        ->first();

                    return (float) ($available?->productVariant?->price ?? 0) * max(1, (int) $component->quantity);
                });
                $bundleSavings = max(0, $usualTotal - (float) $offer->bundle_price);
                $remainingSets = $offer->buyer_redemptions_remaining
                    ?? ($offer->max_redemptions !== null ? max(0, (int) $offer->max_redemptions - (int) $offer->redemptions_used) : null);
            @endphp
            <article class="lk-home-offer-card">
                <div class="lk-home-offer-card__top">
                    <span class="lk-home-offers__type">Bundle deal</span>
                    @if($remainingSets !== null)<span class="lk-home-offer-card__stock">{{ $remainingSets }} set(s) left</span>@endif
                </div>
                <h3>{{ $offer->name }}</h3>
                <p>Get all listed items together for <strong>&#8369;{{ number_format((float) $offer->bundle_price, 2) }}</strong>.</p>
                @if($usualTotal > (float) $offer->bundle_price)
                    <p class="lk-home-offer-card__saving">Usual total <s>&#8369;{{ number_format($usualTotal, 2) }}</s> &middot; Save <strong>&#8369;{{ number_format($bundleSavings, 2) }}</strong></p>
                @endif
                <p class="lk-home-offer-card__seller">{{ $offer->sellerProfile?->business_name ?? 'LIKHAE seller' }}</p>
                <a class="lk-home-offer-card__link" href="{{ route('buyer.bundle-deals') }}">Explore bundle deals <span aria-hidden="true">&rarr;</span></a>
            </article>
        @endforeach

        @foreach($freeGiftOffers as $offer)
            @php
                $rewardPercent = min(100, max(1, (float) ($offer->reward_discount_percent ?? 100)));
                $buyName = $offer->buyVariant?->product?->name ?? 'qualifying item';
                $rewardName = $offer->rewardVariant?->product?->name ?? $offer->rewardProduct?->name ?? 'reward item';
                $remainingSets = $offer->buyer_redemptions_remaining
                    ?? ($offer->max_redemptions !== null ? max(0, (int) $offer->max_redemptions - (int) $offer->redemptions_used) : null);
            @endphp
            <article class="lk-home-offer-card">
                <div class="lk-home-offer-card__top">
                    <span class="lk-home-offers__type">Buy X, get Y</span>
                    @if($remainingSets !== null)<span class="lk-home-offer-card__stock">{{ $remainingSets }} left</span>@endif
                </div>
                <h3>{{ $offer->name }}</h3>
                <p>Buy {{ (int) $offer->buy_quantity }} &times; {{ $buyName }}, get {{ (int) $offer->reward_quantity }} &times; {{ $rewardName }} {{ $rewardPercent >= 100 ? 'free' : 'at '.rtrim(rtrim(number_format($rewardPercent, 2, '.', ''), '0'), '.').'% off' }}.</p>
                <p class="lk-home-offer-card__seller">{{ $offer->sellerProfile?->business_name ?? 'LIKHAE seller' }}</p>
                <a class="lk-home-offer-card__link" href="{{ route('buyer.free-gifts') }}">Explore free gifts <span aria-hidden="true">&rarr;</span></a>
            </article>
        @endforeach

        @foreach($homeVoucherPreviews as $voucher)
            <article class="lk-home-offer-card lk-home-voucher-card">
                <div>
                    <div class="lk-home-offer-card__top">
                        <span class="lk-home-offers__type">{{ $voucher['source'] }}</span>
                        <span class="lk-home-offer-card__stock">Voucher</span>
                    </div>
                    <h3>{{ $voucher['name'] }}</h3>
                    <p><strong>{{ strtoupper($voucher['value']) }}</strong></p>
                    <p>Min &#8369;{{ number_format((float) $voucher['minimum'], 2) }}
                        @if($voucher['maximum']) &middot; Up to &#8369;{{ number_format((float) $voucher['maximum'], 2) }} off @endif
                    </p>
                    <p>Expires {{ $voucher['expires'] }}</p>
                    <span class="lk-home-voucher-feedback" aria-live="polite" data-voucher-feedback></span>
                </div>
                @if($voucher['kind'] === 'seller')
                    <form method="POST" action="{{ route('buyer.vouchers.claim', $voucher['id']) }}" data-home-voucher-claim>
                        @csrf
                        <button class="lk-btn lk-btn-red" type="submit">Claim voucher</button>
                    </form>
                @else
                    <a class="lk-btn lk-btn-red" href="{{ route('buyer.products') }}">Shop with voucher</a>
                @endif
                <a class="lk-home-offer-card__link" href="{{ route('buyer.rewards', ['tab' => 'vouchers']) }}">Explore vouchers <span aria-hidden="true">&rarr;</span></a>
            </article>
        @endforeach

        @unless($hasOffers)
            <div class="lk-home-offers__empty">
                <strong>No current offers</strong>
                <p>Check back soon or browse the offer pages for new finds.</p>
            </div>
        @endunless
    </div>

    <p class="lk-home-offers__status" data-home-offers-status role="status" aria-live="polite"></p>
</section>
