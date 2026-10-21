@extends('layouts.rider.app')

@section('title', 'Delivery Assignments - LIKHAE Rider')

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
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-ink">Delivery Assignments</h1>
            <p class="mt-3 text-sm text-muted">Parcels assigned by the sorting center to your rider account.</p>
        </div>
        <span class="rounded-full bg-primary-soft px-5 py-3 text-sm font-semibold text-primary">{{ number_format($deliveryStats['active']) }} Active Deliveries</span>
    </section>

    <x-shared.parcel-scanner
        :action="route('rider.scan', ['return_to' => 'deliveries'])"
        :tracking="old('tracking', request('tracking', ''))"
        :method="request('scan_method', 'MANUAL')"
        title="Scan Waybill"
        description="Find a delivery assigned to your rider account. A scan never marks the parcel delivered."
        button="Find Assigned Delivery"
    />

    <section class="grid gap-5 md:grid-cols-4">
        <div class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Assigned</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['assigned'] }}</h2></div>
        <div class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Out For Delivery</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['out_for_delivery'] }}</h2></div>
        <div class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Delivered Today</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['delivered_today'] }}</h2></div>
        <div class="rounded-3xl border border-line bg-surface p-6"><p class="text-sm text-muted">Failed Today</p><h2 class="mt-3 text-4xl font-bold text-ink">{{ $deliveryStats['failed_today'] }}</h2></div>
    </section>

    <section class="overflow-hidden rounded-3xl border border-line bg-surface">
        <div class="border-b border-line px-8 py-6">
            <h2 class="text-lg font-bold text-ink">Assigned Deliveries</h2>
            <p class="mt-2 text-xs text-muted">No sample accounts or static parcel rows are rendered here.</p>
        </div>
        <div class="divide-y divide-line">
            @forelse($deliveries as $delivery)
                <article class="flex flex-col gap-5 p-8 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex gap-4">
                        <img src="{{ $delivery['image'] }}" alt="" class="h-16 w-16 rounded-2xl border border-line object-cover">
                        <div>
                            <h3 class="text-lg font-bold text-ink">{{ $delivery['tracking'] }}</h3>
                            <p class="mt-2 text-sm text-muted">Buyer: {{ $delivery['buyer'] }}</p>
                            <p class="mt-1 text-sm text-muted">{{ $delivery['address'] }}</p>
                            <p class="mt-2 text-sm font-semibold text-primary">{{ $delivery['amount'] }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-primary-soft px-4 py-2 text-xs font-semibold text-primary">{{ $delivery['status_label'] }}</span>
                        <a href="{{ route('rider.deliveries.show', $delivery['id']) }}" class="rounded-xl border border-line px-5 py-3 text-sm font-semibold text-ink hover:bg-page-secondary">View</a>
                    </div>
                </article>
            @empty
                <div class="p-8 text-sm text-muted">No active delivery assignments found.</div>
            @endforelse
        </div>
    </section>
    <div>{{ $assignments->links() }}</div>
</div>
@endsection
