@extends('layouts.logistics.app')

@section('title', 'Seller Pickup Queue - LIKHAE Logistics')

@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-bold uppercase text-primary">First-mile operations</p>
        <h1 class="mt-2 text-2xl font-bold text-ink">Seller Pickup Queue</h1>
        <p class="mt-2 text-sm text-muted">Requests appear here when this center is selected by the Seller or receives an offer. For uncovered locations, pickup and Buyer delivery hubs accept their legs separately; the pickup Rider is assigned after all required hubs accept. If a hub declines or times out, LIKHAE tries another eligible hub.</p>
    </header>
    @if(session('status'))<div class="border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif

    <form method="GET" action="{{ route('logistics.pickups') }}" class="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4 sm:flex-row">
        <input name="tracking" value="{{ $tracking }}" class="h-11 min-w-0 flex-1 rounded-lg border border-line bg-page-secondary px-3 text-sm text-ink outline-none transition placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/20" placeholder="Filter by tracking number" aria-label="Filter pickup queue by tracking number">
        <button class="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">Find Parcel</button>
    </form>
    @if(!$pickupSchemaReady)
        <section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Seller pickup batch assignment is unavailable until the additive local trip migrations are installed.</section>
    @endif

    <section class="space-y-4" data-seller-pickup-requests>
        <div><h2 class="text-lg font-bold text-ink">Seller Pickup Requests</h2><p class="mt-1 text-sm text-muted">Covered routes keep the normal pickup flow. For an uncovered Seller or Buyer location, the requested pickup and delivery hubs must each accept their own leg before the pickup Rider can be assigned.</p></div>
        @forelse($sellerPickupRequests as $pickupRequest)
            @php
                $requestParcels = $pickupRequest->memberships;
                $progress = $pickupRequest->progress_payload;
            @endphp
            <article class="rounded-xl border border-line bg-surface p-5 shadow-sm" data-pickup-request="{{ $pickupRequest->id }}">
                <header class="flex flex-col gap-3 border-b border-line pb-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase text-primary">Request #{{ $pickupRequest->id }}</p>
                        <h3 class="mt-1 font-bold text-ink">{{ $pickupRequest->sellerProfile?->business_name ?? 'Seller unavailable' }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ $pickupRequest->pickupAddress?->formatted() ?? 'Pickup address unavailable' }}</p>
                        @if($pickupRequest->preferredLogisticsCenter)
                            <p class="mt-1 text-sm text-muted">Seller's first-choice origin: <strong>{{ $pickupRequest->preferredLogisticsCenter->business_name }}</strong></p>
                        @endif
                        @if($pickupRequest->accepted_center_offer?->logisticsCenter)
                            <p class="mt-1 text-sm text-muted">Route accepted by origin: <strong>{{ $pickupRequest->accepted_center_offer->logisticsCenter->business_name }}</strong></p>
                        @endif
                        @if($pickupRequest->seller_selected_fallback)
                            @php
                                $visibleCenterOffer = $pickupRequest->current_pickup_center_offer ?? $pickupRequest->accepted_center_offer ?? $pickupRequest->current_center_offer;
                                $routeSnapshot = $visibleCenterOffer?->route_snapshot;
                                $shipmentRoutes = collect(data_get($routeSnapshot, 'shipment_routes', []));
                                $requiresInterhubTransfer = $shipmentRoutes->contains(fn (array $route): bool => (int) ($route['transfer_hops'] ?? 0) > 0);
                                $destinationHubs = $shipmentRoutes
                                    ->map(fn (array $route): array => [
                                        'name' => $route['destination_center_name'] ?? null,
                                        'address' => $route['destination_center_address'] ?? null,
                                    ])
                                    ->filter(fn (array $hub): bool => filled($hub['name']))
                                    ->unique(fn (array $hub): string => $hub['name'].'|'.$hub['address'])
                                    ->values();
                                if ($destinationHubs->isEmpty()) {
                                    $destinationHubs = $pickupRequest->memberships
                                        ->map(fn ($membership): ?array => $membership->preferredDestinationLogisticsCenter ? [
                                            'name' => $membership->preferredDestinationLogisticsCenter->business_name,
                                            'address' => $membership->preferredDestinationLogisticsCenter->address?->formatted(),
                                        ] : null)
                                        ->filter()
                                        ->unique(fn (array $hub): string => $hub['name'].'|'.$hub['address'])
                                        ->values();
                                }
                            @endphp
                            @foreach($destinationHubs as $destinationHub)
                                <p class="mt-1 text-sm text-muted">Buyer destination Logistics center: <strong>{{ $destinationHub['name'] }}</strong>{{ filled($destinationHub['address']) ? ' — '.$destinationHub['address'] : '' }}</p>
                            @endforeach
                            @if($visibleCenterOffer)
                                <p class="mt-1 text-sm font-semibold text-ink">{{ $requiresInterhubTransfer ? 'Inter-hub transfer required' : 'No inter-hub transfer required' }}</p>
                            @endif
                            <p class="mt-1 text-xs text-muted">Seller-selected uncovered-location route. Review the destination hub and available transfer/delivery capacity before accepting.</p>
                        @endif
                        <p class="mt-1 text-sm text-muted">{{ $progress['total'] }} {{ str('parcel')->plural($progress['total']) }} · {{ $progress['collected'] }} / {{ $progress['total'] }} collected</p>
                        @if($pickupRequest->current_center_offers->isNotEmpty())
                            @foreach($pickupRequest->current_center_offers as $legOffer)
                                <p class="mt-2 text-sm font-semibold text-ink">
                                    {{ $legOffer->leg_type === 'DELIVERY' ? 'Buyer delivery offer to' : 'Seller pickup offer to' }}
                                    <strong>{{ $legOffer->logisticsCenter?->business_name ?? 'this center' }}</strong>
                                    @if($legOffer->leg_type === 'DELIVERY' && data_get($legOffer->route_snapshot, 'delivery_address'))
                                        for {{ data_get($legOffer->route_snapshot, 'delivery_address') }}
                                    @endif
                                    · {{ str($legOffer->status)->replace('_', ' ')->title() }}
                                    · response due {{ $legOffer->expires_at?->timezone(config('app.timezone'))->format('M j, g:i A') }}
                                </p>
                            @endforeach
                            @php
                                $currentOffer = $pickupRequest->current_center_offer;
                            @endphp
                            <p class="mt-2 text-sm font-semibold text-ink">Offer #{{ $currentOffer->attempt_number }} · {{ $currentOffer->estimate_method === 'ROAD_ROUTE' ? 'estimated drive route' : 'estimated straight-line distance' }} {{ number_format(((int) $currentOffer->estimated_distance_meters) / 1000, 1) }} km · response due {{ $currentOffer->expires_at?->timezone(config('app.timezone'))->format('M j, g:i A') }}</p>
                            @if($currentOffer->estimated_travel_seconds)
                                <p class="mt-1 text-xs text-muted">Estimated travel time: {{ max(1, (int) ceil($currentOffer->estimated_travel_seconds / 60)) }} min.</p>
                            @endif
                        @elseif($pickupRequest->fallback_route_accepted)
                            <p class="mt-2 text-sm font-semibold text-emerald-700">Route accepted by Logistics. Assign a pickup Rider below.</p>
                        @elseif($pickupRequest->status === 'REQUESTED' && $pickupRequest->preferredLogisticsCenter)
                            <p class="mt-2 text-sm text-amber-800">Please accept or assign this request by {{ $pickupRequest->updated_at?->copy()->addMinutes(15)->timezone(config('app.timezone'))->format('M j, g:i A') }}. If it is still unaccepted, it returns to the Seller.</p>
                        @elseif(in_array($pickupRequest->status, ['MATCHING', 'PENDING'], true))
                            @if($automaticPickupMatchingReady && $pickupRequest->seller_selected_fallback && !$pickupRequest->has_active_center_offer)
                                <p class="mt-2 text-sm text-amber-800">{{ $pickupRequest->fallback_review_available ? 'Pending your decision: review the selected Buyer destination and accept or reject this request.' : 'This center has already responded. LIKHAE is checking the next eligible origin hub; the selected Buyer destination stays saved.' }}</p>
                            @elseif($automaticPickupMatchingReady && in_array($pickupRequest->status, ['MATCHING', 'PENDING'], true) && !$pickupRequest->has_active_center_offer)
                                <p class="mt-2 text-sm text-amber-800">This parcel is waiting for automatic Logistics route and pickup Rider capacity matching.</p>
                            @else
                                <p class="mt-2 text-sm text-amber-800">Pending follow-up: no complete authorized route or available Rider capacity is confirmed yet. Confirm it within 15 minutes or it will return to the Seller.</p>
                            @endif
                        @endif
                    </div>
                    <span class="border border-line px-3 py-2 text-xs font-bold uppercase text-ink">{{ str($pickupRequest->status)->replace('_', ' ')->title() }}</span>
                </header>
                @php
                    [$pickupRequestNextStep, $pickupRequestNextActor] = match ($pickupRequest->status) {
                        'REQUESTED' => $pickupRequest->fallback_route_accepted
                            ? ['Assign an available pickup Rider. The route has already been accepted by Logistics.', 'Logistics']
                            : ['Accept or assign this request within 15 minutes. If it remains unaccepted, it returns to the Seller.', 'Logistics'],
                        'MATCHING' => ['Check this request from the Seller; automatic matching checks route and Rider capacity at this center.', 'Logistics'],
                        'WAITING_FOR_RESPONSE' => $pickupRequest->seller_selected_fallback
                            ? ['Review each offer shown above. Pickup and Buyer delivery hubs respond to their own leg; a declined or unanswered offer is retried at another eligible hub.', 'Logistics']
                            : ($automaticPickupMatchingReady
                                ? ['Accept within 15 minutes to confirm the route and assign an eligible pickup Rider. If declined or unanswered, LIKHAE tries the next eligible origin center.', 'Logistics']
                                : ['Accept within 15 minutes to confirm the saved route and assign an eligible pickup Rider. Declining returns the parcels to the Seller for another center choice.', 'Logistics']),
                        'PENDING' => $automaticPickupMatchingReady
                            ? ($pickupRequest->seller_selected_fallback
                                ? ($pickupRequest->fallback_review_available
                                    ? ['Review the selected Buyer destination, then accept or reject this request. After accepting, assign a pickup Rider.', 'Logistics']
                                    : ['Wait while LIKHAE checks the next eligible origin hub. The selected Buyer destination stays saved.', 'Logistics'])
                                : ['Assign the nearest eligible local Rider for Seller pickup. Buyer delivery routing can continue after the parcel reaches the center.', 'Logistics'])
                            : ['Confirm route and Rider capacity within 15 minutes. Otherwise the request returns to the Seller.', 'Logistics'],
                        'ASSIGNED' => ['Collect the parcels and hand them to the authorized center.', 'Pickup rider'],
                        'COMPLETED' => ['Scan the collected parcels into center custody.', 'Logistics'],
                        'REJECTED' => ['Review the rejection reason; the Seller may submit a new request.', 'Seller'],
                        default => ['Check parcel history for the latest handoff update.', 'Logistics / pickup rider'],
                    };
                @endphp
                <x-shared.status-guidance :status="str($pickupRequest->status)->replace('_', ' ')->title()" :next="$pickupRequestNextStep" :actor="$pickupRequestNextActor" />
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
                @if($pickupRequest->current_center_offers->isNotEmpty() && $automaticPickupMatchingReady)
                    <div class="flex flex-wrap gap-3 border-t border-line pt-4">
                        @foreach($pickupRequest->current_center_offers as $currentOffer)
                        @php($deliveryLeg = $currentOffer->leg_type === 'DELIVERY')
                        <form method="POST" action="{{ route('logistics.pickups.requests.offers.accept', [$pickupRequest, $currentOffer->id]) }}" data-confirm-title="{{ $deliveryLeg ? 'Accept this Buyer delivery hub offer?' : ($pickupRequest->seller_selected_fallback ? 'Accept this Seller pickup hub offer?' : 'Accept this Seller pickup offer?') }}" data-confirm-message="{{ $deliveryLeg ? 'LIKHAE rechecks online Rider location, available parcel capacity, and any required inter-hub transfer. Accepting confirms this Buyer delivery hub for the parcels shown.' : ($pickupRequest->seller_selected_fallback ? 'Accepting confirms this Seller pickup hub. LIKHAE will separately ask each uncovered Buyer delivery hub to accept before a pickup Rider can be assigned.' : 'LIKHAE rechecks pickup Rider capacity, destination delivery capacity, and any required inter-hub vehicle capacity, then assigns the nearest eligible pickup Rider in this center.') }}" data-confirm-label="{{ $deliveryLeg ? 'Accept delivery offer' : ($pickupRequest->seller_selected_fallback ? 'Accept pickup offer' : 'Accept offer') }}">
                            @csrf
                            <button class="min-h-11 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">{{ $deliveryLeg ? 'Accept Buyer delivery offer' : ($pickupRequest->seller_selected_fallback ? 'Accept Seller pickup offer' : 'Accept offer and assign Rider') }}</button>
                        </form>
                        <form method="POST" action="{{ route('logistics.pickups.requests.offers.decline', [$pickupRequest, $currentOffer->id]) }}" data-confirm-title="{{ $deliveryLeg ? 'Decline this Buyer delivery hub offer?' : ($pickupRequest->seller_selected_fallback ? 'Decline this Seller pickup hub offer?' : 'Decline this center offer?') }}" data-confirm-message="{{ $deliveryLeg ? 'LIKHAE will try another eligible Buyer delivery hub and keep the accepted Seller pickup hub and other accepted delivery hubs.' : ($pickupRequest->seller_selected_fallback ? 'LIKHAE will try the next eligible Seller pickup hub and keep the selected Buyer delivery hub.' : 'LIKHAE will send the request to the next eligible origin center.') }}" data-confirm-label="{{ $deliveryLeg ? 'Decline delivery offer' : ($pickupRequest->seller_selected_fallback ? 'Decline pickup offer' : 'Decline offer') }}">
                            @csrf
                            <button class="min-h-11 rounded-lg border border-line px-5 py-3 text-sm font-semibold text-muted transition hover:bg-page-secondary focus:outline-none focus:ring-2 focus:ring-primary/20">{{ $deliveryLeg ? 'Decline Buyer delivery offer' : ($pickupRequest->seller_selected_fallback ? 'Decline Seller pickup offer' : 'Decline offer') }}</button>
                        </form>
                        @endforeach
                    </div>
                @elseif($pickupRequest->fallback_review_available && $automaticPickupMatchingReady)
                    <div class="flex flex-wrap gap-3 border-t border-line pt-4">
                        <form method="POST" action="{{ route('logistics.pickups.requests.fallback.accept', $pickupRequest) }}" data-confirm-title="Accept this Seller pickup hub request?" data-confirm-message="LIKHAE will recheck the pickup route. After this pickup hub accepts, LIKHAE will separately request acceptance from each uncovered Buyer delivery hub. The pickup Rider can be assigned after all required hubs accept." data-confirm-label="Accept pickup request">
                            @csrf
                            <button class="min-h-11 bg-primary px-5 py-3 text-sm font-semibold text-white">Accept Seller pickup request</button>
                        </form>
                        <form method="POST" action="{{ route('logistics.pickups.requests.fallback.reject', $pickupRequest) }}" data-confirm-title="Reject this request for this hub?" data-confirm-message="LIKHAE will try the next eligible origin hub and keep the selected Buyer destination hub." data-confirm-label="Reject request">
                            @csrf
                            <button class="min-h-11 border border-line px-5 py-3 text-sm font-semibold text-muted">Reject request</button>
                        </form>
                    </div>
                @elseif(in_array($pickupRequest->status, ['REQUESTED', 'PENDING'], true)
                    && $pickupSchemaReady
                    && (! $pickupRequest->seller_selected_fallback || $pickupRequest->fallback_route_accepted)
                    && ! $pickupRequest->has_active_center_offer)
                    <div class="grid gap-3 border-t border-line pt-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        <form method="POST" action="{{ route('logistics.pickups.requests.assign', $pickupRequest) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            @csrf
                            <label class="grid min-w-0 flex-1 gap-1.5 text-xs font-semibold text-muted">Online idle rider · nearest first
                                <select name="rider_profile_id" required class="min-h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:cursor-not-allowed disabled:opacity-60" @disabled($pickupRequest->eligible_riders->isEmpty())>
                                    <option value="">{{ $pickupRequest->eligible_riders->isEmpty() ? 'No idle online rider with a recent location' : 'Select an idle local Rider' }}</option>
                                    @foreach($pickupRequest->eligible_riders as $rider)
                                        <option value="{{ $rider->id }}">{{ $rider->user?->name }} · {{ number_format((float) $rider->seller_distance_km, 1) }} km · {{ str($rider->vehicle_type)->headline() }} · up to {{ min($requestParcels->count(), \App\Support\LocalTripCapacityRules::remainingCapacity((int) $rider->approved_parcel_capacity, (int) $rider->active_assignments_count)) }} of {{ $requestParcels->count() }} parcel(s)</option>
                                    @endforeach
                                </select>
                                <span class="text-[11px] font-normal">Excess parcels stay in a new request.</span>
                            </label>
                            <button class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30 disabled:cursor-not-allowed disabled:opacity-50" @disabled($pickupRequest->eligible_riders->isEmpty())>Assign request to Rider</button>
                        </form>
                        @unless($pickupRequest->fallback_route_accepted)
                            <form method="POST" action="{{ route('logistics.pickups.requests.reject', $pickupRequest) }}" class="sm:justify-self-end" data-confirm-title="Reject this pickup request?" data-confirm-message="The parcel membership history will remain, and the parcels can be requested again." data-confirm-label="Reject request">
                                @csrf
                                <button class="inline-flex min-h-11 items-center justify-center rounded-lg border border-line bg-surface px-4 py-3 text-sm font-semibold text-ink transition hover:bg-page-secondary focus:outline-none focus:ring-2 focus:ring-primary/20">Reject request</button>
                            </form>
                        @endunless
                    </div>
                @elseif($pickupRequest->rider_local_trip_id)
                    <p class="border-t border-line pt-4 text-sm text-muted">Assigned Rider: <strong class="text-ink">{{ $pickupRequest->riderLocalTrip?->rider?->user?->name ?? 'Unavailable' }}</strong> · <a class="font-semibold text-primary underline" href="{{ route('logistics.local-trips.show', $pickupRequest->rider_local_trip_id) }}">Pickup Batch #P-{{ $pickupRequest->rider_local_trip_id }}</a></p>
                @endif
            </article>
        @empty
            <div class="border border-line bg-surface p-5 text-sm text-muted">No Seller pickup requests yet.</div>
        @endforelse
    </section>

    <section class="space-y-4">
        <div><h2 class="text-lg font-bold text-ink">Waiting for Assignment</h2><p class="mt-1 text-sm text-muted">Other ready-for-pickup parcels not attached to a Seller request. They follow the same Rider assignment and pickup flow, grouped by Seller and pickup address.</p></div>
        @forelse($sellerPickupQueue as $group)
            @php($seller = $group['seller'])
            @php($shipments = $group['shipments'])
            @php($eligibleRiders = $group['eligible_riders'])
            @php($address = $seller?->businessAddress)
            <details open class="border border-line bg-surface" data-seller-pickup-group>
                <summary class="cursor-pointer list-none border-b border-line px-5 py-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div><h3 class="font-bold text-ink">{{ $seller?->business_name ?? 'Seller unavailable' }}</h3><p class="mt-1 text-sm text-muted">{{ $address?->formatted() ?? 'Pickup address unavailable' }}</p></div>
                        <span class="text-sm font-semibold text-ink">{{ $shipments->count() }} ready {{ str('parcel')->plural($shipments->count()) }} · {{ $center->business_name }}</span>
                    </div>
                </summary>
                <div class="p-5">
                    @if($pickupSchemaReady)
                    <form method="POST" action="{{ route('logistics.pickups.assignments.store') }}" data-seller-pickup-form class="space-y-4" data-confirm-title="Assign selected pickups?" data-confirm-message="Assign the selected parcels to the chosen rider. The rider will be responsible for collecting them from the seller." data-confirm-label="Assign parcels">
                        @csrf
                        <div class="grid gap-2">
                            @foreach($shipments as $shipment)
                                @php($pickupRequest = $group['pickup_requests']->get($shipment->id))
                                <label class="flex cursor-pointer items-start gap-3 border border-line p-3 hover:bg-page-secondary">
                                    <input type="checkbox" name="shipment_ids[]" value="{{ $shipment->id }}" class="mt-1" data-pickup-parcel>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold text-primary">{{ $shipment->tracking_number }}</span>
                                        @if(filled($pickupRequest?->notes))<span class="mt-1 block text-xs text-muted">Seller handover: {{ str($pickupRequest->notes)->after(':') }}</span>@endif
                                        <span class="mt-1 block text-xs text-muted">{{ $shipment->sellerOrder?->seller_order_number }} · {{ ($shipment->orderItem?->product_name ?? $shipment->sellerOrder?->items?->pluck('product_name')->filter()->join(', ')) }}</span>
                                    </span>
                                    <span class="text-xs font-semibold text-muted">READY_FOR_PICKUP</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="grid gap-3 border-t border-line pt-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <label class="grid gap-1 text-xs font-semibold text-muted">Available idle local rider · nearest first
                                <select name="rider_profile_id" required data-pickup-rider class="min-h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20">
                                    <option value="">{{ $eligibleRiders->isEmpty() ? 'No nearby online rider with a recent location and no active job' : 'Select an idle motorcycle or tricycle rider' }}</option>
                                    @foreach($eligibleRiders as $rider)
                                        <option value="{{ $rider->id }}" data-capacity="{{ min(10, \App\Support\LocalTripCapacityRules::remainingCapacity((int) $rider->approved_parcel_capacity, (int) $rider->active_assignments_count)) }}">{{ $rider->user?->name }} · {{ number_format((float) $rider->seller_distance_km, 1) }} km · {{ str($rider->vehicle_type)->headline() }} · {{ min(10, \App\Support\LocalTripCapacityRules::remainingCapacity((int) $rider->approved_parcel_capacity, (int) $rider->active_assignments_count)) }} parcel(s) available</option>
                                    @endforeach
                                </select>
                            </label>
                            <button type="submit" disabled data-pickup-submit class="min-h-11 bg-primary px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Assign Selected Parcels</button>
                        </div>
                        <p class="text-xs text-muted"><span data-pickup-selected>0</span> selected · up to 10 parcels per batch. The selected rider must have no other active assignments; any parcels beyond the rider's approved batch capacity stay waiting.</p>
                    </form>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($shipments as $shipment)
                            <form method="POST" action="{{ route('logistics.pickups.auto-assign', $shipment) }}" data-confirm-title="Offer pickup to nearest idle rider?" data-confirm-message="Offer {{ $shipment->tracking_number }} to the nearest online local rider with a recent location and no active assignment. They have 15 minutes to accept; an expired offer moves to the next idle rider." data-confirm-label="Offer pickup">@csrf<button class="border border-primary px-3 py-2 text-xs font-semibold text-primary">Auto-assign {{ $shipment->tracking_number }}</button></form>
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
                @php($phase = match($batch->status) { 'LOADING' => 'Waiting for rider acceptance', 'READY' => 'Accepted · waiting for pickup start', 'EN_ROUTE_TO_HUB' => 'In transit to hub', default => 'In progress at seller' })
                <article class="border border-line bg-surface p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div><p class="text-xs font-bold uppercase tracking-wide text-primary">Pickup Batch #P-{{ $batch->id }}</p><h3 class="mt-1 font-bold text-ink">{{ $batch->seller?->business_name }}</h3><p class="mt-1 text-sm text-muted">{{ $batch->sellerPickupRequest?->pickupAddress?->formatted() ?? $batch->seller?->businessAddress?->formatted() }}</p><p class="mt-1 text-sm text-muted">Rider: {{ $batch->rider?->user?->name }}</p></div>
                        <span class="font-semibold text-ink">{{ $phase }}</span>
                    </div>
                    <p class="mt-4 text-sm font-semibold text-ink">{{ $total }} assigned · {{ $collected }}/{{ $total }} seller scans complete @if($collected > 0) · {{ $received }}/{{ $collected }} received at hub @endif</p>
                    @if($batch->reassignable_riders->isNotEmpty())
                        <form method="POST" action="{{ route('logistics.pickups.batches.reassign', $batch) }}" class="mt-4 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:items-end" data-confirm-title="Reassign pickup batch?" data-confirm-message="Every parcel in this batch is still uncollected. The current rider's jobs will be cancelled and the selected online rider will need to accept the batch." data-confirm-label="Reassign batch">
                            @csrf
                            @method('PATCH')
                            <label class="grid flex-1 gap-1 text-xs font-semibold text-muted">Reassign to an idle online rider
                                <select name="rider_profile_id" required class="min-h-10 rounded-lg border border-line bg-surface px-3 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20">
                                    <option value="">Select another rider</option>
                                    @foreach($batch->reassignable_riders as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->user?->name }} · idle · capacity {{ $candidate->approved_parcel_capacity }} parcels</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="min-h-10 border border-primary px-4 py-2 text-sm font-semibold text-primary">Reassign batch</button>
                        </form>
                    @endif
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
        checks.forEach((check) => { check.disabled = !check.checked && selected >= 10; });
        countLabel.textContent = String(selected);
        submit.disabled = selected < 1 || selected > 10 || !rider.value;
    };
    rider.addEventListener('change', update);
    checks.forEach((check) => check.addEventListener('change', update));
    update();
});
</script>
@endpush
@endsection
