@extends('layouts.rider.app')

@section('title', 'Delivery Tracking - LIKHAE Rider')

@section('content')
@php
    $steps = [
        'assigned' => 'Assigned To Rider',
        'out_for_delivery' => 'Out For Delivery',
        'delivered' => 'Delivered',
        'delivery_failed' => 'Delivery Failed',
    ];
@endphp

<div class="flex flex-col gap-8">
    <section class="rounded-3xl border border-line bg-surface p-8">
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Live Delivery</span>
        <h1 class="mt-3 text-2xl font-bold text-ink">{{ $parcel['tracking'] }}</h1>
        <p class="mt-3 text-sm text-muted">{{ $parcel['status_label'] }} - {{ $parcel['address'] }}</p>
    </section>

    <section class="rounded-3xl border border-line bg-surface p-8">
        <h2 class="text-lg font-bold text-ink">Tracking Timeline</h2>
        <div class="mt-6 grid gap-4">
            @foreach($steps as $status => $label)
                @php
                    $isCurrent = $delivery->status === $status;
                    $isDone = in_array($status, ['assigned', 'out_for_delivery'], true) && in_array($delivery->status, ['out_for_delivery', 'delivered'], true);
                @endphp
                <div class="rounded-2xl border border-line p-5 {{ $isCurrent || $isDone ? 'bg-primary-soft text-primary' : 'bg-page-secondary text-muted' }}">
                    <strong>{{ $label }}</strong>
                    @if($status === 'delivery_failed' && $delivery->failure_reason)
                        <p class="mt-2 text-sm">{{ $delivery->failure_reason }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-2">
        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Recipient</h2>
            <p class="mt-4 text-sm text-muted">{{ $parcel['buyer'] }}</p>
            <p class="mt-2 text-sm text-muted">{{ $parcel['contact'] }}</p>
            <p class="mt-2 whitespace-pre-line text-sm text-muted">{{ $parcel['address'] }}</p>
        </article>
        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Actions</h2>
            <div class="mt-5 grid gap-3">
                <a href="{{ route('rider.deliveries.show', $parcel['id']) }}" class="rounded-xl bg-primary px-5 py-3 text-center text-sm font-semibold text-white">Open Delivery Details</a>
                <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($parcel['address']) }}" target="_blank" class="rounded-xl border border-line px-5 py-3 text-center text-sm font-semibold text-ink">Open Navigation</a>
                @if($parcel['contact'] !== 'No contact recorded')
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $parcel['contact']) }}" class="rounded-xl border border-line px-5 py-3 text-center text-sm font-semibold text-ink">Contact Customer</a>
                @endif
            </div>
        </article>
    </section>
</div>
@endsection
