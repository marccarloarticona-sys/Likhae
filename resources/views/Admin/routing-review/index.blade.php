@extends('layouts.admin')

@section('title', 'PSGC Routing Review')
@section('active', 'routing-review')
@section('content')
<div class="ad-page">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Logistics operations</span>
            <h2>PSGC routing review</h2>
            <p>Choose the authorized origin and destination hubs for parcels whose address mapping is missing or ambiguous.</p>
        </div>
    </div>

    @if(session('status'))<div class="mb-5 rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif

    <div class="space-y-5">
        @forelse($shipments as $shipment)
            @php
                $sellerAddress = $shipment->sellerOrder?->sellerProfile?->businessAddress;
                $buyerAddress = $shipment->sellerOrder?->order?->address;
                $reviewEvent = $shipment->events->first(fn ($event) => str_starts_with((string) $event->notes, 'Parcel placed. ROUTING_REVIEW_REQUIRED:'));
            @endphp
            <article class="rounded-2xl border border-line bg-surface p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-ink">{{ $shipment->tracking_number }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ $shipment->orderItem?->product_name ?? 'Order parcel' }} · {{ $shipment->sellerOrder?->sellerProfile?->business_name ?? 'Seller unavailable' }}</p>
                    </div>
                    <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">Manual route review</span>
                </div>
                <div class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                    <div class="rounded-xl bg-page-secondary p-4"><strong>Seller pickup</strong><p class="mt-1">{{ $sellerAddress?->formatted() ?? 'Address unavailable' }}</p><p class="mt-1 text-xs text-muted">PSGC {{ $sellerAddress?->province_code }} / {{ $sellerAddress?->municipality_code }} / {{ $sellerAddress?->barangay_code }}</p></div>
                    <div class="rounded-xl bg-page-secondary p-4"><strong>Buyer delivery</strong><p class="mt-1">{{ $buyerAddress?->formatted() ?? 'Address unavailable' }}</p><p class="mt-1 text-xs text-muted">PSGC {{ $buyerAddress?->province_code }} / {{ $buyerAddress?->municipality_code }} / {{ $buyerAddress?->barangay_code }}</p></div>
                </div>
                @if($reviewEvent)<p class="mt-3 text-sm text-amber-800">{{ str($reviewEvent->notes)->after('ROUTING_REVIEW_REQUIRED: ')->toString() }}</p>@endif

                <form method="POST" action="{{ route('admin.routing-review.resolve', $shipment) }}" class="mt-5 grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                    @csrf
                    <label class="grid gap-1 text-sm font-semibold">Authorized origin hub
                        <select name="origin_logistics_center_id" required class="min-h-11 rounded-lg border border-line bg-white px-3">
                            <option value="">Choose hub</option>
                            @foreach($centers as $center)
                                @if((string) $center->address?->province_code === (string) $sellerAddress?->province_code && (string) $center->address?->municipality_code === (string) $sellerAddress?->municipality_code)
                                    <option value="{{ $center->id }}" @selected(old('origin_logistics_center_id') == $center->id)>{{ $center->business_name }} · {{ $center->code }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm font-semibold">Authorized destination hub
                        <select name="destination_logistics_center_id" required class="min-h-11 rounded-lg border border-line bg-white px-3">
                            <option value="">Choose hub</option>
                            @foreach($centers as $center)
                                @if((string) $center->address?->province_code === (string) $buyerAddress?->province_code && (string) $center->address?->municipality_code === (string) $buyerAddress?->municipality_code)
                                    <option value="{{ $center->id }}" @selected(old('destination_logistics_center_id') == $center->id)>{{ $center->business_name }} · {{ $center->code }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                    <button class="self-end rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white">Validate & save route</button>
                </form>
            </article>
        @empty
            <div class="rounded-2xl border border-line bg-surface p-8 text-sm text-muted">No parcels need manual route review.</div>
        @endforelse
    </div>
    <div class="mt-5">{{ $shipments->links() }}</div>
</div>
@endsection
