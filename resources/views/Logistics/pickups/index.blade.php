@extends('Logistics.app')

@section('title', 'Seller Pickup Queue - LIKHAE Logistics')

@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-bold uppercase text-primary">First-mile operations</p>
        <h1 class="mt-2 text-2xl font-bold text-ink">Seller Pickup Queue</h1>
        <p class="mt-2 text-sm text-muted">Each pickup assignment visits one seller address and holds 1 to 10 parcels, limited by the selected rider capacity.</p>
    </header>
    @if(session('status'))<div class="border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif

    <form method="GET" action="{{ route('logistics.pickups') }}" class="flex flex-col gap-2 border border-line bg-surface p-4 sm:flex-row">
        <input name="tracking" value="{{ $tracking }}" class="h-10 min-w-0 flex-1 border border-line bg-white px-3 text-sm text-ink" placeholder="Filter by tracking number" aria-label="Filter pickup queue by tracking number">
        <button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Find Parcel</button>
    </form>
    <x-shared.mapbox :markers="$mapMarkers" title="Seller pickup operations map" height="220px" user-location-target="seller" />

    @if(!$pickupSchemaReady)
        <section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Seller pickup batch assignment is unavailable until the additive local trip migrations are installed.</section>
    @endif

    <section class="space-y-4" data-seller-pickup-requests>
        <div><h2 class="text-lg font-bold text-ink">Seller Pickup Requests</h2><p class="mt-1 text-sm text-muted">Each request keeps its selected parcels together and preserves each parcel’s own waybill and scan history.</p></div>
        @forelse($sellerPickupRequests as $pickupRequest)
            @php($requestParcels = $pickupRequest->memberships)
            @php($progress = $pickupRequest->progress_payload)
            <article class="border border-line bg-surface p-5" data-pickup-request="{{ $pickupRequest->id }}">
                <header class="flex flex-col gap-3 border-b border-line pb-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase text-primary">Request #{{ $pickupRequest->id }}</p>
                        <h3 class="mt-1 font-bold text-ink">{{ $pickupRequest->sellerProfile?->business_name ?? 'Seller unavailable' }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ $pickupRequest->pickupAddress?->formatted() ?? 'Pickup address unavailable' }}</p>
                        <p class="mt-1 text-sm text-muted">{{ $requestParcels->count() }} {{ str('parcel')->plural($requestParcels->count()) }} · {{ $progress['collected'] }} / {{ $progress['total'] }} collected</p>
                    </div>
                    <span class="border border-line px-3 py-2 text-xs font-bold uppercase text-ink">{{ str($pickupRequest->status)->replace('_', ' ')->title() }}</span>
                </header>
                <ol class="divide-y divide-line">
                    @foreach($requestParcels as $membership)
                        @php($shipment = $membership->shipment)
                        <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <strong class="block text-primary">{{ $shipment?->tracking_number }}</strong>
                                <span class="block text-xs text-muted">{{ $membership->sellerOrder?->seller_order_number }} · {{ $shipment?->orderItem?->product_name ?? 'Parcel' }} · {{ str($shipment?->current_status ?? 'UNKNOWN')->replace('_', ' ')->title() }}</span>
                            </div>
                            @if($shipment?->tracking_number)
                                <a class="text-xs font-semibold text-primary underline" target="_blank" rel="noopener" href="{{ route('logistics.waybills.show', $shipment->tracking_number) }}">Open individual waybill</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
                @if($pickupRequest->status === 'REQUESTED' && $pickupSchemaReady)
                    <div class="grid gap-4 border-t border-line pt-4 lg:grid-cols-[minmax(0,1fr)_auto]">
                        <form method="POST" action="{{ route('logistics.pickups.requests.assign', $pickupRequest) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            @csrf
                            <label class="grid flex-1 gap-1 text-xs font-semibold text-muted">Eligible Rider with capacity for all {{ $requestParcels->count() }} parcels
                                <select name="rider_profile_id" required class="min-h-11 border border-line bg-white px-3 text-sm text-ink" @disabled($pickupRequest->eligible_riders->isEmpty())>
                                    <option value="">{{ $pickupRequest->eligible_riders->isEmpty() ? 'No eligible Rider with sufficient capacity' : 'Select a local Rider' }}</option>
                                    @foreach($pickupRequest->eligible_riders as $rider)
                                        <option value="{{ $rider->id }}">{{ $rider->user?->name }} · {{ str($rider->vehicle_type)->headline() }} · approved capacity {{ min(10, (int) $rider->approved_parcel_capacity) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="min-h-11 bg-primary px-5 py-3 text-sm font-semibold text-white disabled:opacity-50" @disabled($pickupRequest->eligible_riders->isEmpty())>Assign request to Rider</button>
                        </form>
                        <form method="POST" action="{{ route('logistics.pickups.requests.reject', $pickupRequest) }}" data-confirm-title="Reject this pickup request?" data-confirm-message="The parcel membership history will remain, and the parcels can be requested again." data-confirm-label="Reject request">
                            @csrf
                            <button class="min-h-11 border border-line px-4 py-3 text-sm font-semibold text-muted">Reject request</button>
                        </form>
                    </div>
                @elseif($pickupRequest->rider_local_trip_id)
                    <p class="border-t border-line pt-4 text-sm text-muted">Assigned Rider: <strong class="text-ink">{{ $pickupRequest->riderLocalTrip?->rider?->user?->name ?? 'Unavailable' }}</strong> · <a class="font-semibold text-primary underline" href="{{ route('logistics.local-trips.show', $pickupRequest->rider_local_trip_id) }}">Trip #{{ $pickupRequest->rider_local_trip_id }}</a></p>
                @endif
            </article>
        @empty
            <div class="border border-line bg-surface p-5 text-sm text-muted">No Seller pickup requests yet.</div>
        @endforelse
    </section>

    <section class="space-y-4">
        <div><h2 class="text-lg font-bold text-ink">Waiting for Assignment</h2><p class="mt-1 text-sm text-muted">Ready parcels are grouped by the seller and that seller's registered pickup address.</p></div>
        @forelse($sellerPickupQueue as $group)
            @php($seller = $group['seller'])
            @php($shipments = $group['shipments'])
            @php($eligibleRiders = $group['eligible_riders'])
            @php($address = $seller?->businessAddress)
            <details open class="border border-line bg-surface" data-seller-pickup-group>
                <summary class="cursor-pointer list-none border-b border-line px-5 py-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div><h3 class="font-bold text-ink">{{ $seller?->business_name ?? 'Seller unavailable' }}</h3><p class="mt-1 text-sm text-muted">{{ $address?->formatted() ?? 'Pickup address unavailable' }}</p></div>
                        <span class="text-sm font-semibold text-ink">{{ $shipments->count() }} ready {{ str('parcel')->plural($shipments->count()) }} Â· {{ $center->business_name }}</span>
                    </div>
                </summary>
                <div class="p-5">
                    @if($pickupSchemaReady)
                    <form method="POST" action="{{ route('logistics.pickups.assignments.store') }}" data-seller-pickup-form class="space-y-4">
                        @csrf
                        <div class="grid gap-2">
                            @foreach($shipments as $shipment)
                                @php($pickupRequest = $group['pickup_requests']->get($shipment->id))
                                <label class="flex cursor-pointer items-start gap-3 border border-line p-3 hover:bg-page-secondary">
                                    <input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" class="mt-1" data-pickup-parcel>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold text-primary">{{ $shipment->tracking_number }}</span>
                                        @if(filled($pickupRequest?->notes))<span class="mt-1 block text-xs text-muted">Seller handover: {{ str($pickupRequest->notes)->after(':') }}</span>@endif
                                        <span class="mt-1 block text-xs text-muted">{{ $shipment->sellerOrder?->seller_order_number }} Â· {{ ($shipment->orderItem?->product_name ?? $shipment->sellerOrder?->items?->pluck('product_name')->filter()->join(', ')) }}</span>
                                    </span>
                                    <span class="text-xs font-semibold text-muted">READY_FOR_PICKUP</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="grid gap-3 border-t border-line pt-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <label class="grid gap-1 text-xs font-semibold text-muted">Eligible local rider
                                <select name="rider_profile_id" required data-pickup-rider class="min-h-11 border border-line bg-white px-3 text-sm text-ink">
                                    <option value="">{{ $eligibleRiders->isEmpty() ? 'No eligible rider assigned to this pickup barangay' : 'Select motorcycle or tricycle rider' }}</option>
                                    @foreach($eligibleRiders as $rider)
                                        <option value="{{ $rider->id }}" data-capacity="{{ min(10, (int) $rider->approved_parcel_capacity, \App\Services\Fulfillment\ShipmentWorkflowService::MAX_ACTIVE_PICKUP_ASSIGNMENTS - (int) $rider->active_pickup_assignments_count) }}">{{ $rider->user?->name }} Â· {{ str($rider->vehicle_type)->headline() }} Â· cap {{ min(10, (int) $rider->approved_parcel_capacity, \App\Services\Fulfillment\ShipmentWorkflowService::MAX_ACTIVE_PICKUP_ASSIGNMENTS - (int) $rider->active_pickup_assignments_count) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" disabled data-pickup-submit class="min-h-11 bg-primary px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Assign Selected Parcels</button>
                        </div>
                        <p class="text-xs text-muted"><span data-pickup-selected>0</span> selected Â· 1 to the rider's approved capacity, up to 10 parcels.</p>
                    </form>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($shipments as $shipment)
                            <form method="POST" action="{{ route('logistics.pickups.auto-assign', $shipment) }}">@csrf<button class="border border-primary px-3 py-2 text-xs font-semibold text-primary">Auto-assign {{ $shipment->tracking_number }}</button></form>
                        @endforeach
                    </div>
                    @else
                        <p class="text-sm text-muted">Pickup assignment is unavailable until the seller-specific local-trip migration is installed.</p>
                    @endif
                </div>
            </details>
        @empty
            <div class="border border-dashed border-line bg-surface p-8 text-center text-sm text-muted">{{ $tracking ? 'No waiting pickup parcel matches that waybill.' : 'No sellers are waiting for rider pickup.' }}</div>
        @endforelse
    </section>

    <section class="space-y-4">
        <div><h2 class="text-lg font-bold text-ink">Active Seller Pickups</h2><p class="mt-1 text-sm text-muted">Seller scans, rider progress, and parcels still due for logistics intake.</p></div>
        <div class="grid gap-3">
            @forelse($activePickupBatches as $batch)
                @php($total = $batch->items->count())
                  @php($collectedAssignmentIds = collect($batch->collected_assignment_ids ?? []))
                  @php($collectedItems = $batch->items->filter(fn ($item) => $collectedAssignmentIds->contains($item->rider_assignment_id)))
                @php($collected = $collectedItems->count())
                @php($received = $collectedItems->filter(fn ($item) => $item->riderAssignment?->shipment?->current_status === 'AT_SORTING_CENTER')->count())
                @php($phase = match($batch->status) { 'LOADING' => 'Waiting for rider acceptance', 'READY' => 'Accepted Â· waiting for pickup start', 'EN_ROUTE_TO_HUB' => 'In transit to hub', default => 'In progress at seller' })
                <article class="border border-line bg-surface p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div><p class="text-xs font-bold uppercase tracking-wide text-primary">Pickup Batch #P-{{ $batch->id }}</p><h3 class="mt-1 font-bold text-ink">{{ $batch->seller?->business_name }}</h3><p class="mt-1 text-sm text-muted">{{ $batch->seller?->businessAddress?->formatted() }}</p><p class="mt-1 text-sm text-muted">Rider: {{ $batch->rider?->user?->name }}</p></div>
                        <span class="font-semibold text-ink">{{ $phase }}</span>
                    </div>
                    <p class="mt-4 text-sm font-semibold text-ink">{{ $total }} assigned Â· {{ $collected }}/{{ $total }} seller scans complete@if($collected > 0) Â· {{ $received }}/{{ $collected }} received at hub@endif</p>
                    @if($batch->status === 'EN_ROUTE_TO_HUB')
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">@foreach($collectedItems as $item)<div class="border border-line px-3 py-2 text-sm"><strong>{{ $item->riderAssignment?->shipment?->tracking_number }}</strong><span class="ml-2 text-xs {{ $item->riderAssignment?->shipment?->current_status === 'AT_SORTING_CENTER' ? 'text-green-700' : 'text-amber-800' }}">{{ $item->riderAssignment?->shipment?->current_status === 'AT_SORTING_CENTER' ? 'Received' : 'Expected' }}</span></div>@endforeach</div>
                    @endif
                </article>
            @empty
                <div class="border border-dashed border-line bg-surface p-6 text-sm text-muted">No active seller pickup batches.</div>
            @endforelse
        </div>
    </section>
</div>

@push('scripts')
<script>
document.querySelectorAll('[data-seller-pickup-form]').forEach((form) => {
    const rider = form.querySelector('[data-pickup-rider]');
    const checks = [...form.querySelectorAll('[data-pickup-parcel]')];
    const countLabel = form.querySelector('[data-pickup-selected]');
    const submit = form.querySelector('[data-pickup-submit]');
    const update = () => {
        const selected = checks.filter((check) => check.checked).length;
        const option = rider.options[rider.selectedIndex];
        const capacity = Math.min(10, Number(option?.dataset.capacity || 0));
        checks.forEach((check) => { check.disabled = !check.checked && selected >= capacity; });
        countLabel.textContent = String(selected);
        submit.disabled = selected < 1 || selected > capacity || !rider.value;
    };
    rider.addEventListener('change', update);
    checks.forEach((check) => check.addEventListener('change', update));
    update();
});
</script>
@endpush
@endsection
