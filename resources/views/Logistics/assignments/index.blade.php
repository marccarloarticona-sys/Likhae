@extends('layouts.logistics.app')
@section('title', 'Delivery Rider Assignment - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-bold uppercase text-primary">Final-mile dispatch</p><h1 class="mt-2 text-2xl font-bold text-ink">Assign Sorted Parcels</h1><p class="mt-2 text-sm text-muted">Assign an area rider manually or auto-match the nearest available rider when the barangay team is unavailable.</p><p class="mt-1 text-xs text-muted">Each batch follows the rider’s approved parcel capacity, up to 10 parcels. Parcels routed to another hub belong in Hub Transfers and appear here after the destination hub receives and sorts them.</p></header>
    <x-logistics.parcel-flow current="assign" />
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <x-shared.parcel-scanner :action="route('logistics.assignments')" :tracking="request('tracking', '')" :method="request('scan_method', 'MANUAL')" title="Identify the Area Rider" description="Scan a sorted parcel to show only active riders assigned to its destination area." button="Find Parcel" />
    <div class="grid gap-4">
    @forelse($shipments as $shipment)
        @php($activeAssignment = $shipment->riderAssignments->where('assignment_type','DELIVERY')->whereIn('status',['ASSIGNED','ACCEPTED','IN_PROGRESS'])->sortByDesc('id')->first())
        @php($assignedRiderUnavailable = $activeAssignment && $activeAssignment->status === 'ASSIGNED' && (! $activeAssignment->riderProfile?->is_online || ! ($activeAssignment->riderProfile?->availability_location_updated_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false)))
        @php($eligibleRiders = $eligibleRidersByShipment[$shipment->id] ?? collect())
        <article class="border border-line bg-surface p-5"><div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div><a class="text-lg font-bold text-primary" href="{{ route('logistics.parcels.show',$shipment) }}">{{ $shipment->tracking_number }}</a><p class="mt-1 text-sm text-muted">{{ $shipment->sellerOrder?->order?->address?->formatted() }}</p><span class="text-xs font-semibold">{{ $shipment->serviceArea?->name ?? 'No delivery area' }} · {{ str($shipment->current_status)->headline() }}</span></div>
            @if($activeAssignment)<div class="grid gap-3"><div><strong>{{ $activeAssignment->riderProfile?->user?->name }}</strong><small class="block text-muted">{{ str($activeAssignment->status)->headline() }}</small></div>@if($assignedRiderUnavailable)<form method="POST" action="{{ route('logistics.assignments.auto-match',$shipment) }}" class="flex flex-wrap items-center gap-3 border-t border-line pt-3">@csrf<button class="border border-primary px-4 py-2 font-semibold text-primary">Auto-match replacement rider</button><p class="text-xs text-muted">Available before the assigned rider accepts, when they are offline or their location is stale.</p></form>@endif</div>
            @elseif($shipment->current_status === 'SORTED' && $localTripDispatchReady)
                <div class="grid w-full gap-3">
                <form method="POST" action="{{ route('logistics.assignments.assign',$shipment) }}" class="grid gap-2 sm:grid-cols-3" data-final-mile-dispatch>
                    @csrf
                    <input type="hidden" name="assignment_type" value="DELIVERY">
                    <select name="rider_profile_id" required class="border border-line bg-white px-3 py-2" data-dispatch-rider><option value="">Select area rider</option>@foreach($eligibleRiders as $rider)<option value="{{ $rider->id }}">{{ $rider->user?->name }} - {{ $rider->vehicle_type }}</option>@endforeach</select>
                    <select name="local_trip_id" required class="border border-line bg-white px-3 py-2" data-dispatch-trip>
                        <option value="0">Auto-group or create a batch for this barangay</option>
                        @foreach($eligibleRiders as $tripRider)
                            @foreach($localTripsByRider->get($tripRider->id, collect()) as $trip)
                                @if((string) $trip->province_code === (string) $shipment->destination_province_code && (string) $trip->municipality_code === (string) $shipment->destination_municipality_code && (string) $trip->barangay_code === (string) $shipment->destination_barangay_code && (int) $trip->load_count < min(10, (int) $trip->approved_parcel_capacity, (int) $tripRider->approved_parcel_capacity))
                                    <option value="{{ $trip->id }}" data-rider-id="{{ $tripRider->id }}">Trip #{{ $trip->id }} - {{ $trip->load_count }}/{{ $trip->approved_parcel_capacity }} parcels - {{ $tripRider->user?->name }}</option>
                                @endif
                            @endforeach
                        @endforeach
                    </select>
                    <button class="bg-primary px-4 py-2 font-semibold text-white" @disabled($eligibleRiders->isEmpty())>Add parcel to batch</button>
                    <p class="text-xs text-muted sm:col-span-3">Auto-group fills an open batch for this exact barangay, up to the rider’s approved capacity (maximum 10). A new batch starts when none has room.</p>
                </form>
                <form method="POST" action="{{ route('logistics.assignments.auto-match',$shipment) }}" class="flex flex-wrap items-center gap-3 border-t border-line pt-3">
                    @csrf
                    <button class="border border-primary px-4 py-2 font-semibold text-primary">Auto-match nearest available rider</button>
                    <p class="text-xs text-muted">Uses a recent rider location and remaining capacity. If no available rider covers this barangay, it records a temporary coverage exception.</p>
                </form>
                </div>
            @elseif($shipment->current_status === 'SORTED')
                <span class="text-sm text-amber-800">Final-mile dispatch is disabled until local trip support is installed.</span>
            @else<span class="text-sm text-muted">No active delivery assignment</span>@endif
        </div></article>
    @empty<section class="border border-dashed border-line bg-surface p-8 text-center text-muted">{{ request('tracking') ? 'No parcel matches that waybill in the assignment queue.' : 'No sorted parcels are awaiting assignment.' }}</section>@endforelse
    </div>{{ $shipments->links() }}

<script>
    document.querySelectorAll('[data-final-mile-dispatch]').forEach((form) => {
        const rider = form.querySelector('[data-dispatch-rider]');
        const trip = form.querySelector('[data-dispatch-trip]');
        const filterTrips = () => {
            [...trip.options].forEach((option) => {
                if (!option.dataset.riderId) return;
                const available = option.dataset.riderId === rider.value;
                option.hidden = !available;
                option.disabled = !available;
            });
            if (trip.selectedOptions[0]?.disabled) trip.value = '0';
        };
        rider.addEventListener('change', filterTrips);
        filterTrips();
    });
</script>
</div>
@endsection
