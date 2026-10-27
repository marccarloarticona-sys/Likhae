@extends('layouts.rider.app')
@section('title', 'Delivery Batch #'.$trip->id.' - LIKHAE Rider')
@section('content')
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold text-ink">Delivery Batch #{{ $trip->id }}</h1>
        <p class="mt-2 font-mono text-sm text-muted">{{ $trip->province_code }} / {{ $trip->municipality_code }} / {{ $trip->barangay_code }} &middot; {{ str($trip->status)->headline() }}</p>
        <p class="mt-2 text-sm text-muted">This batch holds up to {{ $trip->effective_capacity }} parcels based on your approved capacity (maximum 10). Accept and start its parcels together, then open each parcel below to record proof and complete that delivery.</p>
    </header>

    @if(session('status'))
        <div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>
    @endif

    @php($activeTripItems = $trip->items->whereIn('status', ['QUEUED', 'LOADED']))
    <div class="flex flex-wrap gap-2">
        @if(in_array($trip->status, ['LOADING', 'READY'], true) && $activeTripItems->isNotEmpty() && $activeTripItems->every(fn ($item) => in_array($item->riderAssignment?->status, ['ASSIGNED', 'ACCEPTED'], true)))
            <form method="POST" action="{{ route('rider.trips.reject', $trip->id) }}" class="grid w-full gap-2 rounded-xl border border-line p-4 sm:max-w-xl" data-confirm-title="Decline this whole batch?" data-confirm-message="Every parcel in this batch will return to Logistics for reassignment." data-confirm-label="Decline batch">
                @csrf
                <label class="grid gap-2 text-sm font-semibold text-ink">Reason for declining the batch
                    <textarea name="reason" rows="2" required maxlength="1000" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" placeholder="Explain why you cannot take this batch">{{ old('reason') }}</textarea>
                </label>
                <button class="w-fit rounded-xl border border-red-200 bg-red-50 px-4 py-2 font-semibold text-red-700">Decline whole batch</button>
            </form>
        @endif
        @if(in_array($trip->status, ['LOADING', 'READY', 'IN_PROGRESS'], true) && $activeTripItems->contains(fn ($item) => $item->riderAssignment?->status === 'ASSIGNED'))
            <form method="POST" action="{{ route('rider.trips.accept', $trip->id) }}" data-confirm-title="Accept every delivery?" data-confirm-message="Accept all assigned parcels on this trip?" data-confirm-label="Accept all deliveries">
                @csrf
                <button class="bg-primary px-4 py-2 font-semibold text-white">Accept all deliveries</button>
            </form>
        @endif

        @if(in_array($trip->status, ['LOADING', 'READY', 'IN_PROGRESS'], true))
            @if($activeTripItems->contains(fn ($item) => $item->riderAssignment?->status === 'ASSIGNED'))
                <p class="self-center text-sm text-muted">Accept every parcel before starting this route.</p>
            @elseif($activeTripItems->isNotEmpty() && $activeTripItems->every(fn ($item) => in_array($item->riderAssignment?->status, ['ACCEPTED', 'IN_PROGRESS'], true)) && $activeTripItems->contains(fn ($item) => $item->riderAssignment?->status === 'ACCEPTED'))
                <form method="POST" action="{{ route('rider.trips.start', $trip->id) }}" data-confirm-title="Start deliveries?" data-confirm-message="Start the route and mark every accepted parcel out for delivery?" data-confirm-label="Start deliveries">
                    @csrf
                    <button class="bg-primary px-4 py-2 font-semibold text-white">{{ $trip->status === 'IN_PROGRESS' ? 'Start remaining deliveries' : 'Start all deliveries' }}</button>
                </form>
            @endif
        @endif
    </div>

    <div class="grid gap-3">
        @foreach($trip->items as $item)
            @php($assignment = $item->riderAssignment)
            <article class="flex flex-col gap-3 border border-line bg-surface p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <strong>{{ $assignment?->shipment?->tracking_number ?? 'Parcel unavailable' }}</strong>
                    <p class="mt-1 text-sm text-muted">Trip load: {{ str($item->status)->headline() }} &middot; Delivery: {{ str($assignment?->status ?? 'Unavailable')->headline() }}</p>
                </div>
                @if($assignment)
                    <a href="{{ route('rider.deliveries.show', $assignment->id) }}" class="inline-flex justify-center border border-line px-4 py-2 text-sm font-semibold text-ink">Open delivery</a>
                @endif
            </article>
        @endforeach
    </div>
</div>
@endsection
