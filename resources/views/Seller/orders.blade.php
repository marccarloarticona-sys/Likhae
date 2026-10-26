@extends('layouts.seller.app')

@php
    $isLogistics = ($pageMode ?? 'orders') === 'logistics';
    $currentMode = $mode ?? ($isLogistics ? request('view', 'couriers') : request('mode', 'index'));
    $currentStatus = $status ?? request('status', 'all');
    $orderId = $selectedOrder ?? request('order');
    $order = $currentMode === 'show' ? ($selectedOrderView ?? null) : ($sellerOrders->firstWhere('id', (string) $orderId) ?? $sellerOrders->first());
    $delivery = data_get($order, 'delivery');
@endphp

@section('title', $isLogistics ? 'Logistics' : ($currentMode === 'show' && $order ? 'Order #'.$order['id'] : 'Orders'))
@section('active', $isLogistics ? 'logistics' : 'orders')
@section('subtitle', $isLogistics ? 'Review Seller pickup progress and parcel tracking.' : 'Review purchases and move each order through fulfillment.')

@section('content')
<div class="sl-page">
    @if(session('status'))<div class="sl-card mb-4 border border-green-200 bg-green-50 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-card mb-4 border border-red-200 bg-red-50 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    @if ($isLogistics && $currentMode === 'couriers')
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Seller Pickup</span><h2>Pickup Status</h2><p>Mark a packed parcel Ready for Pickup. Logistics groups ready parcels by seller and assigns a local rider.</p></div><a href="{{ route('seller.orders',['status'=>'ready-for-pickup']) }}" class="sl-btn sl-btn-ghost">Ready for Pickup Orders</a></div>
        <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Waiting for Logistics</span><h2>{{ $readyPickupCount ?? 0 }} ready parcel(s)</h2><p>Logistics will assign a local rider to collect ready parcels from your registered business address.</p></div></header><a href="{{ route('seller.orders',['status'=>'ready-for-pickup']) }}" class="sl-btn sl-btn-primary">View Ready for Pickup</a></section>

    @elseif ($isLogistics && $currentMode === 'pickups')
        @php
            $deliveryCollection = $deliveries ?? collect();
        @endphp
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Logistics</span><h2>Pickup Requests</h2><p>Manage pickup requests and follow each delivery.</p></div><a href="{{ route('seller.logistics',['view'=>'couriers']) }}" class="sl-btn sl-btn-primary">New Pickup Request</a></div>
        <section class="sl-mini-stats"><div><span>Total Requests</span><strong>{{ $deliveryCollection->count() }}</strong></div><div><span>Requested</span><strong>{{ $deliveryCollection->where('status','requested')->count() }}</strong></div><div><span>In Transit</span><strong>{{ $deliveryCollection->whereIn('status',['assigned','out_for_delivery'])->count() }}</strong></div><div><span>Delivered</span><strong>{{ $deliveryCollection->where('status','delivered')->count() }}</strong></div></section>
        <section class="sl-card"><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Tracking</th><th>Order</th><th>Provider</th><th>Pickup Window</th><th>Rider</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse($deliveryCollection as $pickup)
                <tr><td><strong>{{ $pickup->tracking_code ?: 'Pending' }}</strong></td><td>#{{ $pickup->sellerOrder?->order?->reference }}</td><td>{{ $pickup->provider?->name ?: 'Courier unavailable' }}</td><td>{{ $pickup->created_at?->format('M d, Y') ?: 'Not scheduled' }}</td><td>{{ $pickup->rider?->user?->name ?: 'Waiting assignment' }}</td><td><span class="sl-status {{ $pickup->status==='delivered'?'is-success':($pickup->status==='unassigned'?'is-warning':'is-info') }}">{{ str($pickup->status)->headline() }}</span></td><td><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.logistics',['view'=>'tracking','order'=>$pickup->sellerOrder?->order?->reference.'-'.$pickup->seller_order_id]) }}">Details</a></td></tr>
            @empty<tr><td colspan="7">No pickup requests yet.</td></tr>@endforelse
        </tbody></table></div></section>

    @elseif ($isLogistics && $currentMode === 'tracking')
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Logistics</span><h2>Shipment Tracking</h2><p>Live status is read from the order and delivery records.</p></div></div>
        @if($order)
            <x-shared.mapbox :markers="$order['map_markers']" title="Shipment tracking map" height="220px" class="sl-map-seller" navigation />
            <div class="sl-tracking-layout">
                <section class="sl-card sl-shipment-summary"><div class="sl-shipment-head"><div><span class="sl-eyebrow">Order #{{ $order['id'] }}</span><h2>Shipment items</h2><ul class="mt-2 space-y-1">@foreach($order['items'] as $item)<li>{{ $item->product_name }} · Qty {{ $item->quantity }}</li>@endforeach</ul><p>Shared shipment tracking no. {{ data_get($delivery,'tracking_number','Not assigned') }}</p></div><span class="sl-status is-info">{{ data_get($delivery,'current_status') ? str(data_get($delivery,'current_status'))->replace('_', ' ')->title() : $order['status'] }}</span></div><dl class="sl-shipment-meta"><div><dt>Logistics</dt><dd>{{ data_get($delivery,'provider.name',$order['shipping']) }}</dd></div><div><dt>Rider</dt><dd>{{ data_get($delivery,'rider.user.name','Waiting assignment') }}</dd></div><div><dt>Buyer</dt><dd>{{ $order['buyer'] }}</dd></div><div><dt>Address</dt><dd>{{ $order['shipping_address'] }}</dd></div></dl></section>
                <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Progress</span><h2>Shipment Timeline</h2><p>Timeline follows the current persisted delivery state.</p></div></header><ol class="sl-timeline">
                    @php $steps=['requested'=>'Pickup Requested','assigned'=>'Rider Assigned','out_for_delivery'=>'Out for Delivery','delivered'=>'Delivered']; $current=data_get($delivery,'status','requested'); $keys=array_keys($steps); $currentIndex=array_search($current,$keys,true); @endphp
                    @foreach($steps as $key=>$label)<li class="{{ array_search($key,$keys,true) <= ($currentIndex===false?0:$currentIndex) ? 'is-complete':'' }}"><span>{{ array_search($key,$keys,true) <= ($currentIndex===false?0:$currentIndex) ? '✓':'' }}</span><div><strong>{{ $label }}</strong><small>{{ data_get($delivery,'updated_at')?->format('M d · g:i A') }}</small><p>{{ $key===$current?'Current shipment state':'Shipment milestone' }}</p></div></li>@endforeach
                </ol></section>
            </div>
        @else<section class="sl-card"><x-seller.empty-state title="No shipment selected" message="Choose a delivery request to track." action="Pickup Requests" :href="route('seller.logistics',['view'=>'pickups'])" /></section>@endif

    @elseif (!$isLogistics && $currentMode === 'show')
        @if($order)
            <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Order Details</span><h2>Order #{{ $order['id'] }}</h2><p>Placed {{ $order['date'] }}</p></div><a href="{{ route('seller.orders',['status'=>$order['status_key'],'q'=>$search ?? null,'from_date'=>$fromDate ?? null,'to_date'=>$toDate ?? null]) }}" class="sl-btn sl-btn-ghost">Back to Orders</a></div>
            @include('seller.partials.parcel-measurements', ['order' => $order])
            @if(filled($order['buyer_note'] ?? null))
                <section class="sl-card" data-buyer-note><h3>Buyer Note</h3><p class="whitespace-pre-line">{{ $order['buyer_note'] }}</p></section>
            @endif
            <div class="sl-order-detail-layout"><div class="sl-order-detail-main"><section class="sl-card"><header class="sl-card-head"><div><h2>Products and parcel labels</h2>@if($order['has_unresolved_legacy_shipment'])<p>This legacy shipment's item mapping is unresolved.</p>@else<p>Each product line has its own tracking code. Its ordered quantity stays together in that parcel.</p>@endif</div><span class="sl-status is-warning">{{ $order['status'] }}</span></header>@if($order['has_unresolved_legacy_shipment'])<div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Legacy shipment preserved</strong>@foreach($order['unresolved_legacy_shipments'] as $legacyShipment)<p class="mt-1">Tracking {{ $legacyShipment->tracking_number }} · {{ str($legacyShipment->current_status)->replace('_', ' ')->title() }}</p>@endforeach<p class="mt-2">The item mapping is unresolved, so this tracking history remains attached to the original shipment. Logistics must reconcile it before separate item labels can be issued.</p></div>@elseif($order['parcels_need_setup'])<form method="POST" action="{{ route('seller.orders.parcels.ensure', $order['db_id']) }}" class="mb-4">@csrf<button class="sl-btn sl-btn-primary" type="submit">Create item parcel labels</button><p class="mt-2 text-sm text-stone-600">Create one shipment for each item line before fulfillment starts.</p></form>@elseif($order['can_print_waybills'] && count($order['parcels']) > 1)<a class="sl-btn sl-btn-primary mb-4 inline-flex" href="{{ route('seller.orders.waybill', $order['db_id']) }}">Print all waybills</a>@endif<ul class="divide-y divide-stone-200">@foreach($order['items'] as $item)<li class="flex flex-wrap items-center gap-4 py-4 first:pt-2 last:pb-2"><x-shared.product-thumbnail :item="$item" size="72" class="sl-order-thumb"/><div class="min-w-0 flex-1"><strong>{{ $item->product_name }}</strong><span class="block">Variant: {{ $item->variant_description ?: 'Standard' }}</span><small>@if($item->original_unit_price !== null)Original unit price: PHP {{ number_format((float) $item->original_unit_price, 2) }} | @endif Effective unit price: PHP {{ number_format((float) $item->unit_price, 2) }} | Qty: {{ $item->quantity }}@if((float) $item->discount_amount > 0) | Line discount: PHP {{ number_format((float) $item->discount_amount, 2) }}@endif</small>@if($item->shipment)<span class="mt-1 block text-sm">Tracking: <strong>{{ $item->shipment->tracking_number }}</strong> · {{ str($item->shipment->current_status)->replace('_', ' ')->title() }}</span>@if($order['can_print_waybills'])<a class="mt-2 inline-flex text-sm font-semibold text-red-900 underline" href="{{ route('seller.orders.waybill.item', [$order['db_id'], $item->id]) }}">Print this parcel waybill</a>@endif@else<span class="mt-1 block text-sm text-amber-800">Parcel label not created yet</span>@endif</div><strong>₱{{ number_format((float) $item->line_total, 2) }}</strong></li>@endforeach</ul></section>
            <section class="sl-card">
                <header class="sl-card-head"><div><h2>Fulfillment Actions</h2><p>Mark parcels packed individually, then send 1 to 10 packed parcels to pickup at a time.</p></div></header>
                @php
                    [$orderNextStep, $orderNextActor] = match ($order['status_key']) {
                        'placed' => ['Accept the order or cancel it with a reason.', 'Seller'],
                        'confirmed' => ['Begin preparing the ordered items.', 'Seller'],
                        'preparing' => ['Pack each parcel, then mark packed parcels ready for pickup.', 'Seller'],
                        'ready-for-pickup' => (($order['pickup_status'] ?? '') === 'Pickup scheduled')
                            ? ['Keep parcels ready for the assigned pickup rider.', 'Pickup rider']
                            : ['Assign a pickup rider to collect the ready parcels.', 'Logistics'],
                        'shipping' => ['Deliver the parcel and record proof or an attempt outcome.', 'Delivery rider'],
                        'delivered' => ['Confirm receipt, or wait for the Buyer to confirm.', 'Buyer'],
                        'returns' => ['Review the return or refund request linked to this order.', 'Seller'],
                        'completed', 'cancelled' => ['No further fulfillment action is required.', 'No action pending'],
                        default => ['Check parcel tracking for the next scan or handoff.', 'Logistics / delivery rider'],
                    };
                @endphp
                <x-shared.status-guidance :status="$order['status']" :next="$orderNextStep" :actor="$orderNextActor" />
                <div class="sl-action-panel"><span class="sl-action-panel-icon">&#10003;</span><div><strong>{{ $order['status'] }}</strong><p>Ready parcels can be picked up while other parcels remain in preparation.</p></div>
                    @if($order['status_key']==='placed')<form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="confirm"><button class="sl-btn sl-btn-primary" type="submit">Accept Order</button></form>@endif
                    @if($order['status_key']==='confirmed')<form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="prepare"><button class="sl-btn sl-btn-primary" type="submit">Begin Preparation</button></form>@endif
                    @if(in_array($order['status_key'], ['preparing', 'ready-for-pickup'], true))
                        @if($order['unpacked_parcels']->isNotEmpty())
                            <form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}" class="w-full space-y-2">@csrf @method('PATCH')<input type="hidden" name="action" value="pack"><strong class="block">Pack parcels</strong>
                                @foreach($order['unpacked_parcels'] as $parcel)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shipment_ids[]" value="{{ $parcel['shipment']->id }}" checked><span>{{ $parcel['item']->product_name }} &middot; {{ $parcel['shipment']->tracking_number }}</span></label>
                                @endforeach
                                <button class="sl-btn sl-btn-primary" type="submit">Mark selected parcels packed</button>
                            </form>
                        @endif
                        @if($order['ready_parcels']->isNotEmpty() && $order['unpacked_parcels']->isEmpty())
                            <form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}" class="w-full space-y-2">@csrf @method('PATCH')<input type="hidden" name="action" value="ready"><strong class="block">Ready for pickup ({{ $order['ready_parcels']->count() }} packed parcel(s) available)</strong>
                                @foreach($order['ready_parcels']->take(10) as $parcel)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shipment_ids[]" value="{{ $parcel['shipment']->id }}" @if($loop->first) checked @endif><span>{{ $parcel['item']->product_name }} &middot; {{ $parcel['shipment']->tracking_number }}</span></label>
                                @endforeach
                                <p class="text-xs text-stone-600">Select 1 to 10 parcels. You can send the remaining parcels in another pickup batch.</p>
                                <button class="sl-btn sl-btn-primary" type="submit">Mark selected parcels ready for pickup</button>
                            </form>
                        @endif
                    @endif
                    @if($order['status_key']==='ready-for-pickup')
                        @if($order['can_print_waybills'])<a target="_blank" class="sl-btn sl-btn-ghost" href="{{ route('seller.orders.waybill',$order['db_id']) }}">Print Waybills</a>@endif
                        @if(($order['pickup_status'] ?? '') === 'Pickup scheduled')<span class="sl-status is-info">Pickup scheduled &middot; {{ $order['pickup_rider'] }}</span>@elseif(($order['pickup_status'] ?? '') === 'Waiting for Logistics')<span class="sl-status is-info">Waiting for Logistics assignment</span>@endif
                    @endif
                    @if($order['can_cancel'])<form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}" class="sl-cancel-order-form">@csrf @method('PATCH')<input type="hidden" name="action" value="cancel"><label class="sl-field"><span>Cancellation reason</span><textarea name="cancellation_reason" maxlength="500" required rows="2" placeholder="Explain why you are cancelling this order"></textarea></label><button class="sl-btn sl-btn-ghost" type="submit">Cancel Order</button></form>@endif
                    @if($order['status_key']==='shipping')<a href="{{ route('seller.logistics',['view'=>'tracking','order'=>$order['id']]) }}" class="sl-btn sl-btn-primary">Track Shipment</a>@endif
                </div></section></div><aside class="sl-order-detail-side"><section class="sl-card sl-order-provider-detail"><h3>Seller Pickup</h3><p>{{ data_get($order, 'pickup_status', 'Waiting for Logistics') }}</p><dl class="sl-detail-list"><div><dt>Pickup Batch</dt><dd>{{ data_get($order, 'pickup_batch', 'Not assigned') }}</dd></div><div><dt>Assigned Rider</dt><dd>{{ data_get($order, 'pickup_rider', 'Waiting for Logistics') }}</dd></div><div><dt>Parcels in this pickup</dt><dd>{{ data_get($order, 'pickup_batch_size', '—') }}</dd></div>@if(($order['pickup_status'] ?? '') === 'Picked up')<div><dt>Collected by Rider</dt><dd>{{ data_get($order, 'pickup_collected_at')?->format('M d, Y g:i A') ?? 'Time unavailable' }}</dd></div><div><dt>Now heading to</dt><dd>{{ data_get($order, 'pickup_hub', 'Assigned logistics center') }}</dd></div>@endif</dl></section><section class="sl-card"><h3>Buyer Information</h3><dl class="sl-detail-list"><div><dt>Name</dt><dd>{{ $order['buyer'] }}</dd></div><div><dt>Delivery Address</dt><dd>{{ $order['shipping_address'] }}</dd></div></dl><a href="{{ route('seller.messages',['buyer'=>$order['buyer_id']]) }}" class="sl-btn sl-btn-soft sl-btn-block">Message Buyer</a></section><section class="sl-card"><h3>Payment Summary</h3><dl class="sl-price-list">
    <div><dt>Item subtotal</dt><dd>PHP {{ number_format($order['item_subtotal'], 2) }}</dd></div>
    @if($order['funding_snapshot_version'] === 1)
        @if(($order['seller_voucher_discount'] ?? 0) > 0)
            <div><dt>Seller-funded voucher</dt><dd>- PHP {{ number_format($order['seller_voucher_discount'], 2) }}</dd></div>
        @endif
        @if(($order['platform_voucher_discount'] ?? 0) > 0)
            <div><dt>LIKHAE-funded voucher</dt><dd>- PHP {{ number_format($order['platform_voucher_discount'], 2) }}</dd></div>
            <p class="text-xs text-emerald-800">This platform discount does not reduce your seller earnings.</p>
        @endif
    @elseif($order['voucher_discount'] > 0)
        <div><dt>Voucher discount (funding review)</dt><dd>- PHP {{ number_format($order['voucher_discount'], 2) }}</dd></div>
    @endif
    @if(($order['reward_points_discount'] ?? 0) > 0)
        <div><dt>Buyer reward points (platform funded)</dt><dd>- PHP {{ number_format($order['reward_points_discount'], 2) }}</dd></div>
    @endif
    <div><dt>Shipping fee</dt><dd>PHP {{ number_format($order['shipping_fee'], 2) }}</dd></div>
    @if(($order['shipping_voucher_discount'] ?? 0) > 0)
        <div><dt>LIKHAE shipping voucher</dt><dd>- PHP {{ number_format($order['shipping_voucher_discount'], 2) }}</dd></div>
    @endif
    @if($order['protection_total'] > 0)
        <div><dt>Protection charge</dt><dd>PHP {{ number_format($order['protection_total'], 2) }}</dd></div>
    @endif
    @if($order['seller_commission'] !== null)
        <div><dt>Platform commission</dt><dd>PHP {{ number_format($order['seller_commission'], 2) }}</dd></div>
    @endif
    @if($order['seller_earnings'] !== null)
        <div><dt>Net seller earnings</dt><dd>PHP {{ number_format($order['seller_earnings'], 2) }}</dd></div>
        <small>Settlement: {{ $order['settlement_status'] ?? '' }}</small>
    @endif
    <div class="is-total"><dt>Final payable</dt><dd>PHP {{ number_format($order['total'], 2) }}</dd></div>
    <div><dt>Payment method</dt><dd>{{ $order['payment'] }}</dd></div>
    @if($order['cod_amount'] !== null)
        <div><dt>COD amount</dt><dd>PHP {{ number_format($order['cod_amount'], 2) }}</dd></div>
    @endif
