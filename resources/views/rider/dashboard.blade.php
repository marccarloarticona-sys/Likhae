@extends('Rider.app')

@section('title', 'Rider Dashboard - LIKHAE')

@section('content')
<div class="flex flex-col gap-8">
    @if(session('status'))
        <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif
    <section class="flex flex-col gap-3">
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Rider Operations</span>
        <h1 class="text-2xl font-bold tracking-tight text-ink">Dashboard</h1>
        <p class="text-sm text-muted">Real pickup and delivery work assigned to your active rider account.</p>
    </section>

    <section class="grid gap-5 md:grid-cols-4">
        @foreach($riderStats as $stat)
            <article class="rounded-3xl border border-line bg-surface p-6">
                <p class="text-sm text-muted">{{ $stat['label'] }}</p>
                <h2 class="mt-3 text-4xl font-bold text-ink">{{ $stat['value'] }}</h2>
            </article>
        @endforeach
    </section>

    @if($errors->has('tracking'))
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-800" role="alert">{{ $errors->first('tracking') }}</div>
    @endif

    <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.8fr)]">
        <x-parcel-scanner
            :action="route('rider.scan', ['return_to' => 'dashboard'])"
            :tracking="old('tracking', '')"
            :method="old('scan_method', 'MANUAL')"
            title="Parcel Scanner"
            description="Pickup · Delivery · Hub Transfer. Scan the waybill or enter the tracking number to open only a parcel assigned to you."
            button="Verify Tracking"
            input-placeholder="Scan barcode or enter tracking number"
        />
        <article class="rounded-3xl border border-line bg-surface p-6">
            <h2 class="text-lg font-bold text-ink">Pickup · Delivery · Hub Transfer</h2>
            <p class="mt-2 text-sm text-muted">Scanning verifies the assigned parcel and opens its authorized workflow. It does not record pickup, custody, or delivery by itself.</p>
            <div class="mt-5 flex flex-wrap gap-2">
                <a href="{{ route('rider.pickups') }}" class="rounded-xl border border-line px-4 py-3 text-sm font-semibold text-ink">Pickup Jobs</a>
                <a href="{{ route('rider.deliveries') }}" class="rounded-xl border border-line px-4 py-3 text-sm font-semibold text-ink">Deliveries</a>
                <a href="{{ route('rider.transfers') }}" class="rounded-xl border border-line px-4 py-3 text-sm font-semibold text-ink">Hub Transfers</a>
            </div>
        </article>
    </section>

    <section class="overflow-hidden rounded-3xl border border-line bg-surface">
        <div class="border-b border-line px-8 py-6">
            <h2 class="text-lg font-bold text-ink">Recent Parcels</h2>
            <p class="mt-2 text-xs text-muted">Latest parcels assigned to your rider account.</p>
        </div>

        <div class="divide-y divide-line">
            @forelse($recentDeliveries as $parcel)
                <article class="flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex gap-3">
                        <img src="{{ $parcel['image'] }}" alt="" class="h-14 w-14 rounded-xl border border-line object-cover">
                        <div>
                            <h3 class="text-base font-bold text-ink">{{ $parcel['tracking'] }}</h3>
                            <p class="mt-1 text-sm text-muted">Buyer: {{ $parcel['buyer'] }}</p>
                            <p class="mt-1 text-sm text-muted">{{ $parcel['address'] }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-primary-soft px-4 py-2 text-xs font-semibold text-primary">{{ $parcel['status_label'] }}</span>
                        <a href="{{ route('rider.shipments', ['tracking' => $parcel['tracking']]) }}" class="rounded-xl border border-line px-5 py-3 text-sm font-semibold text-ink hover:bg-page-secondary">View</a>
                    </div>
                </article>
            @empty
                <div class="p-8 text-sm text-muted">No rider parcels found yet.</div>
            @endforelse
        </div>
    </section>
</div>
@endsection
