@extends('layouts.rider.app')
@section('title', 'Delivery Batch #'.$trip->id.' - LIKHAE Rider')
@section('content')
@php
    $activeTripItems = $trip->items->whereIn('status', ['QUEUED', 'LOADED']);
    $pendingAcceptanceCount = $activeTripItems->filter(fn ($item) => $item->riderAssignment?->status === 'ASSIGNED')->count();
    $canManageRoute = in_array($trip->status, ['LOADING', 'READY', 'IN_PROGRESS'], true);
    $canAcceptBatch = $canManageRoute && $pendingAcceptanceCount > 0;
    $canStartBatch = $canManageRoute
        && $activeTripItems->isNotEmpty()
        && $activeTripItems->every(fn ($item) => in_array($item->riderAssignment?->status, ['ACCEPTED', 'IN_PROGRESS'], true))
        && $activeTripItems->contains(fn ($item) => $item->riderAssignment?->status === 'ACCEPTED');
    $canDeclineBatch = in_array($trip->status, ['LOADING', 'READY'], true)
        && $activeTripItems->isNotEmpty()
        && $activeTripItems->every(fn ($item) => in_array($item->riderAssignment?->status, ['ASSIGNED', 'ACCEPTED'], true));
    $routeAddress = $trip->items
        ->first(fn ($item) => $item->riderAssignment?->shipment?->sellerOrder?->order?->address)
        ?->riderAssignment?->shipment?->sellerOrder?->order?->address;
@endphp

