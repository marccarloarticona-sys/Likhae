@extends('Logistics.app')
@section('title', 'Parcels - LIKHAE Logistics')
@php
    $dateQuery = array_filter(['from_date' => $filters['from_date'] ?? null, 'to_date' => $filters['to_date'] ?? null]);
    $statusOptions = [
        '' => 'All', 'pending' => 'Pending', 'in_transit' => 'In Transit',
        'delivered' => 'Delivered', 'failed' => 'Failed',
        'LOST' => 'Lost', 'DAMAGED' => 'Damaged',
        'PICKED_UP' => 'Incoming', 'AT_SORTING_CENTER' => 'For Sorting',
        'SORTED' => 'Sorted', 'ASSIGNED_TO_RIDER' => 'Assigned',
        'OUT_FOR_DELIVERY' => 'Out for Delivery',
    ];
@endphp
@section('content')
<div class="min-w-0 space-y-5">
    <header class="flex flex-col gap-4 rounded-xl border border-line bg-surface p-5 shadow-sm lg:flex-row lg:items-end lg:justify-between">
        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-primary">Parcel Management</p><h1 class="mt-2 text-2xl font-semibold text-ink">All Parcels</h1><p class="mt-1 text-xs text-muted">Authorized shipment records for {{ $center->business_name }}. {{ number_format($shipments->total()) }} matching parcels.</p></div>
        <div class="flex flex-wrap gap-2"><a href="{{ route('logistics.parcels.receive') }}" class="inline-flex min-h-10 items-center rounded-lg bg-primary px-4 text-xs font-semibold text-white">Receive Parcel</a><a href="{{ route('logistics.parcels.tracking') }}" class="inline-flex min-h-10 items-center rounded-lg border border-line px-4 text-xs font-semibold text-ink">Track Parcel</a></div>
    </header>

    <section class="rounded-xl border border-line bg-surface p-4 shadow-sm">
        <form method="GET" action="{{ route('logistics.parcels') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto_auto] lg:items-end" aria-label="Shipment creation date filter">
            @if($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
            <label class="text-xs font-semibold text-ink">From Date<input type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" class="mt-1 block min-h-10 w-full rounded-lg border border-line bg-page-secondary px-3 text-sm"></label>
            <label class="text-xs font-semibold text-ink">To Date<input type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" class="mt-1 block min-h-10 w-full rounded-lg border border-line bg-page-secondary px-3 text-sm"></label>
            <button class="min-h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white">Apply Filter</button>
            <a href="{{ route('logistics.parcels', $status !== '' ? ['status' => $status] : []) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-line px-4 text-xs font-semibold text-ink">Clear Filter</a>
        </form>
        <p class="mt-2 text-xs text-muted">Creation dates in {{ config('app.timezone') }}; start and end dates are included.</p>
        @if($errors->any())<p class="mt-2 text-xs text-red-700" role="alert">{{ $errors->first() }}</p>@endif
    </section>

    <nav class="flex flex-wrap gap-2" aria-label="Parcel status filters">
        @foreach($statusOptions as $key => $label)
            <a href="{{ route('logistics.parcels', $key === '' ? $dateQuery : ['status' => $key, ...$dateQuery]) }}" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold {{ $status === $key ? 'bg-primary text-white' : 'bg-surface text-ink hover:border-primary' }}" @if($status === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <x-shared.mapbox :markers="$mapMarkers" title="Center parcel map" height="220px" user-location-target="destination" />
    <div class="overflow-x-auto rounded-xl border border-line bg-surface shadow-sm">
        <table class="w-full min-w-[760px] text-left text-xs">
            <thead class="bg-page-secondary text-[10px] uppercase tracking-wide text-muted"><tr><th class="p-4">Tracking</th><th class="p-4">Order</th><th class="p-4">Seller</th><th class="p-4">Destination</th><th class="p-4">Area</th><th class="p-4">Status</th><th class="p-4"></th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse($shipments as $shipment)
                    <tr>
                        <td class="p-4 font-semibold text-ink">{{ $shipment->tracking_number }}</td>
                        <td class="p-4">{{ $shipment->sellerOrder?->seller_order_number }}</td>
                        <td class="p-4">{{ $shipment->sellerOrder?->sellerProfile?->business_name }}</td>
                        <td class="p-4">{{ $shipment->sellerOrder?->order?->address?->formatted() }}</td>
                        <td class="p-4">{{ $shipment->serviceArea?->name ?? 'Unmatched' }}</td>
                        <td class="p-4 font-semibold">{{ str($shipment->current_status)->headline() }}</td>
                        <td class="p-4"><a class="font-semibold text-primary hover:underline" href="{{ route('logistics.parcels.show', $shipment) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-8 text-center text-muted">No parcel records match this filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $shipments->links() }}
</div>
@endsection
