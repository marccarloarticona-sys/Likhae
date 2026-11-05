@if($dealProducts->isNotEmpty())
    <section class="lk-section lk-home-marketing-section" aria-labelledby="home-discounts-heading">
        <div class="lk-section-head">
            <div><span class="lk-kicker">Save on your next find</span><h2 id="home-discounts-heading">Discounts For You</h2><p>Active product discounts from LIKHAE sellers.</p></div>
            <a href="{{ route('buyer.discounts') }}" class="lk-text-link">View All Discounts <span aria-hidden="true">→</span></a>
        </div>
        <div class="lk-product-grid lk-home-product-grid" role="region" aria-label="Discounted products">
            @foreach($dealProducts as $product)<x-buyer.product-card :product="$product" deal home />@endforeach
        </div>
    </section>
@endif

@if($flashDealProducts->isNotEmpty())
    <section class="lk-section lk-home-marketing-section" aria-labelledby="home-flash-deals-heading">
        <div class="lk-section-head">
            <div><span class="lk-kicker">Limited-time prices</span><h2 id="home-flash-deals-heading">Flash Deals</h2><p>Seller flash sales with promotional stock and end times.</p></div>
            <a href="{{ route('buyer.flash-deals') }}" class="lk-text-link">View All Flash Deals <span aria-hidden="true">→</span></a>
        </div>
        <div class="lk-product-grid lk-home-product-grid" role="region" aria-label="Active flash deals">
            @foreach($flashDealProducts as $product)<x-buyer.product-card :product="$product" deal home />@endforeach
        </div>
    </section>
@endif

<x-buyer.seller-promotions :promotions="$bundleOffers" heading="Bundle Deals" eyebrow="Save when you shop together" compact />
<x-buyer.seller-promotions :promotions="$freeGiftOffers" heading="Free Gift Offers" eyebrow="Buy X, get Y" compact />

@if($homeVoucherPreviews->isNotEmpty())
    <section class="lk-section lk-home-marketing-section" aria-labelledby="home-vouchers-heading">
        <div class="lk-section-head">
            <div><span class="lk-kicker">Extra savings</span><h2 id="home-vouchers-heading">Vouchers</h2><p>Eligible seller and LIKHAE vouchers for your next order.</p></div>
            <a href="{{ route('buyer.rewards', ['tab' => 'vouchers']) }}" class="lk-text-link">View All Vouchers <span aria-hidden="true">→</span></a>
        </div>
        <div class="lk-home-voucher-grid">
            @foreach($homeVoucherPreviews as $voucher)
                <article class="lk-home-voucher-card">
                    <div>
                        <span class="lk-kicker">{{ $voucher['source'] }}</span>
                        <h3>{{ $voucher['name'] }}</h3>
                        <p><strong>{{ strtoupper($voucher['value']) }} voucher</strong></p>
                        <p>Min ₱{{ number_format((float) $voucher['minimum'], 2) }}
                            @if($voucher['maximum'])
                                · Up to ₱{{ number_format((float) $voucher['maximum'], 2) }} off
                            @endif
                        </p>
                        <p>Expires {{ $voucher['expires'] }}</p>
                        <span class="lk-home-voucher-feedback" aria-live="polite" data-voucher-feedback></span>
                    </div>
                    @if($voucher['kind'] === 'seller')
                        <form method="POST" action="{{ route('buyer.vouchers.claim', $voucher['id']) }}" data-home-voucher-claim>
                            @csrf
                            <button class="lk-btn lk-btn-red" type="submit">Claim</button>
                        </form>
                    @else
                        <a class="lk-btn lk-btn-red" href="{{ route('buyer.products') }}">Shop with voucher</a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif
