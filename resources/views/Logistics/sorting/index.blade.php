@extends('Logistics.app')
@section('title', 'Parcel Sorting - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-bold uppercase text-primary">Sorting Center</p><h1 class="mt-2 text-2xl font-bold text-ink">Sort Parcels by Destination</h1><p class="mt-2 text-sm text-muted">The saved Buyer address determines the matching delivery area.</p></header>
    <x-logistics.parcel-flow current="sort" />
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <x-parcel-scanner :action="route('logistics.sorting')" :tracking="request('tracking', '')" :method="request('scan_method', 'MANUAL')" title="Read Address and Determine Area" description="Scan the received parcel, verify its saved delivery address, then confirm the matching destination area." button="Find Parcel" />
    <div class="grid gap-4">
    @forelse($shipments as $shipment)
        <article class="border border-line bg-surface p-5"><div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div><strong class="text-lg text-primary">{{ $shipment->tracking_number }}</strong><p class="mt-1 text-sm text-muted">{{ $shipment->sellerOrder?->order?->address?->formatted() }}</p><span class="text-xs font-semibold">Current area: {{ $shipment->serviceArea?->name ?? 'Not matched' }} · {{ str($shipment->current_status)->headline() }}</span></div>
            @if($shipment->current_status === 'AT_SORTING_CENTER')<form method="POST" action="{{ route('logistics.sorting.sort',$shipment) }}" class="flex flex-wrap gap-2">@csrf<select name="service_area_id" class="border border-line bg-white px-3 py-2"><option value="">Auto-match destination</option>@foreach($areas as $area)<option value="{{ $area->id }}" @selected($shipment->service_area_id===$area->id)>{{ $area->name }}</option>@endforeach</select><button class="bg-primary px-4 py-2 font-semibold text-white">Confirm Sorted</button></form>@else<span class="font-semibold text-green-700">Sorted</span>@endif
        </div></article>
    @empty<section class="border border-dashed border-line bg-surface p-8 text-center text-muted">{{ request('tracking') ? 'No parcel matches that waybill in the sorting queue.' : 'No parcels are waiting for sorting.' }}</section>@endforelse
    </div>{{ $shipments->links() }}
</div>
@endsection
