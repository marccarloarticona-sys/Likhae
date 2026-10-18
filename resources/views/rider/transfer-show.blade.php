@extends('Rider.app')

@section('title', 'Transfer '.$manifest->manifest_number.' - LIKHAE')

@push('head')
    @vite(['resources/css/shared/mapbox.css', 'resources/js/shared/mapbox.js'])
@endpush

@section('content')
@php($releasedParcelCount = $manifest->items->filter(fn ($item) => $item->transferLeg?->status === \App\Models\Logistics\ShipmentTransferLeg::RELEASED)->count())
<div class="mx-auto flex w-full max-w-5xl flex-col gap-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-primary">Inter-hub transfer</p>
            <h1 class="mt-1 text-2xl font-bold text-ink">{{ $manifest->manifest_number }}</h1>
            <p class="mt-2 text-sm text-muted">{{ $manifest->originLogisticsCenter?->business_name }} &rarr; {{ $manifest->destinationLogisticsCenter?->business_name }}</p>
        </div>
        <a href="{{ route('rider.transfers') }}" class="rounded-lg border border-line px-4 py-2 text-sm font-semibold text-ink">All transfers</a>
    </header>

    @if(session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>
    @endif

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-base font-semibold text-ink">Transfer status: {{ str($manifest->status)->headline() }}</h2>
        @if($manifest->status === 'SEALED')
            <p class="mt-2 text-sm text-muted">
                @if($manifest->driver_accepted_at)
                    Assignment accepted {{ $manifest->driver_accepted_at->format('M d, Y g:i A') }}. The origin hub has released {{ $releasedParcelCount }} of {{ $manifest->items->count() }} parcels. Start Transfer becomes available after the final parcel is handed off.
                @else
                    Accept the assignment, then wait for the origin hub to scan the parcel handoff and release the manifest. Start Transfer becomes available after release.
                @endif
            </p>
        @elseif($manifest->status === 'RELEASED')
            <p class="mt-2 text-sm text-muted">The origin hub has completed parcel handoff. Start transport when you are ready to depart for {{ $manifest->destinationLogisticsCenter?->business_name }}.</p>
        @elseif($manifest->status === 'IN_TRANSIT')
            <p class="mt-2 text-sm text-muted">This transfer is in transit to {{ $manifest->destinationLogisticsCenter?->business_name }}.</p>
        @else
            <p class="mt-2 text-sm text-muted">The origin hub is still preparing this transfer.</p>
        @endif
    </section>

    @if($manifest->status === 'IN_TRANSIT')
        <section class="rounded-xl border border-line bg-surface p-5">
            <h2 class="text-base font-semibold text-ink">Live route to destination hub</h2>
            <p class="mt-1 text-sm text-muted">Your device location updates on this map while this page stays open. Allow location access to see your position and the receiving hub.</p>
            @if($mapMarkers)
                <x-shared.mapbox
                    :markers="$mapMarkers"
                    title="Driver location and destination"
                    height="340px"
                    user-location-target="destination"
                    :rider-gps="true"
                    :live-device-gps="true"
                    :navigation="true"
                />
            @else
                <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">The destination hub has no saved map pin yet. Ask Logistics to add coordinates to the hub address.</p>
            @endif
        </section>
    @endif

    <div class="flex flex-col gap-4">
        @if($manifest->status === 'SEALED' && ! $manifest->driver_accepted_at)
            <form method="POST" action="{{ route('rider.transfers.accept', $manifest->id) }}">
                @csrf
                <button class="rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white">Accept transfer</button>
            </form>
        @endif

        @if($manifest->status === 'SEALED')
            <section class="grid gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <h2 class="font-semibold text-ink">Start transfer</h2>
                <p class="text-sm text-amber-900">Waiting for the origin hub to finish parcel handoff ({{ $releasedParcelCount }} of {{ $manifest->items->count() }} released). Start Transfer appears after the final parcel is released.</p>
                <button type="button" disabled class="justify-self-start rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-900 opacity-70">Waiting for handoff scan</button>
            </section>
        @endif

        @if(in_array($manifest->status, ['DRAFT', 'SEALED'], true) && ! $manifest->driver_accepted_at)
            <form method="POST" action="{{ route('rider.transfers.decline', $manifest->id) }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4">
                @csrf
                <label class="grid gap-1 text-sm font-semibold text-ink">Reason for declining
                    <textarea name="reason" required maxlength="1000" rows="2" class="rounded-lg border border-line px-3 py-2">{{ old('reason') }}</textarea>
                </label>
                <button class="justify-self-start rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700">Decline transfer</button>
            </form>
        @endif

        @if($manifest->status === 'RELEASED')
            <form method="POST" action="{{ route('rider.transfers.start', $manifest->id) }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4">
                @csrf
                <div>
                    <h2 class="font-semibold text-ink">Start transfer</h2>
                    <p class="mt-1 text-sm text-muted">All parcels have been released by the origin hub. Start the trip when you are ready to depart.</p>
                </div>
                <button class="justify-self-start rounded-lg bg-primary px-4 py-2 font-semibold text-white">Start Transfer</button>
            </form>
        @endif
    </div>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-base font-semibold text-ink">Parcels in this transfer</h2>
        <div class="mt-3 grid gap-3">
            @forelse($manifest->items as $item)
                <div class="rounded-lg border border-line bg-white p-4">
                    <strong class="text-sm text-ink">{{ $item->transferLeg?->shipment?->tracking_number }}</strong>
                    <p class="mt-1 text-xs text-muted">Leg #{{ $item->transfer_leg_id }} &middot; {{ str($item->status)->headline() }}</p>
                    @if($item->transferLeg?->shipment?->orderItem?->hasAdditionalCare())
                        <span class="mt-2 inline-block rounded border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-bold uppercase text-amber-900">Fragile / Additional Care</span>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">No parcels have been added to this transfer.</p>
            @endforelse
        </div>
    </section>

    <p class="text-xs text-muted">The destination logistics hub records arrival and checks each parcel before custody is transferred.</p>
</div>
@endsection
