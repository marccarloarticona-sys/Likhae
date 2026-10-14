@extends('Rider.app')

@section('title', 'Seller Pickup Assignments - LIKHAE Rider')

@section('content')
<div class="flex flex-col gap-8">
    @if(session('status'))
        <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif

    <section>
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Seller pickup</span>
        <h1 class="mt-3 text-2xl font-bold tracking-tight text-ink">Seller Pickup Assignments</h1>
        <p class="mt-3 text-sm text-muted">Each assignment is one seller stop. Scan every waybill at the seller, then take collected parcels to the assigned logistics center.</p>
    </section>

    <section class="grid gap-4">
        @forelse($batches as $batch)
            @php($total = $batch->items->count())
            @php($collected = $batch->items->filter(fn ($item) => $item->riderAssignment?->status === 'COMPLETED')->count())
            @php($resolved = $batch->items->every(fn ($item) => in_array($item->riderAssignment?->status, ['COMPLETED', 'REJECTED', 'CANCELLED'], true)))
            @php($label = match($batch->status) { 'LOADING' => 'Assigned', 'READY' => 'Accepted', 'EN_ROUTE_TO_HUB' => 'In transit to hub', 'IN_PROGRESS' => $resolved ? 'Seller pickup complete' : 'In progress', default => str($batch->status)->headline() })
            <article class="rounded-3xl border border-line bg-surface p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.15em] text-primary">Pickup Batch #P-{{ $batch->id }}</p>
                        <h2 class="mt-2 text-xl font-bold text-ink">{{ $batch->seller?->business_name ?? 'Seller unavailable' }}</h2>
                        <p class="mt-2 text-sm text-muted">{{ $batch->seller?->businessAddress?->formatted() ?? 'Pickup address unavailable' }}</p>
                        <p class="mt-3 text-sm font-semibold text-ink">{{ $total }} {{ str('parcel')->plural($total) }} · {{ $collected }}/{{ $total }} verified</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-full bg-warning-soft px-4 py-2 text-xs font-semibold text-warning">{{ $label }}</span>
                        <a href="{{ route('rider.pickups.batches.show', $batch->id) }}" class="rounded-xl border border-line px-5 py-3 text-sm font-semibold text-ink hover:bg-page-secondary">{{ $batch->status === 'LOADING' ? 'View Pickup' : 'Continue Pickup' }}</a>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-3xl border border-dashed border-line bg-surface p-8 text-center text-sm text-muted">No seller pickup assignments are waiting.</div>
        @endforelse
    </section>

    <div>{{ $batches->links() }}</div>
</div>
@endsection
