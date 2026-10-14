@extends('Logistics.app')
@section('title', 'Receive Parcels - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-bold uppercase text-primary">Parcel intake</p><h1 class="mt-2 text-2xl font-bold text-ink">Receive at Sorting Center</h1><p class="mt-2 text-sm text-muted">Scan every collected waybill as the rider arrives. Each parcel moves to At Sorting Center only after its own scan.</p></header>
    <x-logistics.parcel-flow current="receive" />
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <x-parcel-scanner :action="route('logistics.parcels.receive')" :tracking="request('tracking', '')" :method="request('scan_method', 'MANUAL')" title="Receive and Scan Parcel" description="Scan the waybill when the rider reaches the sorting center. A successful match moves the parcel to At Sorting Center." button="Find Parcel" />
    <section class="space-y-4">
        <div><h2 class="text-lg font-bold text-ink">Incoming Seller Pickup Batches</h2><p class="mt-1 text-sm text-muted">Expected and received parcel counts for riders arriving from seller pickups.</p></div>
        @forelse($receivedBatches as $batch)
            @php($collectedItems = $batch->items->filter(fn ($item) => $item->riderAssignment?->status === 'COMPLETED'))
            @php($receivedItems = $collectedItems->filter(fn ($item) => $item->riderAssignment?->shipment?->current_status === 'AT_SORTING_CENTER'))
            @php($pendingItems = $collectedItems->filter(fn ($item) => $item->riderAssignment?->shipment?->current_status === 'PICKED_UP'))
            <article class="border border-line bg-surface p-5">
                <header class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-wide text-primary">Pickup Batch #P-{{ $batch->id }}</p><h3 class="mt-1 font-bold text-ink">{{ $batch->seller?->business_name }}</h3><p class="mt-1 text-sm text-muted">Rider: {{ $batch->rider?->user?->name }} · {{ $batch->seller?->businessAddress?->formatted() }}</p></div><strong class="text-sm text-ink">Expected: {{ $collectedItems->count() }} · Received: {{ $receivedItems->count() }}/{{ $collectedItems->count() }}</strong></header>
                <div class="mt-4 grid gap-3">
                    @foreach($collectedItems as $item)
                        @php($shipment = $item->riderAssignment?->shipment)
                        <div class="flex flex-col gap-3 border border-line p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div><strong class="text-sm text-ink">{{ $shipment?->tracking_number }}</strong><p class="mt-1 text-xs text-muted">{{ $shipment?->current_status === 'AT_SORTING_CENTER' ? 'Received' : 'Pending intake scan' }}</p></div>
                            @if($shipment?->current_status === 'PICKED_UP')
                                <form method="POST" action="{{ route('logistics.parcels.receive.confirm', $shipment) }}" class="flex flex-wrap gap-2">@csrf<input type="hidden" name="scan_method" value="BARCODE"><input name="scanned_code" required class="min-w-0 border border-line px-3 py-2" placeholder="Scan waybill"><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Receive Parcel</button></form>
                            @else
                                <span class="text-sm font-semibold text-green-700">✓ Received at Sorting Center</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        @empty
            <div class="border border-dashed border-line bg-surface p-6 text-sm text-muted">No seller pickup batches are arriving at this center.</div>
        @endforelse
    </section>

    <section class="grid gap-4">
        <h2 class="text-lg font-bold text-ink">Other Picked-Up Parcels</h2>
    @forelse($shipments as $shipment)
        <article class="border border-line bg-surface p-5"><div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div><a class="text-lg font-bold text-primary" href="{{ route('logistics.parcels.show',$shipment) }}">{{ $shipment->tracking_number }}</a><p class="mt-1 text-sm text-muted">{{ $shipment->sellerOrder?->sellerProfile?->business_name }} → {{ $shipment->sellerOrder?->order?->address?->formatted() }}</p><span class="text-xs font-semibold">{{ str($shipment->current_status)->headline() }}</span></div>
            <form method="POST" action="{{ route('logistics.parcels.receive.confirm',$shipment) }}" class="flex flex-wrap gap-2">@csrf<input type="hidden" name="scan_method" value="{{ in_array(strtoupper((string) request('scan_method')), ['QR', 'BARCODE'], true) ? strtoupper((string) request('scan_method')) : 'MANUAL' }}"><input name="scanned_code" required value="{{ request('tracking') }}" placeholder="Scan or enter tracking number" class="border border-line px-3 py-2"><button class="bg-primary px-4 py-2 font-semibold text-white">Receive Parcel</button></form>
        </div></article>
    @empty<section class="border border-dashed border-line bg-surface p-8 text-center text-muted">{{ request('tracking') ? 'No parcel matches that waybill in the receive queue.' : 'No other picked-up parcels are waiting to be received.' }}</section>@endforelse
    {{ $shipments->links() }}
    </section>
</div>
@endsection