<div class="mx-auto max-w-6xl space-y-5 sm:space-y-6">
    @if(session('status'))
        <div role="status" class="rounded-2xl border border-success/30 bg-success-soft px-5 py-4 text-sm font-semibold text-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-2xl border border-danger/30 bg-danger-soft px-5 py-4 text-sm font-semibold text-danger">{{ $errors->first() }}</div>
    @endif

    <header class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-7">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-primary">Delivery assignment</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Delivery Batch #{{ $trip->id }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-muted">Accept the assigned parcels before starting the route. Open each parcel below to record proof and complete delivery.</p>
            </div>
            <span class="inline-flex w-fit shrink-0 items-center rounded-full bg-primary-soft px-4 py-2 text-xs font-bold uppercase tracking-wide text-primary">{{ str($trip->status)->replace('_', ' ')->headline() }}</span>
        </div>

        <dl class="mt-6 grid gap-3 border-t border-line pt-5 sm:grid-cols-3">
            <div class="min-w-0 rounded-xl bg-page-secondary p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">Delivery area</dt>
                <dd class="mt-1 break-words text-sm font-semibold leading-6 text-ink">
                    {{ $routeAddress ? collect([$routeAddress->barangay_name, $routeAddress->municipality_name, $routeAddress->province_name])->filter()->join(', ') : 'Destination area' }}
                </dd>
            </div>
            <div class="rounded-xl bg-page-secondary p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">Parcels in this batch</dt>
                <dd class="mt-1 text-sm font-semibold text-ink">{{ $activeTripItems->count() }} active · {{ $trip->effective_capacity }} parcel capacity</dd>
            </div>
            <div class="rounded-xl bg-page-secondary p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-muted">Batch size</dt>
                <dd class="mt-1 text-sm font-semibold text-ink">Up to {{ $trip->effective_capacity }} parcels (maximum 10)</dd>
            </div>
        </dl>

        <details class="mt-4 text-xs text-muted">
            <summary class="w-fit cursor-pointer font-semibold hover:text-ink">View route codes</summary>
            <p class="mt-2 break-all font-mono">Province {{ $trip->province_code }} · Municipality {{ $trip->municipality_code }} · Barangay {{ $trip->barangay_code }}</p>
        </details>
    </header>

    @if($canAcceptBatch || $canStartBatch || $canDeclineBatch)
        <section class="grid gap-4 {{ ($canDeclineBatch && ($canAcceptBatch || $canStartBatch)) ? 'lg:grid-cols-2' : '' }}" aria-label="Batch actions">
            @if($canAcceptBatch || $canStartBatch)
                <article class="flex flex-col rounded-2xl border border-line bg-surface p-5 sm:p-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-primary">Next action</p>
                        <h2 class="mt-1 text-lg font-bold text-ink">{{ $canAcceptBatch ? 'Accept assigned parcels' : 'Start the delivery route' }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">
                            @if($canAcceptBatch)
                                Review this batch, then accept its {{ $pendingAcceptanceCount }} assigned {{ str('parcel')->plural($pendingAcceptanceCount) }}. You can accept it before it reaches full capacity.
                            @else
                                All active parcels are accepted. Starting the route marks them out for delivery.
                            @endif
                        </p>
                    </div>
                    @if($canAcceptBatch)
                        <form method="POST" action="{{ route('rider.trips.accept', $trip->id) }}" class="mt-5" data-confirm-title="Accept {{ $pendingAcceptanceCount }} deliveries?" data-confirm-message="Accept all {{ $pendingAcceptanceCount }} assigned parcels on this trip?" data-confirm-label="Accept {{ $pendingAcceptanceCount }} deliveries">
                            @csrf
                            <button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Accept {{ $pendingAcceptanceCount }} {{ str('delivery')->plural($pendingAcceptanceCount) }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('rider.trips.start', $trip->id) }}" class="mt-5" data-confirm-title="Start deliveries?" data-confirm-message="Start the route and mark every accepted parcel out for delivery?" data-confirm-label="Start deliveries">
                            @csrf
                            <button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">{{ $trip->status === 'IN_PROGRESS' ? 'Start remaining deliveries' : 'Start all deliveries' }}</button>
                        </form>
                    @endif
                </article>
            @endif

            @if($canDeclineBatch)
                <form method="POST" action="{{ route('rider.trips.reject', $trip->id) }}" class="flex flex-col rounded-2xl border border-danger/30 bg-surface p-5 sm:p-6" data-confirm-title="Decline this whole batch?" data-confirm-message="Every parcel in this batch will return to Logistics for reassignment." data-confirm-label="Decline batch">
                    @csrf
                    <label for="batch-decline-reason" class="text-sm font-semibold text-ink">Can’t take this batch?</label>
                    <p class="mt-1 text-sm leading-6 text-muted">Tell Logistics why the whole batch needs reassignment.</p>
                    <textarea id="batch-decline-reason" name="reason" rows="3" required maxlength="1000" class="mt-4 w-full rounded-xl border border-line bg-surface-secondary px-4 py-3 text-sm text-ink placeholder:text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Explain why you cannot take this batch">{{ old('reason') }}</textarea>
                    <button class="mt-4 w-full rounded-xl border border-danger/40 bg-danger-soft px-5 py-3 text-sm font-semibold text-danger transition hover:border-danger focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2">Decline whole batch</button>
                </form>
            @endif
        </section>
    @endif

    <section class="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" aria-labelledby="batch-parcels-heading">
        <div class="flex flex-col gap-1 border-b border-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                <h2 id="batch-parcels-heading" class="text-lg font-bold text-ink">Parcels in this batch</h2>
                <p class="mt-1 text-sm text-muted">Open a parcel to view its address and record delivery proof.</p>
            </div>
            <span class="mt-2 w-fit rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold text-primary sm:mt-0">{{ $activeTripItems->count() }} active</span>
        </div>

        <div class="divide-y divide-line">
            @forelse($trip->items as $item)
                @php($assignment = $item->riderAssignment)
                @php($shipment = $assignment?->shipment)
                @php($address = $shipment?->sellerOrder?->order?->address)
                <article class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="min-w-0">
                        @if($assignment)
                            <a class="break-all font-mono text-sm font-bold text-primary hover:underline" href="{{ route('rider.deliveries.show', $assignment->id) }}">{{ $shipment?->tracking_number ?? 'Parcel unavailable' }}</a>
                        @else
                            <strong class="break-all font-mono text-sm text-ink">Parcel unavailable</strong>
                        @endif
                        <p class="mt-1 break-words text-sm text-muted">{{ $address?->recipient_name ?? 'Buyer unavailable' }}@if($address) · {{ $address->formatted() }}@endif</p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-page-secondary px-3 py-1 font-medium text-muted">Batch: {{ str($item->status)->replace('_', ' ')->headline() }}</span>
                            <span class="rounded-full bg-primary-soft px-3 py-1 font-semibold text-primary">Delivery: {{ str($assignment?->status ?? 'Unavailable')->replace('_', ' ')->headline() }}</span>
                        </div>
                    </div>
                    @if($assignment)
                        <a href="{{ route('rider.deliveries.show', $assignment->id) }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-line px-5 py-2.5 text-sm font-semibold text-ink transition hover:border-primary hover:bg-primary-soft hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">Open delivery</a>
                    @endif
                </article>
            @empty
                <p class="px-5 py-8 text-center text-sm text-muted">This batch has no parcels.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
