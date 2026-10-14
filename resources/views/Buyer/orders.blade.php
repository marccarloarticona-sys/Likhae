@extends('layouts.buyer')
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
</style>
@php
    $mode = $mode ?? 'index';
    $orderCollection = isset($orders) && method_exists($orders, 'getCollection') ? $orders->getCollection() : collect($orders ?? []);
    $filter = strtoupper((string) request('status', ''));
    $returnRefundReady = $returnRefundReady ?? false;
    $orderActionStates = $orderActionStates ?? collect();
    $selectedOrderActionState = $selectedOrderActionState ?? [];
    $visibleOrders = in_array($filter, ['', 'ALL', 'RETURNS', 'DELIVERED'], true) ? $orderCollection : $orderCollection->where('status', $filter);
    $liveOrders = $mode === 'show' && isset($selectedOrder) ? collect([$selectedOrder]) : $orderCollection;
    $liveOrderIds = $liveOrders->pluck('id')->map(fn ($id) => (int) $id)->values();
    $liveShipmentIds = $liveOrders->flatMap(fn ($order) => $order->sellerOrders->pluck('shipment')->filter()->pluck('id'))->map(fn ($id) => (int) $id)->unique()->values();
    $liveOrderVersion = sha1($liveOrders->sortBy('id')->map(fn ($order) => [
        'id' => (int) $order->id,
        'status' => (string) $order->status,
        'updated_at' => $order->updated_at?->toIso8601String(),
        'shipments' => $order->sellerOrders->map(fn ($sellerOrder) => $sellerOrder->shipment ? [
            'id' => (int) $sellerOrder->shipment->id,
            'status' => (string) $sellerOrder->shipment->current_status,
            'updated_at' => $sellerOrder->shipment->updated_at?->toIso8601String(),
        ] : null)->filter()->sortBy('id')->values()->all(),
    ])->values()->toJson());
@endphp

