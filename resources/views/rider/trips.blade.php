@extends('layouts.rider.app')

@section('title', 'Delivery Batches - LIKHAE Rider')

@section('content')
<div class="flex flex-col gap-8">
    @if(session('status'))
        <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif
    @error('tracking')
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $message }}</div>
    @enderror

    <section class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Delivery Management</span>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-ink">Delivery Batches</h1>
            <p class="mt-3 max-w-2xl text-sm text-muted">Parcels for the same barangay are grouped so you can accept and start them together. Each batch follows your approved capacity, up to 10 parcels.</p>
        </div>
        <span class="rounded-full bg-primary-soft px-5 py-3 text-sm font-semibold text-primary">{{ number_format($deliveryStats['active']) }} Active Deliveries</span>
    </section>

    <x-shared.parcel-scanner
        :action="route('rider.scan', ['return_to' => 'deliveries'])"
        :tracking="old('tracking', request('tracking', ''))"
        :method="request('scan_method', 'MANUAL')"
        title="Scan Waybill"
        description="Find a parcel assigned to your rider account. A scan never marks the parcel delivered."
        button="Find Assigned Delivery"
    />

    <section class="grid gap-5 md:grid-cols-4">
        <article class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Assigned</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['assigned'] }}</h2></article>
        <article class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Out For Delivery</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['out_for_delivery'] }}</h2></article>
        <article class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Delivered Today</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['delivered_today'] }}</h2></article>
        <article class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Failed Today</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['failed_today'] }}</h2></article>
    </section>

    @if(!$localTripsReady)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Delivery batches are temporarily unavailable until local trip support is installed.</div>
    @else
        <section class="overflow-hidden rounded-3xl border border-line bg-surface">
            <div class="border-b border-line px-6 py-5 sm:px-8">
                <h2 class="text-lg font-bold text-ink">Your Batches</h2>
                <p class="mt-2 text-sm text-muted">Open a batch to accept or start all its active parcels, then record delivery proof for each parcel.</p>
            </div>
            <div class="divide-y divide-line">
                @forelse($trips as $trip)
                    <a href="{{ route('rider.trips.show', $trip->id) }}" class="block p-6 transition hover:bg-page-secondary sm:p-8">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <span class="text-xs font-bold uppercase tracking-wider text-primary">Delivery Batch</span>
                                <h3 class="mt-1 text-lg font-bold text-ink">Batch #{{ $trip->id }}</h3>
                                <p class="mt-1 text-sm text-muted">Barangay {{ $trip->barangay_code }} · {{ $trip->municipality_code }}, {{ $trip->province_code }}</p>
                            </div>
                            <span class="w-fit rounded-full bg-primary-soft px-4 py-2 text-xs font-semibold text-primary">{{ str($trip->status)->replace('_', ' ')->headline() }}</span>
                        </div>
                        <p class="mt-3 text-sm font-semibold text-ink">
                            {{ $trip->parcel_count }} {{ \Illuminate\Support\Str::plural('parcel', $trip->parcel_count) }}
                            <span class="font-normal text-muted">· Approved capacity {{ $trip->effective_capacity }} (maximum 10)</span>
                            @if(in_array($trip->status, ['LOADING', 'READY', 'IN_PROGRESS'], true))
                                <span class="font-normal text-muted">· {{ $trip->load_count }} active in batch</span>
                            @endif
                        </p>
                        <div class="mt-4 grid gap-2">
                            @foreach($trip->items as $item)
                                @php($assignment = $item->riderAssignment)
                                @php($shipment = $assignment?->shipment)
                                @php($address = $shipment?->sellerOrder?->order?->address)
                                <div class="flex flex-col gap-1 rounded-xl border border-line bg-page px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <span class="font-mono text-sm font-semibold text-ink">{{ $shipment?->tracking_number ?? 'Parcel unavailable' }}</span>
                                        <p class="mt-1 text-xs text-muted">{{ $address?->recipient_name ?? 'Buyer unavailable' }}@if($address) · {{ $address->formatted() }}@endif</p>
                                    </div>
                                    <span class="text-xs font-semibold text-muted">{{ str($assignment?->status ?? 'Unavailable')->replace('_', ' ')->headline() }}</span>
                                </div>
                            @endforeach
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center text-sm text-muted">No delivery batches assigned yet.</div>
                @endforelse
            </div>
            <div class="px-6 py-5 sm:px-8">{{ $trips->links() }}</div>
        </section>

        @if($unbatchedDeliveries->isNotEmpty())
            <section class="overflow-hidden rounded-3xl border border-amber-200 bg-surface">
                <div class="border-b border-amber-200 bg-amber-50 px-6 py-5 sm:px-8">
                    <h2 class="text-lg font-bold text-amber-950">Deliveries Waiting for a Batch</h2>
                    <p class="mt-2 text-sm text-amber-900">These older assignments are not in a delivery batch yet. Contact Logistics to place them in a batch that fits your approved capacity.</p>
                </div>
                <div class="divide-y divide-line">
                    @foreach($unbatchedDeliveries as $assignment)
                        <article class="flex flex-col gap-3 p-6 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <strong class="font-mono text-sm text-ink">{{ $assignment->shipment?->tracking_number ?? 'Parcel unavailable' }}</strong>
                                <p class="mt-1 text-sm text-muted">{{ $assignment->shipment?->sellerOrder?->order?->address?->recipient_name ?? 'Buyer unavailable' }} · {{ str($assignment->status)->replace('_', ' ')->headline() }}</p>
                            </div>
                            <a href="{{ route('rider.deliveries.show', $assignment->id) }}" class="inline-flex justify-center rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink">View parcel</a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</div>
@endsection
