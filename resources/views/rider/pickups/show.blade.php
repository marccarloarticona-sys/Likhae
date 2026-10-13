@extends('Rider.app')

@section('title', 'Pickup Details - LIKHAE Rider')

@push('head')
    @vite(['resources/css/shared/mapbox.css', 'resources/js/shared/mapbox.js'])
@endpush

@section('content')
<div class="flex flex-col gap-8">
    @if(session('status'))
        <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif

    <section class="rounded-3xl border border-line bg-surface p-8">
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Pickup Details</span>
        <h1 class="mt-3 text-2xl font-bold text-ink">{{ $pickup['tracking'] }}</h1>
        <p class="mt-3 text-sm text-muted">{{ $pickup['status_label'] }} - Seller: {{ $pickup['seller'] }}</p>
        <ol class="mt-5 grid gap-2 text-xs sm:grid-cols-4">
            @foreach(['Accept assignment', 'Go to seller', 'Scan and confirm parcel', 'Take parcel to sorting center'] as $index => $step)
                @php($activeStep = match($pickup['status']) { 'ASSIGNED' => 0, 'ACCEPTED' => 1, 'IN_PROGRESS' => 2, default => 3 })
                <li class="border p-3 {{ $index === $activeStep ? 'border-primary bg-primary-soft font-semibold text-primary' : ($index < $activeStep ? 'border-green-200 bg-green-50 text-green-800' : 'border-line text-muted') }}">{{ $index + 1 }}. {{ $step }}</li>
            @endforeach
        </ol>
    </section>

    <x-shared.mapbox :markers="$mapMarkers" title="Route to seller pickup" height="220px" user-location-target="seller" :rider-gps="true" :navigation="true" />

    <section class="grid gap-6 lg:grid-cols-2">
        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Parcel Information</h2>
            <div class="mt-5 flex flex-wrap gap-3">@foreach($delivery->shipment?->sellerOrder?->items ?? [] as $item)<div class="flex items-center gap-3 rounded-xl border border-line p-3"><x-product-thumbnail :item="$item" size="64"/><div><strong class="block text-sm text-ink">{{ $item->product_name }}</strong><small class="text-muted">{{ $item->variant_description ?: 'Standard' }} · Qty {{ $item->quantity }}</small></div></div>@endforeach</div>
            <dl class="mt-5 grid gap-3 text-sm">
                <div><dt class="text-muted">Buyer</dt><dd class="font-semibold text-ink">{{ $pickup['buyer'] }}</dd></div>
                <div><dt class="text-muted">Delivery Address</dt><dd class="font-semibold text-ink whitespace-pre-line">{{ $pickup['address'] }}</dd></div>
                <div><dt class="text-muted">Items</dt><dd class="font-semibold text-ink">{{ $pickup['items'] }}</dd></div>
                <div><dt class="text-muted">Order Amount</dt><dd class="font-semibold text-ink">{{ $pickup['amount'] }}</dd></div>
            </dl>
        </article>

        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Pickup Actions</h2>
            <div class="mt-5 grid gap-3">
                @if($pickup['status'] === 'ASSIGNED')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="accept"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Accept Pickup</button></form>
                @elseif($pickup['status'] === 'ACCEPTED')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="start"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Start Pickup</button></form>
                @elseif($pickup['status'] === 'IN_PROGRESS')
                    @if($verified)
                        <div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">Parcel Verified: {{ $pickup['tracking'] }}</div>
                        <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="pickup_complete"><input type="hidden" name="scan_method" value="{{ $scanMethod }}"><input type="hidden" name="scanned_code" value="{{ $pickup['tracking'] }}"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Confirm Picked Up</button></form>
                    @else
                        <p class="text-sm text-muted">Scan and verify the seller's waybill below before confirming collection.</p>
                    @endif
                @endif
                <a href="{{ route('rider.pickups') }}" class="rounded-xl border border-line px-5 py-3 text-center text-sm font-semibold text-ink">Back To Pickups</a>
            </div>
        </article>
    </section>

    @if($pickup['status'] === 'IN_PROGRESS')
        <x-parcel-scanner :action="route('rider.pickups.show', $delivery)" :tracking="request('tracking', '')" :method="$scanMethod" title="Scan and Confirm Parcel at Seller" description="Match the waybill to this accepted pickup. After confirmation, take the parcel to the sorting center for its intake scan." button="Verify Parcel" />
        @if(request()->filled('tracking') && !$verified)<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">The scanned tracking number does not match this pickup assignment.</div>@endif
    @endif
</div>
@endsection
