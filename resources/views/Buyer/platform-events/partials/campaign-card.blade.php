@once
    @push('head')
    <style>
        .lk-platform-events-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .lk-platform-events-grid.is-single-event { grid-template-columns: minmax(0, 1fr); }
        .lk-event-card { position: relative; overflow: hidden; display: grid; gap: 20px; padding: clamp(22px, 3vw, 34px); border: 0; border-top: 4px solid var(--lk-event-accent, #E9C7A5); border-radius: 24px; background: radial-gradient(circle at 86% 18%, rgba(193,151,113,.24), transparent 24%), linear-gradient(115deg, #561C17 0%, #642920 56%, #3E130F 100%); color: #FFF8F2; box-shadow: 0 18px 42px rgba(86,28,23,.18); }
        .lk-event-card__image { width: 100%; max-height: 260px; aspect-ratio: 2.5 / 1; border-radius: 16px; object-fit: cover; background: rgba(255,248,242,.08); }
        .lk-event-card__top { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .lk-event-card__eyebrow { color: #E9C7A5; font-size: 10px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
        .lk-event-status { display: inline-flex; align-items: center; border-radius: 999px; padding: 6px 11px; font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .lk-event-status.is-active { background: #E8F3EA; color: #256F4A; }
        .lk-event-status.is-upcoming { background: #F8EDD9; color: #8A5A12; }
        .lk-event-card__hero { display: flex; align-items: center; justify-content: space-between; gap: 24px; }
        .lk-event-card__title { margin: 0; color: #FFF8F2; font: 400 clamp(34px, 4vw, 52px)/1 "Instrument Serif", Georgia, serif; letter-spacing: -.025em; }
        .lk-event-card__offer { min-width: 170px; padding: 13px 24px; border: 1px solid rgba(233,199,165,.5); border-radius: 18px; background: rgba(255,248,242,.1); text-align: center; }
        .lk-event-card__offer strong { display: block; color: #FFF8F2; font-size: clamp(34px, 4vw, 48px); font-weight: 800; letter-spacing: -.04em; line-height: 1; }
        .lk-event-card__offer span { display: block; margin-top: 5px; color: #E9C7A5; font-size: 10px; font-weight: 900; letter-spacing: .22em; }
        .lk-event-card__details { display: grid; grid-template-columns: minmax(150px, .8fr) minmax(150px, .8fr) minmax(300px, 1.8fr); gap: 18px; margin: 0; }
        .lk-event-card__details > div { padding-top: 13px; border-top: 1px solid rgba(255,248,242,.2); }
        .lk-event-card__details dt { color: #E9C7A5; font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .lk-event-card__details dd { margin: 5px 0 0; color: #FFF8F2; font-size: 14px; font-weight: 700; line-height: 1.5; }
        .lk-event-card__footer { display: flex; justify-content: flex-end; }
        .lk-event-card__action { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 44px; padding: 0 20px; border-radius: 999px; background: #E9C7A5; color: #3E130F; font-size: 12px; font-weight: 900; text-decoration: none; transition: transform .18s ease, background .18s ease; }
        .lk-event-card__action:hover { transform: translateY(-1px); background: #F3D9BD; }
        @media (max-width: 700px) {
            .lk-platform-events-grid { grid-template-columns: minmax(0, 1fr); }
            .lk-event-card__details { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .lk-event-card__validity { grid-column: 1 / -1; }
        }
        @media (max-width: 520px) {
            .lk-event-card__hero { align-items: flex-start; flex-direction: column; gap: 16px; }
            .lk-event-card__offer { min-width: 0; }
            .lk-event-card__details { grid-template-columns: minmax(0, 1fr); }
            .lk-event-card__validity { grid-column: 1; }
            .lk-event-card__footer { justify-content: flex-start; }
        }
    </style>
    @endpush
@endonce

@php
    $isUpcoming = $campaign->starts_at?->isFuture() ?? false;
    $discountHeadline = $campaign->discount_type === 'PERCENT'
        ? rtrim(rtrim(number_format((float) $campaign->discount_value, 2, '.', ''), '0'), '.').'%'
        : '₱'.number_format((float) $campaign->discount_value, 2);
    $campaignImageUrl = $campaign->image_path
        && \Illuminate\Support\Facades\Storage::disk('public')->exists($campaign->image_path)
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($campaign->image_path)
            : null;
    $themeColor = $campaign->safe_theme_color;
@endphp

<article class="lk-event-card" @if($themeColor) style="--lk-event-accent: {{ $themeColor }}" @endif>
    @if($campaignImageUrl)
        <img class="lk-event-card__image" src="{{ $campaignImageUrl }}" alt="{{ $campaign->image_alt ?: $campaign->name }}" loading="lazy">
    @endif
    <div class="lk-event-card__top">
        <span class="lk-event-status {{ $isUpcoming ? 'is-upcoming' : 'is-active' }}">
            {{ $isUpcoming ? 'Upcoming' : 'Active' }}
        </span>
        <span class="lk-event-card__eyebrow">LIKHAE Platform Event</span>
    </div>

    <div class="lk-event-card__hero">
        <h3 class="lk-event-card__title">{{ $campaign->name }}</h3>
        <div class="lk-event-card__offer" aria-label="{{ $discountHeadline }} off">
            <strong>{{ $discountHeadline }}</strong>
            <span>OFF</span>
        </div>
    </div>

    <dl class="lk-event-card__details">
        <div>
            <dt>Minimum spend</dt>
            <dd>{{ (float) $campaign->minimum_order_amount > 0 ? '₱'.number_format((float) $campaign->minimum_order_amount, 2) : 'No minimum spend' }}</dd>
        </div>
        <div>
            <dt>Max savings</dt>
            <dd>{{ $campaign->maximum_discount_amount !== null ? '₱'.number_format((float) $campaign->maximum_discount_amount, 2) : 'No discount cap' }}</dd>
        </div>
        <div class="lk-event-card__validity">
            <dt>Valid period</dt>
            <dd>{{ $campaign->starts_at?->format('M j, Y g:i A') ?? 'Starts now' }} – {{ $campaign->ends_at?->format('M j, Y g:i A') ?? 'No end date' }}</dd>
        </div>
    </dl>

    <div class="lk-event-card__footer">
        <a class="lk-event-card__action" href="{{ route('buyer.platform-events.show', $campaign) }}">View Event <span aria-hidden="true">→</span></a>
    </div>
</article>
