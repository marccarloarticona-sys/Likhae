@extends('layouts.buyer.app')

@section('title', 'Platform Events')
@section('active', 'home')
@section('subtitle', 'Explore LIKHAE platform campaigns.')

@push('head')
<style>
    .lk-events-page { min-height: 100%; padding: 40px clamp(18px, 2.4vw, 36px) 56px; color: #3B211B; }
    .lk-events-heading { margin: 0; color: #561C17; font-family: "Instrument Serif", Georgia, serif; font-size: clamp(34px, 3vw, 46px); font-weight: 400; letter-spacing: -.035em; line-height: 1; }
    .lk-events-intro { margin: 10px 0 24px; color: #987865; font-size: 13px; line-height: 1.7; }
    .lk-events-empty { padding: 28px; border: 1px solid #EADCCC; border-radius: 22px; background: #FFFDF9; color: #6C4936; }
    .lk-events-empty h2 { margin: 0; color: #3B211B; font: 400 28px/1.1 "Instrument Serif", Georgia, serif; }
    .lk-events-empty p { margin: 8px 0 0; font-size: 13px; }
    html.dark .lk-events-page { color: #FFF8F2; }
    html.dark .lk-events-heading, html.dark .lk-events-empty h2 { color: #FFF8F2; }
    html.dark .lk-events-intro, html.dark .lk-events-empty { color: #C8B7AD; }
    html.dark .lk-events-empty { border-color: #49342B; background: #241A17; }
</style>
@endpush

@section('content')
<main class="lk-events-page">
    <a class="lk-text-link" href="{{ route('buyer.home') }}">← Back to Home</a>
    <header style="margin-top: 24px">
        <span class="lk-kicker">LIKHAE Marketplace</span>
        <h1 class="lk-events-heading">Platform Events</h1>
        <p class="lk-events-intro">Active and upcoming marketplace campaigns, with their terms and validity.</p>
    </header>

    @if($campaigns->isNotEmpty())
        <div class="lk-platform-events-grid {{ $campaigns->count() === 1 ? 'is-single-event' : '' }}">
            @foreach($campaigns as $campaign)
                @include('Buyer.platform-events.partials.campaign-card', ['campaign' => $campaign])
            @endforeach
        </div>
    @else
        <div class="lk-events-empty" role="status">
            <h2>No scheduled events right now</h2>
            <p>When a marketplace-wide event is scheduled, its dates and details will appear here.</p>
        </div>
    @endif
</main>
@endsection
