@extends('Logistics.app')
@section('title', 'Pickup Requests - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-bold uppercase text-primary">First-mile operations</p><h1 class="mt-2 text-2xl font-bold text-ink">Seller Pickup Requests</h1><p class="mt-2 text-sm text-muted">Approve requests and assign an active rider from this logistics center.</p></header>
    @if(session('status'))<div class="border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <form method="GET" action="{{ route('logistics.pickups') }}" class="flex flex-col gap-2 border border-line bg-surface p-4 sm:flex-row">
        <input name="tracking" value="{{ request('tracking', '') }}" class="h-10 min-w-0 flex-1 border border-line bg-white px-3 text-sm text-ink" placeholder="Filter by tracking number" aria-label="Filter pickup requests by tracking number">
        <button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Find Pickup</button>
    </form>
    <x-shared.mapbox :markers="$mapMarkers" title="Pickup operations map" height="220px" user-location-target="seller" />
    <div class="overflow-x-auto border border-line bg-surface"><table class="min-w-full text-left text-sm">
        <thead class="border-b border-line bg-gray-50 text-xs uppercase text-muted"><tr><th class="p-4">Parcel</th><th class="p-4">Seller</th><th class="p-4">Destination</th><th class="p-4">Status</th><th class="p-4">Assignment</th></tr></thead>
        <tbody class="divide-y divide-line">
        @forelse($pickups as $pickup)
            @php($shipment = $pickup->shipment)
            <tr>
                <td class="p-4"><a class="font-semibold text-primary" href="{{ route('logistics.parcels.show', $shipment) }}">{{ $shipment->tracking_number }}</a><div class="text-muted">{{ $shipment->sellerOrder?->seller_order_number }}</div></td>
                <td class="p-4">{{ $shipment->sellerOrder?->sellerProfile?->business_name }}</td>
                <td class="p-4">{{ collect([$shipment->destination_barangay_name,$shipment->destination_municipality_name,$shipment->destination_province_name])->filter()->implode(', ') }}</td>
                <td class="p-4 font-semibold">{{ str($pickup->status)->headline() }}</td>
                <td class="p-4">
                    @php($activeAssignment = $shipment->riderAssignments->where('assignment_type','PICKUP')->whereIn('status',['ASSIGNED','ACCEPTED','IN_PROGRESS'])->sortByDesc('id')->first())
                    @if(str_starts_with((string) $pickup->notes, 'SELLER_DROPOFF:'))
                        <form method="POST" action="{{ route('logistics.parcels.receive.confirm', $shipment) }}" class="flex min-w-72 gap-2">@csrf<input name="scanned_code" required class="min-w-0 flex-1 border border-line px-3 py-2" placeholder="Scan tracking number"><input type="hidden" name="scan_method" value="MANUAL"><button class="bg-primary px-4 py-2 font-semibold text-white">Confirm Drop-off</button></form>
                    @elseif($activeAssignment)<span class="font-semibold">{{ $activeAssignment->riderProfile?->user?->name }}</span><small class="block text-muted">{{ str($activeAssignment->status)->headline() }}</small>
                    @else
                    @php($pickupAreaId = $pickupAreaIds[$shipment->id] ?? null)
                    @php($eligibleRiders = $pickupAreaId ? $riders->filter(fn ($rider) => $rider->areaAssignments->contains(fn ($assignment) => $assignment->is_active && (int) $assignment->service_area_id === (int) $pickupAreaId)) : $riders)
                    @php($pickupEligibleIds = $pickupEligibleRiderIds[$shipment->id] ?? null)
                    @php($eligibleRiders = $pickupAreaId && $pickupEligibleIds !== null ? $riders->whereIn('id', $pickupEligibleIds) : $eligibleRiders)
                    @php($hasCoveredRiders = $eligibleRiders->isNotEmpty())
                    @php($coverageShortage = $pickupAreaId && !$hasCoveredRiders)
                    @php($eligibleRiders = $coverageShortage ? $riders : $eligibleRiders)
                    <form method="POST" action="{{ route('logistics.pickups.assign', $shipment) }}" class="grid min-w-72 grid-cols-[minmax(0,1fr)_auto] gap-2">@csrf
                        <input type="hidden" name="assignment_shipment_id" value="{{ $shipment->id }}">
                        <select name="rider_profile_id" required class="min-h-11 min-w-0 border border-line bg-white px-3"><option value="">{{ $eligibleRiders->isEmpty() ? 'No active rider available' : 'Select nearby rider' }}</option>@foreach($eligibleRiders as $rider)<option value="{{ $rider->id }}" @selected((int) old('assignment_shipment_id') === (int) $shipment->id && (int) old('rider_profile_id') === (int) $rider->id)>{{ $rider->user?->name }} — {{ $rider->vehicle_type }}</option>@endforeach</select>
                        <button class="bg-primary px-4 py-2 font-semibold text-white">Assign</button>
                        @if($pickupAreaId)
                            <label class="col-span-full flex items-start gap-2 text-[11px] font-semibold {{ $coverageShortage ? 'text-red-700' : 'text-amber-800' }}"><input type="checkbox" name="coverage_override" value="1" @checked((int) old('assignment_shipment_id') === (int) $shipment->id && old('coverage_override')) class="mt-0.5"><span>{{ $coverageShortage ? 'Required for the listed rider: no active rider covers this pickup area. Check to use the shortage override.' : 'Use only if an area-coverage override is required.' }}</span></label>
                            <textarea name="coverage_override_reason" rows="2" maxlength="1000" class="col-span-full border border-line px-3 py-2 text-xs" placeholder="Reason for coverage override (required when checked)">{{ old('assignment_shipment_id') == $shipment->id ? old('coverage_override_reason') : '' }}</textarea>
                        @endif
                        @if((int) old('assignment_shipment_id') === (int) $shipment->id && $errors->has('rider_profile_id'))
                            <p class="col-span-full border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-800" role="alert">{{ $errors->first('rider_profile_id') }}</p>
                        @endif
                    </form>@endif
                </td>
            </tr>
        @empty<tr><td colspan="5" class="p-8 text-center text-muted">{{ request('tracking') ? 'No pending pickup requests match that waybill.' : 'No pending pickup requests.' }}</td></tr>@endforelse
        </tbody>
    </table></div>{{ $pickups->links() }}
</div>
@endsection
