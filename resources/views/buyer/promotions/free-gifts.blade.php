@extends('layouts.buyer.app')

@section('title', 'Free Gifts')
@section('active', 'products')
@section('subtitle', 'Find Buy X Get Y offers from LIKHAE sellers.')

@push('head')
<style>
    .lk-gifts-page {
        min-height: 100%;
        padding: 30px clamp(16px, 3vw, 44px) 56px;
        color: #3B211B;
    }

    .lk-gifts-hero {
        position: relative;
        display: flex;
        min-height: 220px;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
        overflow: hidden;
        padding: clamp(26px, 4vw, 48px);
        border: 1px solid #EBD9CB;
        border-radius: 24px;
        background: radial-gradient(circle at 83% 18%, rgba(255,255,255,.76), transparent 30%), linear-gradient(115deg, #F8EDE5 0%, #F5E2D7 58%, #EDD1C2 100%);
    }

    .lk-gifts-hero-copy { position: relative; z-index: 1; max-width: 620px; }
    .lk-gifts-eyebrow { display: inline-flex; align-items: center; gap: 8px; color: #7B2C23; font-size: 10px; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; }
    .lk-gifts-eyebrow::before { width: 20px; height: 1px; background: currentColor; content: ''; }
    .lk-gifts-heading { margin: 10px 0 0; color: #561C17; font-family: Georgia, "Instrument Serif", serif; font-size: clamp(36px, 4vw, 54px); font-weight: 400; letter-spacing: -.035em; line-height: .98; }
    .lk-gifts-description { max-width: 520px; margin: 14px 0 0; color: #805F50; font-size: 14px; line-height: 1.65; }
    .lk-gifts-browse { display: inline-flex; align-items: center; gap: 10px; margin-top: 22px; color: #6C291F; font-size: 13px; font-weight: 700; text-decoration: none; }
    .lk-gifts-browse span { display: inline-grid; width: 25px; height: 25px; place-items: center; border: 1px solid #D6B8A6; border-radius: 50%; transition: transform .18s ease, background .18s ease; }
    .lk-gifts-browse:hover span { transform: translateX(3px); background: rgba(255,255,255,.55); }

    .lk-gifts-art { position: relative; display: grid; width: 200px; height: 160px; flex: 0 0 200px; place-items: center; }
    .lk-gifts-art::before, .lk-gifts-art::after { position: absolute; border: 1px solid rgba(123,44,35,.17); border-radius: 50%; content: ''; }
    .lk-gifts-art::before { width: 158px; height: 158px; }
    .lk-gifts-art::after { width: 120px; height: 120px; }
    .lk-gifts-gift { position: relative; z-index: 1; display: grid; width: 88px; height: 88px; place-items: center; transform: rotate(-7deg); border: 1px solid rgba(123,44,35,.12); border-radius: 25px; background: #FFF9F3; box-shadow: 0 18px 34px rgba(86,28,23,.14); color: #7B2C23; font-size: 42px; }
    .lk-gifts-spark { position: absolute; z-index: 2; color: #A76548; font-size: 20px; }
    .lk-gifts-spark-one { top: 18px; right: 28px; }
    .lk-gifts-spark-two { bottom: 18px; left: 18px; font-size: 13px; }

    .lk-gifts-steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 18px 0 30px; }
    .lk-gifts-step { display: flex; align-items: center; gap: 12px; min-height: 62px; padding: 12px 16px; border: 1px solid #EFE3D9; border-radius: 14px; background: rgba(255,253,249,.7); }
    .lk-gifts-step-number { display: grid; width: 30px; height: 30px; flex: 0 0 30px; place-items: center; border-radius: 50%; background: #F4E6DF; color: #7B2C23; font-size: 12px; font-weight: 800; }
    .lk-gifts-step-text { color: #76594C; font-size: 12px; line-height: 1.4; }
    .lk-gifts-step-text strong { display: block; margin-bottom: 2px; color: #432720; font-size: 12px; }

    .lk-gifts-offers { margin-top: 0 !important; }
    .lk-gifts-offers section > div:first-child { margin-bottom: 14px; }
    .lk-gifts-offers section > div:first-child h2 { margin-top: 5px; color: #3B211B; font-size: 20px; letter-spacing: -.02em; }
    .lk-gifts-offers section > div:first-child p { margin-top: 4px; color: #8A6D5D; font-size: 13px; }
    .lk-gifts-offers article { display: grid; grid-template-columns: 94px minmax(0, 1fr); column-gap: 20px; row-gap: 12px; padding: 22px; border-color: #E9D9CD; border-radius: 20px; background: #FFFDFA; box-shadow: 0 8px 24px rgba(74,39,28,.045); transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
    .lk-gifts-offers article:hover { transform: translateY(-2px); border-color: #DCC2B1; box-shadow: 0 14px 30px rgba(74,39,28,.09); }
    .lk-gifts-offers article > div:first-child { grid-column: 1; grid-row: 1 / span 2; align-self: start; margin: 1px 0 0; }
    .lk-gifts-offers article > div:first-child img { width: 58px; height: 58px; border: 3px solid #FFFDFA; border-radius: 17px; box-shadow: 0 3px 10px rgba(66,37,28,.12); }
    .lk-gifts-offers article > div:nth-child(2) { grid-column: 2; }
    .lk-gifts-offers article > div:nth-child(2) > div:first-child { min-width: 0; }
    .lk-gifts-offers article > div:nth-child(2) span { border-radius: 999px; }
    .lk-gifts-offers article h3 { color: #43231D; font-size: 16px; }
    .lk-gifts-offers article p { line-height: 1.5; }
    .lk-gifts-offers article form { grid-column: 2; align-items: end; gap: 10px; margin-top: 0; }
    .lk-gifts-offers article form label { color: #60473B; font-size: 11px; }
    .lk-gifts-offers article form input, .lk-gifts-offers article form select { min-height: 42px; border-color: #E4D3C6; border-radius: 11px; background-color: #FFFEFC; color: #3B211B; }
    .lk-gifts-offers article form button { min-height: 42px; border-radius: 11px; }
    .lk-gifts-offers article form button[type="submit"]:first-of-type { border-color: #7B2C23; }
    .lk-gifts-offers article > p:last-child { grid-column: 2; margin-top: 0; color: #907768; }

    .lk-gifts-empty { padding: 52px 24px; border-color: #E7D8CC; border-radius: 22px; background: #FFFDFA; }
    @media (max-width: 700px) {
        .lk-gifts-page { padding-top: 20px; }
        .lk-gifts-hero { min-height: 0; padding: 26px 22px; border-radius: 20px; }
        .lk-gifts-art { width: 112px; height: 112px; flex-basis: 112px; }
        .lk-gifts-art::before { width: 108px; height: 108px; }
        .lk-gifts-art::after { width: 82px; height: 82px; }
        .lk-gifts-gift { width: 60px; height: 60px; border-radius: 19px; font-size: 30px; }
        .lk-gifts-steps { grid-template-columns: 1fr; gap: 8px; margin: 12px 0 24px; }
        .lk-gifts-step { min-height: 54px; }
    }
    @media (max-width: 520px) {
        .lk-gifts-hero { align-items: flex-start; }
        .lk-gifts-art { position: absolute; right: -20px; bottom: -14px; opacity: .42; }
        .lk-gifts-hero-copy { max-width: 100%; }
        .lk-gifts-description { max-width: 310px; }
        .lk-gifts-offers article { grid-template-columns: minmax(0, 1fr); gap: 12px; padding: 17px; }
        .lk-gifts-offers article > div:first-child { grid-column: 1; grid-row: auto; }
        .lk-gifts-offers article > div:nth-child(2), .lk-gifts-offers article form, .lk-gifts-offers article > p:last-child { grid-column: 1; }
        .lk-gifts-offers article form { align-items: stretch; }
        .lk-gifts-offers article form label { flex: 1 1 100%; }
        .lk-gifts-offers article form input, .lk-gifts-offers article form select { width: 100%; }
    }
</style>
@endpush

@section('content')
<main class="lk-gifts-page">
    <section class="lk-gifts-hero" aria-labelledby="buyer-gifts-heading">
        <div class="lk-gifts-hero-copy">
            <span class="lk-gifts-eyebrow">A little extra in every order</span>
            <h1 id="buyer-gifts-heading" class="lk-gifts-heading">Free Gifts &amp; Rewards</h1>
            <p class="lk-gifts-description">Discover seller offers, choose your reward, and add it to your cart with the items you already love.</p>
            <a class="lk-gifts-browse" href="{{ route('buyer.products') }}">Browse products <span aria-hidden="true">→</span></a>
        </div>
        <div class="lk-gifts-art" aria-hidden="true">
            <span class="lk-gifts-spark lk-gifts-spark-one">✦</span>
            <span class="lk-gifts-gift">🎁</span>
            <span class="lk-gifts-spark lk-gifts-spark-two">✦</span>
        </div>
    </section>

    <div class="lk-gifts-steps" aria-label="How free gift offers work">
        <div class="lk-gifts-step"><span class="lk-gifts-step-number">1</span><span class="lk-gifts-step-text"><strong>Choose an offer</strong>Check what qualifies and what’s included.</span></div>
        <div class="lk-gifts-step"><span class="lk-gifts-step-number">2</span><span class="lk-gifts-step-text"><strong>Select your reward</strong>Pick an eligible variation when needed.</span></div>
        <div class="lk-gifts-step"><span class="lk-gifts-step-number">3</span><span class="lk-gifts-step-text"><strong>Claim in your cart</strong>Your offer is confirmed with available stock.</span></div>
    </div>

    @if($promotions->isNotEmpty())
        <div class="lk-gifts-offers">
            <x-buyer.seller-promotions :promotions="$promotions" heading="Available offers" eyebrow="Picked by LIKHAE sellers" />
        </div>
    @else
        <div class="lk-gifts-empty rounded-2xl border border-dashed px-6 text-center" role="status">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#F4E6DF] text-[#7B2C23]" aria-hidden="true">✦</div>
            <h2 class="mt-4 text-lg font-semibold text-[#3B211B]">No free gift offers are available right now</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-[#987865]">New seller rewards will show up here as soon as their qualifying items and gift stock are ready.</p>
            <a href="{{ route('buyer.products') }}" class="mt-5 inline-flex rounded-xl bg-[#561C17] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#3E130F]">Explore products</a>
        </div>
    @endif
</main>
@endsection
