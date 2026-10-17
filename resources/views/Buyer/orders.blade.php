@extends('layouts.buyer')
@section('title', 'My Purchases')
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
.lk-order-progress{display:grid;grid-template-columns:repeat(5,minmax(124px,1fr));min-width:650px}.lk-order-progress li{position:relative;z-index:0;display:flex;min-width:0;flex-direction:column;align-items:center;text-align:center}.lk-order-progress li:not(:last-child)::after{position:absolute;z-index:-1;top:17px;left:50%;width:100%;height:2px;background:#eadbce;content:""}.lk-order-progress li.is-complete:not(:last-child)::after{background:#561c17}.lk-order-progress__node{display:grid;width:36px;height:36px;place-items:center;border:2px solid #d8c8ba;border-radius:999px;background:#fffdf9;color:#806356;font-size:13px;font-weight:800}.lk-order-progress li.is-complete .lk-order-progress__node{border-color:#561c17;background:#561c17;color:#fff}.lk-order-progress li.is-current .lk-order-progress__node{border:3px solid #561c17;background:#fffdf9;color:#561c17}.lk-order-progress__label{margin-top:9px;color:#806356;font-size:11px;font-weight:600;line-height:1.3}.lk-order-progress li.is-complete .lk-order-progress__label{color:#37231d}.lk-order-progress li.is-current .lk-order-progress__label{color:#561c17;font-weight:800}
</style>
@php
    $mode = $mode ?? 'index';
    $orderCollection = isset($orders) && method_exists($orders, 'getCollection') ? $orders->getCollection() : collect($orders ?? []);
    $filter = $filter ?? strtoupper(str_replace([' ', '-', '/'], '_', (string) request('status', 'ALL')));
    $returnRefundReady = $returnRefundReady ?? false;
    $orderActionStates = $orderActionStates ?? collect();
    $orderTabKeys = $orderTabKeys ?? collect();
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

<div class="lk-page-narrow lk-order-page" data-buyer-order-live data-order-stream-url="{{ route('buyer.orders.stream') }}" data-order-ids="{{ $liveOrderIds->toJson() }}" data-shipment-ids="{{ $liveShipmentIds->toJson() }}" data-order-version="{{ $liveOrderVersion }}" data-order-detail-id="{{ $mode === 'show' ? (int) ($selectedOrder?->id ?? 0) : '' }}">
    @if(session('buyer_notice'))<div class="mb-4 rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('buyer_notice') }}</div>@endif

    @if($mode === 'success')
        <section class="rounded-2xl border border-stone-200 bg-white p-8 text-center shadow-sm"><div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-red-50 text-3xl text-red-900">&#10003;</div><span class="mt-5 block text-[10px] font-bold uppercase tracking-widest text-red-800">Order placed</span><h1 class="mt-2 text-2xl font-bold text-stone-950">Thank you for your order</h1><p class="mt-2 text-sm text-stone-500">{{ $selectedOrder?->order_number ? 'Order '.$selectedOrder->order_number.' was placed successfully.' : 'Your order was placed successfully.' }}</p><div class="mt-6 flex justify-center gap-2"><a class="lk-btn lk-btn-red" href="{{ route('buyer.orders') }}">View My Purchases</a><a class="lk-btn lk-btn-light" href="{{ route('buyer.products') }}">Continue Shopping</a></div></section>
    @elseif($mode === 'show' && $selectedOrder)
        @php
            $allShipments = $selectedOrder->sellerOrders->flatMap->shipments;
            $isCancelledOrder = $selectedOrder->status === 'CANCELLED';
            $hasReturnRefundRequest = $selectedOrder->relationLoaded('returnRefundRequests') && $selectedOrder->returnRefundRequests->isNotEmpty();
            $isTerminalOrder = $isCancelledOrder || in_array($selectedOrder->status, ['RETURNED', 'COMPLETED'], true)
                || ($allShipments->isNotEmpty() && $allShipments->every(fn ($shipment) => in_array($shipment->current_status, ['RETURNED', 'CANCELLED', 'DELIVERED', 'COMPLETED'], true)));
            $detailDelivered = $selectedOrder->sellerOrders->isNotEmpty() && $selectedOrder->sellerOrders->every(fn ($sellerOrder) => $sellerOrder->shipments->isNotEmpty() && $sellerOrder->shipments->every(fn ($shipment) => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true)));
            $shippingEvents = $allShipments->flatMap->events->sortBy('occurred_at');
            $deliveryAssignment = $allShipments->flatMap->riderAssignments->where('assignment_type', 'DELIVERY')->sortByDesc('id')->first();
            $payment = $selectedOrder->payments->first();
            $shipmentStarted = $allShipments->contains(fn ($shipment) => in_array($shipment->current_status, ['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERY_FAILED', 'DELIVERED', 'COMPLETED', 'RETURNED'], true));
            $currentProgressIndex = $selectedOrder->status === 'COMPLETED' ? 4 : ($detailDelivered ? 3 : ($shipmentStarted ? 2 : ($payment ? 1 : 0)));
            $progressSteps = [
                ['label' => 'Order placed', 'date' => $selectedOrder->placed_at],
                ['label' => 'Payment info confirmed', 'date' => $payment?->initiated_at ?? $payment?->paid_at],
                ['label' => 'Order shipped out', 'date' => $shippingEvents->firstWhere('status', 'PICKED_UP')?->occurred_at],
                ['label' => 'To Receive', 'date' => $shippingEvents->firstWhere('status', 'DELIVERED')?->occurred_at],
                ['label' => 'To Rate', 'date' => $selectedOrder->completed_at],
            ];
        @endphp
        <div class="lk-page-title"><div><span class="lk-kicker">Order details</span><h1>{{ $selectedOrder->order_number }}</h1>@if($isCancelledOrder && $allShipments->isEmpty())<p>Cancelled · Placed {{ $selectedOrder->placed_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Date unavailable' }}</p>@elseif($isCancelledOrder)<p>Placed {{ $selectedOrder->placed_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Date unavailable' }}</p>@endif</div><div class="flex flex-wrap gap-2"><a class="lk-btn lk-btn-light" href="{{ route('buyer.orders') }}">Back to My Purchases</a></div></div>
        <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
            @unless($isCancelledOrder)
                <div class="mb-5 overflow-x-auto border-b border-stone-100 pb-5" aria-label="Order progress"><ol class="lk-order-progress">
                    @foreach($progressSteps as $progressIndex => $progressStep)
                        @php $stepIsCurrent = $progressIndex === $currentProgressIndex; $stepIsComplete = $progressIndex <= $currentProgressIndex; @endphp
                        <li class="{{ $stepIsCurrent ? 'is-current' : ($stepIsComplete ? 'is-complete' : '') }}" data-order-progress-step="{{ $progressStep['label'] }}" @if($stepIsCurrent) aria-current="step" @endif>
                            <span class="lk-order-progress__node" aria-hidden="true">{{ $stepIsComplete ? '&#10003;' : $progressIndex + 1 }}</span>
                            <span class="lk-order-progress__label">{{ $progressStep['label'] }}</span>
                            @if($progressStep['date'])<time class="mt-1 text-[10px] text-stone-500" datetime="{{ $progressStep['date']->toIso8601String() }}">{{ $progressStep['date']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</time>@endif
                        </li>
                    @endforeach
                </ol></div>
            @endunless
            @if(($selectedOrderActionState['can_confirm_received'] ?? false) || ($selectedOrderActionState['can_request_return'] ?? false) || $selectedOrder->sellerOrders->isNotEmpty())
                <div class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-stone-200 bg-stone-50/70 p-4">
                    <p class="min-w-0 flex-1 text-xs leading-5 text-stone-600" data-order-detail-message>{{ ($selectedOrderActionState['can_confirm_received'] ?? false) ? 'Check the delivered items before confirming receipt.' : ($selectedOrder->status === 'COMPLETED' ? 'Your order is complete. You can rate the products below.' : 'Review your delivery and order details.') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <div data-order-received-action @if(!($selectedOrderActionState['can_confirm_received'] ?? false)) hidden @endif><form method="POST" action="{{ route('buyer.orders.received', $selectedOrder) }}" onsubmit="if (!window.confirm('Confirm that you received every parcel in this order? This will complete the order and enable product reviews.')) return false; this.elements.confirm_receipt.value = '1'; return true;">@csrf<input type="hidden" name="confirm_receipt" value="0"><button class="lk-btn lk-btn-red" type="submit">Order Received</button></form><p class="mt-1 text-xs text-stone-500" data-auto-receipt-deadline @if(!($selectedOrderActionState['auto_confirm_at'] ?? null)) hidden @endif>@if($selectedOrderActionState['auto_confirm_at'] ?? null)Automatic receipt on {{ $selectedOrderActionState['auto_confirm_at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}@endif</p></div>
                        <a class="lk-btn lk-btn-light" data-order-return-action href="{{ route('buyer.orders.return-refund.form', $selectedOrder) }}" @if(!($selectedOrderActionState['can_request_return'] ?? false)) hidden @endif>Request Return / Refund</a>
                        @foreach($selectedOrder->sellerOrders as $sellerOrder)
                            <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$selectedOrder, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light">{{ $selectedOrder->sellerOrders->count() === 1 ? 'Contact Seller' : 'Contact '.$sellerOrder->sellerProfile?->business_name }}</button></form>
                        @endforeach
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
        @if($deliveryPinRequired && !$isTerminalOrder)
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
        @else
            <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm" aria-labelledby="buyer-live-map-heading">
                <div class="mb-4"><h2 id="buyer-live-map-heading" class="text-lg font-bold text-stone-900">Live Delivery Map</h2><p class="mt-1 text-sm text-stone-600">Saved seller, sorting center, and buyer locations are shown when available. Rider movement appears only during an active delivery phase.</p></div>
                <x-shared.mapbox :markers="$mapMarkers" title="Live delivery locations for this order" height="280px" :allow-user-location="false" :live-tracking="$allShipments->contains(fn ($shipment) => $shipment->current_status === 'OUT_FOR_DELIVERY')" />
                <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-stone-600" aria-label="Map marker legend"><li>&#9679; Buyer destination</li><li>&#9679; Seller / sorting center</li><li>&#9679; Rider location when available</li></ul>
            </section>
        @endif
        @foreach($selectedOrder->sellerOrders as $sellerOrder)
          @forelse($sellerOrder->shipments as $shipment)
            @php
                $shipmentStatus = $shipment->current_status;
                $isCancelledShipment = $isCancelledOrder || $sellerOrder->status === 'CANCELLED' || $shipmentStatus === 'CANCELLED';
                $shipmentTransferLeg = $shipment->transferLegs->firstWhere('status', 'IN_TRANSIT');
                $shipmentEvents = $shipment->events->sortBy('occurred_at')->values();
                $timelineLabels = [
                    'PLACED' => 'Order placed',
                    'CONFIRMED' => 'Seller confirmed order',
                    'PREPARING' => 'Preparing parcel',
                    'READY_FOR_PICKUP' => 'Ready for pickup',
                    'PICKED_UP' => 'Picked up from seller',
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
                        $timelineRows->push(['status' => 'CANCELLED', 'label' => 'Order cancelled', 'event' => $event]);
                        continue;
                    }

                    $eventLabel = $timelineLabels[$event->status] ?? null;
                    if ($event->status === 'COMPLETED') {
                        $eventLabel = str_starts_with((string) $event->notes, 'Order automatically confirmed received after 3 days')
                            ? 'Automatically confirmed receipt'
                            : 'Buyer confirmed receipt';
                    }
                    if ($eventLabel) {
                        $timelineRows->push(['status' => $event->status, 'label' => $eventLabel, 'event' => $event]);
                    }
                }
                if ($isCancelledShipment) {
                    $timelineRows = $timelineRows->filter(fn ($row) => in_array($row['status'], ['PLACED', 'CANCELLED'], true))->values();
                    if (!$timelineRows->contains(fn ($row) => $row['status'] === 'CANCELLED')) {
                        $timelineRows->push(['status' => 'CANCELLED', 'label' => 'Order cancelled', 'event' => null]);
                    }
                } elseif ($isTerminalOrder) {
                    $timelineRows = $timelineRows->filter(fn ($row) => in_array($row['status'], ['PLACED', $shipmentStatus, 'CANCELLED'], true))->values();
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
                $shipmentCardStatusLabel = $isCancelledShipment
                    ? 'Cancelled'
                    : ($shipmentStatus === 'RETURNED'
                        ? ($hasReturnRefundRequest ? 'Parcel returned to seller' : 'Returned')
                        : $shipmentStatusLabel);
                $shipmentCardMessage = $isCancelledShipment
                    ? 'This parcel was cancelled before pickup and was not handed to a rider.'
                    : null;
            @endphp
            <section class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm" aria-labelledby="shipment-{{ $shipment->id }}-heading">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><span class="text-xs font-semibold uppercase tracking-wide text-stone-500">Store</span><h2 id="shipment-{{ $shipment->id }}-heading" class="text-lg font-bold text-stone-900">{{ $sellerOrder->sellerProfile?->business_name }}</h2><p class="mt-1 text-sm text-stone-600">{{ $sellerOrder->seller_order_number }}</p></div></div>
                    <div class="mt-3 flex flex-wrap items-center gap-2"><span class="rounded-full border border-red-100 bg-red-50 px-3 py-1 text-xs font-bold text-red-900" data-shipment-card-status-id="{{ $shipment->id }}" data-shipment-returned-label="{{ $hasReturnRefundRequest ? 'Parcel returned to seller' : 'Returned' }}">{{ $shipmentCardStatusLabel }}</span>@unless($isTerminalOrder)<span class="text-xs text-stone-500">Tracking: <strong class="text-stone-700">{{ $shipment->tracking_number }}</strong></span>@endunless</div>
                    @if($shipmentCardMessage)<p class="mt-3 text-sm text-stone-700">{{ $shipmentCardMessage }}</p>@endif
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
                <div class="mt-5 divide-y divide-stone-100">@foreach(($shipment->orderItem ? collect([$shipment->orderItem]) : $sellerOrder->items) as $item)<div class="flex min-w-0 items-center justify-between gap-4 py-3 text-sm"><div class="flex min-w-0 items-center gap-3"><x-product-thumbnail :item="$item" size="64"/><div class="min-w-0"><strong class="block break-words">{{ $item->product_name }}</strong><span class="block text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }} &#183; Unit price &#8369;{{ number_format((float) $item->unit_price, 2) }}</span></div></div><strong class="shrink-0">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong></div>@endforeach</div>
            </section>
          @empty
            <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><strong>{{ $sellerOrder->sellerProfile?->business_name }}</strong><p class="mt-1 text-sm text-stone-600">Parcel tracking has not been assigned yet.</p><div class="mt-4 divide-y divide-stone-100">@foreach($sellerOrder->items as $item)<div class="flex min-w-0 items-center justify-between gap-4 py-3 text-sm"><div class="flex min-w-0 items-center gap-3"><x-product-thumbnail :item="$item" size="64"/><div class="min-w-0"><strong class="block break-words">{{ $item->product_name }}</strong><span class="block text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }} &#183; Unit price &#8369;{{ number_format((float) $item->unit_price, 2) }}</span></div></div><strong class="shrink-0">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong></div>@endforeach</div></section>
          @endforelse
            @if($selectedOrder->sellerOrders->count() > 1)
                <dl class="mx-3 mt-2 divide-y divide-stone-100 rounded-xl border border-stone-100 bg-stone-50/60 px-4 text-sm sm:mx-5">
                    <div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">{{ $sellerOrder->sellerProfile?->business_name ?: 'Shop' }} merchandise subtotal</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $sellerOrder->item_subtotal, 2) }}</dd></div>
                    <div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">Shipping fee</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $sellerOrder->shipping_fee, 2) }}</dd></div>
                    <div class="flex flex-wrap justify-between gap-3 py-2.5"><dt class="text-stone-600">Voucher discount</dt><dd class="font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $sellerOrder->voucher_discount, 2) }}</dd></div>
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
            $actionState = $selectedOrderActionState;
            $returnRequest = $actionState['latest_request'] ?? null;
        @endphp
        <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-2 border-b border-stone-100 pb-4">
                <div><h2 class="text-lg font-bold text-stone-900">Order Summary</h2><p class="mt-1 text-sm text-stone-500">{{ $selectedItemCount }} {{ $selectedItemCount === 1 ? 'item' : 'items' }} in this order</p></div>
            </div>
            <dl class="mt-1 divide-y divide-stone-100 text-sm">
                <div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Merchandise subtotal</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $selectedOrder->subtotal, 2) }}</dd></div>
                <div class="flex flex-wrap justify-between gap-3 py-3"><dt class="text-stone-600">Shipping fee</dt><dd class="font-semibold text-stone-900">&#8369;{{ number_format((float) $selectedOrder->shipping_total, 2) }}</dd></div>
                <div class="flex flex-wrap justify-between gap-3 py-3"><dt class="min-w-0 text-stone-600">Voucher discount @if($appliedVoucherGroups->isNotEmpty())<span class="mt-1 block text-xs text-stone-500">@foreach($appliedVoucherGroups as $voucherOrders)@php $appliedVoucher = $voucherOrders->first()->voucher; @endphp<span class="mr-2 inline-block">{{ $appliedVoucher->seller_profile_id ? ($voucherOrders->first()->sellerProfile?->business_name ?? 'Shop').' voucher' : 'LIKHAE Platform voucher' }}: {{ $appliedVoucher->code }}</span>@endforeach</span>@endif</dt><dd class="shrink-0 font-semibold text-stone-900">&#8722;&#8369;{{ number_format((float) $selectedOrder->discount_total, 2) }}</dd></div>
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
        <section class="mt-5 grid gap-3 sm:grid-cols-2">
            <form method="POST" action="{{ route('buyer.orders.support-conversation', $selectedOrder) }}">@csrf<button type="submit" class="lk-btn lk-btn-light w-full justify-center">Help Center / Admin Support</button></form>
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
                                <x-product-thumbnail :item="$item" size="56"/>
                                <div>
                                    <strong class="block">{{ $item->product_name }}</strong>
                                    <small class="text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }}</small>
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
                                        <img src="{{ $photoUrl }}" alt="Current review photo" class="h-24 w-24 rounded-lg object-cover">
                                    @endforeach
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
        <div class="lk-page-title"><div><span class="lk-kicker">Order Center</span><h1>My Purchases</h1><p>Track fulfillment and confirm receipt after delivery.</p></div><div class="flex flex-wrap gap-2"><a class="lk-btn lk-btn-light" href="{{ route('buyer.products') }}">Shop Products</a></div></div>
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
                    $displayTab = $orderTabKeys->get($order->id, $order->status);
                    $unitCount = $order->sellerOrders->sum(fn ($sellerOrder) => $sellerOrder->items->sum('quantity'));
                    $returnRequests = $returnRefundReady ? $order->returnRefundRequests->sortByDesc('submitted_at') : collect();
                    $returnRequest = $returnRequests->first();
                    $returnSellerRequests = $returnRequests->flatMap(fn ($requestRecord) => $requestRecord->sellerRequests);
                    $actionState = $orderActionStates->get($order->id, []);
                    $displayStatus = $order->status === 'PARTIALLY_CANCELLED' ? 'Partially cancelled' : (\App\Services\Marketplace\BuyerOrderTabService::LABELS[$displayTab] ?? str($order->status)->headline());
                @endphp
                @if($displayTab === 'RETURNS' && $returnSellerRequests->isNotEmpty())
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
                            <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
                                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h2 class="font-semibold text-stone-900">{{ $refundSellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h2>
                                            <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $refundSellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light px-3 py-1.5 text-xs">Chat</button></form>
                                            @if($refundSellerOrder->sellerProfile?->id && \Illuminate\Support\Facades\Route::has('buyer.shop'))<a class="lk-btn lk-btn-light px-3 py-1.5 text-xs" href="{{ route('buyer.shop', ['seller' => $refundSellerOrder->sellerProfile->id]) }}">View Shop</a>@endif
                                        </div>
                                        <p class="mt-1 text-xs text-stone-500">Order {{ $order->order_number }} &#183; Submitted {{ $sellerRequest->created_at?->format('M j, Y') }}</p>
                                    </div>
                                    <span class="rounded-full bg-stone-100 px-3 py-1.5 text-xs font-semibold text-stone-700">{{ $refundStatusLabel }}</span>
                                </header>
                                <ul class="divide-y divide-stone-100 px-5">
                                    @forelse($refundItems as $refundItem)
                                        @if($refundItem->orderItem)
                                            <li class="flex items-start gap-3 py-4">
                                                <x-product-thumbnail :item="$refundItem->orderItem" size="56" class="rounded-lg border border-stone-100" />
                                                <div class="min-w-0 flex-1"><p class="font-medium text-stone-900">{{ $refundItem->orderItem->product_name }}</p><p class="mt-1 text-xs text-stone-500">{{ $refundItem->orderItem->variant_description ?: 'Standard' }} &#183; Qty {{ $refundItem->quantity }}</p></div>
                                                <strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $refundItem->refundable_amount, 2) }}</strong>
                                            </li>
                                        @endif
                                    @empty
                                        @foreach($refundSellerOrder->items as $item)
                                            <li class="flex items-start gap-3 py-4"><x-product-thumbnail :item="$item" size="56" class="rounded-lg border border-stone-100" /><div class="min-w-0 flex-1"><p class="font-medium text-stone-900">{{ $item->product_name }}</p><p class="mt-1 text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }}</p></div><strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong></li>
                                        @endforeach
                                    @endforelse
                                </ul>
                                <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-100 bg-stone-50/60 px-5 py-4">
                                    <div><span class="text-xs text-stone-500">Total Refund</span><strong class="ml-3 text-lg font-bold text-stone-900">&#8369;{{ number_format($refundTotal, 2) }}</strong></div>
                                    <div class="lk-order-card-actions"><a class="lk-btn lk-btn-light" href="{{ route('buyer.returns.show', $sellerRequest) }}">View Refund Details</a><form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $refundSellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light">Contact Seller</button></form></div>
                                </footer>
                            </article>
                        @endif
                    @endforeach
                @elseif(in_array($displayTab, ['COMPLETED', 'CANCELLED'], true))
                    @foreach($order->sellerOrders as $sellerOrder)
                        @php
                            $sellerCancelled = $sellerOrder->status === 'CANCELLED';
                            $sellerShipments = $sellerOrder->shipments;
                            $sellerActiveShipment = $sellerShipments->first(fn ($shipment) => !in_array($shipment->current_status, ['DELIVERED', 'COMPLETED', 'CANCELLED', 'RETURNED'], true)) ?: $sellerShipments->first();
                            $sellerDelivered = $sellerShipments->isNotEmpty() && $sellerShipments->every(fn ($shipment) => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true));
                            $sellerDisplayStatus = $sellerCancelled ? 'Cancelled' : ($sellerDelivered ? ($order->status === 'COMPLETED' ? 'Completed' : 'To Receive') : str($sellerOrder->status)->replace('_', ' ')->title());
                            $sellerUnitCount = $sellerOrder->items->sum('quantity');
                        @endphp
                        <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-order-id="{{ $order->id }}">
                            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="font-semibold text-stone-900">{{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h2>
                                        <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light px-3 py-1.5 text-xs">Chat</button></form>
                                        @if($sellerOrder->sellerProfile?->id && \Illuminate\Support\Facades\Route::has('buyer.shop'))<a class="lk-btn lk-btn-light px-3 py-1.5 text-xs" href="{{ route('buyer.shop', ['seller' => $sellerOrder->sellerProfile->id]) }}">View Shop</a>@endif
                                    </div>
                                    <p class="mt-1 text-xs text-stone-500">Order {{ $order->order_number }} &#183; {{ optional($order->placed_at)->format('M j, Y') }}</p>
                                </div>
                                @include('Buyer.partials.purchase-shipment-summary', ['shipment' => $sellerActiveShipment, 'status' => $sellerDisplayStatus, 'orderId' => $order->id])
                            </header>
                            <ul class="divide-y divide-stone-100 px-5">
                                @foreach($sellerOrder->items as $item)
                                    <li class="flex items-start gap-3 py-4">
                                        <x-product-thumbnail :item="$item" size="56" class="rounded-lg border border-stone-100" />
                                        <div class="min-w-0 flex-1">
                                            <p class="font-medium text-stone-900">{{ $item->product_name }}</p>
                                            <p class="mt-1 text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} &#183; Qty {{ $item->quantity }}</p>
                                            @if($displayTab === 'COMPLETED')
                                                @if($item->review)<p class="mt-1 text-xs font-semibold text-stone-600">Quick review submitted @if($item->review->rating)<span class="ml-1 tracking-wide text-amber-500">{{ str_repeat('★', (int) $item->review->rating) }}{{ str_repeat('☆', 5 - (int) $item->review->rating) }}</span>@endif</p>
                                                @else<form method="POST" action="{{ route('buyer.orders.review.store', $item) }}" class="mt-1 flex flex-wrap items-center gap-1" aria-label="Quick review for {{ $item->product_name }}">@csrf<span class="mr-1 text-[11px] font-semibold text-stone-600">Quick review</span>@foreach(range(1, 5) as $rating)<button type="submit" name="rating" value="{{ $rating }}" title="Rate {{ $rating }} out of 5 stars" aria-label="Rate {{ $item->product_name }} {{ $rating }} out of 5 stars" class="inline-flex h-7 w-7 items-center justify-center rounded-md text-lg leading-none text-amber-500 transition hover:bg-amber-100 hover:text-amber-700">&#9733;</button>@endforeach</form>@endif
                                            @endif
                                        </div>
                                        <strong class="shrink-0 pt-1 text-sm font-semibold text-stone-900">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong>
                                    </li>
                                @endforeach
                            </ul>
                            <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-100 bg-stone-50/60 px-5 py-4">
                                <div><span class="block text-xs text-stone-500">{{ $sellerUnitCount }} {{ \Illuminate\Support\Str::plural('item', $sellerUnitCount) }} &#183; Order Total</span><strong class="text-lg font-bold text-stone-900">&#8369;{{ number_format((float) $sellerOrder->grand_total, 2) }}</strong></div>
                                <div class="lk-order-card-actions">
                                    @if($displayTab === 'COMPLETED')
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
                        </article>
                    @endforeach
                @else
                <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-order-id="{{ $order->id }}">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-5 py-4">
                        <div class="min-w-0">
                            <h2 class="font-semibold text-stone-900">Order {{ $order->order_number }}</h2>
                            <p class="mt-1 text-xs text-stone-500">{{ optional($order->placed_at)->format('M j, Y') }}</p>
                        </div>
                    </header>
                    <div class="divide-y divide-stone-100">
                        @foreach($order->sellerOrders as $sellerOrder)
                            <section class="px-5 py-4" aria-label="Items from {{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}">
                                @php
                                    $sellerShipments = $sellerOrder->shipments;
                                    $sellerActiveShipment = $sellerShipments->first(fn ($shipment) => !in_array($shipment->current_status, ['DELIVERED', 'COMPLETED', 'CANCELLED', 'RETURNED'], true)) ?: $sellerShipments->first();
                                @endphp
                                <header class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 pb-3">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <h3 class="text-sm font-bold text-stone-900">{{ $sellerOrder->sellerProfile?->business_name ?: 'Seller' }}</h3>
                                        <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$order, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light px-3 py-1.5 text-xs">Chat</button></form>
                                        @if($sellerOrder->sellerProfile?->id && \Illuminate\Support\Facades\Route::has('buyer.shop'))<a class="lk-btn lk-btn-light px-3 py-1.5 text-xs" href="{{ route('buyer.shop', ['seller' => $sellerOrder->sellerProfile->id]) }}">View Shop</a>@endif
                                    </div>
                                    @include('Buyer.partials.purchase-shipment-summary', ['shipment' => $sellerActiveShipment, 'status' => $displayStatus, 'orderId' => $order->id])
                                </header>
                                <ul class="divide-y divide-stone-100">
                                    @foreach($sellerOrder->items as $item)
                                        <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                            <x-product-thumbnail :item="$item" size="56" class="rounded-lg border border-stone-100" />
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
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </div>
                    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-100 bg-stone-50/60 px-5 py-4">
                        <div><span class="block text-xs text-stone-500">{{ $unitCount }} {{ \Illuminate\Support\Str::plural('item', $unitCount) }} · Total</span><strong class="text-lg font-bold text-stone-900">&#8369;{{ number_format((float) $order->grand_total, 2) }}</strong></div>
                        <div class="lk-order-card-actions">
                            @if($order->status === 'COMPLETED')<a class="lk-btn lk-btn-red" href="{{ route('buyer.orders.show', $order) }}#reviews">Rate</a>@endif
                            @if($actionState['can_cancel'] ?? false)
                                <details class="w-full max-w-xs rounded-xl border border-stone-200 bg-white p-3">
                                    <summary class="cursor-pointer text-sm font-semibold text-red-900">Cancel Order</summary>
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
                            @if($actionState['can_confirm_received'])<div><form method="POST" action="{{ route('buyer.orders.received', $order) }}" onsubmit="if (!window.confirm('Confirm that you received every parcel in this order? This will complete the order and enable product reviews.')) return false; this.elements.confirm_receipt.value = '1'; return true;">@csrf<input type="hidden" name="confirm_receipt" value="0"><button class="lk-btn lk-btn-red" type="submit">Order Received</button></form>@if($actionState['auto_confirm_at'] ?? null)<p class="mt-2 max-w-xs text-xs text-stone-600">Automatic receipt on {{ $actionState['auto_confirm_at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>@endif</div>@endif
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
