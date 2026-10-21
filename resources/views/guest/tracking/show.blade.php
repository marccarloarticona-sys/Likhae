@extends('layouts.guest.app')

@section('title', 'Track '.$tracking)

@section('content')
<main class="lk-page-narrow py-10">
    <header class="lk-page-title"><div><span class="lk-kicker">Parcel tracking</span><h1>{{ $tracking }}</h1><p>{{ $courier }} · {{ $city }}</p></div><span class="lk-btn lk-btn-light">{{ str($status)->headline() }}</span></header>
    <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
        <h2 class="text-base font-bold text-stone-900">Tracking timeline</h2>
        <ol class="mt-5 space-y-4">@forelse($events as $event)<li class="border-l-2 border-red-800 pl-4"><strong class="block text-sm text-stone-900">{{ str($event->status)->headline() }}</strong><time class="text-xs text-stone-500">{{ $event->occurred_at?->format('M d, Y g:i A') }}</time></li>@empty<li class="text-sm text-stone-500">Shipment preparation is in progress.</li>@endforelse</ol>
    </section>
</main>
@endsection
