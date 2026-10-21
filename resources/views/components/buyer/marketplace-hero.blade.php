@props(['guest' => false, 'products' => collect(), 'browseUrl', 'categoriesUrl', 'ordersUrl' => null])

@php
    $heroProducts = collect($products)->take(2)->values();
@endphp

@once
<style>
    .lk-buyer-hero {
        position: relative !important;
        display: grid !important;
        grid-template-columns: minmax(0, 46%) minmax(0, 54%) !important;
        align-items: center !important;
        aspect-ratio: auto !important;
        min-height: clamp(560px, calc(100svh - 124px), 720px) !important;
        padding: 48px clamp(28px, 4.2vw, 64px) 62px !important;
        overflow: hidden !important;
        border: 1px solid rgba(193, 151, 113, .32) !important;
        border-radius: 28px !important;
        background-color: #fbf8f4 !important;
        background-image: url('{{ asset('images/home-buyer.jpg') }}') !important;
        background-image: image-set(url('{{ asset('images/home-buyer.webp') }}') type('image/webp'), url('{{ asset('images/home-buyer.jpg') }}') type('image/jpeg')) !important;
        background-position: center !important;
        background-size: calc(100% + 8px) auto !important;
        background-repeat: no-repeat !important;
        box-shadow: none !important;
        color: #171311 !important;
        width: 100% !important;
        margin: 0 !important;
    }
    .lk-buyer-hero__copy { position: relative; z-index: 2; padding-right: clamp(18px, 3vw, 48px); }
    .lk-buyer-hero__kicker { display: flex; align-items: center; gap: 14px; margin-bottom: 32px; color: #a92f20; font-size: 11px; font-weight: 900; letter-spacing: .22em; text-transform: uppercase; }
    .lk-buyer-hero__kicker::before { width: 34px; height: 1px; background: #d84937; content: ""; }
    .lk-buyer-hero h1 { margin: 0 !important; color: #0f0d0c !important; font-family: "Instrument Serif", Georgia, serif !important; font-size: clamp(55px, 5.4vw, 82px) !important; font-weight: 400 !important; line-height: .98 !important; letter-spacing: -.045em !important; }
    .lk-buyer-hero h1 span { display: block; color: #b6321f !important; font: inherit !important; font-style: normal !important; }
    .lk-buyer-hero__description { max-width: 560px; margin: 24px 0 0 !important; color: #626268 !important; font-size: 15px !important; line-height: 1.55 !important; }
    .lk-buyer-hero__actions { display: flex; gap: 14px; margin-top: 30px; }
    .lk-buyer-hero__button { display: inline-flex; align-items: center; justify-content: center; gap: 12px; min-height: 56px; padding: 0 24px; border: 1.5px solid #b93a28; border-radius: 12px; font-size: 14px; font-weight: 800; text-decoration: none; transition: transform .18s ease, box-shadow .18s ease; }
    .lk-buyer-hero__button:hover { transform: translateY(-2px); }
    .lk-buyer-hero__button--primary { background: linear-gradient(135deg, #b33824, #c64b34); color: #fff !important; box-shadow: 0 12px 22px rgba(177, 52, 34, .2); }
    .lk-buyer-hero__button--secondary { background: rgba(255,255,255,.7); color: #aa2f21 !important; }
    .lk-buyer-hero__button svg { width: 23px; height: 23px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.8; }
    .lk-buyer-hero__products { position: relative; z-index: 2; display: grid; grid-template-columns: minmax(0, 1.12fr) minmax(0, .96fr); align-items: start; gap: 14px; min-width: 0; padding-top: 22px; }
    .lk-buyer-hero__card { position: relative; display: block; overflow: hidden; border: 1px solid rgba(255,255,255,.9); border-radius: 20px; background: #f8f0e8; color: #1f1814 !important; box-shadow: 0 20px 38px rgba(82,49,32,.17); text-decoration: none; transition: transform .2s ease, box-shadow .2s ease; }
    .lk-buyer-hero__card:first-child { transform: rotate(-1.2deg); }
    .lk-buyer-hero__card:nth-child(2) { margin-top: 18px; transform: rotate(1deg); }
    .lk-buyer-hero__card:hover { box-shadow: 0 25px 46px rgba(82,49,32,.24); }
    .lk-buyer-hero__card:first-child:hover { transform: translateY(-4px) rotate(-1.2deg); }
    .lk-buyer-hero__card:nth-child(2):hover { transform: translateY(-4px) rotate(1deg); }
    .lk-buyer-hero__image { position: relative; overflow: hidden; background: #e8dbce; }
    .lk-buyer-hero__image img { display: block; box-sizing: border-box; width: 100%; height: auto; padding: 12px; object-fit: contain; object-position: center; transition: transform .3s ease; }
    .lk-buyer-hero__card:hover img { transform: scale(1.035); }
    .lk-buyer-hero__placeholder { display: grid; width: 100%; min-height: 240px; place-items: center; padding: 20px; color: #96604e; background: linear-gradient(145deg,#f5e9df,#ddc8b6); text-align: center; }
    .lk-buyer-hero__badge { position: absolute; top: 16px; left: 16px; display: inline-flex; align-items: center; gap: 7px; padding: 9px 14px; border-radius: 999px; background: #fff0d7; color: #692b1f; font-size: 10px; font-weight: 900; letter-spacing: .02em; text-transform: uppercase; box-shadow: 0 4px 12px rgba(61,35,20,.08); }
    .lk-buyer-hero__badge i { color: #f4a31e; font-size: 16px; font-style: normal; line-height: 1; }
    .lk-buyer-hero__info { position: relative; min-height: 108px; padding: 17px 68px 17px 20px; background: rgba(249,241,232,.98); }
    .lk-buyer-hero__name { display: -webkit-box; overflow: hidden; margin: 0; color: #1e1714; font-family: "Instrument Serif", Georgia, serif; font-size: clamp(20px, 1.8vw, 27px); font-weight: 400; line-height: 1.02; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
    .lk-buyer-hero__meta { display: flex; flex-wrap: wrap; gap: 5px 10px; margin-top: 9px; color: #65554e; font-size: 11px; }
    .lk-buyer-hero__price { color: #a72e20; font-weight: 900; }
    .lk-buyer-hero__arrow { position: absolute; right: 16px; bottom: 18px; display: grid; width: 44px; height: 44px; place-items: center; border-radius: 50%; background: #fff; color: #b43123; font-size: 23px; box-shadow: 0 5px 14px rgba(79,50,35,.08); transition: transform .18s ease; }
    .lk-buyer-hero__card:hover .lk-buyer-hero__arrow { transform: translateX(3px); }

    /* The hero is styled here because it is shared by authenticated and guest buyer pages. */
    html.dark .lk-buyer-hero {
        background-color: #161210 !important;
        background-image:
            linear-gradient(135deg, rgba(22, 18, 16, .92), rgba(33, 27, 23, .78)),
            url('{{ asset('images/home-buyer.jpg') }}') !important;
        background-image:
            linear-gradient(135deg, rgba(22, 18, 16, .92), rgba(33, 27, 23, .78)),
            image-set(url('{{ asset('images/home-buyer.webp') }}') type('image/webp'), url('{{ asset('images/home-buyer.jpg') }}') type('image/jpeg')) !important;
        color: #f5efe8 !important;
        border-color: #3b2e27 !important;
        box-shadow: 0 18px 46px rgba(0, 0, 0, .24) !important;
    }
    html.dark .lk-buyer-hero__kicker { color: #eba99d; }
    html.dark .lk-buyer-hero__kicker::before { background: #eba99d; }
    html.dark .lk-buyer-hero h1 { color: #f5efe8 !important; }
    html.dark .lk-buyer-hero h1 span { color: #eba99d !important; }
    html.dark .lk-buyer-hero__description { color: #c8b7ad !important; }
    html.dark .lk-buyer-hero__button--secondary {
        background: rgba(33, 27, 23, .9);
        border-color: #514037;
        color: #f0c0b6 !important;
    }
    html.dark .lk-buyer-hero__button--secondary:hover { background: #3b2e27; }
    html.dark .lk-buyer-hero__card {
        background: #211b17;
        border-color: #514037;
        color: #f5efe8 !important;
        box-shadow: 0 20px 38px rgba(0, 0, 0, .3);
    }
    html.dark .lk-buyer-hero__card:hover { box-shadow: 0 25px 46px rgba(0, 0, 0, .4); }
    html.dark .lk-buyer-hero__image { background: #2a211c; }
    html.dark .lk-buyer-hero__placeholder { color: #d8c1b2; background: linear-gradient(145deg, #2a211c, #3b2e27); }
    html.dark .lk-buyer-hero__badge { background: #2d1414; color: #f0c0b6; box-shadow: none; }
    html.dark .lk-buyer-hero__info { background: rgba(33, 27, 23, .98); }
    html.dark .lk-buyer-hero__name { color: #f5efe8; }
    html.dark .lk-buyer-hero__meta { color: #c8b7ad; }
    html.dark .lk-buyer-hero__price { color: #eba99d; }
    html.dark .lk-buyer-hero__arrow { background: #3b2e27; color: #f0c0b6; box-shadow: none; }

    @media (max-width: 1100px) { .lk-buyer-hero { min-height: clamp(620px, calc(100svh - 96px), 760px) !important; grid-template-columns: 1fr !important; padding-bottom: 72px !important; background-position: center !important; background-size: auto calc(100% + 8px) !important; } .lk-buyer-hero__copy { padding-right: 0; } .lk-buyer-hero__products { width: min(760px, 100%); margin: 16px auto 0; } }
    @media (max-width: 900px) { .lk-buyer-hero { width: 100% !important; margin: 0 !important; } }
    @media (max-width: 640px) { .lk-buyer-hero { min-height: 0 !important; padding: 34px 18px 46px !important; border-radius: 20px !important; background-position: 38% center !important; } .lk-buyer-hero h1 { font-size: clamp(45px, 13vw, 60px) !important; } .lk-buyer-hero__actions { flex-direction: column; } .lk-buyer-hero__button { width: 100%; } .lk-buyer-hero__products { grid-template-columns: 1fr; gap: 18px; padding-top: 12px; } .lk-buyer-hero__card:first-child, .lk-buyer-hero__card:nth-child(2) { margin-top: 0; transform: none; } }
    @media (max-width: 560px) { .lk-buyer-hero { width: 100% !important; margin: 0 !important; } }
</style>
@endonce

<section class="lk-buyer-hero" aria-labelledby="buyer-hero-title">
    <div class="lk-buyer-hero__copy">
        <span class="lk-buyer-hero__kicker">Welcome to LIKHAE</span>
        <h1 id="buyer-hero-title">Everything you need,<span>in one place.</span></h1>
        <p class="lk-buyer-hero__description">Shop across a wide range of categories from trusted sellers.<br>Compare options and find everything you need for everyday living.</p>
        <div class="lk-buyer-hero__actions">
            <a href="{{ $browseUrl }}" class="lk-buyer-hero__button lk-buyer-hero__button--primary"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14l1 12H4L5 8Z"/><path d="M8 8a4 4 0 0 1 8 0"/></svg>Browse Products</a>
            <a href="{{ $categoriesUrl }}" class="lk-buyer-hero__button lk-buyer-hero__button--secondary"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Explore Categories</a>
        </div>
    </div>
    <div class="lk-buyer-hero__products" aria-label="Best-selling products">
        @forelse($heroProducts as $index => $product)
            @php
                $image = data_get($product, 'primary_image.url');
                $name = data_get($product, 'name', 'Marketplace product');
                $category = data_get($product, 'category.name');
                $slug = data_get($product, 'slug');
                $price = data_get($product, 'min_price');
            @endphp
            <a class="lk-buyer-hero__card" href="{{ $slug ? route('buyer.product-details', ['slug' => $slug]) : $browseUrl }}">
                <div class="lk-buyer-hero__image">
                    @if($image)<img src="{{ $image }}" alt="{{ $name }}" loading="{{ $index === 0 ? 'eager' : 'lazy' }}" fetchpriority="{{ $index === 0 ? 'high' : 'low' }}" decoding="async">@else<div class="lk-buyer-hero__placeholder">Product image unavailable</div>@endif
                    <span class="lk-buyer-hero__badge"><i aria-hidden="true">★</i>{{ $index === 0 ? 'Best Seller' : 'Top Pick' }}</span>
                </div>
                <div class="lk-buyer-hero__info"><h2 class="lk-buyer-hero__name">{{ $name }}</h2><div class="lk-buyer-hero__meta">@if($price !== null)<span class="lk-buyer-hero__price">₱{{ number_format((float) $price, 2) }}</span>@endif @if($category)<span>{{ $category }}</span>@endif</div><span class="lk-buyer-hero__arrow" aria-hidden="true">→</span></div>
            </a>
        @empty
            <div class="lk-buyer-hero__card"><div class="lk-buyer-hero__image"><div class="lk-buyer-hero__placeholder">Products will appear here when available.</div></div></div>
        @endforelse
    </div>
</section>
