@extends('layouts.rider.app')
@section('title', 'Return Parcels - LIKHAE Rider')
@section('content')
<div class="flex flex-col gap-6">
    <header>
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Reverse custody</span>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-ink">Return Parcels</h1>
        <p class="mt-2 max-w-3xl text-sm text-muted">Use the parcel tracking number at each custody handoff. Failed deliveries stay open until the seller confirms the parcel was returned.</p>
    </header>
    @if(session('status'))<div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>@endif

    <section class="grid gap-5">
        @forelse($workflows as $workflow)
            @php
                $shipment = $workflow->shipment;
                $isOriginalRider = (int) $workflow->originalDeliveryAssignment?->rider_profile_id === (int) $rider->id;
                $isReturnRider = (int) $workflow->return_rider_profile_id === (int) $rider->id;
                $sellerProfile = $shipment?->sellerOrder?->sellerProfile;
            @endphp
            <article class="rounded-3xl border border-line bg-surface p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wide text-primary">{{ str($workflow->status)->replace('_', ' ')->title() }}</span>
                        <h2 class="mt-2 text-xl font-bold text-ink">{{ $shipment?->tracking_number }}</h2>
                        <p class="mt-1 text-sm text-muted">Seller: {{ $sellerProfile?->business_name ?? 'Seller unavailable' }} · Order {{ $shipment?->sellerOrder?->seller_order_number }}</p>
                        <p class="mt-1 text-sm text-muted">Return reason: {{ $workflow->reason }}</p>
                        <p class="mt-1 text-sm text-muted">Center: {{ $workflow->logisticsCenter?->business_name ?? 'Sorting center' }}</p>
                    </div>
                    <div class="grid w-full gap-3 lg:max-w-md">
                        @if($isOriginalRider && $workflow->status === 'AWAITING_CENTER_HANDOFF')
                            <form method="POST" action="{{ route('rider.returns.center-handoff', $workflow) }}" class="grid gap-2">
                                @csrf
                                <label class="text-sm font-semibold">Scan parcel at the sorting center</label>
                                <x-shared.parcel-scan-input name="scanned_code" method-name="scan_method" :id="'return-center-handoff-'.$workflow->id" :value="old('scanned_code', '')" />
                                <button class="rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white">Hand parcel to center</button>
                            </form>
                        @elseif($isReturnRider && $workflow->status === 'ASSIGNED_TO_RETURN_RIDER')
                            <form method="POST" action="{{ route('rider.returns.accept', $workflow) }}">@csrf<button class="w-full rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white">Accept return assignment</button></form>
                        @elseif($isReturnRider && $workflow->status === 'RETURN_RIDER_ACCEPTED')
                            <form method="POST" action="{{ route('rider.returns.pickup', $workflow) }}" class="grid gap-2">
                                @csrf
                                <label class="text-sm font-semibold">Scan at center pickup</label>
                                <x-shared.parcel-scan-input name="scanned_code" method-name="scan_method" :id="'return-center-pickup-'.$workflow->id" :value="old('scanned_code', '')" />
                                <button class="rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white">Collect parcel for seller</button>
                            </form>
                        @elseif($isReturnRider && $workflow->status === 'IN_TRANSIT_TO_SELLER')
                            <form method="POST" action="{{ route('rider.returns.seller-handoff', $workflow) }}" class="grid gap-2">
                                @csrf
                                <label class="text-sm font-semibold">Scan at seller handoff</label>
                                <x-shared.parcel-scan-input name="scanned_code" method-name="scan_method" :id="'return-seller-handoff-'.$workflow->id" :value="old('scanned_code', '')" />
                                <button class="rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white">Record seller handoff</button>
                            </form>
                        @else
                            <p class="rounded-xl bg-slate-50 p-4 text-sm text-muted">{{ $isOriginalRider ? 'Waiting for the sorting center to confirm receipt.' : 'Waiting for the next return custody step.' }}</p>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-3xl border border-line bg-surface p-8 text-center text-sm text-muted">No return parcels are currently assigned to you.</div>
        @endforelse
    </section>
    {{ $workflows->links() }}
</div>
@endsection
