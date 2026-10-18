@extends('Rider.app')

@section('title', 'Pickup Batch #'.$pickup->id.' - LIKHAE Rider')

@push('head')
    @vite(['resources/css/shared/mapbox.css', 'resources/js/shared/mapbox.js'])
@endpush

@section('content')
@php($allResolved = $items->every(fn ($item) => in_array($item->riderAssignment?->status, ['COMPLETED', 'REJECTED', 'CANCELLED'], true)))
<div class="flex flex-col gap-8">
    @if(session('status'))
        <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>
    @endif

    <section class="rounded-3xl border border-line bg-surface p-8">
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Pickup Batch #P-{{ $pickup->id }}</span>
        <h1 class="mt-3 text-2xl font-bold text-ink">{{ $pickup->seller?->business_name ?? 'Seller unavailable' }}</h1>
        <p class="mt-2 text-sm font-semibold text-muted">{{ $total }} {{ str('parcel')->plural($total) }} Â· {{ $collected }}/{{ $total }} collected</p>
        <p class="mt-4 text-sm text-muted">Status: <strong class="text-ink">{{ match($pickup->status) { 'LOADING' => 'Assigned', 'READY' => 'Accepted', 'IN_PROGRESS' => $pickupFinished ? 'Seller pickup complete' : 'In progress', 'EN_ROUTE_TO_HUB' => 'In transit to logistics center', 'COMPLETED' => 'Completed', default => str($pickup->status)->headline() } }}</strong></p>

        <ol class="mt-6 grid gap-2 text-xs sm:grid-cols-4">
            @foreach(['Assigned', 'Accepted', 'Scan each parcel at seller', 'Logistics intake'] as $index => $step)
                @php($activeStep = match($pickup->status) { 'LOADING' => 0, 'READY' => 1, 'IN_PROGRESS' => 2, 'EN_ROUTE_TO_HUB' => 3, default => 4 })
                <li class="border p-3 {{ $index === $activeStep ? 'border-primary bg-primary-soft font-semibold text-primary' : ($index < $activeStep ? 'border-green-200 bg-green-50 text-green-800' : 'border-line text-muted') }}">{{ $index + 1 }}. {{ $step }}</li>
            @endforeach
        </ol>
    </section>

    <x-shared.mapbox :markers="$mapMarkers" :title="$pickup->status === 'EN_ROUTE_TO_HUB' ? 'Route to logistics center' : 'Route to seller pickup'" height="220px" :user-location-target="$navigationTargetKind" :rider-gps="true" :navigation="true" />

    <section class="grid gap-6 lg:grid-cols-2">
        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Pickup and destination</h2>
            <dl class="mt-5 grid gap-4 text-sm">
                <div><dt class="text-muted">Seller contact</dt><dd class="mt-1 font-semibold text-ink">@if(filled($pickup->seller?->user?->contact_number))<a href="tel:{{ preg_replace('/[^0-9+]/', '', $pickup->seller->user->contact_number) }}">{{ $pickup->seller->user->contact_number }}</a>@else Contact unavailable @endif</dd></div>
                <div><dt class="text-muted">Seller pickup address</dt><dd class="mt-1 whitespace-pre-line font-semibold text-ink">{{ $sellerAddress }}</dd></div>
                <div><dt class="text-muted">Authorized logistics / sorting center</dt><dd class="mt-1 whitespace-pre-line font-semibold text-ink">{{ $pickup->logisticsCenter?->business_name }} Â· {{ $hubAddress }}</dd></div>
            </dl>
        </article>

        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Pickup actions</h2>
            <div class="mt-5 grid gap-3">
                @if($pickup->status === 'LOADING')
                    <form method="POST" action="{{ route('rider.pickups.batches.accept', $pickup->id) }}">@csrf<button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Accept Pickup</button></form>
                @elseif($pickup->status === 'READY')
                    <form method="POST" action="{{ route('rider.pickups.batches.start', $pickup->id) }}">@csrf<button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Start Pickup</button></form>
                @elseif($pickup->status === 'IN_PROGRESS' && $pickupFinished)
                    <div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">All assigned parcels are resolved. Continue with {{ $collected }} collected parcel(s).</div>
                    <form method="POST" action="{{ route('rider.pickups.batches.finish', $pickup->id) }}">@csrf<button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Complete Seller Pickup Â· Start Route to Logistics</button></form>
                @elseif($pickup->status === 'IN_PROGRESS')
                    <div class="hidden border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800" data-pickup-finished-message>All assigned parcels are resolved. Continue with the collected parcels.</div>
                    <form method="POST" action="{{ route('rider.pickups.batches.finish', $pickup->id) }}" class="hidden" data-finish-pickup-form>@csrf<button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Complete Seller Pickup · Start Route to Logistics</button></form>
                @elseif($pickup->status === 'EN_ROUTE_TO_HUB')
                    <div class="border border-primary/20 bg-primary-soft p-4 text-sm font-semibold text-primary">Seller pickup complete. Deliver {{ $collected }} collected parcel(s) to the assigned logistics center for individual intake scans.</div>
                @elseif($pickup->status === 'COMPLETED')
                    <div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">This pickup batch is closed.</div>
                @endif
                <a href="{{ route('rider.pickups') }}" class="rounded-xl border border-line px-5 py-3 text-center text-sm font-semibold text-ink">Back to Seller Pickups</a>
            </div>
        </article>
    </section>

    <section class="rounded-3xl border border-line bg-surface p-8">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div><h2 class="text-lg font-bold text-ink">Parcel Checklist</h2><p class="mt-1 text-sm text-muted">Scan each tracking waybill separately at the seller.</p></div>
            <span class="text-sm font-bold text-ink" data-pickup-count data-total="{{ $total }}">{{ $collected }} / {{ $total }} verified</span>
        </div>

        @if($pickup->status === 'IN_PROGRESS' && ! $pickupFinished)
            <div class="mt-5">
                <x-parcel-scanner
                    :action="route('rider.pickups.batches.scan', $pickup->id)"
                    :tracking="old('scanned_code', '')"
                    :method="old('scan_method', 'MANUAL')"
                    form-method="POST"
                    input-name="scanned_code"
                    input-label="Scan seller waybill"
                    input-placeholder="Scan or enter a waybill number"
                    title="Scan Pickup Barcode"
                    description="Scan the seller waybill with your camera or enter its tracking number. Each accepted scan verifies one assigned parcel."
                    button="Verify Parcel"
                />
            </div>
        @endif

        <div class="mt-5 grid gap-3">
            @foreach($items as $item)
                @php($assignment = $item->riderAssignment)
                @php($shipment = $assignment?->shipment)
                <article class="rounded-2xl border border-line p-4" data-pickup-item data-tracking="{{ $shipment?->tracking_number }}" data-pickup-resolved="{{ in_array($assignment?->status, ['COMPLETED', 'REJECTED', 'CANCELLED'], true) ? 'true' : 'false' }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div><strong class="text-ink">{{ $shipment?->tracking_number ?? 'Waybill unavailable' }}</strong><p class="mt-1 text-xs text-muted">{{ ($shipment?->orderItem?->product_name ?? $shipment?->sellerOrder?->items?->pluck('product_name')->filter()->join(', ')) }}</p></div>
                        @if($collectedAssignmentIds->contains($assignment?->id))
                            <span class="text-sm font-semibold text-green-700">âœ“ Received by rider Â· waiting for logistics intake</span>
                        @elseif(in_array($assignment?->status, ['REJECTED', 'CANCELLED'], true))
                            <span class="text-sm font-semibold text-amber-800">Not collected: {{ $assignment->rejection_reason ?: 'Resolved' }}</span>
                        @else
                            <span class="text-sm font-semibold text-muted">{{ str($assignment?->status ?? $item->status)->headline() }}</span>
                        @endif
                    </div>

                    @if($pickup->status === 'IN_PROGRESS' && $assignment?->status === 'IN_PROGRESS')
                        <details class="mt-3 border-t border-line pt-3">
                            <summary class="cursor-pointer text-xs font-semibold text-muted">Mark parcel not collected</summary>
                            <form method="POST" action="{{ route('rider.pickups.batches.not-collected', $pickup->id) }}" class="mt-3 grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
                                @csrf
                                <input type="hidden" name="rider_assignment_id" value="{{ $assignment->id }}">
                                <select name="reason" required class="min-h-10 border border-line bg-white px-3 text-sm">
                                    <option value="">Select reason</option>
                                    @foreach($notCollectedReasons as $reason)<option value="{{ $reason }}" @selected(old('rider_assignment_id') == $assignment->id && old('reason') === $reason)>{{ $reason }}</option>@endforeach
                                </select>
                                <input name="other_reason" value="{{ old('rider_assignment_id') == $assignment->id ? old('other_reason') : '' }}" maxlength="1000" class="min-h-10 border border-line px-3 text-sm" placeholder="Describe other reason">
                                <button class="border border-line px-4 py-2 text-sm font-semibold text-ink">Save reason</button>
                            </form>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
</div>
<script>
document.addEventListener('parcel-scanner:success', (event) => {
    const result = event.detail || {};
    if (!result.tracking) return;

    const item = [...document.querySelectorAll('[data-pickup-item]')]
        .find((candidate) => candidate.dataset.tracking === result.tracking);
    if (item) {
        item.dataset.pickupResolved = 'true';
        const status = item.querySelector('span');
        if (status) {
            status.className = 'text-sm font-semibold text-green-700';
            status.textContent = result.already_verified
                ? 'Already collected by rider · waiting for logistics intake'
                : 'Collected by rider · waiting for logistics intake';
        }
    }

    const count = document.querySelector('[data-pickup-count]');
    if (count && Number.isFinite(Number(result.collected_count))) {
        count.textContent = `${result.collected_count} / ${result.total_count ?? count.dataset.total} verified`;
    }

    const allResolved = [...document.querySelectorAll('[data-pickup-item]')]
        .every((candidate) => candidate.dataset.pickupResolved === 'true');
    if (allResolved) {
        document.querySelector('[data-pickup-finished-message]')?.classList.remove('hidden');
        document.querySelector('[data-finish-pickup-form]')?.classList.remove('hidden');
    }
});
</script>
@endsection