</dl>>@unless($order['financial_reconciles'])<p class="mt-2 text-sm text-amber-800">Stored order totals do not reconcile; the historical payable total is shown as recorded.</p>@endunless</section></aside></div>
        @if(($order['pickup_progress_total'] ?? 0) > 0)
            @include('seller.partials.pickup-progress', ['order' => $order])
        @endif
        @else<section class="sl-card"><x-seller.empty-state title="Order not found" message="The selected order does not exist." action="Back to Orders" :href="route('seller.orders')" /></section>@endif

    @else
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Order Management</span><h2>Manage Orders</h2><p>Review orders and move each purchase through fulfillment.</p></div><a href="{{ route('seller.orders.export') }}" class="sl-btn sl-btn-ghost">Download PDF</a></div>
        @if($currentStatus==='ready-for-pickup')<section class="sl-card"><strong>{{ $readyPickupCount ?? 0 }} parcel(s) waiting for pickup.</strong><p>Newly ready parcels wait for Logistics assignment. Once assigned, this order shows the rider and pickup batch.</p></section>@endif
        <section class="sl-order-tabs" aria-label="Order filters">
            @foreach(['all'=>'All Orders','placed'=>'Placed','confirmed'=>'Confirmed','preparing'=>'Preparing','ready-for-pickup'=>'Ready for Pickup','shipping'=>'Shipping','completed'=>'Completed','cancelled'=>'Cancelled','returns'=>'Returns/Refunds'] as $key=>$label)<a href="{{ route('seller.orders',['status'=>$key,'q'=>$search ?? null,'from_date'=>$fromDate ?? null,'to_date'=>$toDate ?? null]) }}" class="{{ $currentStatus===$key?'is-active':'' }}">{{ $label }} @if(($statusCounts[$key]??0)>0)<span>{{ $statusCounts[$key] }}</span>@endif</a>@endforeach
        </section>
        @if($currentStatus==='returns')
            <section class="sl-card sl-order-filters"><form method="GET" action="{{ route('seller.orders') }}" class="sl-search-input"><input type="hidden" name="status" value="returns"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="{{ request('q') }}" placeholder="Search request, order, buyer, or product"><select name="refund_status" class="sl-field"><option value="">All refund statuses</option>@foreach(['SELLER_REVIEW','APPROVED','REJECTED','REFUND_SENT','CONFIRMED','ESCALATED','ADMIN_REFUND_REQUIRED','DISPUTE_RESOLVED'] as $refundFilter)<option value="{{ $refundFilter }}" @selected(($refundStatus ?? '') === $refundFilter)>{{ str($refundFilter)->headline() }}</option>@endforeach</select><button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">Search</button></form></section>
            <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Seller-first workflow</span><h2>Returns & Refunds</h2><p>Only refund requests for your Seller-owned items are shown.</p></div></header><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Request</th><th>Order / Buyer</th><th>Products</th><th>Requested</th><th>Status</th><th></th></tr></thead><tbody>@forelse($refundRequests ?? [] as $refund)<tr><td><strong>{{ $refund->request?->request_number }}</strong><small>{{ $refund->created_at?->format('M d, Y g:i A') }}</small></td><td>{{ $refund->request?->order?->order_number }}<small>{{ $refund->request?->order?->buyer?->name }}</small></td><td>{{ $refund->items->map(fn($item)=>$item->orderItem?->product_name)->filter()->join(', ') ?: 'Items' }}</td><td>PHP {{ number_format((float)$refund->requested_amount,2) }}</td><td><span class="sl-status is-warning">{{ str($refund->status)->headline() }}</span></td><td><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.orders.refunds.show',$refund) }}">View Request</a></td></tr>@empty<tr><td colspan="6">No Seller refund requests yet.</td></tr>@endforelse</tbody></table></div>@if($refundRequests?->hasPages())<div class="ad-card-body">{{ $refundRequests->links() }}</div>@endif</section>
        @else
            <section class="sl-card sl-order-filters"><form method="GET" action="{{ route('seller.orders') }}" class="sl-search-input"><input type="hidden" name="status" value="{{ $currentStatus }}"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="{{ $search ?? '' }}" placeholder="Search order, buyer, or product"><label class="sl-field"><span>From date</span><input type="date" name="from_date" value="{{ $fromDate ?? '' }}"></label><label class="sl-field"><span>To date</span><input type="date" name="to_date" value="{{ $toDate ?? '' }}"></label><button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">Apply Filters</button></form></section>
            <div class="sl-order-list">@php $visibleOrders=$currentStatus==='all'?$sellerOrders:$sellerOrders->where('status_key',$currentStatus); @endphp @forelse($visibleOrders as $item)<x-seller.order-card :order="$item" />@empty<section class="sl-card"><x-seller.empty-state title="No orders in this stage" message="Orders will appear here when they enter this fulfillment stage." action="View All Orders" :href="route('seller.orders')" /></section>@endforelse</div>@if($orders->hasPages())<div class="ad-card-body">{{ $orders->links() }}</div>@endif<div class="sl-no-results sl-card" data-order-empty hidden>No orders match your search.</div>
        @endif
    @endif
</div>
@endsection
