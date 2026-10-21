@extends('layouts.buyer.app')

@section('title', $campaign->name)
@section('active', 'home')
@section('subtitle', 'LIKHAE Platform Event')

@push('head')
<style>
    .lk-event-detail-page { min-height: 100%; padding: 40px clamp(18px, 2.4vw, 36px) 56px; color: #3B211B; }
    .lk-event-detail { max-width: 1000px; margin: 26px auto 0; padding: clamp(24px, 4vw, 44px); border: 0; border-top: 4px solid var(--lk-event-accent, #E9C7A5); border-radius: 26px; background: radial-gradient(circle at 88% 14%, rgba(193,151,113,.24), transparent 24%), linear-gradient(115deg, #561C17 0%, #642920 56%, #3E130F 100%); color: #FFF8F2; box-shadow: 0 18px 42px rgba(86,28,23,.18); }
    .lk-event-detail__image { display: block; width: 100%; max-height: 390px; margin-bottom: 24px; aspect-ratio: 2.3 / 1; border-radius: 18px; object-fit: cover; background: rgba(255,248,242,.08); }
    .lk-event-detail h1 { margin: 12px 0 0; color: #FFF8F2; font: 400 clamp(38px, 5vw, 58px)/1 "Instrument Serif", Georgia, serif; letter-spacing: -.035em; }
    .lk-event-detail__benefit { display: inline-flex; margin: 15px 0 26px; padding: 10px 16px; border: 1px solid rgba(233,199,165,.45); border-radius: 999px; background: rgba(255,248,242,.1); color: #E9C7A5; font-size: 18px; font-weight: 900; }
    .lk-event-detail__terms { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 0; }
    .lk-event-detail__terms div { padding: 16px; border: 1px solid rgba(255,248,242,.18); border-radius: 16px; background: rgba(255,248,242,.08); }
    .lk-event-detail__terms .is-validity { grid-column: 1 / -1; }
    .lk-event-detail__terms dt { color: #E9C7A5; font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    .lk-event-detail__terms dd { margin: 6px 0 0; color: #FFF8F2; font-size: 14px; font-weight: 700; line-height: 1.5; }
    .lk-event-detail__code { margin-top: 20px; padding: 17px 18px; border-radius: 16px; background: #E9C7A5; color: #3E130F; }
    .lk-event-detail__code span { display: block; color: #6A3025; font-size: 10px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
    .lk-event-detail__code strong { display: inline-block; margin-top: 5px; font-size: 20px; letter-spacing: .08em; }
    .lk-event-detail__note { margin: 18px 0 0; color: #F6EFE7; font-size: 13px; line-height: 1.7; }
    .lk-event-detail__actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
    .lk-event-detail__button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 20px; border: 1px solid #E9C7A5; border-radius: 999px; background: #E9C7A5; color: #3E130F; font-size: 12px; font-weight: 900; text-decoration: none; }
    .lk-event-detail__button.is-secondary { border-color: rgba(255,248,242,.45); background: transparent; color: #FFF8F2; }
    .lk-event-status { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 9px; font-size: 9px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .lk-event-status.is-active { background: #E8F3EA; color: #256F4A; }
    .lk-event-status.is-upcoming { background: #F8EDD9; color: #8A5A12; }
    html.dark .lk-event-detail-page { color: #FFF8F2; }
    @media (max-width: 620px) { .lk-event-detail__terms { grid-template-columns: 1fr; } }
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
        @if($campaignImageUrl)
            <img class="lk-event-detail__image" src="{{ $campaignImageUrl }}" alt="{{ $campaign->image_alt ?: $campaign->name }}">
        @endif
        <span class="lk-event-status {{ $isUpcoming ? 'is-upcoming' : 'is-active' }}">{{ $isUpcoming ? 'Upcoming' : 'Active' }}</span>
        <span class="lk-kicker" style="margin-left: 10px">LIKHAE Platform Campaign</span>
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
            <a class="lk-event-detail__button is-secondary" href="{{ route('buyer.rewards') }}">View Offers</a>
        </div>
    </article>
</main>
@endsection
