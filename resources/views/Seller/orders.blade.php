@extends('layouts.seller')

@php
    $isLogistics = ($pageMode ?? 'orders') === 'logistics';
    $currentMode = $mode ?? ($isLogistics ? request('view', 'couriers') : request('mode', 'index'));
    $currentStatus = $status ?? request('status', 'all');
    $orderId = $selectedOrder ?? request('order');
    $order = $sellerOrders->firstWhere('id', (string) $orderId) ?? $sellerOrders->first();
    $delivery = data_get($order, 'delivery');
@endphp

@section('title', $isLogistics ? 'Logistics' : ($currentMode === 'show' && $order ? 'Order #'.$order['id'] : 'Orders'))
@section('active', $isLogistics ? 'logistics' : 'orders')
@section('subtitle', $isLogistics ? 'Review Seller pickup progress and parcel tracking.' : 'Review purchases and move each order through fulfillment.')

@section('content')
<div class="sl-page">
    @if ($isLogistics && $currentMode === 'couriers')
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Seller Pickup</span><h2>Pickup Status</h2><p>Mark a packed parcel Ready for Pickup. Logistics groups ready parcels by seller and assigns a local rider.</p></div><a href="{{ route('seller.orders',['status'=>'ready-for-pickup']) }}" class="sl-btn sl-btn-ghost">Ready for Pickup Orders</a></div>
        <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Waiting for Logistics</span><h2>{{ $readyPickupCount ?? 0 }} ready parcel(s)</h2><p>Logistics will assign a local rider to collect ready parcels from your registered business address.</p></div></header><a href="{{ route('seller.orders',['status'=>'ready-for-pickup']) }}" class="sl-btn sl-btn-primary">View Ready for Pickup</a></section>

    @elseif ($isLogistics && $currentMode === 'pickups')
        @php
            $deliveryCollection = $deliveries ?? collect();
        @endphp
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Logistics</span><h2>Pickup Requests</h2><p>Manage pickup requests and follow each delivery.</p></div><a href="{{ route('seller.logistics',['view'=>'couriers']) }}" class="sl-btn sl-btn-primary">New Pickup Request</a></div>
        <section class="sl-mini-stats"><div><span>Total Requests</span><strong>{{ $deliveryCollection->count() }}</strong></div><div><span>Requested</span><strong>{{ $deliveryCollection->where('status','requested')->count() }}</strong></div><div><span>In Transit</span><strong>{{ $deliveryCollection->whereIn('status',['assigned','out_for_delivery'])->count() }}</strong></div><div><span>Delivered</span><strong>{{ $deliveryCollection->where('status','delivered')->count() }}</strong></div></section>
        <section class="sl-card"><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Tracking</th><th>Order</th><th>Provider</th><th>Pickup Window</th><th>Courier</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse($deliveryCollection as $pickup)
                <tr><td><strong>{{ $pickup->tracking_code ?: 'Pending' }}</strong></td><td>#{{ $pickup->sellerOrder?->order?->reference }}</td><td>{{ $pickup->provider?->name ?: 'Courier unavailable' }}</td><td>{{ $pickup->created_at?->format('M d, Y') ?: 'Not scheduled' }}</td><td>{{ $pickup->rider?->user?->name ?: 'Waiting assignment' }}</td><td><span class="sl-status {{ $pickup->status==='delivered'?'is-success':($pickup->status==='unassigned'?'is-warning':'is-info') }}">{{ str($pickup->status)->headline() }}</span></td><td><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.logistics',['view'=>'tracking','order'=>$pickup->sellerOrder?->order?->reference.'-'.$pickup->seller_order_id]) }}">Details</a></td></tr>
            @empty<tr><td colspan="7">No pickup requests yet.</td></tr>@endforelse
        </tbody></table></div></section>

    @elseif ($isLogistics && $currentMode === 'tracking')
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Logistics</span><h2>Shipment Tracking</h2><p>Live status is read from the order and delivery records.</p></div></div>
        @if($order)
            <x-shared.mapbox :markers="$order['map_markers']" title="Shipment tracking map" height="220px" class="sl-map-seller" navigation />
            <div class="sl-tracking-layout">
                <section class="sl-card sl-shipment-summary"><div class="sl-shipment-head"><div><span class="sl-eyebrow">Order #{{ $order['id'] }}</span><h2>{{ $order['product'] }}</h2><p>Tracking no. {{ data_get($delivery,'tracking_code','Not assigned') }}</p></div><span class="sl-status is-info">{{ data_get($delivery,'status') ? str(data_get($delivery,'status'))->headline() : $order['status'] }}</span></div><dl class="sl-shipment-meta"><div><dt>Logistics</dt><dd>{{ data_get($delivery,'provider.name',$order['shipping']) }}</dd></div><div><dt>Courier</dt><dd>{{ data_get($delivery,'rider.user.name','Waiting assignment') }}</dd></div><div><dt>Buyer</dt><dd>{{ $order['buyer'] }}</dd></div><div><dt>Address</dt><dd>{{ $order['shipping_address'] }}</dd></div></dl></section>
                <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Progress</span><h2>Shipment Timeline</h2><p>Timeline follows the current persisted delivery state.</p></div></header><ol class="sl-timeline">
                    @php $steps=['requested'=>'Pickup Requested','assigned'=>'Courier Assigned','out_for_delivery'=>'Out for Delivery','delivered'=>'Delivered']; $current=data_get($delivery,'status','requested'); $keys=array_keys($steps); $currentIndex=array_search($current,$keys,true); @endphp
                    @foreach($steps as $key=>$label)<li class="{{ array_search($key,$keys,true) <= ($currentIndex===false?0:$currentIndex) ? 'is-complete':'' }}"><span>{{ array_search($key,$keys,true) <= ($currentIndex===false?0:$currentIndex) ? '✓':'' }}</span><div><strong>{{ $label }}</strong><small>{{ data_get($delivery,'updated_at')?->format('M d · g:i A') }}</small><p>{{ $key===$current?'Current shipment state':'Shipment milestone' }}</p></div></li>@endforeach
                </ol></section>
            </div>
        @else<section class="sl-card"><x-seller.empty-state title="No shipment selected" message="Choose a delivery request to track." action="Pickup Requests" :href="route('seller.logistics',['view'=>'pickups'])" /></section>@endif

    @elseif (!$isLogistics && $currentMode === 'show')
        @if($order)
            <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Order Details</span><h2>Order #{{ $order['id'] }}</h2><p>Placed {{ $order['date'] }}</p></div><a href="{{ route('seller.orders',['status'=>$order['status_key']]) }}" class="sl-btn sl-btn-ghost">Back to Orders</a></div>
            <x-shared.mapbox :markers="$order['map_markers']" title="Order delivery map" height="220px" class="sl-map-seller" navigation />
            <section class="sl-card sl-order-provider-detail"><h3>Seller Pickup</h3><p>{{ data_get($order, 'pickup_status', 'Waiting for Logistics') }}</p><dl class="sl-detail-list"><div><dt>Pickup Batch</dt><dd>{{ data_get($order, 'pickup_batch', 'Not assigned') }}</dd></div><div><dt>Assigned Rider</dt><dd>{{ data_get($order, 'pickup_rider', 'Waiting for Logistics') }}</dd></div><div><dt>Parcels in this pickup</dt><dd>{{ data_get($order, 'pickup_batch_size', '—') }}</dd></div>@if(($order['pickup_status'] ?? '') === 'Picked up')<div><dt>Collected by Rider</dt><dd>{{ data_get($order, 'pickup_collected_at')?->format('M d, Y g:i A') ?? 'Time unavailable' }}</dd></div><div><dt>Now heading to</dt><dd>{{ data_get($order, 'pickup_hub', 'Assigned logistics center') }}</dd></div>@endif</dl></section>
            @if(filled($order['buyer_note'] ?? null))
                <section class="sl-card" data-buyer-note><h3>Buyer Note</h3><p class="whitespace-pre-line">{{ $order['buyer_note'] }}</p></section>
            @endif
            <div class="sl-order-detail-layout"><div class="sl-order-detail-main"><section class="sl-card"><header class="sl-card-head"><div><h2>Ordered Product</h2><p>Items included in this purchase.</p></div><span class="sl-status is-warning">{{ $order['status'] }}</span></header><div class="sl-package-summary"><x-product-thumbnail :item="$order['first_item']" size="72" class="sl-order-thumb"/><div><strong>{{ $order['product'] }}</strong><span>Variation: {{ $order['variant'] }}</span><small>₱{{ number_format($order['total']/$order['quantity'],2) }} × {{ $order['quantity'] }}</small></div><strong>₱{{ number_format($order['total'],2) }}</strong></div></section>
            <section class="sl-card"><header class="sl-card-head"><div><h2>Fulfillment Actions</h2><p>Available actions depend on the current status.</p></div></header><div class="sl-action-panel"><span class="sl-action-panel-icon">✓</span><div><strong>{{ $order['status'] }}</strong><p>Use the valid next action to move the order through fulfillment.</p></div>
                @if($order['status_key']==='placed')<form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="confirm"><button class="sl-btn sl-btn-primary" type="submit">Accept Order</button></form>@endif
                @if($order['status_key']==='confirmed')<form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="prepare"><button class="sl-btn sl-btn-primary" type="submit">Begin Preparation</button></form>@endif
                @if($order['status_key']==='preparing')<form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="ready"><button class="sl-btn sl-btn-primary" type="submit">Ready for Pickup</button></form>@endif
                @if($order['status_key']==='ready-for-pickup')
                    <a target="_blank" class="sl-btn sl-btn-ghost" href="{{ route('seller.orders.waybill',$order['db_id']) }}">Print Waybill</a>
                    @if(($order['pickup_status'] ?? '') === 'Pickup scheduled')<span class="sl-status is-info">Pickup scheduled · {{ $order['pickup_rider'] }}</span>@elseif(($order['pickup_status'] ?? '') === 'Waiting for Logistics')<span class="sl-status is-info">Waiting for Logistics assignment</span>@endif
                @endif
                @if($order['status_key']==='shipping')<a href="{{ route('seller.logistics',['view'=>'tracking','order'=>$order['id']]) }}" class="sl-btn sl-btn-primary">Track Shipment</a>@endif
            </div></section></div><aside class="sl-order-detail-side"><section class="sl-card"><h3>Buyer Information</h3><dl class="sl-detail-list"><div><dt>Name</dt><dd>{{ $order['buyer'] }}</dd></div><div><dt>Delivery Address</dt><dd>{{ $order['shipping_address'] }}</dd></div></dl><a href="{{ route('seller.messages',['buyer'=>$order['buyer_id']]) }}" class="sl-btn sl-btn-soft sl-btn-block">Message Buyer</a></section><section class="sl-card"><h3>Payment Summary</h3><dl class="sl-price-list"><div><dt>Order total</dt><dd>₱{{ number_format($order['total'],2) }}</dd></div><div class="is-total"><dt>Payment</dt><dd>{{ $order['payment'] }}</dd></div></dl></section></aside></div>
        @else<section class="sl-card"><x-seller.empty-state title="Order not found" message="The selected order does not exist." action="Back to Orders" :href="route('seller.orders')" /></section>@endif

    @else
        <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Order Management</span><h2>Manage Orders</h2><p>Review orders and move each purchase through fulfillment.</p></div><a href="{{ route('seller.orders.export') }}" class="sl-btn sl-btn-ghost">Export CSV</a></div>
        @if($currentStatus==='ready-for-pickup')<section class="sl-card"><strong>{{ $readyPickupCount ?? 0 }} parcel(s) waiting for pickup.</strong><p>Newly ready parcels wait for Logistics assignment. Once assigned, this order shows the rider and pickup batch.</p></section>@endif
        <section class="sl-order-tabs" aria-label="Order filters">
            @foreach(['all'=>'All Orders','placed'=>'Placed','confirmed'=>'Confirmed','preparing'=>'Preparing','ready-for-pickup'=>'Ready for Pickup','shipping'=>'Shipping','completed'=>'Completed','cancelled'=>'Cancelled','returns'=>'Returns/Refunds'] as $key=>$label)<a href="{{ route('seller.orders',['status'=>$key]) }}" class="{{ $currentStatus===$key?'is-active':'' }}">{{ $label }} @if(($statusCounts[$key]??0)>0)<span>{{ $statusCounts[$key] }}</span>@endif</a>@endforeach
        </section>
        @if($currentStatus==='returns')
            <section class="sl-card sl-order-filters"><form method="GET" action="{{ route('seller.orders') }}" class="sl-search-input"><input type="hidden" name="status" value="returns"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="{{ request('q') }}" placeholder="Search request, order, buyer, or product"><select name="refund_status" class="sl-field"><option value="">All refund statuses</option>@foreach(['SELLER_REVIEW','APPROVED','REJECTED','REFUND_SENT','CONFIRMED','ESCALATED','ADMIN_REFUND_REQUIRED','DISPUTE_RESOLVED'] as $refundFilter)<option value="{{ $refundFilter }}" @selected(($refundStatus ?? '') === $refundFilter)>{{ str($refundFilter)->headline() }}</option>@endforeach</select><button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">Search</button></form></section>
            <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Seller-first workflow</span><h2>Returns & Refunds</h2><p>Only refund requests for your Seller-owned items are shown.</p></div></header><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Request</th><th>Order / Buyer</th><th>Products</th><th>Requested</th><th>Status</th><th></th></tr></thead><tbody>@forelse($refundRequests ?? [] as $refund)<tr><td><strong>{{ $refund->request?->request_number }}</strong><small>{{ $refund->created_at?->format('M d, Y g:i A') }}</small></td><td>{{ $refund->request?->order?->order_number }}<small>{{ $refund->request?->order?->buyer?->name }}</small></td><td>{{ $refund->items->map(fn($item)=>$item->orderItem?->product_name)->filter()->join(', ') ?: 'Items' }}</td><td>PHP {{ number_format((float)$refund->requested_amount,2) }}</td><td><span class="sl-status is-warning">{{ str($refund->status)->headline() }}</span></td><td><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.orders.refunds.show',$refund) }}">View Request</a></td></tr>@empty<tr><td colspan="6">No Seller refund requests yet.</td></tr>@endforelse</tbody></table></div>@if($refundRequests?->hasPages())<div class="ad-card-body">{{ $refundRequests->links() }}</div>@endif</section>
        @else
            <section class="sl-card sl-order-filters"><div class="sl-search-input"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="{{ request('q') }}" placeholder="Search order, buyer, or product" data-order-search></div></section>
            <div class="sl-order-list">@php $visibleOrders=$currentStatus==='all'?$sellerOrders:$sellerOrders->where('status_key',$currentStatus); @endphp @forelse($visibleOrders as $item)<x-seller.order-card :order="$item" />@empty<section class="sl-card"><x-seller.empty-state title="No orders in this stage" message="Orders will appear here when they enter this fulfillment stage." action="View All Orders" :href="route('seller.orders')" /></section>@endforelse</div><div class="sl-no-results sl-card" data-order-empty hidden>No orders match your search.</div>
        @endif
    @endif
</div>
@endsection
