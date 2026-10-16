@extends('Logistics.app')
@section('title', 'Local Trip #'.$trip->id.' - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-bold uppercase text-primary">Local trip #{{ $trip->id }}</p>
        <h1 class="mt-2 text-2xl font-bold text-ink">{{ $trip->trip_type }} · {{ str($trip->status)->headline() }}</h1>
        <p class="mt-2 font-mono text-sm text-muted">{{ $trip->province_code }} / {{ $trip->municipality_code }} / {{ $trip->barangay_code }}</p>
    </header>

    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif

    @if($trip->trip_type === 'PICKUP')
        @php($collectedItems = $trip->items->filter(fn ($item) => $item->riderAssignment?->status === 'COMPLETED'))
        @php($receivedItems = $collectedItems->filter(fn ($item) => $item->riderAssignment?->shipment?->current_status === 'AT_SORTING_CENTER'))
        <section class="border border-primary/20 bg-primary/5 p-5">
            <h2 class="font-bold text-ink">Seller pickup batch #P-{{ $trip->id }}</h2>
            <p class="mt-1 text-sm text-muted">Seller: {{ $trip->seller?->business_name ?? 'Seller unavailable' }}</p>
            <p class="mt-1 text-sm text-muted">Rider: {{ $trip->rider?->user?->name ?? 'Rider unavailable' }} · {{ $trip->items->count() }}/{{ $trip->approved_parcel_capacity }} parcels</p>
            @if($trip->status === 'EN_ROUTE_TO_HUB')
                <p class="mt-3 text-sm font-semibold text-primary">{{ $receivedItems->count() }} of {{ $collectedItems->count() }} collected parcels received. Scan each parcel in the Receive at Sorting Center queue.</p>
            @elseif($trip->status === 'COMPLETED')
                <p class="mt-3 text-sm font-semibold text-green-700">All collected parcels have been received at the sorting center.</p>
            @else
                <p class="mt-3 text-sm text-muted">Rider acceptance, per-parcel pickup scans, and batch completion are managed from the rider’s Seller Pickups page.</p>
            @endif
        </section>
    @else
        <p class="text-sm text-muted">{{ $trip->items->count() }}/{{ $trip->approved_parcel_capacity }} parcels loaded</p>
        @if($trip->status === 'LOADING')
            <form method="POST" action="{{ route('logistics.local-trips.items.store', $trip->id) }}" class="flex gap-2 border border-line bg-surface p-4">
                @csrf
                <select name="rider_assignment_id" required class="min-w-0 flex-1 border border-line bg-white px-3 py-2">
                    <option value="">Select eligible assignment</option>
                    @foreach($assignments as $assignment)<option value="{{ $assignment->id }}">#{{ $assignment->id }} · {{ $assignment->shipment?->tracking_number }} · {{ $assignment->status }}</option>@endforeach
                </select>
                <button class="bg-primary px-4 py-2 font-semibold text-white">Add parcel</button>
            </form>
        @endif
        <div class="flex flex-wrap gap-2">
            @if($trip->status === 'LOADING')
                <form method="POST" action="{{ route('logistics.local-trips.load', $trip->id) }}" class="grid w-full gap-3 border border-line bg-surface p-4">
                    @csrf
                    <strong>Scan each parcel to verify this trip load</strong>
                    @foreach($trip->items->whereIn('status', ['QUEUED', 'LOADED']) as $item)
                        <label class="grid gap-2 text-sm sm:grid-cols-[1fr_1fr] sm:items-center"><span>{{ $item->riderAssignment?->shipment?->tracking_number }}</span><input name="scanned_codes[{{ $item->id }}]" required autocomplete="off" placeholder="Scan parcel barcode" class="border border-line px-3 py-2"></label>
                    @endforeach
                    <button class="justify-self-start bg-primary px-4 py-2 font-semibold text-white">Verify and load trip</button>
                </form>
            @endif
            @if(in_array($trip->status, ['READY','IN_PROGRESS']))<form method="POST" action="{{ route('logistics.local-trips.close', $trip->id) }}">@csrf<button class="border border-line px-4 py-2 font-semibold">Close trip</button></form>@endif
        </div>
    @endif

    <div class="overflow-x-auto border border-line bg-surface">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-line bg-gray-50 text-xs uppercase text-muted"><tr><th class="p-4">Assignment</th><th class="p-4">Parcel</th><th class="p-4">Item status</th><th class="p-4">Assignment status</th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse($trip->items as $item)
                    <tr><td class="p-4">#{{ $item->rider_assignment_id }}</td><td class="p-4">{{ $item->riderAssignment?->shipment?->tracking_number }}</td><td class="p-4 font-semibold">{{ str($item->status)->headline() }}</td><td class="p-4">{{ str($item->riderAssignment?->status)->headline() }}</td></tr>
                @empty
                    <tr><td colspan="4" class="p-8 text-center text-muted">No assignments loaded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