<div class="lk-page-narrow lk-order-page" data-buyer-order-live data-order-stream-url="{{ route('buyer.orders.stream') }}" data-order-ids="{{ $liveOrderIds->toJson() }}" data-shipment-ids="{{ $liveShipmentIds->toJson() }}" data-order-version="{{ $liveOrderVersion }}">
    @if(session('buyer_notice'))<div class="mb-4 rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('buyer_notice') }}</div>@endif

    @if($mode === 'success')
        <section class="rounded-2xl border border-stone-200 bg-white p-8 text-center shadow-sm"><div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-red-50 text-3xl text-red-900">✓</div><span class="mt-5 block text-[10px] font-bold uppercase tracking-widest text-red-800">Order placed</span><h1 class="mt-2 text-2xl font-bold text-stone-950">Thank you for your order</h1><p class="mt-2 text-sm text-stone-500">{{ $selectedOrder?->order_number ? 'Order '.$selectedOrder->order_number.' was placed successfully.' : 'Your order was placed successfully.' }}</p><div class="mt-6 flex justify-center gap-2"><a class="lk-btn lk-btn-red" href="{{ route('buyer.orders') }}">View My Orders</a><a class="lk-btn lk-btn-light" href="{{ route('buyer.products') }}">Continue Shopping</a></div></section>
    @elseif($mode === 'show' && $selectedOrder)
        @php
            $detailDelivered = $selectedOrder->sellerOrders->isNotEmpty() && $selectedOrder->sellerOrders->every(fn ($sellerOrder) => in_array($sellerOrder->shipment?->current_status, ['DELIVERED', 'COMPLETED'], true));
            $shippingEvents = $selectedOrder->sellerOrders->flatMap(fn ($sellerOrder) => $sellerOrder->shipment?->events ?? collect())->sortBy('occurred_at');
            $automaticReceiptEvent = $shippingEvents->first(fn ($event) => $event->status === 'COMPLETED' && str_starts_with((string) $event->notes, 'Order automatically confirmed received after 3 days'));
            $deliveryAssignment = $selectedOrder->sellerOrders->pluck('shipment')->filter()->flatMap->riderAssignments->where('assignment_type', 'DELIVERY')->sortByDesc('id')->first();
            $payment = $selectedOrder->payments->first();
        @endphp
        <div class="lk-page-title"><div><span class="lk-kicker">Order details</span><h1>{{ $selectedOrder->order_number }}</h1><p>{{ $detailDelivered && $selectedOrder->status !== 'COMPLETED' ? 'Delivered — please confirm receipt' : str($selectedOrder->status)->headline() }}</p></div><div class="flex flex-wrap gap-2"><a class="lk-btn lk-btn-light" href="{{ route('buyer.orders') }}">Back to Orders</a></div></div>
        <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-bold text-stone-900">Shipping information</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div><p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Current status</p><div class="mt-2 flex flex-wrap gap-2" data-order-detail-status>@foreach($selectedOrder->sellerOrders as $sellerOrder)@if($sellerOrder->shipment)<span class="inline-flex rounded-full border border-red-100 bg-red-50 px-3 py-1 text-xs font-bold text-red-900">{{ str($sellerOrder->shipment->current_status)->headline() }}</span>@endif @endforeach</div>@if($deliveryAssignment)<p class="mt-2 text-sm text-stone-600">Rider: {{ $deliveryAssignment->riderProfile?->user?->name ?? 'Assigned rider' }}</p>@endif</div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Delivery information</p><strong class="mt-1 block">{{ $selectedOrder->address?->recipient_name }}</strong><p class="text-sm text-stone-600">{{ $selectedOrder->address?->contact_number }}<br>{{ $selectedOrder->address?->formatted() }}</p></div>
            </div>
            @php
                $orderShipmentStatuses = $selectedOrder->sellerOrders->pluck('shipment.current_status')->filter()->unique()->values();
                $overallStatus = $orderShipmentStatuses->count() === 1 ? $orderShipmentStatuses->first() : null;
            $shippingMessage = $automaticReceiptEvent
                ? 'Your order was automatically marked as received after 3 days.'
                : ($detailDelivered && $selectedOrder->status !== 'COMPLETED'
                    ? 'Your order has been delivered.'
                    : match ($overallStatus) {
                        'PLACED' => 'Your order has been placed.',
                        'CONFIRMED' => 'The seller confirmed your order.',
                        'PREPARING' => 'The seller is preparing your parcel.',
                        'READY_FOR_PICKUP' => 'Your parcel is ready for rider pickup.',
                        'PICKED_UP' => 'Your parcel was collected from the seller.',
                        'AT_SORTING_CENTER' => 'Your parcel arrived at the sorting center.',
                        'SORTED' => 'Your parcel has been sorted by destination.',
                        'ASSIGNED_TO_RIDER' => 'A delivery rider has been assigned.',
                        'OUT_FOR_DELIVERY' => 'Your parcel is on the way.',
                        'DELIVERED' => 'Your parcel was delivered successfully.',
                        'COMPLETED' => 'You confirmed receipt of your order. Thank you for shopping with LIKHAE.',
                        'DELIVERY_FAILED' => 'The delivery attempt was unsuccessful.',
                        'RETURNED' => 'The parcel was returned.',
                        default => $orderShipmentStatuses->isNotEmpty() ? 'Each seller shipment has its own status. See the parcel timelines below for updates.' : 'Delivery updates will appear here.',
                    });
            @endphp
            <p class="mt-4 rounded-xl bg-[#fcf7f2] px-4 py-3 text-sm text-stone-700">{{ $shippingMessage }}</p>
            @if($automaticReceiptEvent)
                <p class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">Automatically confirmed received on {{ ($selectedOrder->completed_at ?? $automaticReceiptEvent->occurred_at)->timezone('Asia/Manila')->format('M j, Y g:i A') }}.</p>
            @endif
        </section>
        @if($deliveryPinRequired)
            <section class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm" aria-labelledby="delivery-pin-required-heading">
                <h2 id="delivery-pin-required-heading" class="text-lg font-bold text-amber-950">Delivery pin required</h2>
                <p class="mt-2 text-sm text-amber-950">Confirm the exact delivery location for this existing order so the assigned rider can navigate to you. The address text below stays unchanged; only this order’s missing map pin will be saved.</p>
                <p class="mt-3 rounded-xl border border-amber-200 bg-white/80 px-4 py-3 text-sm text-stone-800"><strong>Saved order address:</strong> {{ $selectedOrder->address?->formatted() }}</p>
                <h3 class="mt-4 text-base font-bold text-stone-900">Live Delivery Map · Confirm Delivery Pin</h3>
                <p class="mt-3 text-sm font-semibold text-stone-800">Tap the exact location on the map. Zoom in before selecting the house or handoff point.</p>
                <x-shared.mapbox :markers="[]" title="Confirm exact delivery location" height="320px" :allow-user-location="false" pin-form-id="delivery-pin-form" :zoom="8" :live-tracking="$selectedOrder->sellerOrders->contains(fn ($sellerOrder) => $sellerOrder->shipment?->current_status === 'OUT_FOR_DELIVERY')" />
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
                <x-shared.mapbox :markers="$mapMarkers" title="Live delivery locations for this order" height="280px" :allow-user-location="false" :live-tracking="$selectedOrder->sellerOrders->contains(fn ($sellerOrder) => $sellerOrder->shipment?->current_status === 'OUT_FOR_DELIVERY')" />
                <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-stone-600" aria-label="Map marker legend"><li>● Buyer destination</li><li>● Seller / sorting center</li><li>● Rider location when available</li></ul>
            </section>
        @endif
        @foreach($selectedOrder->sellerOrders as $sellerOrder)
            @php
                $shipment = $sellerOrder->shipment;
                $shipmentStatus = $shipment?->current_status;
                $shipmentEvents = $shipment?->events ?? collect();
                $latestEventByStatus = $shipmentEvents->sortBy('occurred_at')->keyBy('status');
                $timelineSteps = [
                    'PLACED' => 'Order placed',
                    'CONFIRMED' => 'Seller confirmed order',
                    'PREPARING' => 'Preparing parcel',
                    'READY_FOR_PICKUP' => 'Ready for pickup',
                    'PICKED_UP' => 'Picked up from seller',
                    'AT_SORTING_CENTER' => 'Arrived at sorting center',
                    'SORTED' => 'Sorted by destination',
                    'ASSIGNED_TO_RIDER' => 'Assigned to delivery rider',
                    'OUT_FOR_DELIVERY' => 'Out for delivery',
                ];
                foreach (['DELIVERY_FAILED', 'RETURNED'] as $exceptionStatus) {
                    if ($shipmentEvents->contains('status', $exceptionStatus) || $shipmentStatus === $exceptionStatus) {
                        $timelineSteps[$exceptionStatus] = $exceptionStatus === 'DELIVERY_FAILED' ? 'Delivery failed' : 'Returned';
                    }
                }
                $timelineSteps['DELIVERED'] = 'Delivered';
                $completedShipmentEvent = $shipmentEvents->where('status', 'COMPLETED')->sortBy('occurred_at')->last();
                $timelineSteps['COMPLETED'] = str_starts_with((string) $completedShipmentEvent?->notes, 'Order automatically confirmed received after 3 days')
                    ? 'Automatically confirmed received'
                    : 'Buyer confirmed receipt';
                $deliveryProof = in_array($shipmentStatus, ['DELIVERED', 'COMPLETED'], true)
                    ? $shipment?->deliveryAttempts
                        ?->where('status', 'DELIVERED')
                        ->filter(fn ($attempt) => filled($attempt->proof_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($attempt->proof_path))
                        ->sortByDesc('attempted_at')
                        ->first()
                    : null;
                $shipmentMessage = match ($shipmentStatus) {
                    'PLACED' => 'Your order has been placed.',
                    'CONFIRMED' => 'The seller confirmed your order.',
                    'PREPARING' => 'The seller is preparing your parcel.',
                    'READY_FOR_PICKUP' => 'Your parcel is ready for rider pickup.',
                    'PICKED_UP' => 'Your parcel was collected from the seller.',
                    'AT_SORTING_CENTER' => 'Your parcel arrived at the sorting center.',
                    'SORTED' => 'Your parcel has been sorted by destination.',
                    'ASSIGNED_TO_RIDER' => 'A delivery rider has been assigned.',
                    'OUT_FOR_DELIVERY' => 'Your parcel is on the way.',
                    'DELIVERED' => 'Your parcel was delivered successfully.',
                    'COMPLETED' => 'Order completed. Thank you for shopping with LIKHAE.',
                    'DELIVERY_FAILED' => 'The delivery attempt was unsuccessful.',
                    'RETURNED' => 'The parcel was returned.',
                    default => 'Delivery updates will appear here.',
                };
            @endphp
            <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm" aria-labelledby="shipment-{{ $shipment?->id ?? $sellerOrder->id }}-heading">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><span class="text-xs font-semibold uppercase tracking-wide text-stone-500">Store</span><h2 id="shipment-{{ $shipment?->id ?? $sellerOrder->id }}-heading" class="text-lg font-bold text-stone-900">{{ $sellerOrder->sellerProfile?->business_name }}</h2><p class="mt-1 text-sm text-stone-600">{{ $sellerOrder->seller_order_number }}</p></div><form method="POST" action="{{ route('buyer.orders.seller-conversation', [$selectedOrder, $sellerOrder]) }}">@csrf<button type="submit" class="text-sm font-semibold text-red-900">Contact Seller</button></form></div>
                @if($shipment)
                    <div class="mt-4 flex flex-wrap items-center gap-2"><span class="rounded-full border border-red-100 bg-red-50 px-3 py-1 text-xs font-bold text-red-900">{{ str($shipmentStatus)->headline() }}</span><span class="text-xs text-stone-500">Tracking: <strong class="text-stone-700">{{ $shipment->tracking_number }}</strong></span></div>
                    <p class="mt-3 rounded-xl bg-[#fcf7f2] px-4 py-3 text-sm text-stone-700">{{ $shipmentMessage }}</p>
                    <div class="mt-5 rounded-xl border border-stone-100 p-4"><h3 class="mb-4 font-bold text-stone-900">Tracking Timeline</h3><ol class="lk-shipment-timeline" aria-label="Tracking timeline for {{ $shipment->tracking_number }}">
                        @foreach($timelineSteps as $stepStatus => $stepLabel)
                            @php
                                $stepEvent = $latestEventByStatus->get($stepStatus);
                                $stepIsCurrent = $shipmentStatus === $stepStatus;
                                $stepIsComplete = $stepEvent !== null || $stepIsCurrent;
                                $failureAttempts = $shipment->deliveryAttempts?->whereIn('status', ['FAILED', 'RESCHEDULED', 'RETURNED']) ?? collect();
                                $stepDetail = $stepStatus === 'DELIVERY_FAILED'
                                    ? ($stepEvent?->notes ?: $failureAttempts->sortByDesc('attempted_at')->first()?->failure_reason)
                                    : null;
                            @endphp
                            <li class="lk-shipment-step {{ $stepIsCurrent ? 'is-current' : ($stepIsComplete ? 'is-complete' : '') }}" @if($stepIsCurrent) aria-current="step" @endif>
                                <span class="lk-shipment-step__date">@if($stepEvent?->occurred_at)<time datetime="{{ $stepEvent->occurred_at->toIso8601String() }}">{{ $stepEvent->occurred_at->format('M j, Y') }}<span class="block">{{ $stepEvent->occurred_at->format('g:i A') }}</span></time>@else Pending @endif</span>
                                <span class="lk-shipment-step__dot" aria-hidden="true">{{ $stepIsComplete ? '✓' : '' }}</span>
                                <span class="lk-shipment-step__label">{{ $stepLabel }}@if($stepDetail)<span class="mt-1 block text-xs font-normal text-stone-500">{{ $stepDetail }}</span>@endif</span>
                            </li>
                        @endforeach
                    </ol></div>
                    @if(filled($sellerOrder->buyer_note))
                        <div class="mt-4 rounded-xl border border-stone-100 bg-[#fcf8f4] p-4" data-seller-note><h3 class="text-xs font-semibold uppercase tracking-wide text-stone-600">Note to Seller</h3><p class="mt-2 whitespace-pre-line text-sm text-stone-800">{{ $sellerOrder->buyer_note }}</p></div>
                    @endif
                    @if($deliveryProof)
                        @php $proofUrl = route('delivery-events.proof', $deliveryProof); @endphp
                        <section class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50/50 p-4" aria-labelledby="proof-{{ $shipment->id }}-heading" data-proof-of-delivery>
                            <div class="flex items-start gap-3"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-100 font-bold text-emerald-800" aria-hidden="true">✓</span><div class="min-w-0 flex-1"><h3 id="proof-{{ $shipment->id }}-heading" class="font-bold text-stone-900">Proof of Delivery</h3><p class="mt-1 text-sm text-stone-600">Your parcel was successfully delivered.</p></div></div>
                            <dl class="mt-4 grid gap-2 text-sm">
                                <div class="flex flex-wrap gap-x-2"><dt class="font-semibold text-stone-600">Delivered:</dt><dd class="text-stone-800">@if($deliveryProof->attempted_at)<time datetime="{{ $deliveryProof->attempted_at->toIso8601String() }}">{{ $deliveryProof->attempted_at->timezone('Asia/Manila')->format('M j, Y') }} · {{ $deliveryProof->attempted_at->timezone('Asia/Manila')->format('g:i A') }}</time>@else Successfully @endif</dd></div>
                                <div class="flex flex-wrap gap-x-2"><dt class="font-semibold text-stone-600">Tracking:</dt><dd class="break-all text-stone-800">{{ $shipment->tracking_number }}</dd></div>
                            </dl>
                            <a class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 sm:w-auto" href="{{ $proofUrl }}" target="_blank" rel="noopener noreferrer">View Full Photo</a>
                        </section>
                    @endif
                @endif
                <div class="mt-5 divide-y divide-stone-100">@foreach($sellerOrder->items as $item)<div class="flex min-w-0 items-center justify-between gap-4 py-3 text-sm"><div class="flex min-w-0 items-center gap-3"><x-product-thumbnail :item="$item" size="64"/><div class="min-w-0"><strong class="block break-words">{{ $item->product_name }}</strong><span class="block text-xs text-stone-500">{{ $item->variant_description ?: 'Standard' }} · Qty {{ $item->quantity }}</span></div></div><strong class="shrink-0">&#8369;{{ number_format((float) $item->line_total, 2) }}</strong></div>@endforeach</div><div class="mt-3 flex justify-between border-t border-stone-100 pt-3 text-sm"><span class="font-semibold">Seller order total</span><strong>&#8369;{{ number_format((float) $sellerOrder->grand_total, 2) }}</strong></div>
            </section>
        @endforeach
        @php
            $hasPickedUpParcel = $selectedOrder->sellerOrders->contains(fn ($sellerOrder) => in_array($sellerOrder->shipment?->current_status, ['PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED'], true));
            $actionState = $selectedOrderActionState;
            $returnRequest = $actionState['latest_request'] ?? null;
        @endphp
        <div class="lk-order-actions mt-5">
            @if(!$hasPickedUpParcel && in_array($selectedOrder->status, ['PLACED', 'PROCESSING'], true))<form method="POST" action="{{ route('buyer.orders.cancel', $selectedOrder) }}">@csrf<button class="lk-btn lk-btn-light" type="submit">Cancel Order</button></form>@endif
            @if($actionState['can_request_return'] ?? false)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.return-refund.form', $selectedOrder) }}">Return / Refund</a>@endif
            @if($returnRequest)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders', ['status' => 'RETURNS']) }}">View Return/Refund Request · {{ str($returnRequest->status)->headline() }}</a>@endif
            @if($actionState['can_confirm_received'])<div class="max-w-xl"><form method="POST" action="{{ route('buyer.orders.received', $selectedOrder) }}" onsubmit="if (!window.confirm('Confirm that you received every parcel in this order? This will complete the order and enable product reviews.')) return false; this.elements.confirm_receipt.value = '1'; return true;">@csrf<input type="hidden" name="confirm_receipt" value="0"><button class="lk-btn lk-btn-red" type="submit">Order Received</button></form>@if($actionState['auto_confirm_at'] ?? null)<p class="mt-2 text-sm text-stone-600">If you do not confirm receipt, this order will automatically be marked as received on {{ $actionState['auto_confirm_at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}.</p>@endif</div>@endif
        </div>
        <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-bold text-stone-900">Order and payment information</h2>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-stone-500">Order ID</dt><dd class="font-semibold">{{ $selectedOrder->order_number }}</dd></div><div><dt class="text-stone-500">Order total</dt><dd class="font-semibold">&#8369;{{ number_format((float) $selectedOrder->grand_total, 2) }}</dd></div><div><dt class="text-stone-500">Payment method</dt><dd class="font-semibold">{{ $payment?->method === 'COD' ? 'Cash on Delivery (COD)' : str($payment?->method ?? 'Pending')->headline() }}</dd></div><div><dt class="text-stone-500">Ordered</dt><dd class="font-semibold">{{ $selectedOrder->placed_at?->format('M j, Y g:i A') }}</dd></div><div><dt class="text-stone-500">Payment status / time</dt><dd class="font-semibold">@if($payment?->paid_at)Paid {{ $payment->paid_at->format('M j, Y g:i A') }}@elseif($payment?->method === 'COD') Collect on delivery · pending @else {{ $payment?->initiated_at?->format('M j, Y g:i A') ?? 'Pending' }} @endif</dd></div><div><dt class="text-stone-500">Shipped / picked up</dt><dd class="font-semibold">{{ $shippingEvents->firstWhere('status', 'PICKED_UP')?->occurred_at?->format('M j, Y g:i A') ?? 'Pending' }}</dd></div><div><dt class="text-stone-500">Completed</dt><dd class="font-semibold">{{ $selectedOrder->completed_at?->format('M j, Y g:i A') ?? 'Pending confirmation' }}</dd></div></dl>
        </section>
        <section class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach($selectedOrder->sellerOrders as $sellerOrder)
                <form method="POST" action="{{ route('buyer.orders.seller-conversation', [$selectedOrder, $sellerOrder]) }}">@csrf<button type="submit" class="lk-btn lk-btn-light w-full justify-center">{{ $selectedOrder->sellerOrders->count() === 1 ? 'Contact Seller' : 'Contact '.$sellerOrder->sellerProfile?->business_name }}</button></form>
            @endforeach
            <form method="POST" action="{{ route('buyer.orders.support-conversation', $selectedOrder) }}">@csrf<button type="submit" class="lk-btn lk-btn-light w-full justify-center">Help Center / Admin Support</button></form>
        </section>
        @if($selectedOrder->status === 'COMPLETED')
            <section id="reviews" class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-stone-900">Rate and review your order</h2>
                <p class="mt-1 text-sm text-stone-500">Ratings and photos lock after your first submission. Review text can still be edited.</p>
                @if($errors->any())<p class="mt-2 text-sm font-medium text-red-700">{{ $errors->first() }}</p>@endif
                <div class="mt-4 space-y-4">
                    @foreach($selectedOrder->sellerOrders->flatMap->items as $item)
                        @php
                            $deliveryAssignment = $item->sellerOrder->shipment?->riderAssignments->where('assignment_type', 'DELIVERY')->sortByDesc('id')->first();
                        @endphp
                        <form method="POST" enctype="multipart/form-data" action="{{ route('buyer.orders.review.store', $item) }}" class="grid gap-3 rounded-xl border border-stone-200 p-4">
                            @csrf
                            <div class="flex items-center gap-3">
                                <x-product-thumbnail :item="$item" size="56"/>
                                <div>
                                    <strong class="block">{{ $item->product_name }}</strong>
                                    <small class="text-stone-500">{{ $item->variant_description ?: 'Standard' }} · Qty {{ $item->quantity }}</small>
                                </div>
                            </div>

                            <fieldset>
                                <legend class="text-sm font-semibold">Product rating</legend>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach(range(1, 5) as $rating)
                                        <label class="cursor-pointer">
                                            <input class="peer sr-only" type="radio" name="rating" value="{{ $rating }}" @checked((int) $item->review?->rating === $rating) @disabled($item->review)>
                                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-stone-300 text-lg text-yellow-500 hover:border-yellow-400 hover:bg-yellow-50 peer-checked:border-yellow-500 peer-checked:bg-yellow-400 peer-checked:text-yellow-950">★</span>
                                            <span class="sr-only">{{ $rating }} product stars</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            @if($item->sellerOrder->items->first()?->id === $item->id && $deliveryAssignment)
                                <fieldset>
                                    <legend class="text-sm font-semibold">Delivery rider rating <span class="font-normal text-stone-500">{{ $deliveryAssignment->riderProfile?->user?->name }}</span></legend>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach(range(1, 5) as $rating)
                                            <label class="cursor-pointer">
                                                <input class="peer sr-only" type="radio" name="rider_rating" value="{{ $rating }}" @checked(($riderRatingsByShipment[$item->sellerOrder->shipment->id] ?? null) === $rating) @disabled($item->review)>
                                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-stone-300 text-lg text-yellow-500 hover:border-yellow-400 hover:bg-yellow-50 peer-checked:border-yellow-500 peer-checked:bg-yellow-400 peer-checked:text-yellow-950">★</span>
                                                <span class="sr-only">{{ $rating }} rider stars</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <label class="grid gap-1 text-sm font-medium">Rider review (optional)
                                    <textarea name="rider_comment" maxlength="2000" rows="2" class="rounded-lg border border-stone-300 p-3 text-sm" placeholder="Comment on the delivery experience">{{ $item->review?->rider_comment }}</textarea>
                                </label>
                            @endif

                            @if(isset($reviewImagesById[$item->review?->id]))
                                <img src="{{ $reviewImagesById[$item->review->id] }}" alt="Current review photo" class="h-24 w-24 rounded-lg object-cover">
                            @endif

                            <label class="grid gap-1 text-sm font-medium">Add a product photo
                                <span class="text-xs font-normal text-stone-500">JPG, PNG, or WebP, up to 5 MB</span>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2" @disabled($item->review)>
                            </label>
                            <textarea name="comment" maxlength="2000" rows="3" class="rounded-lg border border-stone-300 p-3 text-sm" placeholder="Write a product review (optional)">{{ $item->review?->comment }}</textarea>
                            <button class="lk-btn lk-btn-red justify-self-start" type="submit">{{ $item->review ? 'Update Review' : 'Submit Rating' }}</button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <div class="lk-page-title"><div><span class="lk-kicker">Order Center</span><h1>My Orders</h1><p>Track fulfillment and confirm receipt after delivery.</p></div><div class="flex flex-wrap gap-2"><a class="lk-btn lk-btn-light" href="{{ route('buyer.products') }}">Shop Products</a></div></div>
        @php
            $orderTabs = ['' => 'All', 'PLACED' => 'Placed', 'PROCESSING' => 'Processing', 'DELIVERED' => 'Delivered', 'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled', 'RETURNS' => 'Returns & Refunds'];
        @endphp
        <nav class="lk-order-status-tabs mt-4" aria-label="Order status filters">
            @foreach($orderTabs as $key => $label)
                @php
                    $isActive = $filter === $key;
                    $countKey = $key === '' ? 'ALL' : $key;
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
                    $displayStatus = $order->status === 'PROCESSING' && $order->sellerOrders->isNotEmpty() && $order->sellerOrders->every(fn ($sellerOrder) => in_array($sellerOrder->shipment?->current_status, ['DELIVERED', 'COMPLETED'], true)) ? 'DELIVERED' : $order->status;
                    $previewItem = $order->sellerOrders->flatMap->items->first();
                    $itemCount = $order->sellerOrders->sum(fn ($sellerOrder) => $sellerOrder->items->sum('quantity'));
                    $returnRequest = $returnRefundReady ? $order->returnRefundRequests->sortByDesc('submitted_at')->first() : null;
                    $actionState = $orderActionStates->get($order->id, []);
                @endphp
                <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm" data-order-id="{{ $order->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-4">
                            <x-product-thumbnail :item="$previewItem" size="72" />
                            <div class="min-w-0">
                                <span class="text-xs text-stone-500">{{ optional($order->placed_at)->format('M j, Y g:i A') }}</span>
                                <h2 class="mt-1 font-bold text-stone-900">{{ $order->order_number }}</h2>
                                @if($previewItem)<p class="mt-1 truncate text-sm text-stone-500">{{ $previewItem->product_name }}@if($itemCount > 1) <span aria-label="and {{ $itemCount - 1 }} more item{{ $itemCount === 2 ? '' : 's' }}">+ {{ $itemCount - 1 }} more</span>@endif</p>@endif
                            </div>
                        </div>
                        <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold" data-order-status-id="{{ $order->id }}">{{ $returnRequest ? 'Return / Refund · '.str($returnRequest->status)->headline() : str($displayStatus)->headline() }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-stone-100 pt-4">
                        <div><span class="block text-xs text-stone-500">{{ $itemCount }} item(s)</span><strong>&#8369;{{ number_format((float) $order->grand_total, 2) }}</strong></div>
                        <div class="lk-order-card-actions">
                            @if($actionState['can_request_return'] ?? false)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.return-refund.form', $order) }}">Return / Refund</a>@endif
                            @if($returnRequest)<a class="lk-btn lk-btn-light" href="{{ route('buyer.orders', ['status' => 'RETURNS']) }}">View Return/Refund Request · {{ str($returnRequest->status)->headline() }}</a>@endif
                            @if($actionState['can_confirm_received'])<div><form method="POST" action="{{ route('buyer.orders.received', $order) }}" onsubmit="if (!window.confirm('Confirm that you received every parcel in this order? This will complete the order and enable product reviews.')) return false; this.elements.confirm_receipt.value = '1'; return true;">@csrf<input type="hidden" name="confirm_receipt" value="0"><button class="lk-btn lk-btn-red" type="submit">Order Received</button></form>@if($actionState['auto_confirm_at'] ?? null)<p class="mt-2 max-w-xs text-xs text-stone-600">Automatic receipt on {{ $actionState['auto_confirm_at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>@endif</div>@endif
                            <a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.show', $order) }}">View Details</a>
                        </div>
                    </div>
                </article>
            @empty
                <x-buyer.empty-state title="No orders found" message="Your matching orders will appear here." />
            @endforelse
        </div>
        @if(isset($orders) && method_exists($orders, 'links'))<div class="mt-5">{{ $orders->links() }}</div>@endif
    @endif
</div>
@endsection
