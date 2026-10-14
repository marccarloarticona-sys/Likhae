@extends('Logistics.app')
@section('title', 'Local Rider Trips - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-bold uppercase text-primary">Barangay dispatch</p><h1 class="mt-2 text-2xl font-bold text-ink">Local Rider Trips</h1><p class="mt-2 text-sm text-muted">Capacity is the lower of the approved rider capacity and 10 parcels. Seller pickup batches are created from the Seller Pickup Queue.</p></header>
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    @if(!$ready)<section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Local trip dispatch is prepared but inactive. Step 4 custody schema and the additive trip migrations are still pending approval and execution.</section>@else
    @if($riders->isEmpty() || $areas->isEmpty())
        <section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <strong class="block">Local dispatch is not ready for a loading trip.</strong>
            <p class="mt-1">An active local rider with approved parcel capacity and an exact barangay area assignment are required. Configure those prerequisites in <a class="font-semibold underline" href="{{ route('logistics.delivery-areas') }}">Delivery Areas</a> and rider management first.</p>
        </section>
    @endif
    <form method="POST" action="{{ route('logistics.local-trips.store') }}" class="grid gap-3 border border-line bg-surface p-5 md:grid-cols-4">@csrf
        <select name="rider_profile_id" required class="border border-line bg-white px-3 py-2"><option value="">Select local rider</option>@foreach($riders as $rider)<option value="{{ $rider->id }}">{{ $rider->user?->name }} — {{ $rider->vehicle_type }} (cap {{ $rider->approved_parcel_capacity ?? 'not approved' }})</option>@endforeach</select>
        <select name="service_area_id" required class="border border-line bg-white px-3 py-2"><option value="">Select one-barangay area</option>@foreach($areas as $area)@php($location = $area->locations->count() === 1 ? $area->locations->first() : null)<option value="{{ $area->id }}">{{ $area->name }}{{ $location ? ' — '.$location->barangay_code : ' — invalid multi-barangay area' }}</option>@endforeach</select>
        <input type="hidden" name="trip_type" value="DELIVERY"><div class="border border-line bg-gray-50 px-3 py-2 text-sm font-semibold text-muted">Delivery</div>
        <button class="bg-primary px-4 py-2 font-semibold text-white" @disabled($riders->isEmpty() || $areas->isEmpty())>Create loading trip</button>
    </form>
    <div class="overflow-x-auto border border-line bg-surface"><table class="min-w-full text-left text-sm"><thead class="border-b border-line bg-gray-50 text-xs uppercase text-muted"><tr><th class="p-4">Trip</th><th class="p-4">Rider</th><th class="p-4">PSGC barangay</th><th class="p-4">Load</th><th class="p-4">Status</th></tr></thead><tbody class="divide-y divide-line">@forelse($trips as $trip)<tr><td class="p-4"><a class="font-semibold text-primary" href="{{ route('logistics.local-trips.show', $trip->id) }}">#{{ $trip->id }}</a><div class="text-xs text-muted">{{ $trip->trip_type }}</div></td><td class="p-4">{{ $trip->rider?->user?->name }}</td><td class="p-4 font-mono text-xs">{{ $trip->province_code }} / {{ $trip->municipality_code }} / {{ $trip->barangay_code }}</td><td class="p-4">{{ $trip->load_count }}/{{ $trip->approved_parcel_capacity }}</td><td class="p-4 font-semibold">{{ str($trip->status)->headline() }}</td></tr>@empty<tr><td colspan="5" class="p-8 text-center text-muted">No local trips yet.</td></tr>@endforelse</tbody></table></div>{{ $trips->links() }}
    @endif
</div>
@endsection
