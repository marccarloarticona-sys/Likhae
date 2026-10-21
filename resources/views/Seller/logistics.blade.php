@extends('layouts.seller')
@section('title','Shipment Monitoring')
@section('active','logistics')
@section('subtitle','Track parcel pickup, sorting, assignment, and delivery from live shipment records.')
@section('content')
<div class="sl-page{{ $trackingOrder ? ' sl-tracking-page' : '' }}">
    @if(session('status'))<div class="sl-card mb-4 border border-green-200 bg-green-50 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-card mb-4 border border-red-200 bg-red-50 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    @if($trackingOrder)
        @php
            $trackingShipments = $trackingOrder->shipments;
            $mapLocationList = collect($mapMarkers)->unique(fn ($marker): string => implode(':', [
                data_get($marker, 'kind', 'destination'),
                data_get($marker, 'latitude', ''),
                data_get($marker, 'longitude', ''),
            ]))->values();
            $mapHasLiveRider = collect($mapMarkers)->contains(fn ($marker): bool => data_get($marker, 'kind') === 'rider' && data_get($marker, 'location_state') === 'live');
            $terminalShipmentStatuses = ['DELIVERED', 'COMPLETED', 'RETURNED', 'CANCELLED', 'LOST', 'DAMAGED'];
            $mapTrackingInProgress = $trackingShipments->contains(fn ($shipment): bool => ! in_array($shipment->current_status, $terminalShipmentStatuses, true));
            $mapStatusLabel = $mapHasLiveRider
                ? 'Rider location live'
                : ($trackingShipments->contains(fn ($shipment): bool => $shipment->current_status === 'OUT_FOR_DELIVERY')
                    ? 'Out for delivery'
                    : ($trackingShipments->isNotEmpty() && $trackingShipments->every(fn ($shipment): bool => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true))
                        ? 'Delivered'
                        : 'Route overview'));
            $timelineLabels = [
                'PLACED' => 'Order placed',
                'CONFIRMED' => 'Seller accepted the order',
                'PREPARING' => 'Parcel preparation started',
                'READY_FOR_PICKUP' => 'Ready for pickup',
                'PICKUP_ASSIGNED' => 'Pickup courier assigned',
                'PICKED_UP' => 'Collected from seller',
                'AT_SORTING_CENTER' => 'Arrived at sorting center',
                'SORTED' => 'Sorted for delivery',
                'ASSIGNED_TO_RIDER' => 'Delivery courier assigned',
                'OUT_FOR_DELIVERY' => 'Out for delivery',
                'DELIVERY_FAILED' => 'Delivery attempt unsuccessful',
                'DELIVERED' => 'Delivered',
                'COMPLETED' => 'Order completed',
                'RETURNED' => 'Returned to seller',
                'CANCELLED' => 'Shipment cancelled',
                'LOST' => 'Shipment marked lost',
                'DAMAGED' => 'Shipment marked damaged',
            ];
        @endphp
        <div class="sl-page-toolbar sl-tracking-order-heading">
            <div class="sl-tracking-order-heading__copy">
                <span class="sl-eyebrow">Fulfillment tracking</span>
                <h2>{{ $trackingOrder->seller_order_number }}</h2>
                <p>Follow each parcel's status history and route.</p>
            </div>
            <div class="sl-tracking-order-heading__actions">
                <span class="sl-tracking-order-heading__count">{{ $trackingShipments->count() }} {{ $trackingShipments->count() === 1 ? 'parcel' : 'parcels' }}</span>
                <a href="{{ route('seller.logistics') }}" class="sl-btn sl-btn-soft"><span aria-hidden="true">&larr;</span> Shipment monitoring</a>
            </div>
        </div>

        <section class="sl-tracking-map-panel" aria-labelledby="seller-tracking-map-title">
            <header class="sl-tracking-map-panel__header">
                <div><span class="sl-eyebrow">ROUTE OVERVIEW</span><h2 id="seller-tracking-map-title">Follow this delivery</h2><p>Saved pickup, sorting center, and delivery points are shown when available.</p></div>
                <span class="sl-status {{ $mapHasLiveRider ? 'is-success' : 'is-info' }}"><i aria-hidden="true"></i>{{ $mapStatusLabel }}</span>
            </header>
            <div class="sl-tracking-map-panel__layout">
                <div class="sl-tracking-map-panel__map">
                    <x-shared.mapbox :markers="$mapMarkers" title="Order route" height="420px" :allow-user-location="false" :live-tracking="$mapTrackingInProgress" :order-route="true" projection="mercator" class="sl-tracking-mapbox" />
                </div>
                <aside class="sl-tracking-map-panel__locations" aria-label="Locations shown on the map">
                    <div class="sl-tracking-map-panel__locations-head"><div><span class="sl-eyebrow">ROUTE POINTS</span><h3>Locations</h3></div><span>{{ $mapLocationList->count() }} {{ $mapLocationList->count() === 1 ? 'pin' : 'pins' }}</span></div>
                    @if($mapLocationList->isNotEmpty())
                        <ol class="sl-tracking-map-points">
                            @foreach($mapLocationList as $mapMarker)
                                @php
                                    $markerKind = data_get($mapMarker, 'kind', 'destination');
                                    $markerTitle = match ($markerKind) {
                                        'seller' => 'Seller pickup',
                                        'center' => 'Sorting center',
                                        'rider' => data_get($mapMarker, 'assignment_type') === 'PICKUP' ? 'Pickup courier' : 'Delivery courier',
                                        'destination' => 'Buyer destination',
                                        default => data_get($mapMarker, 'label', 'Route location'),
                                    };
                                    $markerDetail = $markerKind === 'rider'
                                        ? (data_get($mapMarker, 'location_pending') ? 'Waiting for courier GPS' : (data_get($mapMarker, 'location_state') === 'live' ? 'Live location · '.data_get($mapMarker, 'popup', 'Recently updated') : 'Last known location · '.data_get($mapMarker, 'popup', '')))
                                        : (data_get($mapMarker, 'popup') ?: 'Saved map location');
                                @endphp
                                <li class="sl-tracking-map-point sl-tracking-map-point--{{ $markerKind }}">
                                    <span class="sl-tracking-map-point__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.2"/></svg></span>
                                    <span><strong>{{ $markerTitle }}</strong><small>{{ $markerDetail }}</small></span>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <div class="sl-tracking-map-empty"><strong>No route pins yet</strong><p>Saved pickup and delivery coordinates will appear here when available.</p></div>
                    @endif
                    <p class="sl-tracking-map-note">Courier GPS appears when an active assignment is sharing a location.</p>
                </aside>
            </div>
        </section>

        <section class="sl-card sl-tracking-parcels" aria-labelledby="seller-tracking-timeline-title">
            <header class="sl-card-head"><div><span class="sl-eyebrow">Shipment history</span><h2 id="seller-tracking-timeline-title">Parcel timelines</h2><p>{{ $trackingShipments->count() }} {{ $trackingShipments->count() === 1 ? 'parcel' : 'parcels' }} linked to this order.</p></div></header>
            @forelse($trackingShipments as $shipment)
                @php
                    $timelineRows = collect();
                    foreach ($shipment->events as $event) {
                        $status = strtoupper((string) $event->status);
                        $isCancellationEvent = $status === 'CANCELLED'
                            || str_starts_with((string) $event->notes, 'BUYER_ORDER_CANCELLED:')
                            || str_starts_with((string) $event->notes, 'SELLER_ORDER_CANCELLED:');
                        $label = $isCancellationEvent ? 'Shipment cancelled' : ($timelineLabels[$status] ?? null);
                        if ($status === 'COMPLETED') {
                            $label = str_starts_with((string) $event->notes, 'Order automatically confirmed received after 3 days')
                                ? 'Receipt automatically confirmed'
                                : 'Buyer confirmed receipt';
                        }
                        if (! $label) continue;
                        $previousRow = $timelineRows->last();
                        if ($status !== 'DELIVERY_FAILED' && $previousRow && $previousRow['status'] === $status && $previousRow['label'] === $label) continue;
                        $timelineRows->push(['status' => $status, 'label' => $label, 'event' => $event]);
                    }
                    $currentStatus = strtoupper((string) $shipment->current_status);
                    $lastTimelineRow = $timelineRows->last();
                    if (! $lastTimelineRow || $lastTimelineRow['status'] !== $currentStatus) {
                        $timelineRows->push([
                            'status' => $currentStatus,
                            'label' => $timelineLabels[$currentStatus] ?? str($currentStatus)->replace('_', ' ')->title()->toString(),
                            'event' => null,
                        ]);
                    }
                    $currentTimelineIndex = $timelineRows->keys()->last();
                    $shipmentTone = match ($currentStatus) {
                        'DELIVERED', 'COMPLETED' => 'is-success',
                        'CANCELLED', 'RETURNED', 'DELIVERY_FAILED', 'LOST', 'DAMAGED' => 'is-danger',
                        'PREPARING', 'READY_FOR_PICKUP' => 'is-warning',
                        default => 'is-info',
                    };
                @endphp
                <article class="sl-tracking-parcel">
                    <header class="sl-tracking-parcel__head">
                        <div><span class="sl-eyebrow">Parcel {{ $loop->iteration }} of {{ $trackingShipments->count() }}</span><h3>{{ $shipment->orderItem?->product_name ?: 'Shipment' }}</h3><p>Tracking <strong>{{ $shipment->tracking_number }}</strong></p></div>
                        <span class="sl-status {{ $shipmentTone }}">{{ str($currentStatus)->replace('_', ' ')->title() }}</span>
                    </header>
                    <ol class="sl-tracking-timeline" aria-label="Tracking timeline for {{ $shipment->tracking_number }}">
                        @foreach($timelineRows as $timelineIndex => $timelineRow)
                            @php
                                $event = $timelineRow['event'];
                                $isCurrent = $timelineIndex === $currentTimelineIndex;
                                $isException = in_array($timelineRow['status'], ['DELIVERY_FAILED', 'RETURNED', 'CANCELLED', 'LOST', 'DAMAGED'], true);
                                $eventContext = $event?->logisticsCenter?->business_name
                                    ?? $event?->riderAssignment?->riderProfile?->user?->name
                                    ?? $event?->actor?->name;
                            @endphp
                            <li class="sl-tracking-timeline__step {{ $isCurrent ? 'is-current' : ($event ? 'is-complete' : '') }} {{ $isException ? 'is-exception' : '' }}" @if($isCurrent) aria-current="step" @endif>
                                <span class="sl-tracking-timeline__node" aria-hidden="true">{{ $event ? '✓' : '' }}</span>
                                <div class="sl-tracking-timeline__copy">
                                    <div class="sl-tracking-timeline__title"><strong>{{ $timelineRow['label'] }}</strong>@if($isCurrent)<span>Current</span>@endif</div>
                                    @if($event?->occurred_at)<time datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</time>@elseif($isCurrent)<small>Current shipment status</small>@endif
                                    @if($eventContext)<small>{{ $eventContext }}</small>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </article>
            @empty
                <p class="sl-tracking-empty">No parcels are linked to this order yet.</p>
            @endforelse
        </section>
    @else
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Fulfillment</span><h2>Shipment Monitoring</h2><p>Every status below comes from the connected shipment record.</p></div></div>
    @if(!$pickupRequestSchemaReady)
        <section class="sl-card border border-amber-200 bg-amber-50 text-sm text-amber-900">Seller pickup requests are unavailable until their database schema is installed.</section>
    @else
        <section class="sl-card" data-seller-pickup-request-form>
            <header class="sl-card-head"><div><span class="sl-eyebrow">Seller Pickup</span><h2>Request one pickup for 1–10 ready parcels</h2><p>Parcels may come from different SellerOrders. Each keeps its own tracking number, waybill, and Rider scan state.</p></div></header>
            @if(!$pickupAddress)
                <p class="mt-3 text-sm text-amber-800">Add a registered business pickup address before requesting a Rider.</p>
            @elseif($readyPickupParcels->isEmpty())
                <p class="mt-3 text-sm text-muted">No eligible parcels are ready for a new pickup request.</p>
            @else
                <p class="mt-2 text-sm text-muted">Pickup address: <strong class="text-ink">{{ $pickupAddress->formatted() }}</strong></p>
                <form method="POST" action="{{ route('seller.logistics.pickup-requests.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="pickup_address_id" value="{{ $pickupAddress->id }}">
                    <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Select</th><th>Tracking / product</th><th>SellerOrder</th><th>Waybill</th></tr></thead><tbody>
                        @foreach($readyPickupParcels as $parcel)
                            <tr>
                                <td><input type="checkbox" name="shipment_ids[]" value="{{ $parcel->id }}" data-pickup-request-parcel aria-label="Select {{ $parcel->tracking_number }}"><input type="hidden" name="seller_order_ids[{{ $parcel->id }}]" value="{{ $parcel->seller_order_id }}"></td>
                                <td><strong>{{ $parcel->tracking_number }}</strong><small class="block">{{ $parcel->orderItem?->product_name ?? 'Parcel' }}</small></td>
                                <td>{{ $parcel->sellerOrder?->seller_order_number }}</td>
                                <td>
                                    @if($parcel->order_item_id)
                                        <a class="text-sm font-semibold text-primary underline" target="_blank" rel="noopener" href="{{ route('seller.orders.waybill.item', [$parcel->seller_order_id, $parcel->order_item_id]) }}">Open waybill</a>
                                    @else
                                        <a class="text-sm font-semibold text-primary underline" target="_blank" rel="noopener" href="{{ route('seller.orders.waybill', $parcel->seller_order_id) }}">Open waybill</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody></table></div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-muted"><strong data-pickup-request-count>0</strong> selected · choose 1 to 10 parcels.</p>
                        <button type="submit" data-pickup-request-submit disabled class="sl-btn sl-btn-primary disabled:cursor-not-allowed disabled:opacity-50">Request pickup</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="space-y-4">
            <div><h2 class="text-xl font-semibold text-ink">Your pickup requests</h2><p class="mt-1 text-sm text-muted">Collected progress is counted from successful per-parcel Rider scans.</p></div>
            @forelse($pickupRequests as $pickupRequest)
                @include('Seller.partials.pickup-request-progress', ['pickupRequest' => $pickupRequest])
            @empty
                <section class="sl-card text-sm text-muted">No pickup requests yet.</section>
            @endforelse
        </section>
        <script>
            (() => {
                const form = document.querySelector('[data-seller-pickup-request-form] form');
                if (!form) return;
                const checks = [...form.querySelectorAll('[data-pickup-request-parcel]')];
                const count = form.querySelector('[data-pickup-request-count]');
                const submit = form.querySelector('[data-pickup-request-submit]');
                form.addEventListener('change', (event) => {
                    if (!event.target.matches('[data-pickup-request-parcel]')) return;
                    const checked = checks.filter((checkbox) => checkbox.checked);
                    if (checked.length > 10) event.target.checked = false;
                    const total = checks.filter((checkbox) => checkbox.checked).length;
                    count.textContent = String(total);
                    submit.disabled = total < 1 || total > 10;
                });
            })();
        </script>
    @endif
    <section class="sl-card"><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Tracking</th><th>Order</th><th>Destination</th><th>Rider</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
        @forelse($orders as $order)
            @foreach($order->shipments as $shipment)
                @php($assignment = $shipment->riderAssignments?->whereIn('status',['ASSIGNED','ACCEPTED','IN_PROGRESS'])->sortByDesc('id')->first())
                <tr id="shipment-{{ $shipment->id }}"><td><strong>{{ $shipment->tracking_number }}</strong><small class="block">{{ $shipment->orderItem?->product_name }}</small></td><td>{{ $order->seller_order_number }}</td><td>{{ $order->order?->address?->formatted() }}</td><td>{{ $assignment?->riderProfile?->user?->name ?? 'Awaiting assignment' }}</td><td><span class="sl-status is-info">{{ str($shipment->current_status)->headline() }}</span></td><td>{{ $shipment->updated_at?->diffForHumans() }}</td><td><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.logistics', ['view' => 'tracking', 'order' => $order->seller_order_number]) }}">Track order</a></td></tr>
            @endforeach
        @empty<tr><td colspan="7">No shipments yet.</td></tr>@endforelse
    </tbody></table></div></section>{{ $orders->links() }}
    @endif
</div>
@endsection
