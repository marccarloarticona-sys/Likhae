@extends('Logistics.app')
@section('title','Parcel '.$shipment->tracking_number)
@section('content')
<div class="space-y-6">
    <header><a class="text-sm text-primary" href="{{ route('logistics.parcels') }}">â† Back to Parcels</a><h1 class="mt-3 text-2xl font-bold text-ink">{{ $shipment->tracking_number }}</h1><p class="mt-1 text-sm font-semibold text-primary">{{ str($shipment->current_status)->headline() }}</p></header>
    <div class="flex flex-wrap gap-2">@if($shipment->sellerOrder?->sellerProfile?->user)<a class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink" href="{{ route('logistics.messages', ['recipient_user_id' => $shipment->sellerOrder->sellerProfile->user_id, 'shipment_id' => $shipment->id]) }}">Message Seller</a>@endif @php($assignedRider = $shipment->riderAssignments->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'])->sortByDesc('id')->first()) @if($assignedRider?->riderProfile?->user)<a class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink" href="{{ route('logistics.messages', ['recipient_user_id' => $assignedRider->riderProfile->user_id, 'shipment_id' => $shipment->id]) }}">Message Assigned Rider</a>@endif</div>
    <x-shared.mapbox :markers="$mapMarkers" title="Shipment location" height="220px" user-location-target="destination" navigation />
    @if($shipment->orderItem?->hasAdditionalCare())<div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold uppercase text-amber-900">Fragile / Additional Care â€” handling flag</div>@endif
    <section class="grid gap-4 md:grid-cols-2"><article class="border border-line bg-surface p-5"><h2 class="font-semibold">Order and destination</h2><dl class="mt-4 space-y-2 text-sm"><div><dt class="text-muted">Order</dt><dd>{{ $shipment->sellerOrder?->seller_order_number }}</dd></div><div><dt class="text-muted">Seller</dt><dd>{{ $shipment->sellerOrder?->sellerProfile?->business_name }}</dd></div><div><dt class="text-muted">Buyer</dt><dd>{{ $shipment->sellerOrder?->order?->buyer?->name }}</dd></div><div><dt class="text-muted">Address</dt><dd>{{ $shipment->sellerOrder?->order?->address?->formatted() }}</dd></div><div><dt class="text-muted">Delivery area</dt><dd>{{ $shipment->serviceArea?->name ?? 'Not assigned' }}</dd></div></dl></article>
    <article class="border border-line bg-surface p-5"><h2 class="font-semibold">Items</h2><div class="mt-4 space-y-3">@foreach(($shipment->orderItem ? collect([$shipment->orderItem]) : ($shipment->sellerOrder?->items ?? collect())) as $item)<div class="flex items-center justify-between gap-3 text-sm"><div class="flex items-center gap-3"><x-product-thumbnail :item="$item" size="64"/><span><strong class="block">{{ $item->product_name }}</strong><small class="text-muted">{{ $item->variant_description ?: 'Standard' }}</small></span></div><strong>Ã— {{ $item->quantity }}</strong></div>@endforeach</div></article></section>
    <section class="border border-line bg-surface p-5"><h2 class="font-semibold">Shipment history</h2><ol class="mt-4 space-y-3">@forelse($shipment->events as $event)<li class="border-l-2 border-primary pl-4"><strong class="text-sm">{{ str($event->status)->headline() }}</strong><p class="text-sm text-muted">{{ $event->notes }}</p><small class="text-muted">{{ $event->occurred_at?->format('M d, Y g:i A') }}</small></li>@empty<li class="text-sm text-muted">No events recorded.</li>@endforelse</ol></section>
    @php($deliveryProof = $shipment->deliveryAttempts->where('status', 'DELIVERED')->whereNotNull('proof_path')->sortByDesc('attempted_at')->first())
    @if($deliveryProof)
        <section class="border border-line bg-surface p-5">
            <h2 class="font-semibold">Proof of delivery</h2>
            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($deliveryProof->proof_path) }}" target="_blank" rel="noopener" class="mt-4 inline-block">
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($deliveryProof->proof_path) }}" alt="Delivery proof for {{ $shipment->tracking_number }}" class="max-h-96 max-w-full border border-line object-contain">
            </a>
            <p class="mt-2 text-sm text-muted">Submitted {{ $deliveryProof->attempted_at?->format('M d, Y g:i A') }}</p>
        </section>
    @endif
</div>
@endsection
