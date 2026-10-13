@extends('Logistics.app')
@section('title', 'Receive Parcels - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-bold uppercase text-primary">Parcel intake</p><h1 class="mt-2 text-2xl font-bold text-ink">Receive at Sorting Center</h1><p class="mt-2 text-sm text-muted">Confirm a real picked-up parcel using its shipment tracking number.</p></header>
    <x-logistics.parcel-flow current="receive" />
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <x-parcel-scanner :action="route('logistics.parcels.receive')" :tracking="request('tracking', '')" :method="request('scan_method', 'MANUAL')" title="Receive and Scan Parcel" description="Scan the waybill when the rider reaches the sorting center. A successful match moves the parcel to At Sorting Center." button="Find Parcel" />
    <div class="grid gap-4">
    @forelse($shipments as $shipment)
        <article class="border border-line bg-surface p-5"><div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div><a class="text-lg font-bold text-primary" href="{{ route('logistics.parcels.show',$shipment) }}">{{ $shipment->tracking_number }}</a><p class="mt-1 text-sm text-muted">{{ $shipment->sellerOrder?->sellerProfile?->business_name }} → {{ $shipment->sellerOrder?->order?->address?->formatted() }}</p><span class="text-xs font-semibold">{{ str($shipment->current_status)->headline() }}</span></div>
            <form method="POST" action="{{ route('logistics.parcels.receive.confirm',$shipment) }}" class="flex flex-wrap gap-2">@csrf<input type="hidden" name="scan_method" value="{{ in_array(strtoupper((string) request('scan_method')), ['QR', 'BARCODE'], true) ? strtoupper((string) request('scan_method')) : 'MANUAL' }}"><input name="scanned_code" required value="{{ request('tracking') }}" placeholder="Scan or enter tracking number" class="border border-line px-3 py-2"><button class="bg-primary px-4 py-2 font-semibold text-white">Receive Parcel</button></form>
        </div></article>
    @empty<section class="border border-dashed border-line bg-surface p-8 text-center text-muted">{{ request('tracking') ? 'No parcel matches that waybill in the receive queue.' : 'No picked-up or seller drop-off parcels are waiting to be received.' }}</section>@endforelse
    </div>{{ $shipments->links() }}
</div>
@endsection
