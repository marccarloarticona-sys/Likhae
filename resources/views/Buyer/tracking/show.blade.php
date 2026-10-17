@extends('layouts.buyer')
@section('title', 'Track Parcel')
@section('content')
@php
    $timelineLabels = [
        'PLACED' => 'Order placed',
        'CONFIRMED' => 'Seller confirmed order',
        'PREPARING' => 'Preparing parcel',
        'READY_FOR_PICKUP' => 'Ready for pickup',
        'PICKED_UP' => 'Picked up from seller',
        'AT_SORTING_CENTER' => 'Arrived at sorting center',
        'SORTED' => 'Sorted by destination',
        'ASSIGNED_TO_RIDER' => 'Delivery rider assigned',
        'OUT_FOR_DELIVERY' => 'Out for delivery',
        'DELIVERY_FAILED' => 'Delivery attempt unsuccessful',
        'DELIVERED' => 'Delivered',
        'COMPLETED' => 'Buyer confirmed receipt',
        'RETURNED' => 'Parcel returned',
    ];
    $timelineRows = collect();
    foreach ($shipment->events as $event) {
        $cancelled = str_starts_with((string) $event->notes, 'BUYER_ORDER_CANCELLED:')
            || str_starts_with((string) $event->notes, 'SELLER_ORDER_CANCELLED:');
        if ($cancelled) {
            $timelineRows->push(['status' => 'CANCELLED', 'label' => 'Order cancelled', 'event' => $event]);
        } elseif (isset($timelineLabels[$event->status])) {
            $label = $event->status === 'COMPLETED'
                && str_starts_with((string) $event->notes, 'Order automatically confirmed received after 3 days')
                ? 'Automatically confirmed receipt'
                : $timelineLabels[$event->status];
            $timelineRows->push(['status' => $event->status, 'label' => $label, 'event' => $event]);
        }
    }
    $hasCancellation = $timelineRows->contains(fn ($row): bool => $row['status'] === 'CANCELLED');
    $currentRows = $hasCancellation
        ? $timelineRows->filter(fn ($row): bool => $row['status'] === 'CANCELLED')
        : $timelineRows->filter(fn ($row): bool => $row['status'] === $shipment->current_status);
    $currentTimelineIndex = $currentRows->keys()->last();
    if ($currentTimelineIndex === null && ! $hasCancellation && isset($timelineLabels[$shipment->current_status])) {
        $timelineRows->push(['status' => $shipment->current_status, 'label' => $timelineLabels[$shipment->current_status], 'event' => null]);
        $currentTimelineIndex = $timelineRows->keys()->last();
    }
@endphp
<div class="space-y-6">
    <h1 class="text-2xl font-bold">Tracking {{ $shipment->tracking_number }}</h1>
    <div class="rounded-2xl border bg-white p-5">
        <p>Status: {{ str($shipment->current_status)->replace('_', ' ')->title() }}</p>
        @if($shipment->orderItem)
            <p class="mt-2">Parcel: {{ $shipment->orderItem->product_name }} · SKU {{ $shipment->orderItem->sku ?: 'Not provided' }} · Qty {{ $shipment->orderItem->quantity }}</p>
        @endif
        <p>Destination: {{ $shipment->destination_barangay_name }}, {{ $shipment->destination_municipality_name }}</p>
    </div>
    <x-shared.mapbox :markers="$mapMarkers" title="Parcel location" height="220px" :allow-user-location="false" navigation/>
    <div class="rounded-2xl border bg-white p-5"><h2 class="font-bold">Timeline</h2><ol class="mt-3 space-y-3" aria-label="Shipment progress timeline">@foreach($timelineRows as $index => $row)<li class="flex items-start gap-3" @if($index === $currentTimelineIndex) aria-current="step" @endif><span class="mt-1 h-3 w-3 shrink-0 rounded-full {{ $index === $currentTimelineIndex ? 'bg-red-900 ring-4 ring-red-100' : ($row['event'] ? 'bg-red-900' : 'border-2 border-stone-300') }}" aria-hidden="true"></span><div><strong class="text-sm {{ $index === $currentTimelineIndex ? 'text-red-900' : 'text-stone-800' }}">{{ $row['label'] }}</strong>@if($row['event']?->occurred_at)<time class="mt-1 block text-xs text-stone-500" datetime="{{ $row['event']->occurred_at->toIso8601String() }}">{{ $row['event']->occurred_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</time>@endif</div></li>@endforeach</ol></div>
</div>
@endsection
