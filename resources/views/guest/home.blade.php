@extends('layouts.guest')
@section('title', 'Welcome to LIKHAE')
@section('content')
@php
    if (isset($buyerProducts) && method_exists($buyerProducts, 'items')) {
        $products = collect($buyerProducts->items());
    } else {
        $products = collect($buyerProducts ?? []);
    }
    $featuredProducts    = $products->take(6);
    $recommendedProducts = $products->skip(6)->take(6);

    $categories = [
        ['name' => 'Electronics',  'slug' => 'electronics', 'sub' => 'Laptops & gadgets',   'img' => 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=800&q=75'],
        ['name' => 'Fashion',      'slug' => 'fashion',     'sub' => 'Clothing & handbags',  'img' => 'https://images.unsplash.com/photo-1490481651871-ab68de25d43d?auto=format&fit=crop&w=800&q=75'],
        ['name' => 'Home & Living','slug' => 'home',        'sub' => 'Furniture & décor',    'img' => 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?auto=format&fit=crop&w=800&q=75'],
        ['name' => 'Beauty',       'slug' => 'beauty',      'sub' => 'Skincare & wellness',  'img' => 'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9?auto=format&fit=crop&w=800&q=75'],
        ['name' => 'Groceries',    'slug' => 'groceries',   'sub' => 'Fresh essentials',     'img' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=800&q=75'],
        ['name' => 'Sports',       'slug' => 'sports',      'sub' => 'Gear & activewear',    'img' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=75'],
    ];

    $trust = [
        ['title' => 'Secure Checkout',         'sub' => 'Shop with confidence',                    'icon' => 'shield'],
        ['title' => 'Trusted Sellers',          'sub' => 'Verified and reliable',                   'icon' => 'users'],
        ['title' => 'Wide Variety',             'sub' => 'Everything you need',                     'icon' => 'package'],
        ['title' => 'Fast & Reliable Delivery', 'sub' => 'Bringing happiness to your doorstep',     'icon' => 'truck'],
    ];

    $journey = [
        ['n' => '01', 'title' => 'Discover',   'text' => 'Browse a wide variety of products from trusted sellers.',       'icon' => 'search'],
        ['n' => '02', 'title' => 'Shop',        'text' => 'Find what you need at great prices.',                           'icon' => 'cart'],
        ['n' => '03', 'title' => 'Check Out',   'text' => 'Enjoy a secure and hassle-free checkout experience.',           'icon' => 'shield'],
        ['n' => '04', 'title' => 'Receive',     'text' => 'Get your items delivered fast and start enjoying them.',        'icon' => 'box'],
    ];

    $testimonials = collect();
@endphp

<div class="lk-ed-page">

    {{-- ══════════════════════════════════════════════════════
         HERO
    ══════════════════════════════════════════════════════ --}}
    @include('guest.partials.hero-carousel')

    {{-- ══════════════════════════════════════════════════════
         TRUST BAR
    ══════════════════════════════════════════════════════ --}}
    <div class="lk-ed-trust" role="list" aria-label="Marketplace trust highlights">
        @foreach($trust as $i => $t)
            <div class="lk-ed-trust-item" role="listitem">
                <div class="lk-ed-trust-icon">
                    @if($t['icon'] === 'shield')
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    @elseif($t['icon'] === 'users')
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="7" r="3"/><path d="M20 19v-1a4 4 0 0 0-3-3.87M16 3.13A4 4 0 0 1 16 11"/></svg>
                    @elseif($t['icon'] === 'package')
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z"/><path d="M12 12v9M3 7.5 12 12l9-4.5"/></svg>
                    @else
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 5v3h-7V8Z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    @endif
                </div>
                <div class="lk-ed-trust-copy">
                    <strong>{{ $t['title'] }}</strong>
                    <span>{{ $t['sub'] }}</span>
                </div>
            </div>
            @if(!$loop->last)<div class="lk-ed-trust-sep" aria-hidden="true"></div>@endif
        @endforeach
    </div>

    {{-- ══════════════════════════════════════════════════════
         CATEGORIES
    ══════════════════════════════════════════════════════ --}}
    <section id="lk-categories" class="lk-ed-section scroll-mt-24" aria-labelledby="lk-cat-heading">
        <div class="lk-ed-section-head">
            <div>
                <h2 id="lk-cat-heading" class="lk-ed-section-title">Shop by Category</h2>
            </div>
            <a href="{{ route('products') }}" class="lk-ed-viewall">View All Categories →</a>
        </div>

        <div class="lk-ed-cat-grid">
            @foreach($categories as $cat)
                <a href="{{ route('products', ['category' => $cat['slug']]) }}" class="lk-ed-cat-card">
                    <img
                        class="lk-ed-cat-img"
                        src="{{ $cat['img'] }}"
                        srcset="{{ str_replace('w=800', 'w=400', $cat['img']) }} 400w, {{ $cat['img'] }} 800w"
                        sizes="(max-width: 600px) 50vw, (max-width: 1100px) 33vw, 17vw"
                        alt="{{ $cat['name'] }}"
                        width="800"
                        height="680"
                        loading="lazy"
                        fetchpriority="low"
                        decoding="async"
                    >
                    <div class="lk-ed-cat-body">
                        <div>
                            <strong>{{ $cat['name'] }}</strong>
                            <span>{{ $cat['sub'] }}</span>
                        </div>
                        <div class="lk-ed-cat-arrow" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════
         FEATURED PRODUCTS
    ══════════════════════════════════════════════════════ --}}
    <section class="lk-ed-section" aria-labelledby="lk-feat-heading">
        <div class="lk-ed-section-head">
            <div>
                <h2 id="lk-feat-heading" class="lk-ed-section-title">Featured Products</h2>
            </div>
            <a href="{{ route('products', ['sort' => 'featured']) }}" class="lk-ed-viewall">View All Products →</a>
        </div>

        @if($featuredProducts->isNotEmpty())
            <div class="lk-product-grid lk-ed-product-grid">
                @foreach($featuredProducts as $product)
                    @include('guest.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        @else
            <div class="lk-ed-empty">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l1 13H5z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
                <h3>Featured products coming soon</h3>
                <p>Products from trusted local sellers will appear here.</p>
                <a href="{{ route('register') }}" class="lk-btn lk-btn-red" style="margin-top:12px">Become a Seller</a>
            </div>
        @endif
    </section>

    {{-- ══════════════════════════════════════════════════════
         PROMO BANNER
    ══════════════════════════════════════════════════════ --}}
    <section class="lk-ed-promo" aria-labelledby="lk-promo-heading">
        <div class="lk-ed-promo-copy">
            <span class="lk-ed-promo-label">EVERYDAY ESSENTIALS, GREATER POSSIBILITIES</span>
            <h2 id="lk-promo-heading">Shop More.<br>Live Better.</h2>
            <p>Quality products from trusted sellers, all in one place.</p>
            <a href="{{ route('products', ['sort' => 'best-selling']) }}" class="lk-btn lk-ed-btn-ghost">
                Shop the Deals →
            </a>
        </div>
        <div class="lk-ed-promo-visual" aria-hidden="true"></div>
    </section>

    {{-- ══════════════════════════════════════════════════════
         SHOPPING JOURNEY
    ══════════════════════════════════════════════════════ --}}
    <section id="about-likhae" class="lk-ed-section scroll-mt-24" aria-labelledby="lk-journey-heading">
        <div class="lk-ed-section-head">
            <div>
                <span class="lk-ed-section-kicker">Why Choose LIKHAE?</span>
                <h2 id="lk-journey-heading" class="lk-ed-section-title">A simpler, better way to shop</h2>
            </div>
        </div>

        <div class="lk-ed-journey">
            @foreach($journey as $step)
                <div class="lk-ed-journey-step">
                    <div class="lk-ed-journey-num">{{ $step['n'] }}</div>
                    <div class="lk-ed-journey-icon">
                        @if($step['icon'] === 'search')
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 5 5"/></svg>
                        @elseif($step['icon'] === 'cart')
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2 9h10l2-7H7"/><circle cx="10" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/></svg>
                        @elseif($step['icon'] === 'shield')
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        @else
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8 8 3h8l5 5v9a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V8Z"/><path d="M8 12h8"/></svg>
                        @endif
                    </div>
                    <strong>{{ $step['title'] }}</strong>
                    <p>{{ $step['text'] }}</p>
                </div>
                @if(!$loop->last)
                    <div class="lk-ed-journey-arrow" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                    </div>
                @endif
            @endforeach
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════
         TESTIMONIALS
    ══════════════════════════════════════════════════════ --}}
    @if($testimonials->isNotEmpty())
    <section class="lk-ed-section" aria-labelledby="lk-testi-heading">
        <div class="lk-ed-section-head">
            <div>
                <span class="lk-ed-section-kicker">Customer feedback</span>
                <h2 id="lk-testi-heading" class="lk-ed-section-title">What Our Shoppers Say</h2>
            </div>
            <span class="lk-ed-section-sub">Real people. Real experiences.</span>
        </div>

        <div class="lk-ed-testi-grid">
            @foreach($testimonials as $t)
                <article class="lk-ed-testi-card">
                    <div class="lk-ed-testi-head">
                        <img src="{{ $t['img'] }}" alt="{{ $t['name'] }}" loading="lazy">
                        <div>
                            <strong>{{ $t['name'] }}</strong>
                            <div class="lk-ed-stars" aria-label="5 stars">★★★★★</div>
                        </div>
                    </div>
                    <p>"{{ $t['quote'] }}"</p>
                </article>
            @endforeach
        </div>
    </section>
    @endif

    {{-- ══════════════════════════════════════════════════════
         NEWSLETTER
    ══════════════════════════════════════════════════════ --}}
    <section class="lk-ed-newsletter" aria-label="Community newsletter">
        <div class="lk-ed-newsletter-copy">
            <div class="lk-ed-newsletter-icon">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 8 8 6 8-6"/></svg>
            </div>
            <div>
                <h2>Be Part of the LIKHAE Community</h2>
                <p>Get exclusive deals, new arrivals, and more.</p>
            </div>
        </div>
        <form class="lk-ed-newsletter-form" action="{{ route('products') }}" method="GET">
            <input type="email" name="_email" placeholder="Enter your email address" aria-label="Email address">
            <button type="submit" class="lk-btn lk-btn-red">Subscribe</button>
        </form>
        <div class="lk-ed-newsletter-leaf lk-ed-nl-leaf-1" aria-hidden="true"></div>
        <div class="lk-ed-newsletter-leaf lk-ed-nl-leaf-2" aria-hidden="true"></div>
    </section>

</div>
@endsection
