@extends('layouts.buyer.app')

@section('title', $campaign->name)
@section('active', 'home')
@section('subtitle', 'LIKHAE Platform Event')

@push('head')
<style>
    .lk-event-detail-page { min-height: calc(100vh - 72px); padding: 24px clamp(18px, 3vw, 44px) 30px; color: #3B211B; }
    .lk-event-detail { display: grid; grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr); max-width: 1120px; min-height: min(500px, calc(100vh - 155px)); margin: 14px auto 0; overflow: hidden; border: 1px solid rgba(86,28,23,.14); border-top: 3px solid var(--lk-event-accent, #E9C7A5); border-radius: 24px; background: linear-gradient(120deg, #561C17 0%, #642920 58%, #3E130F 100%); color: #FFF8F2; box-shadow: 0 16px 36px rgba(86,28,23,.14); }
    .lk-event-detail__visual { position: relative; display: flex; min-width: 0; min-height: 100%; align-items: center; justify-content: center; overflow: hidden; padding: 26px; background: radial-gradient(ellipse at 50% 48%, rgba(255,231,207,.22), transparent 58%), linear-gradient(145deg, rgba(255,248,242,.07), rgba(30,7,5,.22)); }
    .lk-event-detail__visual::before, .lk-event-detail__visual::after { position: absolute; width: 270px; height: 270px; border: 1px solid rgba(233,199,165,.14); border-radius: 50%; content: ''; pointer-events: none; }
    .lk-event-detail__visual::after { width: 220px; height: 220px; }
    .lk-event-detail__image { position: relative; z-index: 1; display: block; width: 100%; max-height: 290px; border: 1px solid rgba(255,248,242,.18); border-radius: 16px; object-fit: contain; background: rgba(255,248,242,.04); box-shadow: 0 16px 32px rgba(20,5,4,.2); }
    .lk-event-detail__visual-placeholder { position: relative; z-index: 1; display: grid; width: 130px; height: 130px; place-items: center; border: 1px solid rgba(233,199,165,.4); border-radius: 38px; background: rgba(255,248,242,.08); color: #E9C7A5; font: 400 64px Georgia, serif; }
    .lk-event-detail__content { min-width: 0; align-self: center; padding: clamp(24px, 3.5vw, 40px); }
    .lk-event-detail__eyebrow { margin-left: 9px; color: #E9C7A5; }
    .lk-event-detail h1 { margin: 9px 0 0; color: #FFF8F2; font: 400 clamp(34px, 4vw, 48px)/1.02 Georgia, "Instrument Serif", serif; letter-spacing: -.035em; }
    .lk-event-detail__benefit { display: inline-flex; margin: 12px 0 18px; padding: 7px 13px; border: 1px solid rgba(233,199,165,.45); border-radius: 999px; background: rgba(255,248,242,.1); color: #E9C7A5; font-size: 15px; font-weight: 900; }
    .lk-event-detail__terms { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; margin: 0; }
    .lk-event-detail__terms div { padding: 11px 13px; border: 1px solid rgba(255,248,242,.18); border-radius: 13px; background: rgba(255,248,242,.08); }
    .lk-event-detail__terms .is-validity { grid-column: 1 / -1; }
    .lk-event-detail__terms dt { color: #E9C7A5; font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    .lk-event-detail__terms dd { margin: 4px 0 0; color: #FFF8F2; font-size: 13px; font-weight: 700; line-height: 1.4; }
    .lk-event-detail__code { margin-top: 12px; padding: 11px 15px; border-radius: 13px; background: #E9C7A5; color: #3E130F; }
    .lk-event-detail__code span { display: block; color: #6A3025; font-size: 10px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
    .lk-event-detail__code strong { display: inline-block; margin-top: 3px; font-size: 18px; letter-spacing: .08em; }
    .lk-event-detail__note { margin: 10px 0 0; color: #F6EFE7; font-size: 12px; line-height: 1.55; }
    .lk-event-detail__actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 15px; }
    .lk-event-detail__button { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 17px; border: 1px solid #E9C7A5; border-radius: 999px; background: #E9C7A5; color: #3E130F; font-size: 12px; font-weight: 900; text-decoration: none; transition: background .18s ease, transform .18s ease; }
    .lk-event-detail__button:hover { transform: translateY(-1px); background: #F3D9BD; }
    .lk-event-detail__button.is-secondary { border-color: rgba(255,248,242,.45); background: transparent; color: #FFF8F2; }
    .lk-event-status { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 9px; font-size: 9px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .lk-event-status.is-active { background: #E8F3EA; color: #256F4A; }
    .lk-event-status.is-upcoming { background: #F8EDD9; color: #8A5A12; }
    html.dark .lk-event-detail-page { color: #FFF8F2; }
    @media (max-width: 760px) {
        .lk-event-detail-page { min-height: 0; padding: 20px 16px 32px; }
        .lk-event-detail { grid-template-columns: minmax(0, 1fr); min-height: 0; margin-top: 12px; }
        .lk-event-detail__visual { min-height: 210px; padding: 20px; }
        .lk-event-detail__image { max-height: 230px; }
        .lk-event-detail__content { padding: 24px; }
    }
    @media (max-width: 480px) {
        .lk-event-detail__terms { grid-template-columns: 1fr; }
        .lk-event-detail__terms .is-validity { grid-column: auto; }
        .lk-event-detail__content { padding: 20px; }
        .lk-event-detail__actions > a { flex: 1; }
    }
</style>
@endpush

@section('content')
@php
    $isUpcoming = $campaign->starts_at?->isFuture() ?? false;
    $benefit = $campaign->discount_type === 'PERCENT'
        ? rtrim(rtrim(number_format((float) $campaign->discount_value, 2, '.', ''), '0'), '.').'% off'
        : '₱'.number_format((float) $campaign->discount_value, 2).' off';
    $campaignImageUrl = $campaign->image_path
        && \Illuminate\Support\Facades\Storage::disk('public')->exists($campaign->image_path)
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($campaign->image_path)
            : null;
    $themeColor = $campaign->safe_theme_color;
@endphp

<main class="lk-event-detail-page">
    <a class="lk-text-link" href="{{ route('buyer.platform-events.index') }}">← All Platform Events</a>
    <article class="lk-event-detail" @if($themeColor) style="--lk-event-accent: {{ $themeColor }}" @endif>
        <div class="lk-event-detail__visual">
        @if($campaignImageUrl)
            <img class="lk-event-detail__image" src="{{ $campaignImageUrl }}" alt="{{ $campaign->image_alt ?: $campaign->name }}">
        @else
            <div class="lk-event-detail__visual-placeholder" aria-hidden="true">L</div>
        @endif
        </div>
        <div class="lk-event-detail__content">
        <span class="lk-event-status {{ $isUpcoming ? 'is-upcoming' : 'is-active' }}">{{ $isUpcoming ? 'Upcoming' : 'Active' }}</span>
        <span class="lk-kicker lk-event-detail__eyebrow">LIKHAE Platform Campaign</span>
        <h1>{{ $campaign->name }}</h1>
        <p class="lk-event-detail__benefit">{{ $benefit }}</p>

        <dl class="lk-event-detail__terms">
            <div><dt>Minimum spend</dt><dd>{{ (float) $campaign->minimum_order_amount > 0 ? '₱'.number_format((float) $campaign->minimum_order_amount, 2) : 'No minimum spend' }}</dd></div>
            <div><dt>Max savings</dt><dd>{{ $campaign->maximum_discount_amount !== null ? '₱'.number_format((float) $campaign->maximum_discount_amount, 2) : 'No discount cap' }}</dd></div>
            <div class="is-validity"><dt>Validity period</dt><dd>{{ $campaign->starts_at?->format('M j, Y g:i A') ?? 'Starts now' }} – {{ $campaign->ends_at?->format('M j, Y g:i A') ?? 'No end date' }}</dd></div>
        </dl>

        <div class="lk-event-detail__code"><span>Voucher code</span><strong>{{ $campaign->code }}</strong></div>
        <p class="lk-event-detail__note">Add items to your cart and enter this code. Cart and Checkout will confirm which items qualify and calculate the final discount.</p>
        <div class="lk-event-detail__actions">
            <a class="lk-event-detail__button" href="{{ route('buyer.products') }}">Browse Products</a>
            <a class="lk-event-detail__button is-secondary" href="{{ route('buyer.home').'#home-offers' }}">View Offers</a>
        </div>
        </div>
    </article>
</main>
@endsection
