@extends('layouts.logistics.app')
@section('title', 'Parcel Sorting - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-bold uppercase text-primary">Sorting Center</p>
        <h1 class="mt-2 text-2xl font-bold text-ink">Sort Parcels by Destination</h1>
        <p class="mt-2 text-sm text-muted">The saved Buyer address determines the destination area and, when needed, the destination logistics hub.</p>
    </header>
    <x-logistics.parcel-flow current="sort" />
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <x-shared.parcel-scanner :action="route('logistics.sorting')" :tracking="request('tracking', '')" :method="request('scan_method', 'MANUAL')" title="Read Address and Determine Area" description="Scan the received parcel and verify its saved buyer destination and matched logistics route." button="Find Parcel" />
    <div class="grid gap-4">
    @forelse($shipments as $shipment)
        @php
            $isCrossHubRoute = (bool) $shipment->sorting_is_cross_hub_route;
            $matchIsAmbiguous = (bool) $shipment->sorting_match_is_ambiguous;
            $matchedArea = $shipment->sorting_auto_matched_area;
            $destinationHub = $shipment->destinationLogisticsCenter ?? $shipment->serviceArea?->logisticsCenter;
            $matchedDestinationHub = $shipment->sorting_destination_hubs->firstWhere('id', (int) $shipment->destination_logistics_center_id);
            $matchedDestinationArea = $matchedDestinationHub?->sorting_destination_area ?? $shipment->serviceArea;
            $buyerDestination = collect([
                $shipment->destination_barangay_name,
                $shipment->destination_municipality_name,
                $shipment->destination_province_name,
            ])->filter()->implode(', ');
        @endphp
        <article class="border border-line bg-surface p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <strong class="text-lg text-primary">{{ $shipment->tracking_number }}</strong>
                    <p class="mt-1 text-sm text-muted">Buyer destination: {{ $buyerDestination ?: ($shipment->sellerOrder?->order?->address?->formatted() ?? 'Address unavailable') }}</p>
                    @if($isCrossHubRoute)
                        <p class="mt-1 text-xs font-semibold">Hub route: {{ $center?->business_name ?? 'Origin hub' }} → {{ $destinationHub?->business_name ?? ($shipment->destination_municipality_name ?: 'Buyer destination hub') }}@if($shipment->serviceArea) · {{ $shipment->serviceArea->name }}@endif</p>
                        <p class="mt-1 text-xs text-muted">Destination match: {{ $destinationHub?->business_name ?? 'Destination hub' }}@if($matchedDestinationArea) · {{ $matchedDestinationArea->name }}@endif · {{ $shipment->destination_municipality_name }}, {{ $shipment->destination_province_name }}</p>
                    @else
                        <p class="mt-1 text-xs font-semibold">Matched delivery area: {{ $matchedArea?->name ?? ($matchIsAmbiguous ? 'Multiple matches; choose manually' : 'No matching area at this hub') }}@if($matchedArea) · {{ $center?->business_name ?? 'Current hub' }}@endif</p>
                    @endif
                    <span class="text-xs">Sorting hub: {{ $center?->business_name ?? 'Current hub' }} · {{ str($shipment->current_status)->headline() }}</span>
                </div>
                @if($shipment->current_status === 'AT_SORTING_CENTER')
                    <form method="POST" action="{{ route('logistics.sorting.sort', $shipment) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        @if($isCrossHubRoute)
                            <select name="destination_logistics_center_id" class="border border-line bg-white px-3 py-2" aria-label="Destination hub">
                                <option value="">Automatic match · {{ $destinationHub?->business_name ?? 'Destination hub' }}@if($matchedDestinationArea) · {{ $matchedDestinationArea->name }}@endif</option>
                                @foreach($shipment->sorting_destination_hubs as $candidateHub)
                                    <option value="{{ $candidateHub->id }}">
                                        {{ $candidateHub->business_name }} · {{ $candidateHub->sorting_destination_area->name }} · {{ $shipment->destination_municipality_name }}, {{ $shipment->destination_province_name }}
                                        @if((int) $candidateHub->id === (int) $shipment->destination_logistics_center_id) (current match) @endif
                                    </option>
                                @endforeach
                            </select>
                            <button class="bg-primary px-4 py-2 font-semibold text-white">Confirm Hub Transfer</button>
                        @else
                            <select name="service_area_id" class="border border-line bg-white px-3 py-2">
                                <option value="">{{ $matchIsAmbiguous ? 'Choose area · multiple matches' : 'Auto-match · '.($matchedArea?->name ?? 'No matching area') }}</option>
                                @foreach($shipment->sorting_matching_areas as $area)
                                    <option value="{{ $area->id }}">{{ $area->name }}</option>
                                @endforeach
                            </select>
                            <button class="bg-primary px-4 py-2 font-semibold text-white">Confirm Sorted</button>
                        @endif
                    </form>
                @else
                    <span class="font-semibold text-green-700">Sorted</span>
                @endif
            </div>
        </article>
    @empty
        <section class="border border-dashed border-line bg-surface p-8 text-center text-muted">{{ request('tracking') ? 'No parcel matches that waybill in the sorting queue.' : 'No parcels are waiting for sorting.' }}</section>
    @endforelse
    </div>
    {{ $shipments->links() }}
</div>
@endsection
