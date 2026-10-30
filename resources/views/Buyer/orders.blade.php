@extends('layouts.buyer.app')
@section('title', 'My Orders')
@section('active', 'orders')

@section('content')
<style>
    @foreach(range(1, 5) as $selectedCount)
        fieldset:has(input[name="rating"][value="{{ $selectedCount }}"]:checked) label:nth-child(-n+{{ $selectedCount }}) > span,
        fieldset:has(input[name="rider_rating"][value="{{ $selectedCount }}"]:checked) label:nth-child(-n+{{ $selectedCount }}) > span {
            background-color: #facc15 !important;
            border-color: #eab308 !important;
            color: #713f12 !important;
        }
    @endforeach
    .lk-order-page{width:min(100%,1240px)!important}.lk-order-page .lk-page-title{margin-bottom:20px}.lk-order-page section.rounded-2xl{margin-top:0!important;padding:24px!important;border:1px solid #eadbce!important;border-radius:20px!important;background:#fffdf9!important;box-shadow:0 10px 28px rgba(86,28,23,.06)!important}.lk-order-page section.rounded-2xl>h2{margin:0 0 18px!important;color:#321d17!important;font-family:"Instrument Serif",Georgia,serif;font-size:27px!important;font-weight:400!important}.lk-order-page section.rounded-2xl+.mt-5,.lk-order-page .mt-5+section.rounded-2xl{margin-top:20px!important}.lk-order-page section.rounded-2xl .md\:grid-cols-2>div{padding:18px;border:1px solid #f0e4da;border-radius:14px;background:#fcf7f2}.lk-order-page section.rounded-2xl .divide-y>div{min-width:0;padding:18px 0!important}.lk-order-page section.rounded-2xl .divide-y>div>div{min-width:0}.lk-order-page dl.sm\:grid-cols-2{gap:0!important;overflow:hidden;border:1px solid #f0e4da;border-radius:14px}.lk-order-page dl.sm\:grid-cols-2>div{min-width:0;padding:16px 18px;background:#fcf8f4;border-bottom:1px solid #f0e4da}.lk-order-page dl.sm\:grid-cols-2 dt{margin-bottom:5px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}.lk-order-page dl.sm\:grid-cols-2 dd{margin:0;overflow-wrap:anywhere;color:#37231d}.lk-order-page #reviews form{padding:20px!important;border-color:#eadbce!important;border-radius:16px!important;background:#fcf8f4}.lk-order-page #reviews fieldset{min-width:0;padding:0;border:0}.lk-order-page #reviews textarea{width:100%;resize:vertical;background:#fff}.lk-order-page #reviews input[type=file]{max-width:100%}.lk-order-status-tabs{display:flex;gap:4px;overflow-x:auto;padding:5px;border:1px solid #eadbce;border-radius:16px;background:#fcf7f2;box-shadow:0 8px 20px rgba(86,28,23,.05);scrollbar-width:thin}.lk-order-status-tab{display:inline-flex;min-height:38px;flex:0 0 auto;align-items:center;gap:7px;padding:0 12px;border:1px solid transparent;border-radius:11px;color:#806356;font-size:12px;font-weight:700;text-decoration:none;transition:background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease,transform .16s ease}.lk-order-status-tab:hover{border-color:#eadbce;background:#fffdf9;color:#561c17;box-shadow:0 4px 12px rgba(86,28,23,.07);transform:translateY(-1px)}.lk-order-status-tab:focus-visible{outline:3px solid rgba(122,42,34,.22);outline-offset:2px}.lk-order-status-tab.is-active{border-color:#561c17;background:#561c17;color:#fff;box-shadow:0 5px 14px rgba(86,28,23,.22)}.lk-order-status-count{display:inline-grid;min-width:21px;height:21px;place-items:center;padding:0 5px;border-radius:999px;background:#efe3d7;color:#642920;font-size:10px;font-weight:800;font-variant-numeric:tabular-nums}.lk-order-status-tab.is-active .lk-order-status-count{background:rgba(255,255,255,.18);color:#fff}@media(min-width:640px){.lk-order-status-tab:first-child{padding-left:14px}.lk-order-status-tab:last-child{padding-right:14px}}@media(min-width:1024px){.lk-order-status-tabs{justify-content:space-between}.lk-order-status-tab{justify-content:center}}@media(max-width:639px){.lk-order-page section.rounded-2xl{padding:18px!important}.lk-order-page .lk-page-title{align-items:flex-start!important;flex-direction:column!important}.lk-order-page .flex.items-center.justify-between{align-items:flex-start;gap:10px}.lk-order-page .divide-y .flex.items-center.justify-between{align-items:flex-start;flex-direction:column}.lk-order-page dl.sm\:grid-cols-2{grid-template-columns:1fr!important}.lk-order-page dl.sm\:grid-cols-2>div:last-child{border-bottom:0}.lk-order-status-tabs{margin-inline:-2px;border-radius:14px}.lk-order-status-tab{min-height:36px;padding-inline:11px;font-size:11px}}
.lk-shipment-timeline{display:grid;gap:0}.lk-shipment-step{position:relative;display:grid;grid-template-columns:minmax(88px,112px) 18px minmax(0,1fr);gap:10px;min-height:54px}.lk-shipment-step:not(:last-child)::before{position:absolute;top:19px;bottom:-1px;left:calc(min(112px, max(88px, 26%)) + 9px);width:1px;background:#eadbce;content:""}.lk-shipment-step__date{padding-top:1px;color:#806356;font-size:11px;line-height:1.4}.lk-shipment-step__dot{position:relative;z-index:1;display:grid;width:18px;height:18px;place-items:center;border:2px solid #d8c8ba;border-radius:999px;background:#fffdf9;color:transparent;font-size:10px}.lk-shipment-step.is-complete .lk-shipment-step__dot{border-color:#561c17;background:#561c17;color:#fff}.lk-shipment-step.is-current .lk-shipment-step__dot{border:4px solid #561c17;background:#fff}.lk-shipment-step__label{min-width:0;padding:0 0 14px;color:#806356;font-size:13px;line-height:1.4;overflow-wrap:anywhere}.lk-shipment-step.is-complete .lk-shipment-step__label{color:#37231d}.lk-shipment-step.is-current .lk-shipment-step__label{color:#561c17;font-weight:800}.lk-proof-image{display:block;width:100%;max-height:260px;aspect-ratio:16/10;border-radius:12px;background:#f4ece4;object-fit:cover}.lk-order-page :is(a,button):focus-visible{outline:3px solid rgba(122,42,34,.3);outline-offset:3px}@media(max-width:639px){.lk-shipment-step{grid-template-columns:minmax(72px,88px) 18px minmax(0,1fr);gap:8px;min-height:50px}.lk-shipment-step:not(:last-child)::before{left:calc(min(88px, max(72px, 25%)) + 8px)}.lk-shipment-step__date{font-size:10px}.lk-shipment-step__label{font-size:12px}.lk-proof-image{max-height:220px}}

    .lk-order-page .lk-order-contact-link.lk-btn-light{min-height:32px;padding:6px 8px!important;border:0!important;background:transparent!important;box-shadow:none!important;color:#561c17!important}.lk-order-page .lk-order-contact-link.lk-btn-light:hover{background:transparent!important;border-color:transparent!important;color:#3e130f!important;text-decoration:underline}
</style>
@php
    $mode = $mode ?? 'index';
    $orderCollection = isset($orders) && method_exists($orders, 'getCollection') ? $orders->getCollection() : collect($orders ?? []);
    $filter = $filter ?? strtoupper(str_replace([' ', '-', '/'], '_', (string) request('status', 'ALL')));
    $returnRefundReady = $returnRefundReady ?? false;
    $orderActionStates = $orderActionStates ?? collect();
    $orderTabKeys = $orderTabKeys ?? collect();
    $sellerOrderDisplayStatuses = $sellerOrderDisplayStatuses ?? collect();
    $shipmentTabKeys = $shipmentTabKeys ?? collect();
    $selectedOrderActionState = $selectedOrderActionState ?? [];
    $visibleOrders = $orderCollection;
    $liveOrders = $mode === 'show' && isset($selectedOrder) ? collect([$selectedOrder]) : $orderCollection;
    $liveOrderIds = $liveOrders->pluck('id')->map(fn ($id) => (int) $id)->values();
    $liveShipmentIds = $liveOrders->flatMap(fn ($order) => $order->sellerOrders->flatMap->shipments->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values();
    $liveOrderVersion = sha1($liveOrders->sortBy('id')->map(fn ($order) => [
        'id' => (int) $order->id,
        'status' => (string) $order->status,
        'updated_at' => $order->updated_at?->toIso8601String(),
        'shipments' => $order->sellerOrders->flatMap(fn ($sellerOrder) => $sellerOrder->shipments->map(fn ($shipment) => [
            'id' => (int) $shipment->id,
            'status' => (string) $shipment->current_status,
            'updated_at' => $shipment->updated_at?->toIso8601String(),
            'transfer_legs' => $shipment->transferLegs->map(fn ($leg) => ['id' => (int) $leg->id, 'status' => (string) $leg->status, 'updated_at' => $leg->updated_at?->toIso8601String()])->values()->all(),
        ]))->sortBy('id')->values()->all(),
    ])->values()->toJson());
@endphp

<div class="lk-page-narrow lk-order-page" data-buyer-order-live data-order-stream-url="{{ route('buyer.orders.stream') }}" data-order-ids="{{ $liveOrderIds->toJson() }}" data-shipment-ids="{{ $liveShipmentIds->toJson() }}" data-order-version="{{ $liveOrderVersion }}" data-order-detail-id="{{ $mode === 'show' ? (int) ($selectedOrder?->id ?? 0) : '' }}" data-order-tab-key="{{ $mode === 'index' ? strtolower(str_replace('_', '-', $filter)) : 'all' }}">
    @if(session('buyer_notice'))<div class="mb-4 rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('buyer_notice') }}</div>@endif

    @if($mode === 'success')
        <section class="rounded-2xl border border-stone-200 bg-white p-8 text-center shadow-sm"><div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-red-50 text-3xl text-red-900">&#10003;</div><span class="mt-5 block text-[10px] font-bold uppercase tracking-widest text-red-800">Order placed</span><h1 class="mt-2 text-2xl font-bold text-stone-950">Thank you for your order</h1><p class="mt-2 text-sm text-stone-500">{{ $selectedOrder?->order_number ? 'Order '.$selectedOrder->order_number.' was placed successfully.' : 'Your order was placed successfully.' }}</p><div class="mt-6 flex justify-center gap-2"><a class="lk-btn lk-btn-red" href="{{ route('buyer.orders') }}">View My Orders</a><a class="lk-btn lk-btn-light" href="{{ route('buyer.products') }}">Continue Shopping</a></div></section>
    @elseif($mode === 'show' && $selectedOrder)
        @php
            $allShipments = $selectedOrder->sellerOrders->flatMap->shipments;
            $isCancelledOrder = $selectedOrder->status === 'CANCELLED';
            $hasReturnRefundRequest = $selectedOrder->relationLoaded('returnRefundRequests') && $selectedOrder->returnRefundRequests->isNotEmpty();
            $hasSellerReturnInProgress = $allShipments->contains(function ($shipment): bool {
                if (! $shipment->relationLoaded('returnWorkflow')) {
                    return false;
                }

                $workflow = $shipment->getRelation('returnWorkflow');

                return $workflow
                    && $workflow->return_disposition === \App\Models\Logistics\ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN
                    && (in_array($workflow->status, \App\Models\Logistics\ShipmentReturnWorkflow::OPEN_STATUSES, true)
                        || $workflow->status === \App\Models\Logistics\ShipmentReturnWorkflow::SELLER_RECEIVED);
            });
            $allShipmentsReturnedOrCancelled = $allShipments->isNotEmpty()
                && $allShipments->every(fn ($shipment): bool => in_array($shipment->current_status, ['RETURNED', 'CANCELLED'], true));
            $hideTrackingMap = in_array($selectedOrder->status, ['COMPLETED', 'RETURNED', 'CANCELLED'], true)
                || $hasReturnRefundRequest
                || $hasSellerReturnInProgress
                || $allShipmentsReturnedOrCancelled;
            $isTerminalOrder = $isCancelledOrder || in_array($selectedOrder->status, ['RETURNED', 'COMPLETED'], true)
                || ($allShipments->isNotEmpty() && $allShipments->every(fn ($shipment) => in_array($shipment->current_status, ['RETURNED', 'CANCELLED', 'DELIVERED', 'COMPLETED'], true)));
            $detailDelivered = $selectedOrder->sellerOrders->isNotEmpty() && $selectedOrder->sellerOrders->every(fn ($sellerOrder) => $sellerOrder->shipments->isNotEmpty() && $sellerOrder->shipments->every(fn ($shipment) => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true)));
            $deliveryAssignment = $allShipments->flatMap->riderAssignments->where('assignment_type', 'DELIVERY')->sortByDesc('id')->first();
            $payment = $selectedOrder->payments->first();
            $mapMarkers = $mapMarkers ?? [];
            $mapLocationList = collect($mapMarkers)->unique(function ($marker): string {
                $kind = (string) data_get($marker, 'kind', 'destination');
                $normalize = fn ($value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
                if ($kind === 'center') {
                    $centerId = data_get($marker, 'center_id');
                    if ($centerId) {
                        return 'center:'.$centerId;
                    }

                    $id = (string) data_get($marker, 'id', '');
                    $centerId = preg_replace('/-shipment-\d+$/', '', $id);

                    return 'center:'.($centerId !== $id ? $centerId : $normalize(data_get($marker, 'popup')));
                }
                if ($kind === 'destination') {
                    return 'destination:'.$normalize(data_get($marker, 'title', data_get($marker, 'label'))).':'.$normalize(data_get($marker, 'popup'));
                }
                if ($kind === 'seller') {
                    return implode(':', [$kind, round((float) data_get($marker, 'latitude', 0), 4), round((float) data_get($marker, 'longitude', 0), 4)]);
                }

                return 'marker:'.(string) data_get($marker, 'id', $kind);
            })->values();
            $mapHasLiveRider = collect($mapMarkers)->contains(fn ($marker): bool => data_get($marker, 'kind') === 'rider' && data_get($marker, 'location_state') === 'live');
            $mapDeliveryInProgress = $allShipments->contains(fn ($shipment): bool => $shipment->current_status === 'OUT_FOR_DELIVERY');
            $mapHubTransferInProgress = $allShipments->contains(fn ($shipment): bool => $shipment->transferLegs->contains(fn ($leg): bool => $leg->status === 'IN_TRANSIT'));
            $mapTrackingInProgress = $allShipments->isNotEmpty() && !$isTerminalOrder;
            $mapStatusLabel = $mapHasLiveRider ? 'Rider location live' : ($mapHubTransferInProgress ? 'In Transit: Hub to Hub' : ($mapDeliveryInProgress ? 'Out for delivery' : ($detailDelivered ? 'Delivered' : 'Route overview')));
        @endphp
        <div class="lk-page-title"><div><span class="lk-kicker">Order details</span><h1>{{ $selectedOrder->order_number }}</h1>@if($isCancelledOrder && $allShipments->isEmpty())<p>Cancelled · Placed {{ $selectedOrder->placed_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Date unavailable' }}</p>@elseif($isCancelledOrder)<p>Placed {{ $selectedOrder->placed_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Date unavailable' }}</p>@endif</div><div class="flex flex-wrap gap-2"><a class="lk-btn lk-btn-light" href="{{ route('buyer.orders') }}">Back to My Orders</a></div></div>
        <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
            @if(($selectedOrderActionState['can_confirm_received'] ?? false) || ($selectedOrderActionState['can_request_return'] ?? false) || $selectedOrder->sellerOrders->isNotEmpty())
                <div data-order-detail-actions class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-stone-200 bg-stone-50/70 p-4" @if(!($selectedOrderActionState['can_confirm_received'] ?? false) && !($selectedOrderActionState['can_request_return'] ?? false) && $selectedOrder->status !== 'COMPLETED') hidden @endif>
                    <p class="min-w-0 flex-1 text-xs leading-5 text-stone-600" data-order-detail-message @if(!($selectedOrderActionState['can_confirm_received'] ?? false) && $selectedOrder->status !== 'COMPLETED') hidden @endif>{{ ($selectedOrderActionState['can_confirm_received'] ?? false) ? 'Check the delivered items before confirming receipt.' : ($selectedOrder->status === 'COMPLETED' ? 'Your order is complete. You can rate the products below.' : '') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <div data-order-received-action @if(!($selectedOrderActionState['can_confirm_received'] ?? false)) hidden @endif><form method="POST" action="{{ route('buyer.orders.received', $selectedOrder) }}" onsubmit="if (!window.confirm('Confirm that you received every parcel in this order? This will complete the order and enable product reviews.')) return false; this.elements.confirm_receipt.value = '1'; return true;">@csrf<input type="hidden" name="confirm_receipt" value="0"><button class="lk-btn lk-btn-red" type="submit">Order Received</button></form><p class="mt-1 text-xs text-stone-500" data-auto-receipt-deadline @if(!($selectedOrderActionState['auto_confirm_at'] ?? null)) hidden @endif>@if($selectedOrderActionState['auto_confirm_at'] ?? null)Automatic receipt on {{ $selectedOrderActionState['auto_confirm_at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}@endif</p></div>
                        <a class="lk-btn lk-btn-light" data-order-return-action href="{{ route('buyer.orders.return-refund.form', $selectedOrder) }}" @if(!($selectedOrderActionState['can_request_return'] ?? false)) hidden @endif>Request Return / Refund</a>
                    </div>
                </div>
            @endif
            @if(!$isCancelledOrder && ($selectedOrder->address || (!$isTerminalOrder && $deliveryAssignment)))
                <h2 class="text-lg font-bold text-stone-900">Delivery information</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @if($selectedOrder->address)<div><p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Delivery Address</p><strong class="mt-1 block">{{ $selectedOrder->address->recipient_name }}</strong><p class="text-sm text-stone-600">{{ $selectedOrder->address->contact_number }}<br>{{ $selectedOrder->address->formatted() }}</p></div>@endif
                    @if(!$isTerminalOrder && $deliveryAssignment)<div><p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Delivery rider</p><p class="mt-1 text-sm text-stone-700">{{ $deliveryAssignment->riderProfile?->user?->name ?? 'Assigned rider' }}</p></div>@endif
                </div>
            @endif
        </section>
        @if($deliveryPinRequired && !$isTerminalOrder && !$hideTrackingMap)
            <section class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm" aria-labelledby="delivery-pin-required-heading">
                <h2 id="delivery-pin-required-heading" class="text-lg font-bold text-amber-950">Delivery pin required</h2>
                <p class="mt-2 text-sm text-amber-950">Confirm the exact delivery location for this existing order so the assigned rider can navigate to you. The address text below stays unchanged; only this order's missing map pin will be saved.</p>
                <p class="mt-3 rounded-xl border border-amber-200 bg-white/80 px-4 py-3 text-sm text-stone-800"><strong>Saved order address:</strong> {{ $selectedOrder->address?->formatted() }}</p>
                <h3 class="mt-4 text-base font-bold text-stone-900">Live Delivery Map &#183; Confirm Delivery Pin</h3>
                <p class="mt-3 text-sm font-semibold text-stone-800">Tap the exact location on the map. Zoom in before selecting the house or handoff point.</p>
                <x-shared.mapbox :markers="[]" title="Confirm exact delivery location" height="320px" :allow-user-location="false" pin-form-id="delivery-pin-form" :zoom="8" :live-tracking="$allShipments->contains(fn ($shipment) => $shipment->current_status === 'OUT_FOR_DELIVERY')" />
                <form id="delivery-pin-form" method="POST" action="{{ route('buyer.orders.delivery-pin', $selectedOrder) }}" class="mt-4 grid gap-3" data-delivery-pin-form>
                    @csrf
                    <input type="hidden" name="latitude" value="">
                    <input type="hidden" name="longitude" value="">
                    <p class="text-sm text-stone-700" aria-live="polite" data-delivery-pin-status>Tap the map to place your exact delivery pin.</p>
                    <button type="submit" class="lk-btn lk-btn-red justify-self-start disabled:cursor-not-allowed disabled:opacity-50" data-delivery-pin-save disabled>Confirm Delivery Pin</button>
                </form>
            </section>
        @elseif(!$hideTrackingMap)
            <section class="lk-order-map-panel" aria-labelledby="buyer-live-map-heading">
                <header class="lk-order-map-panel__header">
                    <div>
                        <span class="lk-order-map-panel__eyebrow">TRACKING OVERVIEW</span>
                        <h2 id="buyer-live-map-heading">Follow your delivery</h2>
                        <p>See the saved pickup, sorting center and delivery points. Rider movement appears during active delivery.</p>
                    </div>
                    <span class="lk-order-map-status {{ $mapHasLiveRider ? 'is-live' : '' }}"><span aria-hidden="true"></span>{{ $mapStatusLabel }}</span>
                </header>
                <div class="lk-order-map-panel__layout">
                    <div class="lk-order-map-panel__map">
                        <x-shared.mapbox :markers="$mapMarkers" title="Order route" height="420px" :allow-user-location="false" :live-tracking="$mapTrackingInProgress" :order-route="true" class="lk-order-mapbox" />
                    </div>
                    <aside class="lk-order-map-panel__locations" aria-label="Locations shown on the map">
                        <div class="lk-order-map-panel__locations-head">
                            <div><span class="lk-order-map-panel__eyebrow">ROUTE POINTS</span><h3>Locations</h3></div>
                            <span>{{ $mapLocationList->count() }} {{ $mapLocationList->count() === 1 ? 'pin' : 'pins' }}</span>
                        </div>
                        @if($mapLocationList->isNotEmpty())
                            <ol class="lk-order-map-points">
                                @foreach($mapLocationList as $mapMarker)
                                    @php
                                        $markerKind = data_get($mapMarker, 'kind', 'destination');
                                        $markerTitle = match ($markerKind) {
                                            'seller' => 'Seller pickup',
                                            'center' => match (data_get($mapMarker, 'center_role')) {
                                                'origin' => 'Origin sorting center',
                                                'destination' => 'Destination sorting center',
                                                default => 'Sorting center',
                                            },
                                            'rider' => data_get($mapMarker, 'assignment_type') === 'PICKUP' ? 'Pickup rider' : 'Delivery rider',
                                            'destination' => 'Delivery address',
                                            default => data_get($mapMarker, 'label', 'Route location'),
                                        };
                                        $markerDetail = $markerKind === 'rider'
                                            ? (data_get($mapMarker, 'location_pending') ? 'Waiting for rider GPS' : (data_get($mapMarker, 'location_state') === 'live' ? 'Live location · '.data_get($mapMarker, 'popup', 'Recently updated') : 'Last known location · '.data_get($mapMarker, 'popup', '')))
                                            : (data_get($mapMarker, 'popup') ?: 'Saved map location');
                                    @endphp
                                    <li class="lk-order-map-point lk-order-map-point--{{ $markerKind }}">
                                        <span class="lk-order-map-point__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.2"/></svg></span>
                                        <span class="lk-order-map-point__copy"><strong>{{ $markerTitle }}</strong><span>{{ $markerDetail }}</span></span>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <div class="lk-order-map-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.2"/></svg></span><strong>No route pins yet</strong><p>Saved pickup, sorting center and delivery locations will appear as they become available.</p></div>
                        @endif
                        <p class="lk-order-map-panel__note"><span aria-hidden="true">i</span> Rider GPS appears when a delivery is active and the rider is sharing a location.</p>
                    </aside>
                </div>
            </section>
        @endif
        @foreach($selectedOrder->sellerOrders as $sellerOrder)
          @forelse($sellerOrder->shipments as $shipment)
            @php
                $shipmentStatus = $shipment->current_status;
                $returnWorkflow = $shipment->relationLoaded('returnWorkflow') ? $shipment->getRelation('returnWorkflow') : null;
                $isCancelledShipment = $isCancelledOrder || $sellerOrder->status === 'CANCELLED' || $shipmentStatus === 'CANCELLED';
                $shipmentTransferLeg = $shipment->transferLegs->firstWhere('status', 'IN_TRANSIT');
                $shipmentEvents = $shipment->events->sortBy('occurred_at')->values();
                $timelineLabels = [
                    'PLACED' => 'Order placed',
                    'CONFIRMED' => 'Seller confirmed order',
                    'PREPARING' => 'Preparing parcel',
                    'READY_FOR_PICKUP' => 'Ready for pickup',
                    'PICKUP_ASSIGNED' => 'Pickup rider assigned',
                    'AT_SORTING_CENTER' => 'Arrived at sorting center',
                    'SORTED' => 'Sorted by destination',
                    'ASSIGNED_TO_RIDER' => 'Assigned to delivery rider',
                    'OUT_FOR_DELIVERY' => 'Out for delivery',
                    'DELIVERY_FAILED' => 'Delivery attempt unsuccessful',
                    'RETURNED' => 'Parcel returned',
                    'DELIVERED' => 'Delivered',
                    'COMPLETED' => 'Buyer confirmed receipt',
                ];
                $timelineRows = collect();
                foreach ($shipmentEvents as $event) {
                    $isCancellationEvent = str_starts_with((string) $event->notes, 'BUYER_ORDER_CANCELLED:')
                        || str_starts_with((string) $event->notes, 'SELLER_ORDER_CANCELLED:');
                    if ($isCancellationEvent) {
                        $previousTimelineRow = $timelineRows->last();
                        if (!$previousTimelineRow || $previousTimelineRow['status'] !== 'CANCELLED' || $previousTimelineRow['label'] !== 'Order cancelled') {
                            $timelineRows->push(['status' => 'CANCELLED', 'label' => 'Order cancelled', 'event' => $event]);
                        }
                        continue;
                    }

                    $eventLabel = $timelineLabels[$event->status] ?? null;
                    if ($event->status === 'COMPLETED') {
                        $eventLabel = str_starts_with((string) $event->notes, 'Order automatically confirmed received after 3 days')
                            ? 'Automatically confirmed receipt'
                            : 'Buyer confirmed receipt';
                    }
                    $eventNotes = (string) $event->notes;
                    if (str_starts_with($eventNotes, 'RETURN_TO_CENTER_PENDING:')) {
                        $eventLabel = 'Pending return to Logistics';
                    } elseif (str_starts_with($eventNotes, 'RETURN_TO_SELLER_INITIATED:')) {
                        $eventLabel = 'Return to seller initiated';
                    } elseif (str_starts_with($eventNotes, 'RETURN_TO_SELLER_COMPLETED:')) {
                        $eventLabel = 'Return completed after seller receipt';
                    } elseif (str_starts_with($eventNotes, 'RETURN_CUSTODY:')) {
                        $returnNotes = strtolower(substr($eventNotes, strlen('RETURN_CUSTODY:')));
                        $eventLabel = match (true) {
                            str_contains($returnNotes, 'handed to the sorting center') => 'Rider presented parcel to Logistics',
                            str_contains($returnNotes, 'sorting center confirmed receipt') => 'Logistics received parcel',
                            str_contains($returnNotes, 'redelivery workflow') => 'Parcel received for redelivery',
                            str_contains($returnNotes, 'awaiting transfer') => 'Return transfer to seller-side center pending',
                            str_contains($returnNotes, 'transfer') && str_contains($returnNotes, 'ready') => 'Return transfer prepared',
                            str_contains($returnNotes, 'released into') => 'Return parcel released to transfer',
                            str_contains($returnNotes, 'in transit between') => 'Return in transit between centers',
                            str_contains($returnNotes, 'confirmed receipt of the return parcel') => 'Seller-side center received parcel',
                            str_contains($returnNotes, 'return rider assigned') => 'Return rider assigned',
                            str_contains($returnNotes, 'accepted the seller return') => 'Return rider accepted assignment',
                            str_contains($returnNotes, 'collected the parcel') => 'Return rider collected parcel',
                            str_contains($returnNotes, 'custody transferred') => 'Return rider handoff completed',
                            str_contains($returnNotes, 'handed the parcel to the seller') => 'Parcel handed to seller; confirmation pending',
                            default => 'Return custody updated',
                        };
                    }
                    if ($eventLabel) {
                        $previousTimelineRow = $timelineRows->last();
                        if (!$previousTimelineRow || $previousTimelineRow['status'] !== $event->status || $previousTimelineRow['label'] !== $eventLabel || str_starts_with($eventNotes, 'RETURN_CUSTODY:')) {
                            $timelineRows->push(['status' => $event->status, 'label' => $eventLabel, 'event' => $event]);
                        }
                    }
                }
                if ($isCancelledShipment) {
                    $timelineRows = $timelineRows->filter(fn ($row) => in_array($row['status'], ['PLACED', 'CANCELLED'], true))->values();
                    if (!$timelineRows->contains(fn ($row) => $row['status'] === 'CANCELLED')) {
                        $timelineRows->push(['status' => 'CANCELLED', 'label' => 'Order cancelled', 'event' => null]);
                    }
                } elseif ($isTerminalOrder) {
                    $timelineRows = $timelineRows->filter(fn ($row) => in_array($row['status'], ['PLACED', $shipmentStatus, 'CANCELLED'], true)
                        || in_array($row['label'], [
                            'Pending return to Logistics', 'Return to seller initiated', 'Return completed after seller receipt',
                            'Rider presented parcel to Logistics', 'Logistics received parcel', 'Parcel received for redelivery',
                            'Return transfer to seller-side center pending', 'Return transfer prepared', 'Return parcel released to transfer',
                            'Return in transit between centers', 'Seller-side center received parcel', 'Return rider assigned',
                            'Return rider accepted assignment', 'Return rider collected parcel', 'Return rider handoff completed',
                            'Parcel handed to seller; confirmation pending', 'Return custody updated',
                        ], true))->values();
                }
                $hasCancellationEvent = $timelineRows->contains(fn ($row): bool => $row['status'] === 'CANCELLED');
                $currentTimelineRows = $hasCancellationEvent
                    ? $timelineRows->filter(fn ($row): bool => $row['status'] === 'CANCELLED')
                    : $timelineRows->filter(fn ($row): bool => $row['status'] === $shipmentStatus);
                $currentTimelineIndex = $currentTimelineRows->keys()->last();
                if ($currentTimelineIndex === null && ! $hasCancellationEvent && ! $isCancelledShipment && isset($timelineLabels[$shipmentStatus])) {
                    $timelineRows->push(['status' => $shipmentStatus, 'label' => $timelineLabels[$shipmentStatus], 'event' => null]);
                    $currentTimelineIndex = $timelineRows->keys()->last();
                }
                $deliveryProof = in_array($shipmentStatus, ['DELIVERED', 'COMPLETED'], true)
                    ? $shipment->deliveryAttempts
                        ?->where('status', 'DELIVERED')
                        ->filter(fn ($attempt) => filled($attempt->proof_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($attempt->proof_path))
                        ->sortByDesc('attempted_at')
                        ->first()
                    : null;
                $shipmentStatusLabel = $shipmentTransferLeg
                    ? 'In Transit: Hub to Hub'
                    : match ($shipmentStatus) {
                        'PICKED_UP' => 'Picked Up from Seller',
                        'AT_SORTING_CENTER' => 'At Sorting Center',
                        default => str($shipmentStatus)->replace('_', ' ')->title()->toString(),
                    };
                if ($shipmentStatus === 'DELIVERY_FAILED' && ($returnEnhancementsReady ?? false) && $returnWorkflow) {
                    $shipmentStatusLabel = $returnWorkflow->return_disposition === 'REDELIVERY'
                        ? 'Pending Redelivery'
                        : 'Return to Seller in Progress';
                }
                $shipmentCardStatusLabel = $isCancelledShipment
                    ? 'Cancelled'
                    : ($shipmentStatus === 'RETURNED'
                        ? ($hasReturnRefundRequest ? 'Parcel returned to seller' : 'Returned')
                        : $shipmentStatusLabel);
                $shipmentCardMessage = $isCancelledShipment
                    ? 'This parcel was cancelled before pickup and was not handed to a rider.'
                    : null;
                [$shipmentNextStep, $shipmentNextActor] = $isCancelledShipment
                    ? ['No further delivery action is required for this parcel.', 'No action pending']
                    : match ($shipmentStatus) {
                        'PLACED', 'CONFIRMED', 'PREPARING' => ['Wait for the seller to prepare and confirm the parcel.', 'Seller'],
                        'READY_FOR_PICKUP' => ['Logistics will assign a pickup rider.', 'Logistics'],
                        'PICKUP_ASSIGNED' => ['The pickup rider will collect the parcel from the seller.', 'Pickup rider'],
                        'PICKED_UP' => ['The sorting center will scan the parcel into its facility.', 'Logistics'],
                        'AT_SORTING_CENTER' => ['The parcel will be sorted toward your delivery area.', 'Logistics'],
                        'SORTED' => ['A delivery rider will be assigned in your area.', 'Logistics'],
                        'ASSIGNED_TO_RIDER' => ['The delivery rider will accept and start the route.', 'Delivery rider'],
                        'OUT_FOR_DELIVERY' => ['Keep your phone available while the rider attempts delivery.', 'Delivery rider'],
                        'DELIVERY_FAILED' => (($returnEnhancementsReady ?? false) && $returnWorkflow)
                            ? ($returnWorkflow->return_disposition === 'REDELIVERY'
                                ? ['The rider will return the parcel to Logistics after the route; it remains eligible for redelivery.', 'Rider / Logistics']
                                : ['The parcel is moving through verified return custody; seller receipt is required before it is marked returned.', 'Logistics / return rider / seller'])
                            : ['Check for a new attempt or contact the delivery team if needed.', 'Logistics / delivery rider'],
                        'DELIVERED' => ($selectedOrderActionState['can_confirm_received'] ?? false)
                            ? ['Check the parcel, then confirm receipt for the order.', 'Buyer']
                            : ['Wait for the remaining parcels; confirm receipt when the order is ready.', 'Buyer'],
                        'COMPLETED' => ['You can leave a review for the delivered products.', 'Buyer'],
                        'RETURNED' => ['Check Returns & Refunds for the seller review and refund update.', 'Seller / refund payer'],
                        default => ['Follow the tracking timeline for the next scan.', 'Logistics'],
                    };
            @endphp
            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm" aria-labelledby="shipment-{{ $shipment->id }}-heading">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><span class="text-xs font-semibold uppercase tracking-wide text-stone-500">Store</span><h2 id="shipment-{{ $shipment->id }}-heading" class="text-lg font-bold text-stone-900">{{ $sellerOrder->sellerProfile?->business_name }}</h2><p class="mt-1 text-sm text-stone-600">{{ $sellerOrder->seller_order_number }}</p></div></div>
                    @unless($isTerminalOrder)<div class="mt-3 text-xs text-stone-500">Tracking: <strong class="text-stone-700">{{ $shipment->tracking_number }}</strong></div>@endunless
                    @if($shipmentCardMessage)<p class="mt-3 text-sm text-stone-700">{{ $shipmentCardMessage }}</p>@endif
                    <x-shared.status-guidance :status="$shipmentCardStatusLabel" :next="$shipmentNextStep" :actor="$shipmentNextActor" :shipment-id="$shipment->id" :returned-label="$hasReturnRefundRequest ? 'Parcel returned to seller' : 'Returned'" />
                    <form method="POST" action="{{ route('buyer.orders.shipments.received', [$selectedOrder, $shipment]) }}" class="mt-3" data-confirm-shipment-action="{{ $shipment->id }}" @if(! in_array((int) $shipment->id, (array) ($selectedOrderActionState['confirmable_shipment_ids'] ?? []), true)) hidden @endif onsubmit="return window.confirm('Confirm receipt for this parcel? Only this parcel will move to Completed.');">
                        @csrf
                        <input type="hidden" name="confirm_receipt" value="1">
                        <button class="lk-btn lk-btn-red" type="submit">Confirm this parcel received</button>
                    </form>
                    <details class="mt-4 rounded-xl border border-stone-100 px-4 py-3"><summary class="cursor-pointer text-sm font-bold text-stone-900">{{ $isTerminalOrder ? 'Status history' : 'Tracking Timeline' }}</summary><ol class="lk-shipment-timeline mt-4" aria-label="Tracking timeline for {{ $shipment->tracking_number }}" data-shipment-timeline="{{ $shipment->id }}" data-shipment-current-status="{{ $shipmentStatus }}" data-shipment-cancelled="{{ $isCancelledShipment ? 'true' : 'false' }}">
                        @foreach($timelineRows as $timelineIndex => $timelineRow)
                            @php
                                $stepEvent = $timelineRow['event'];
                                $stepIsCurrent = $currentTimelineIndex === $timelineIndex;
                                $stepIsComplete = $stepEvent !== null;
                            @endphp
                            <li class="lk-shipment-step {{ $stepIsCurrent ? 'is-current' : ($stepIsComplete ? 'is-complete' : '') }}" @if($stepIsCurrent) aria-current="step" @endif>
                                <span class="lk-shipment-step__date">@if($stepEvent?->occurred_at)<time datetime="{{ $stepEvent->occurred_at->toIso8601String() }}">{{ $stepEvent->occurred_at->timezone('Asia/Manila')->format('M j, Y') }}<span class="block">{{ $stepEvent->occurred_at->timezone('Asia/Manila')->format('g:i A') }}</span></time>@endif</span>
                                <span class="lk-shipment-step__dot" aria-hidden="true">{{ $stepIsComplete ? '&#10003;' : '' }}</span>
                                <span class="lk-shipment-step__label">{{ $timelineRow['label'] }}</span>
                            </li>
                        @endforeach
                    </ol></details>
                    @if(filled($sellerOrder->buyer_note))
                        <div class="mt-4 rounded-xl border border-stone-100 bg-[#fcf8f4] p-4" data-seller-note><h3 class="text-xs font-semibold uppercase tracking-wide text-stone-600">Note to Seller</h3><p class="mt-2 whitespace-pre-line text-sm text-stone-800">{{ $sellerOrder->buyer_note }}</p></div>
                    @endif
                    @if($deliveryProof)
                        @php $proofUrl = route('delivery-events.proof', $deliveryProof); @endphp
                        <section class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50/50 p-4" aria-labelledby="proof-{{ $shipment->id }}-heading" data-proof-of-delivery>
                            <div class="flex items-start gap-3"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-100 font-bold text-emerald-800" aria-hidden="true">&#10003;</span><div class="min-w-0 flex-1"><h3 id="proof-{{ $shipment->id }}-heading" class="font-bold text-stone-900">Proof of Delivery</h3><p class="mt-1 text-sm text-stone-600">Your parcel was successfully delivered.</p></div></div>
                            <dl class="mt-4 grid gap-2 text-sm">
                                <div class="flex flex-wrap gap-x-2"><dt class="font-semibold text-stone-600">Delivered:</dt><dd class="text-stone-800">@if($deliveryProof->attempted_at)<time datetime="{{ $deliveryProof->attempted_at->toIso8601String() }}">{{ $deliveryProof->attempted_at->timezone('Asia/Manila')->format('M j, Y') }} &#183; {{ $deliveryProof->attempted_at->timezone('Asia/Manila')->format('g:i A') }}</time>@else Successfully @endif</dd></div>
                                <div class="flex flex-wrap gap-x-2"><dt class="font-semibold text-stone-600">Tracking:</dt><dd class="break-all text-stone-800">{{ $shipment->tracking_number }}</dd></div>
                            </dl>
                            <a class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 sm:w-auto" href="{{ $proofUrl }}" target="_blank" rel="noopener noreferrer">View Full Photo</a>
                        </section>
                    @endif
                <div class="mt-5 divide-y divide-stone-100">@foreach(($shipment->orderItem ? collect([$shipment->orderItem]) : $sellerOrder->items) as $item)<div class="flex min-w-0 items-center justify-between gap-4 py-3 text-sm"><div class="flex min-w-0 items-center gap-3"><x-shared.product-thumbnail :item="$item" size="64"/><div class="min-w-0"><strong class="block break-words">{{ $item->product_name }}</strong><span class="block text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }} &#183; Unit price &#8369;{{ number_format((float) $item->unit_price, 2) }}</span>@if((float) $item->discount_amount > 0)<span class="mt-1 block text-xs font-semibold text-rose-700">{{ (int) $item->flash_sale_quantity > 0 ? 'Flash Sale' : 'Product Discount' }} · @if($item->original_unit_price !== null)Original &#8369;{{ number_format((float) $item->original_unit_price, 2) }} / unit · @endif saved &#8369;{{ number_format((float) $item->discount_amount, 2) }}</span>@endif @if((float) $item->seller_voucher_discount > 0)<span class="block text-xs text-emerald-700">Seller voucher · −&#8369;{{ number_format((float) $item->seller_voucher_discount, 2) }}</span>@endif @if((float) $item->platform_voucher_discount > 0)<span class="block text-xs text-blue-700">Platform voucher · −&#8369;{{ number_format((float) $item->platform_voucher_discount, 2) }}</span>@endif @foreach((array) $item->promotion_snapshot as $promotionSnapshot)<span class="mt-1 block text-xs font-semibold text-emerald-700">{{ data_get($promotionSnapshot, 'name', 'Seller promotion') }} · {{ str_replace('_', ' ', data_get($promotionSnapshot, 'promotion_type', '')) }} · −&#8369;{{ number_format((float) data_get($promotionSnapshot, 'discount_amount', 0), 2) }}</span>@if(data_get($promotionSnapshot, 'components'))<span class="block text-xs text-stone-500">Bundle includes: {{ collect(data_get($promotionSnapshot, 'components'))->map(fn ($component) => data_get($component, 'product_name').' × '.data_get($component, 'quantity_per_set', data_get($component, 'quantity')))->join(', ') }}</span>@elseif(data_get($promotionSnapshot, 'buy_product_name') || data_get($promotionSnapshot, 'reward_product_name'))<span class="block text-xs text-stone-500">Buy {{ data_get($promotionSnapshot, 'buy_quantity') }} × {{ data_get($promotionSnapshot, 'buy_product_name') }} · Get {{ data_get($promotionSnapshot, 'reward_quantity') }} × {{ data_get($promotionSnapshot, 'reward_product_name') }}</span>@endif @endforeach</div></div><strong class="shrink-0">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong></div>@endforeach</div>
            </section>
          @empty
            <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><strong>{{ $sellerOrder->sellerProfile?->business_name }}</strong><p class="mt-1 text-sm text-stone-600">Parcel tracking has not been assigned yet.</p><div class="mt-4 divide-y divide-stone-100">@foreach($sellerOrder->items as $item)<div class="flex min-w-0 items-center justify-between gap-4 py-3 text-sm"><div class="flex min-w-0 items-center gap-3"><x-shared.product-thumbnail :item="$item" size="64"/><div class="min-w-0"><strong class="block break-words">{{ $item->product_name }}</strong><span class="block text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }} &#183; Unit price &#8369;{{ number_format((float) $item->unit_price, 2) }}</span>@if((float) $item->discount_amount > 0)<span class="mt-1 block text-xs font-semibold text-rose-700">{{ (int) $item->flash_sale_quantity > 0 ? 'Flash Sale' : 'Product Discount' }} · @if($item->original_unit_price !== null)Original &#8369;{{ number_format((float) $item->original_unit_price, 2) }} / unit · @endif saved &#8369;{{ number_format((float) $item->discount_amount, 2) }}</span>@endif @if((float) $item->seller_voucher_discount > 0)<span class="block text-xs text-emerald-700">Seller voucher · −&#8369;{{ number_format((float) $item->seller_voucher_discount, 2) }}</span>@endif @if((float) $item->platform_voucher_discount > 0)<span class="block text-xs text-blue-700">Platform voucher · −&#8369;{{ number_format((float) $item->platform_voucher_discount, 2) }}</span>@endif @foreach((array) $item->promotion_snapshot as $promotionSnapshot)<span class="mt-1 block text-xs font-semibold text-emerald-700">{{ data_get($promotionSnapshot, 'name', 'Seller promotion') }} · {{ str_replace('_', ' ', data_get($promotionSnapshot, 'promotion_type', '')) }} · −&#8369;{{ number_format((float) data_get($promotionSnapshot, 'discount_amount', 0), 2) }}</span>@if(data_get($promotionSnapshot, 'components'))<span class="block text-xs text-stone-500">Bundle includes: {{ collect(data_get($promotionSnapshot, 'components'))->map(fn ($component) => data_get($component, 'product_name').' × '.data_get($component, 'quantity_per_set', data_get($component, 'quantity')))->join(', ') }}</span>@elseif(data_get($promotionSnapshot, 'buy_product_name') || data_get($promotionSnapshot, 'reward_product_name'))<span class="block text-xs text-stone-500">Buy {{ data_get($promotionSnapshot, 'buy_quantity') }} × {{ data_get($promotionSnapshot, 'buy_product_name') }} · Get {{ data_get($promotionSnapshot, 'reward_quantity') }} × {{ data_get($promotionSnapshot, 'reward_product_name') }}</span>@endif @endforeach</div></div><strong class="shrink-0">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong></div>@endforeach</div></section>
          @endforelse
            @if($selectedOrder->sellerOrders->count() > 1)
                <dl class="mx-3 mt-2 divide-y divide-stone-100 rounded-xl border border-stone-100 bg-stone-50/60 px-4 text-sm sm:mx-5">
                    <div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">{{ $sellerOrder->sellerProfile?->business_name ?: 'Shop' }} merchandise subtotal</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $sellerOrder->item_subtotal, 2) }}</dd></div>
                    <div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">Shipping fee</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $sellerOrder->shipping_fee, 2) }}</dd></div>
                    @if((float) $sellerOrder->promotion_discount_total > 0)<div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">Seller promotion</dt><dd class="font-semibold text-emerald-700">−&#8369;{{ number_format((float) $sellerOrder->promotion_discount_total, 2) }}</dd></div>@endif
                    @if((int) ($sellerOrder->funding_snapshot_version ?? 0) === 1)
                        @if((float) $sellerOrder->seller_voucher_discount > 0)<div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">Seller-funded voucher</dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $sellerOrder->seller_voucher_discount, 2) }}</dd></div>@endif
                        @if((float) $sellerOrder->platform_voucher_discount > 0)<div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">LIKHAE-funded product voucher</dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $sellerOrder->platform_voucher_discount, 2) }}</dd></div>@endif
                        @if((float) $sellerOrder->platform_shipping_voucher_discount > 0)<div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">LIKHAE-funded shipping voucher</dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $sellerOrder->platform_shipping_voucher_discount, 2) }}</dd></div>@endif
                    @elseif((float) $sellerOrder->voucher_discount > 0)
                        <div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">Legacy voucher discount (funding review)</dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $sellerOrder->voucher_discount, 2) }}</dd></div>
                    @endif
                    <div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">Additional Care</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $sellerOrder->protection_total, 2) }}</dd></div>
                    <div class="flex flex-wrap justify-between gap-3 border-t border-stone-200 py-3"><dt class="font-bold text-stone-900">Shop order total</dt><dd class="font-bold text-stone-900">&#8369;{{ number_format((float) $sellerOrder->grand_total, 2) }}</dd></div>
                </dl>
            @endif
        @endforeach
        @php
            $appliedVoucherGroups = $selectedOrder->sellerOrders
                ->filter(fn ($sellerOrder) => $sellerOrder->voucher_id && $sellerOrder->voucher)
                ->groupBy('voucher_id');
            $selectedItemCount = $selectedOrder->sellerOrders->flatMap->items->sum('quantity');
            $selectedFlashSaleUnits = $selectedOrder->sellerOrders->flatMap->items->sum('flash_sale_quantity');
            $actionState = $selectedOrderActionState;
            $returnRequest = $actionState['latest_request'] ?? null;
        @endphp
        <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-2 border-b border-stone-100 pb-4">
                <div><h2 class="text-lg font-bold text-stone-900">Order Summary</h2><p class="mt-1 text-sm text-stone-500">{{ $selectedItemCount }} {{ $selectedItemCount === 1 ? 'item' : 'items' }} in this order</p>@if($selectedFlashSaleUnits > 0)<p class="mt-1 text-xs font-semibold text-rose-800">Flash Sale pricing is included for {{ number_format((int) $selectedFlashSaleUnits) }} unit(s).</p>@endif</div>
            </div>
            <dl class="mt-1 divide-y divide-stone-100 text-sm">
                <div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Merchandise subtotal</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $selectedOrder->subtotal, 2) }}</dd></div>
                <div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Shipping fee</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $selectedOrder->shipping_total, 2) }}</dd></div>
                @if((float) $selectedOrder->sellerOrders->sum('promotion_discount_total') > 0)<div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Seller promotions</dt><dd class="font-semibold text-emerald-700">−&#8369;{{ number_format((float) $selectedOrder->sellerOrders->sum('promotion_discount_total'), 2) }}</dd></div>@endif
                @php
                    $sellerVoucherTotal = $selectedOrder->sellerOrders->sum(fn ($sellerOrder) => (int) ($sellerOrder->funding_snapshot_version ?? 0) === 1 ? (float) $sellerOrder->seller_voucher_discount : 0);
                    $legacyVoucherTotal = $selectedOrder->sellerOrders->sum(fn ($sellerOrder) => (int) ($sellerOrder->funding_snapshot_version ?? 0) === 1 ? 0 : (float) $sellerOrder->voucher_discount);
                @endphp
                @if($sellerVoucherTotal > 0)<div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Seller-funded voucher @if($appliedVoucherGroups->isNotEmpty())<span class="mt-1 block text-xs text-stone-500">@foreach($appliedVoucherGroups as $voucherOrders)<span class="mr-2 inline-block">{{ $voucherOrders->first()->sellerProfile?->business_name ?? 'Shop' }}: {{ $voucherOrders->first()->voucher?->code }}</span>@endforeach</span>@endif</dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $sellerVoucherTotal, 2) }}</dd></div>@endif
                @if((float) $selectedOrder->platform_voucher_discount > 0)<div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">LIKHAE-funded product voucher <span class="mt-1 block text-xs text-stone-500">{{ $selectedOrder->platformVoucher?->code }}</span></dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $selectedOrder->platform_voucher_discount, 2) }}</dd></div>@endif
                @if((float) $selectedOrder->shipping_voucher_discount > 0)<div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">LIKHAE-funded shipping voucher <span class="mt-1 block text-xs text-stone-500">{{ $selectedOrder->shippingVoucher?->code }}</span></dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $selectedOrder->shipping_voucher_discount, 2) }}</dd></div>@endif
                @if($legacyVoucherTotal > 0)<div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Legacy voucher discounts (funding review)</dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $legacyVoucherTotal, 2) }}</dd></div>@endif
                @if((int) $selectedOrder->reward_points_redeemed > 0)
                    <div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Reward points ({{ number_format((int) $selectedOrder->reward_points_redeemed) }})</dt><dd class="font-semibold text-red-800">&#8722;&#8369;{{ number_format((float) $selectedOrder->reward_points_discount, 2) }}</dd></div>
                @endif
                <div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Additional Care</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $selectedOrder->protection_total, 2) }}</dd></div>
                <div class="flex flex-wrap justify-between gap-3 border-t border-stone-200 py-4 text-base"><dt class="font-bold text-stone-900">Order total</dt><dd class="font-bold text-stone-900">&#8369;{{ number_format((float) $selectedOrder->grand_total, 2) }}</dd></div>
            </dl>
            <div class="mt-1 grid gap-3 border-t border-stone-100 pt-4 text-sm sm:grid-cols-2">
                <div><p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Payment method</p><p class="mt-1 font-semibold text-stone-900">{{ $payment?->method === 'COD' ? 'Cash on Delivery (COD)' : str($payment?->method ?? 'Pending')->headline() }}</p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Payment status</p><p class="mt-1 font-semibold text-stone-900">@if($payment?->paid_at)Paid {{ $payment->paid_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}@elseif($payment?->method === 'COD')Pending @else{{ str($payment?->status ?? $selectedOrder->payment_status ?? 'Pending')->headline() }}@endif</p></div>
            </div>
        </section>
        <div class="lk-order-actions mt-5">
            @if($actionState['can_cancel'] ?? false)
                <details class="w-full max-w-xl rounded-xl border border-stone-200 bg-white p-4" @if($errors->has('reason') || $errors->has('reason_details')) open @endif>
                    <summary class="cursor-pointer text-sm font-semibold text-red-900">Cancel Order</summary>
                    <form method="POST" action="{{ route('buyer.orders.cancel', $selectedOrder) }}" class="mt-4 grid gap-3" onsubmit="return window.confirm('Cancel this order? Eligible stock and voucher usage will be restored.');">
                        @csrf
                        <label class="grid gap-1 text-sm font-medium text-stone-800">Reason for cancellation
                            <select name="reason" required class="rounded-lg border border-stone-300 bg-white p-3 text-sm" onchange="this.form.elements.reason_details.required = this.value === 'OTHER';">
                                <option value="">Select a reason</option>
                                <option value="CHANGED_MIND" @selected(old('reason') === 'CHANGED_MIND')>Changed my mind</option>
                                <option value="ORDERED_BY_MISTAKE" @selected(old('reason') === 'ORDERED_BY_MISTAKE')>Ordered by mistake</option>
                                <option value="DELIVERY_TIMING" @selected(old('reason') === 'DELIVERY_TIMING')>Delivery timing no longer works</option>
                                <option value="OTHER" @selected(old('reason') === 'OTHER')>Other</option>
                            </select>
                        </label>
                        @error('reason')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                        <label class="grid gap-1 text-sm font-medium text-stone-800">Additional details <span class="text-xs font-normal text-stone-500">Required when you select Other.</span>
                            <textarea name="reason_details" maxlength="480" rows="3" class="rounded-lg border border-stone-300 p-3 text-sm" placeholder="Add a short explanation">{{ old('reason_details') }}</textarea>
                        </label>
                        @error('reason_details')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                        <button class="lk-btn lk-btn-light justify-self-start" type="submit">Confirm cancellation</button>
                    </form>
                </details>
            @endif
            @if($returnRequest)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders', ['status' => 'RETURNS']) }}">View Return/Refund Request &#183; {{ str($returnRequest->status)->headline() }}</a>@endif
        </div>
        @if(filled($selectedOrder->cancellation_reason))
            <details class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                <summary class="cursor-pointer text-sm font-bold text-stone-900">Cancellation details</summary>
                <p class="mt-3 text-sm text-stone-700">{{ $selectedOrder->cancellation_reason }}</p>
            </details>
        @endif
        <section class="mt-5 flex flex-wrap gap-3" aria-label="Order support and seller contact">
            <form class="w-full sm:w-auto" method="POST" action="{{ route('buyer.orders.support-conversation', $selectedOrder) }}">@csrf<button type="submit" class="lk-btn lk-btn-light w-full justify-center sm:w-auto">Help Center / Admin Support</button></form>
            @foreach($selectedOrder->sellerOrders as $sellerOrder)
                <form class="w-full sm:w-auto" method="POST" action="{{ route('buyer.orders.seller-conversation', [$selectedOrder, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light w-full justify-center sm:w-auto">{{ $selectedOrder->sellerOrders->count() === 1 ? 'Contact Seller' : 'Contact '.$sellerOrder->sellerProfile?->business_name }}</button></form>
            @endforeach
        </section>
        @if($selectedOrder->status === 'COMPLETED')
            <section id="reviews" class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-stone-900">Rate and review your order</h2>
                <p class="mt-1 text-sm text-stone-500">Ratings and photos lock after your first submission. Review text can be edited once within 30 days.</p>
                @if($errors->any())<p class="mt-2 text-sm font-medium text-red-700">{{ $errors->first() }}</p>@endif
                <div class="mt-4 space-y-4">
                    @foreach($selectedOrder->sellerOrders->flatMap->items as $item)
                        @php
                            $deliveryAssignment = $item->shipment?->riderAssignments->where('assignment_type', 'DELIVERY')->sortByDesc('id')->first();
                            $reviewComment = (int) old('review_item_id') === (int) $item->id
                                ? old('comment', '')
                                : ($item->review?->comment ?? '');
                            $canEditReviewText = $item->review
                                && (bool) ($reviewTextEditAvailableById[$item->review->id] ?? false);
                        @endphp
                        <form method="POST" enctype="multipart/form-data" action="{{ route('buyer.orders.review.store', $item) }}" class="grid gap-3 rounded-xl border border-stone-200 p-4">
                            @csrf
                            <input type="hidden" name="review_item_id" value="{{ $item->id }}">
                            <div class="flex items-center gap-3">
                                <x-shared.product-thumbnail :item="$item" size="56"/>
                                <div>
                                    <strong class="block">{{ $item->product_name }}</strong>
                                    <small class="block text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }}</small>
                                    <small class="block text-stone-500">Seller: {{ $item->sellerOrder?->sellerProfile?->business_name ?? 'Seller' }} &#183; Order {{ $selectedOrder->order_number }}</small>
                                </div>
                            </div>

                            <fieldset>
                                <legend class="text-sm font-semibold">Product rating</legend>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach(range(1, 5) as $rating)
                                        <label class="cursor-pointer">
                                            <input class="peer sr-only" type="radio" name="rating" value="{{ $rating }}" @checked((int) $item->review?->rating === $rating) @disabled($item->review)>
                                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-stone-300 text-lg text-yellow-500 hover:border-yellow-400 hover:bg-yellow-50 peer-checked:border-yellow-500 peer-checked:bg-yellow-400 peer-checked:text-yellow-950">&#9733;</span>
                                            <span class="sr-only">{{ $rating }} product stars</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            @if($deliveryAssignment)
                                <fieldset>
                                    <legend class="text-sm font-semibold">Delivery rider rating <span class="font-normal text-stone-500">{{ $deliveryAssignment->riderProfile?->user?->name }}</span></legend>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach(range(1, 5) as $rating)
                                            <label class="cursor-pointer">
                                                <input class="peer sr-only" type="radio" name="rider_rating" value="{{ $rating }}" @checked(($riderRatingsByShipment[$item->shipment->id] ?? null) === $rating) @disabled($item->review)>
                                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-stone-300 text-lg text-yellow-500 hover:border-yellow-400 hover:bg-yellow-50 peer-checked:border-yellow-500 peer-checked:bg-yellow-400 peer-checked:text-yellow-950">&#9733;</span>
                                                <span class="sr-only">{{ $rating }} rider stars</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <label class="grid gap-1 text-sm font-medium">Rider review (optional)
                                    <textarea name="rider_comment" maxlength="2000" rows="2" class="rounded-lg border border-stone-300 p-3 text-sm" placeholder="Comment on the delivery experience">{{ $item->review?->rider_comment }}</textarea>
                                </label>
                            @endif

                            @if(isset($reviewPhotosById[$item->review?->id]))
                                <div class="flex flex-wrap gap-2">
                                    @foreach($reviewPhotosById[$item->review->id] as $photoUrl)
                                        <img src="{{ $photoUrl }}" alt="Current review photo" class="h-24 w-24 rounded-lg object-cover" width="96" height="96" loading="lazy" decoding="async">
                                    @endforeach
                                </div>
                            @endif

                            @if(filled($item->review?->seller_reply))
                                <div class="rounded-xl border border-stone-200 bg-stone-50 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <strong>Seller Response</strong>
                                        @if($item->review->seller_replied_at)<time class="text-sm text-stone-500">{{ $item->review->seller_replied_at->format('M j, Y') }}</time>@endif
                                    </div>
                                    <p class="mt-2 whitespace-pre-line">{{ $item->review->seller_reply }}</p>
                                </div>
                            @endif

                            <label class="grid gap-1 text-sm font-medium">Add a product photo
                                <span class="text-xs font-normal text-stone-500">JPG, PNG, or WebP, up to 5 MB each; up to 5 photos</span>
                                <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2" @disabled($item->review)>
                            </label>
                            <label class="grid gap-1 text-sm font-medium">Product review (optional)
                                <textarea name="comment" data-review-comment maxlength="500" rows="3" class="rounded-lg border border-stone-300 p-3 text-sm" placeholder="Write a product review (optional)" @readonly($item->review && ! $canEditReviewText)>{{ $reviewComment }}</textarea>
                                <span class="text-right text-xs font-normal text-stone-500" data-review-comment-count>{{ mb_strlen((string) $reviewComment) }} / 500</span>
                            </label>
                            @if($item->review && ! $canEditReviewText)
                                <p class="text-xs text-stone-500">The one-time review text edit has been used or its 30-day window has passed.</p>
                            @endif
                            <button class="lk-btn lk-btn-red justify-self-start" type="submit">{{ $item->review ? 'Update Review' : 'Submit Rating' }}</button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <div class="lk-page-title"><div><span class="lk-kicker">Order Center</span><h1>My Orders</h1><p>Track fulfillment and confirm receipt after delivery.</p></div><div class="flex flex-wrap gap-2"><a class="lk-btn lk-btn-light" href="{{ route('buyer.products') }}">Shop Products</a></div></div>
        @php
            $orderTabs = ['all' => 'All', 'to-pay' => 'To Pay', 'to-ship' => 'To Ship', 'to-receive' => 'To Receive', 'completed' => 'Completed', 'returns' => 'Return / Refund', 'cancelled' => 'Cancelled'];
            $activeTabKey = strtolower(str_replace('_', '-', $filter));
        @endphp
        <nav class="lk-order-status-tabs mt-4" aria-label="Order status filters">
            @foreach($orderTabs as $key => $label)
                @php
                    $isActive = $activeTabKey === $key;
                    $countKey = strtoupper(str_replace('-', '_', $key));
                    $count = (int) data_get($orderStatusCounts ?? [], $countKey, 0);
                @endphp
                <a href="{{ route('buyer.orders', ['status' => $key]) }}" class="lk-order-status-tab {{ $isActive ? 'is-active' : '' }}" aria-label="{{ $label }}: {{ $count }} order{{ $count === 1 ? '' : 's' }}" @if($isActive) aria-current="page" @endif>
                    {{ $label }}
                    <span class="lk-order-status-count" aria-hidden="true">{{ $count }}</span>
                </a>
            @endforeach
        </nav>
        <div class="mt-5 space-y-4">
            @forelse($visibleOrders as $order)
                @php
                    $displayTab = $activeTabKey === 'all'
                        ? $orderTabKeys->get($order->id, $order->status)
                        : strtoupper(str_replace('-', '_', $activeTabKey));
                    $sellerFulfillmentStatuses = $order->sellerOrders
                        ->map(fn ($sellerOrder) => $sellerOrderDisplayStatuses->get((int) $sellerOrder->id))
                        ->filter()
                        ->unique();
                    $hasMixedSellerFulfillment = $sellerFulfillmentStatuses->count() > 1;
                    $parcelTabKey = $hasMixedSellerFulfillment ? null : match ($activeTabKey) {
                        'to-receive' => 'TO_RECEIVE',
                        'completed' => 'COMPLETED',
                        'returns' => 'RETURNS',
                        default => null,
                    };
                    $sellerItemsByTab = $order->sellerOrders->mapWithKeys(function ($sellerOrder) use ($parcelTabKey, $shipmentTabKeys, $activeTabKey, $order) {
                        $sellerItems = $sellerOrder->items;
                        $itemsForTab = $sellerItems->filter(function ($item) use ($sellerOrder, $parcelTabKey, $shipmentTabKeys, $activeTabKey, $order) {
                            if (! $parcelTabKey) {
                                return true;
                            }
                            $shipment = $sellerOrder->shipments->first(fn ($candidate) => (int) $candidate->order_item_id === (int) $item->id);
                            if (! $shipment) {
                                return $activeTabKey === 'completed' && $order->status === 'COMPLETED';
                            }

                            return $shipmentTabKeys->get((int) $shipment->id) === $parcelTabKey;
                        })->values();

                        $hasShipmentForTab = $parcelTabKey && $sellerOrder->shipments->contains(
                            fn ($shipment) => $shipmentTabKeys->get((int) $shipment->id) === $parcelTabKey,
                        );
                        $sellerStatusImpliesToReceive = $parcelTabKey === 'TO_RECEIVE'
                            && in_array($sellerOrder->status, ['PICKED_UP', 'COMPLETED'], true);
                        $showSellerItemsAsFallback = $parcelTabKey === 'TO_RECEIVE'
                            && $itemsForTab->isEmpty()
                            && $sellerItems->isNotEmpty()
                            && ($hasShipmentForTab || $sellerStatusImpliesToReceive);

                        if ($showSellerItemsAsFallback) {
                            $itemsForTab = $sellerItems->values();
                        }

                        return [(int) $sellerOrder->id => [
                            'items' => $itemsForTab,
                            'uses_order_fallback' => $showSellerItemsAsFallback,
                        ]];
                    });
                    $orderItemsForTab = $sellerItemsByTab->flatMap(fn (array $sellerData) => $sellerData['items']);
                    $orderUsesItemFallback = $sellerItemsByTab->contains(fn (array $sellerData) => $sellerData['uses_order_fallback']);
                    $unitCount = $orderItemsForTab->sum('quantity');
                    $orderDisplayTotal = $orderItemsForTab->sum(fn ($item) => (float) $item->line_total);
                    $returnRequests = $returnRefundReady ? $order->returnRefundRequests->sortByDesc('submitted_at') : collect();
                    $returnRequest = $returnRequests->first();
                    $returnSellerRequests = $returnRequests->flatMap(fn ($requestRecord) => $requestRecord->sellerRequests);
                    $formalReturnItemIds = $returnRequests->flatMap(function ($requestRecord) {
                        $itemIds = $requestRecord->items->pluck('order_item_id');
                        $sellerRequestItems = $requestRecord->sellerRequests->flatMap(fn ($sellerRequest) => $sellerRequest->items);
                        $itemIds = $itemIds->merge($sellerRequestItems->pluck('order_item_id'));
                        if ($itemIds->isEmpty()) {
                            $itemIds = $requestRecord->sellerRequests->flatMap(fn ($sellerRequest) => $sellerRequest->sellerOrder?->items->pluck('id') ?? collect());
                        }

                        return $itemIds->filter()->map(fn ($id): int => (int) $id)->all();
                    })->unique()->values();
                    $actionState = $orderActionStates->get($order->id, []);
                    $confirmableShipmentIds = collect($actionState['confirmable_shipment_ids'] ?? [])->map(fn ($id): int => (int) $id)->all();
                @endphp
                @if($activeTabKey === 'returns' && $returnSellerRequests->isNotEmpty())
                    <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-order-id="{{ $order->id }}" data-order-number="{{ $order->order_number }}">
                        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
                            <div><h2 class="font-semibold text-stone-900">Order {{ $order->order_number }}</h2><p class="mt-1 text-xs text-stone-500">{{ optional($order->placed_at)->format('M j, Y') }}</p></div>
                        </header>
                        <div class="divide-y divide-stone-100">
                    @foreach($returnSellerRequests as $sellerRequest)
                        @php
                            $refundSellerOrder = $sellerRequest->sellerOrder;
                            $refundItems = $sellerRequest->items;
                            $refundTotal = (float) $sellerRequest->payouts->sum('amount_sent');
                            if ($refundTotal <= 0) $refundTotal = (float) ($sellerRequest->approved_amount ?? $sellerRequest->requested_amount);
                            $refundStatusLabel = match ($sellerRequest->status) {
                                'CONFIRMED' => 'Refund completed',
                                'REFUND_SENT' => 'Refund sent',
                                'APPROVED' => 'Refund approved',
                                'REJECTED' => 'Request rejected',
                                'ESCALATED' => 'Under review',
                                'ADMIN_REFUND_REQUIRED' => 'Admin review',
                                'SELLER_REVIEW' => 'Seller reviewing',
                                'DISPUTE_RESOLVED' => 'Resolved',
                                default => str($sellerRequest->status)->replace('_', ' ')->title(),
                            };
                        @endphp
                        @if($refundSellerOrder)
                            <section class="px-5 py-4">
                                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 pb-4">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h2 class="font-semibold text-stone-900">{{ $refundSellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h2>
                                            <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $refundSellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light lk-order-contact-link px-3 py-1.5 text-xs">Chat</button></form>
                                            @if($refundSellerOrder->sellerProfile?->id && \Illuminate\Support\Facades\Route::has('buyer.shop'))<a class="lk-btn lk-btn-light lk-order-contact-link px-3 py-1.5 text-xs" href="{{ route('buyer.shop', ['seller' => $refundSellerOrder->sellerProfile->id]) }}">View Shop</a>@endif
                                        </div>
                                        <p class="mt-1 text-xs text-stone-500">Order {{ $order->order_number }} &#183; Submitted {{ $sellerRequest->created_at?->format('M j, Y') }}</p>
                                    </div>
                                    <span class="rounded-full bg-stone-100 px-3 py-1.5 text-xs font-semibold text-stone-700">{{ $refundStatusLabel }}</span>
                                </header>
                                <ul class="divide-y divide-stone-100 px-5">
                                    @forelse($refundItems as $refundItem)
                                        @if($refundItem->orderItem)
                                            <li class="flex items-start gap-3 py-4">
                                                <x-shared.product-thumbnail :item="$refundItem->orderItem" size="56" class="rounded-lg border border-stone-100" />
                                                <div class="min-w-0 flex-1"><p class="font-medium text-stone-900">{{ $refundItem->orderItem->product_name }}</p><p class="mt-1 text-xs text-stone-500">{{ $refundItem->orderItem->variant_description ?: 'Standard' }} &#183; Qty {{ $refundItem->quantity }}</p></div>
                                                <strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $refundItem->refundable_amount, 2) }}</strong>
                                            </li>
                                        @endif
                                    @empty
                                        @foreach($refundSellerOrder->items as $item)
                                            <li class="flex items-start gap-3 py-4"><x-shared.product-thumbnail :item="$item" size="56" class="rounded-lg border border-stone-100" /><div class="min-w-0 flex-1"><p class="font-medium text-stone-900">{{ $item->product_name }}</p><p class="mt-1 text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }}</p></div><strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong></li>
                                        @endforeach
                                    @endforelse
                                </ul>
                                <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-100 bg-stone-50/60 px-5 py-4">
                                    <div><span class="text-xs text-stone-500">Total Refund</span><strong class="ml-3 text-lg font-bold text-stone-900">&#8369;{{ number_format($refundTotal, 2) }}</strong></div>
                                    <div class="lk-order-card-actions"><a class="lk-btn lk-btn-light" href="{{ route('buyer.returns.show', $sellerRequest) }}">View Refund Details</a><form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $refundSellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light">Contact Seller</button></form></div>
                                </footer>
                            </section>
                        @endif
                    @endforeach
                    @foreach($order->sellerOrders as $sellerOrder)
                        @php
                            $returnWorkflowItems = $sellerOrder->items->filter(function ($item) use ($sellerOrder, $shipmentTabKeys, $formalReturnItemIds) {
                                $shipment = $sellerOrder->shipments->first(fn ($candidate) => (int) $candidate->order_item_id === (int) $item->id);

                                return $shipment
                                    && $shipmentTabKeys->get((int) $shipment->id) === 'RETURNS'
                                    && ! $formalReturnItemIds->contains((int) $item->id);
                            });
                            $returnWorkflowUnmapped = $sellerOrder->shipments->filter(fn ($shipment) =>
                                (! $shipment->order_item_id || ! $sellerOrder->items->contains('id', (int) $shipment->order_item_id))
                                && $shipmentTabKeys->get((int) $shipment->id) === 'RETURNS'
                            );
                        @endphp
                        @if($returnWorkflowItems->isNotEmpty())
                            <section class="border-t border-stone-100 px-5 py-4">
                                <h3 class="font-semibold text-stone-900">Return parcels · {{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h3>
                                <ul class="mt-2 divide-y divide-stone-100">
                                    @foreach($returnWorkflowItems as $item)
                                        @php
                                            $returnShipment = $sellerOrder->shipments->first(fn ($candidate) => (int) $candidate->order_item_id === (int) $item->id);
                                            $returnParcelNumber = $sellerOrder->shipments->search(fn ($candidate) => (int) $candidate->id === (int) $returnShipment->id) + 1;
                                        @endphp
                                        <li class="py-3" data-shipment-tab-key-id="{{ $returnShipment->id }}" data-shipment-tab-key="{{ $shipmentTabKeys->get((int) $returnShipment->id) }}">
                                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-stone-50 px-3 py-2">
                                                <span class="text-[11px] text-stone-600">Parcel {{ $returnParcelNumber }} · Tracking {{ $returnShipment->tracking_number }}</span>
                                                @include('buyer.partials.purchase-shipment-summary', ['shipment' => $returnShipment, 'status' => 'Return / Refund', 'orderId' => $order->id, 'showOrderStatus' => false, 'fullStatus' => true])
                                            </div>
                                            <div class="mt-3 flex items-start gap-3">
                                                <x-shared.product-thumbnail :item="$item" size="56" class="rounded-lg border border-stone-100" />
                                                <div class="min-w-0 flex-1"><p class="font-medium text-stone-900">{{ $item->product_name }}</p><p class="mt-1 text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} · Qty {{ $item->quantity }}</p></div>
                                                <strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                        @if($returnWorkflowUnmapped->isNotEmpty())
                            <section class="border-t border-stone-100 px-5 py-4">
                                <h3 class="font-semibold text-stone-900">Other return parcels · {{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h3>
                                <div class="mt-2 grid gap-2">
                                    @foreach($returnWorkflowUnmapped as $returnShipment)
                                        @php $returnParcelNumber = $sellerOrder->shipments->search(fn ($candidate) => (int) $candidate->id === (int) $returnShipment->id) + 1; @endphp
                                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-stone-50 px-3 py-2" data-shipment-tab-key-id="{{ $returnShipment->id }}" data-shipment-tab-key="{{ $shipmentTabKeys->get((int) $returnShipment->id) }}">
                                            <span class="text-[11px] text-stone-600">Parcel {{ $returnParcelNumber }} · Tracking {{ $returnShipment->tracking_number }}</span>
                                            @include('buyer.partials.purchase-shipment-summary', ['shipment' => $returnShipment, 'status' => 'Return / Refund', 'orderId' => $order->id, 'showOrderStatus' => false, 'fullStatus' => true])
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    @endforeach
                        </div>
                    </article>
                @elseif(in_array($activeTabKey, ['completed', 'cancelled'], true))
                    <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-order-id="{{ $order->id }}" data-order-number="{{ $order->order_number }}">
                        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
                            <div><h2 class="font-semibold text-stone-900">Order {{ $order->order_number }}</h2><p class="mt-1 text-xs text-stone-500">{{ optional($order->placed_at)->format('M j, Y') }}</p></div>
                        </header>
                        <div class="divide-y divide-stone-100">
                    @foreach($order->sellerOrders as $sellerOrder)
                        @php
                            $sellerShipments = $sellerOrder->shipments;
                            $sellerTabData = $sellerItemsByTab->get((int) $sellerOrder->id, ['items' => collect(), 'uses_order_fallback' => false]);
                            $sellerItemsForTab = $sellerTabData['items'];
                            $sellerItemsNeedFallback = $sellerTabData['uses_order_fallback'];
                            $unmappedShipmentsForTab = $sellerShipments->filter(fn ($shipment) =>
                                (! $shipment->order_item_id || ! $sellerOrder->items->contains('id', (int) $shipment->order_item_id))
                                && (! $parcelTabKey || $shipmentTabKeys->get((int) $shipment->id) === $parcelTabKey)
                            );
                            $sellerDisplayStatus = $parcelTabKey
                                ? match ($parcelTabKey) {
                                    'TO_RECEIVE' => 'To Receive',
                                    'COMPLETED' => 'Completed',
                                    'RETURNS' => 'Return / Refund',
                                }
                                : $sellerOrderDisplayStatuses->get($sellerOrder->id, str($sellerOrder->status)->replace('_', ' ')->title()->toString());
                            $sellerUnitCount = $sellerItemsForTab->sum('quantity');
                            $sellerDisplayTotal = $sellerItemsForTab->sum(fn ($item) => (float) $item->line_total);
                        @endphp
                        @if($sellerItemsForTab->isNotEmpty() || $unmappedShipmentsForTab->isNotEmpty())
                        <section class="px-5 py-4">
                            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 pb-4">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="font-semibold text-stone-900">{{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h2>
                                        <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light lk-order-contact-link px-3 py-1.5 text-xs">Chat</button></form>
                                        @if($sellerOrder->sellerProfile?->id && \Illuminate\Support\Facades\Route::has('buyer.shop'))<a class="lk-btn lk-btn-light lk-order-contact-link px-3 py-1.5 text-xs" href="{{ route('buyer.shop', ['seller' => $sellerOrder->sellerProfile->id]) }}">View Shop</a>@endif
                                    </div>
                                    <p class="mt-1 text-xs text-stone-500">Order {{ $order->order_number }} &#183; {{ optional($order->placed_at)->format('M j, Y') }}</p>
                                </div>
                                <span class="shrink-0 font-semibold uppercase tracking-wide text-[#7a2a22]" @if(! $parcelTabKey) data-seller-order-status-id="{{ $sellerOrder->id }}" @endif>{{ $sellerDisplayStatus }}</span>
                            </header>
                            @if($unmappedShipmentsForTab->isNotEmpty())
                                <div class="my-3 grid gap-2">
                                    @foreach($unmappedShipmentsForTab as $shipment)
                                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-stone-50 px-3 py-2" data-shipment-tab-key-id="{{ $shipment->id }}" data-shipment-tab-key="{{ $shipmentTabKeys->get((int) $shipment->id) }}">
                                            <span class="text-[11px] text-stone-600">Parcel {{ $sellerShipments->search(fn ($candidate) => (int) $candidate->id === (int) $shipment->id) + 1 }} · Tracking {{ $shipment->tracking_number }}</span>
                                            @include('buyer.partials.purchase-shipment-summary', ['shipment' => $shipment, 'status' => $sellerDisplayStatus, 'orderId' => $order->id, 'showOrderStatus' => false, 'fullStatus' => true])
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            @if($sellerItemsNeedFallback)
                                <p class="mb-3 text-xs text-stone-500">Parcel item links are unavailable, so all products from this seller order are shown.</p>
                            @endif
                            <ul class="divide-y divide-stone-100 px-5">
                                @foreach($sellerItemsForTab as $item)
                                    @php
                                        $shipmentForItem = $sellerShipments->first(fn ($shipment) => (int) $shipment->order_item_id === (int) $item->id);
                                        $shipmentParcelNumber = $shipmentForItem ? $sellerShipments->search(fn ($shipment) => (int) $shipment->id === (int) $shipmentForItem->id) + 1 : null;
                                    @endphp
                                    <li class="flex flex-col gap-3 py-4">
                                        @if($shipmentForItem)
                                            <div class="flex flex-col gap-1 rounded-lg border border-stone-100 bg-stone-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between" data-shipment-tab-key-id="{{ $shipmentForItem->id }}" data-shipment-tab-key="{{ $shipmentTabKeys->get((int) $shipmentForItem->id) }}">
                                                <span class="text-[11px] font-semibold text-stone-700">Parcel {{ $shipmentParcelNumber }} <span aria-hidden="true">&#183;</span> Tracking <span class="break-all">{{ $shipmentForItem->tracking_number }}</span></span>
                                                @include('buyer.partials.purchase-shipment-summary', ['shipment' => $shipmentForItem, 'status' => $sellerDisplayStatus, 'orderId' => $order->id, 'showOrderStatus' => false, 'fullStatus' => true])
                                            </div>
                                        @endif
                                        <div class="flex items-start gap-3">
                                            <x-shared.product-thumbnail :item="$item" size="56" class="rounded-lg border border-stone-100" />
                                            <div class="min-w-0 flex-1">
                                                <p class="font-medium text-stone-900">{{ $item->product_name }}</p>
                                                <p class="mt-1 text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }}</p>
                                                @if($displayTab === 'COMPLETED' && $order->status === 'COMPLETED')
                                                    @if($item->review)<p class="mt-1 text-xs font-semibold text-stone-600">Quick review submitted @if($item->review->rating)<span class="ml-1 tracking-wide text-amber-500">{{ str_repeat('★', (int) $item->review->rating) }}{{ str_repeat('☆', 5 - (int) $item->review->rating) }}</span>@endif</p>
                                                    @else<form method="POST" action="{{ route('buyer.orders.review.store', $item) }}" class="mt-1 flex flex-wrap items-center gap-1" aria-label="Quick review for {{ $item->product_name }}">@csrf<span class="mr-1 text-[11px] font-semibold text-stone-600">Quick review</span>@foreach(range(1, 5) as $rating)<button type="submit" name="rating" value="{{ $rating }}" title="Rate {{ $rating }} out of 5 stars" aria-label="Rate {{ $item->product_name }} {{ $rating }} out of 5 stars" class="inline-flex h-7 w-7 items-center justify-center rounded-md text-lg leading-none text-amber-500 transition hover:bg-amber-100 hover:text-amber-700">&#9733;</button>@endforeach</form>@endif
                                                @elseif($displayTab === 'COMPLETED')
                                                    <p class="mt-1 text-xs text-emerald-800">This parcel is complete. Other parcels remain in their own status tabs.</p>
                                                @endif
                                            </div>
                                            <strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-100 bg-stone-50/60 px-5 py-4">
                                <div><span class="block text-xs text-stone-500">{{ $sellerUnitCount }} {{ \Illuminate\Support\Str::plural('item', $sellerUnitCount) }} &#183; {{ $sellerItemsNeedFallback ? 'Items shown total' : ($parcelTabKey ? 'Parcel Total' : 'Order Total') }}</span><strong class="text-lg font-bold text-stone-900">&#8369;{{ number_format($parcelTabKey ? $sellerDisplayTotal : (float) $sellerOrder->grand_total, 2) }}</strong></div>
                                <div class="lk-order-card-actions">
                                    @if($activeTabKey === 'completed' && $order->status !== 'COMPLETED')
                                        <a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.show', $order) }}">View Details</a>
                                    @elseif($displayTab === 'COMPLETED')
                                        <a class="lk-btn lk-btn-red" href="{{ route('buyer.orders.show', $order) }}#reviews">Rate</a>
                                        @if($actionState['can_request_return'] ?? false)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.return-refund.form', $order) }}">Request For Return/Refund</a>@endif
                                        <details class="rounded-xl border border-stone-200 bg-white p-3"><summary class="cursor-pointer text-sm font-semibold text-stone-800">More</summary><div class="mt-3 grid gap-2"><form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light w-full justify-center">Contact Seller</button></form><form method="POST" action="{{ route('buyer.orders.seller-orders.buy-again', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light w-full justify-center">Buy Again</button></form><a class="lk-btn lk-btn-light w-full justify-center" href="{{ route('buyer.orders.show', $order) }}">View Details</a></div></details>
                                    @else
                                        <form method="POST" action="{{ route('buyer.orders.seller-orders.buy-again', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-red">Buy Again</button></form>
                                        <a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.show', $order) }}">View Cancellation Details</a>
                                        <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light">Contact Seller</button></form>
                                    @endif
                                </div>
                            </footer>
                        </section>
                        @endif
                    @endforeach
                        </div>
                    </article>
                @else
                <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-order-id="{{ $order->id }}" data-order-number="{{ $order->order_number }}">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
                        <div class="min-w-0">
                            <h2 class="font-semibold text-stone-900">Order {{ $order->order_number }}</h2>
                            <p class="mt-1 text-xs text-stone-500">{{ optional($order->placed_at)->format('M j, Y') }}</p>
                        </div>
                    </header>
                    <div class="divide-y divide-stone-100">
                        @foreach($order->sellerOrders as $sellerOrder)
                                @php
                                    $sellerShipments = $sellerOrder->shipments;
                                    $sellerTabData = $sellerItemsByTab->get((int) $sellerOrder->id, ['items' => collect(), 'uses_order_fallback' => false]);
                                    $sellerItemsForTab = $sellerTabData['items'];
                                    $sellerItemsNeedFallback = $sellerTabData['uses_order_fallback'];
                                    $unmappedShipmentsForTab = $sellerShipments->filter(fn ($shipment) =>
                                        (! $shipment->order_item_id || ! $sellerOrder->items->contains('id', (int) $shipment->order_item_id))
                                        && (! $parcelTabKey || $shipmentTabKeys->get((int) $shipment->id) === $parcelTabKey)
                                    );
                                    $sellerDisplayStatus = $parcelTabKey
                                        ? match ($parcelTabKey) {
                                            'TO_RECEIVE' => 'To Receive',
                                            'COMPLETED' => 'Completed',
                                            'RETURNS' => 'Return / Refund',
                                        }
                                        : $sellerOrderDisplayStatuses->get($sellerOrder->id, str($sellerOrder->status)->replace('_', ' ')->title()->toString());
                                @endphp
                                @if($sellerItemsForTab->isNotEmpty() || $unmappedShipmentsForTab->isNotEmpty())
                            <section class="px-5 py-4" aria-label="Items from {{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}">
                                <header class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 pb-3">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <h3 class="text-sm font-bold text-stone-900">{{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h3>
                                        <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light lk-order-contact-link px-3 py-1.5 text-xs">Chat</button></form>
                                        @if($sellerOrder->sellerProfile?->id && \Illuminate\Support\Facades\Route::has('buyer.shop'))<a class="lk-btn lk-btn-light lk-order-contact-link px-3 py-1.5 text-xs" href="{{ route('buyer.shop', ['seller' => $sellerOrder->sellerProfile->id]) }}">View Shop</a>@endif
                                    </div>
                                    <span class="shrink-0 font-semibold uppercase tracking-wide text-[#7a2a22]" @if(! $parcelTabKey) data-seller-order-status-id="{{ $sellerOrder->id }}" @endif>{{ $sellerDisplayStatus }}</span>
                                </header>
                                @if($unmappedShipmentsForTab->isNotEmpty())
                                    <div class="mb-3 grid gap-2">
                                        @foreach($unmappedShipmentsForTab as $shipment)
                                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-stone-50 px-3 py-2" data-shipment-tab-key-id="{{ $shipment->id }}" data-shipment-tab-key="{{ $shipmentTabKeys->get((int) $shipment->id) }}">
                                                <span class="text-[11px] text-stone-600">Parcel {{ $sellerShipments->search(fn ($candidate) => (int) $candidate->id === (int) $shipment->id) + 1 }} · Tracking {{ $shipment->tracking_number }}</span>
                                                @include('buyer.partials.purchase-shipment-summary', ['shipment' => $shipment, 'status' => $sellerDisplayStatus, 'orderId' => $order->id, 'showOrderStatus' => false, 'fullStatus' => true])
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @if($sellerItemsNeedFallback)
                                    <p class="mb-3 text-xs text-stone-500">Parcel item links are unavailable, so all products from this seller order are shown.</p>
                                @endif
                                <ul class="divide-y divide-stone-100">
                                    @foreach($sellerItemsForTab as $item)
                                        @php
                                            $shipmentForItem = $sellerShipments->first(fn ($shipment) => (int) $shipment->order_item_id === (int) $item->id);
                                            $shipmentParcelNumber = $shipmentForItem ? $sellerShipments->search(fn ($shipment) => (int) $shipment->id === (int) $shipmentForItem->id) + 1 : null;
                                        @endphp
                                        <li class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0">
                                            @if($shipmentForItem)
                                                <div class="flex flex-col gap-1 rounded-lg border border-stone-100 bg-stone-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between" data-shipment-tab-key-id="{{ $shipmentForItem->id }}" data-shipment-tab-key="{{ $shipmentTabKeys->get((int) $shipmentForItem->id) }}">
                                                    <span class="text-[11px] font-semibold text-stone-700">Parcel {{ $shipmentParcelNumber }} <span aria-hidden="true">&#183;</span> Tracking <span class="break-all">{{ $shipmentForItem->tracking_number }}</span></span>
                                                    <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                                                        @include('buyer.partials.purchase-shipment-summary', ['shipment' => $shipmentForItem, 'status' => $sellerDisplayStatus, 'orderId' => $order->id, 'showOrderStatus' => false, 'fullStatus' => true])
                                                        @if(in_array($activeTabKey, ['all', 'to-receive'], true))
                                                            <form method="POST" action="{{ route('buyer.orders.shipments.received', [$order, $shipmentForItem]) }}" data-confirm-shipment-action="{{ $shipmentForItem->id }}" @if(! in_array((int) $shipmentForItem->id, $confirmableShipmentIds, true)) hidden @endif onsubmit="return window.confirm('Confirm receipt for Parcel {{ $shipmentParcelNumber }}? Only this parcel will move to Completed.');">
                                                                @csrf<input type="hidden" name="confirm_receipt" value="1">
                                                                <button class="lk-btn lk-btn-red px-3 py-1.5 text-xs" type="submit">Confirm received</button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif
                                            <div class="flex items-start gap-3">
                                                <x-shared.product-thumbnail :item="$item" size="56" class="rounded-lg border border-stone-100" />
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate font-medium text-stone-900">{{ $item->product_name }}</p>
                                                    <p class="mt-1 text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} <span aria-hidden="true">·</span> Qty {{ $item->quantity }}</p>
                                                @if($order->status === 'COMPLETED')
                                                    @if($item->review)
                                                        <p class="mt-2 text-[11px] font-semibold text-emerald-800">Quick review submitted @if($item->review->rating) <span class="ml-1 tracking-wide text-amber-500" aria-label="{{ $item->review->rating }} out of 5 stars">{{ str_repeat('★', (int) $item->review->rating) }}{{ str_repeat('☆', 5 - (int) $item->review->rating) }}</span>@endif</p>
                                                    @else
                                                        <form method="POST" action="{{ route('buyer.orders.review.store', $item) }}" class="mt-2 flex flex-wrap items-center gap-2" aria-label="Quick review for {{ $item->product_name }}">
                                                            @csrf
                                                            <span class="text-[11px] font-semibold text-stone-600">Quick review</span>
                                                            <div class="flex items-center gap-0.5" role="group" aria-label="Choose a product rating">
                                                                @foreach(range(1, 5) as $rating)
                                                                    <button type="submit" name="rating" value="{{ $rating }}" title="Rate {{ $rating }} out of 5 stars" aria-label="Rate {{ $item->product_name }} {{ $rating }} out of 5 stars" class="inline-flex h-7 w-7 items-center justify-center rounded-md text-lg leading-none text-amber-500 transition hover:bg-amber-100 hover:text-amber-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-red-800">&#9733;</button>
                                                                @endforeach
                                                            </div>
                                                        </form>
                                                    @endif
                                                @endif
                                                </div>
                                                <strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                                @endif
                        @endforeach
                    </div>
                    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-100 bg-stone-50/60 px-5 py-4">
                        <div><span class="block text-xs text-stone-500">{{ $unitCount }} {{ \Illuminate\Support\Str::plural('item', $unitCount) }} · {{ $orderUsesItemFallback ? 'Items shown total' : ($parcelTabKey ? 'Parcel total' : 'Total') }}</span><strong class="text-lg font-bold text-stone-900">&#8369;{{ number_format($parcelTabKey ? $orderDisplayTotal : (float) $order->grand_total, 2) }}</strong></div>
                        <div class="lk-order-card-actions">
                            @if($order->status === 'COMPLETED')<a class="lk-btn lk-btn-red" href="{{ route('buyer.orders.show', $order) }}#reviews">Rate</a>@endif
                            @if($actionState['can_cancel'] ?? false)
                                <details class="lk-order-cancel-control">
                                    <summary class="cursor-pointer text-sm font-semibold">Cancel Order</summary>
                                    <form method="POST" action="{{ route('buyer.orders.cancel', $order) }}" class="mt-3 grid gap-3" onsubmit="return window.confirm('Cancel this order? Eligible stock and voucher usage will be restored.');">
                                        @csrf
                                        <label class="grid gap-1 text-sm font-medium text-stone-800">Reason for cancellation
                                            <select name="reason" required class="rounded-lg border border-stone-300 bg-white p-3 text-sm" onchange="this.form.elements.reason_details.required = this.value === 'OTHER'">
                                                <option value="">Select a reason</option>
                                                <option value="CHANGED_MIND">Changed my mind</option>
                                                <option value="ORDERED_BY_MISTAKE">Ordered by mistake</option>
                                                <option value="DELIVERY_TIMING">Delivery timing no longer works</option>
                                                <option value="OTHER">Other</option>
                                            </select>
                                        </label>
                                        <label class="grid gap-1 text-sm font-medium text-stone-800">Additional details <span class="text-xs font-normal text-stone-500">Required when you select Other.</span>
                                            <textarea name="reason_details" maxlength="480" rows="2" class="rounded-lg border border-stone-300 p-3 text-sm" placeholder="Add a short explanation" oninput="this.required = this.form.elements.reason.value === 'OTHER'"></textarea>
                                        </label>
                                        <button class="lk-btn lk-btn-light justify-self-start" type="submit">Confirm cancellation</button>
                                    </form>
                                </details>
                            @endif
                            @if($actionState['can_request_return'] ?? false)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.return-refund.form', $order) }}">Return / Refund</a>@endif
                            @if($returnRequest)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders', ['status' => 'RETURNS']) }}">View Return/Refund Request &#183; {{ str($returnRequest->status)->headline() }}</a>@endif
                            @if($actionState['can_confirm_received'])
                                <div>
                                    @if(count($confirmableShipmentIds) > 1)
                                        <form method="POST" action="{{ route('buyer.orders.received', $order) }}" onsubmit="if (!window.confirm('Confirm that you received every parcel in this order? This will complete the order and enable product reviews.')) return false; this.elements.confirm_receipt.value = '1'; return true;">
                                            @csrf<input type="hidden" name="confirm_receipt" value="0">
                                            <button class="lk-btn lk-btn-red" type="submit">Order Received</button>
                                        </form>
                                    @endif
                                    @if($actionState['auto_confirm_at'] ?? null)
                                        <p class="mt-2 max-w-xs text-xs text-stone-600">Automatic receipt on {{ $actionState['auto_confirm_at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>
                                    @endif
                                </div>
                            @endif
                            <a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.show', $order) }}">View Details</a>
                        </div>
                    </footer>
                </article>
                @endif
            @empty
                <x-buyer.empty-state title="No orders found" message="Your matching orders will appear here." />
            @endforelse
        </div>
        @if(isset($orders) && method_exists($orders, 'links'))<div class="mt-5">{{ $orders->links() }}</div>@endif
    @endif
</div>
@endsection
