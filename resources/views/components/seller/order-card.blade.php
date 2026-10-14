@props(['order'])

@php
    $id = data_get($order, 'id');
    $dbId = data_get($order, 'db_id');
    $status = data_get($order, 'status', 'To Process');
    $statusKey = (string) data_get($order, 'status_key', 'to-process');
    $statusTone = match ($statusKey) {
        'completed' => 'is-success',
        'cancelled', 'returns' => 'is-danger',
        'shipping', 'ready-pickup' => 'is-info',
        'to-prepare' => 'is-warning',
        default => 'is-neutral',
    };
@endphp

<article class="sl-order-card" data-order-card data-status="{{ $statusKey }}" data-search="{{ mb_strtolower($id.' '.data_get($order, 'buyer').' '.data_get($order, 'product')) }}">
    <header class="sl-order-card-head"><div><span class="sl-order-id">Order #{{ $id }}</span><small>Placed {{ data_get($order, 'date') }}</small></div><span class="sl-status {{ $statusTone }}" data-order-status>{{ $status }}</span></header>
    <div class="sl-order-card-body">
        <div class="sl-order-product"><x-product-thumbnail :item="data_get($order, 'first_item')" size="64" class="sl-order-thumb"/><div><strong>{{ data_get($order,'product') }}</strong><small>{{ data_get($order,'variant','Standard') }} · Qty: {{ data_get($order,'quantity',1) }}</small></div></div>
        <dl class="sl-order-details"><div><dt>Buyer</dt><dd>{{ data_get($order,'buyer') }}</dd></div><div><dt>Payment</dt><dd>{{ data_get($order,'payment') }}</dd></div><div><dt>Shipping</dt><dd>{{ data_get($order,'shipping') }}</dd></div><div><dt>Total</dt><dd><strong>₱{{ number_format((float) data_get($order,'total'),2) }}</strong></dd></div></dl>
    </div>
    <p class="sl-order-provider"><strong>Logistics:</strong> {{ data_get($order, 'logistics_provider', 'Not assigned') }} <span aria-hidden="true">·</span> <strong>Courier:</strong> {{ data_get($order, 'shipping', 'Waiting for Logistics') }}</p>
    <footer class="sl-order-card-actions">
        <a href="{{ route('seller.orders',['mode'=>'show','order'=>$id]) }}" class="sl-btn sl-btn-ghost sl-btn-sm">View Details</a>
        @if ($statusKey === 'placed')
            <form method="POST" action="{{ route('seller.orders.status',$dbId) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="confirm"><button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Accept Order</button></form>
        @elseif ($statusKey === 'confirmed')
            <form method="POST" action="{{ route('seller.orders.status',$dbId) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="prepare"><button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Begin Preparation</button></form>
        @elseif ($statusKey === 'preparing')
            <a target="_blank" href="{{ route('seller.orders.waybill',$dbId) }}" class="sl-btn sl-btn-ghost sl-btn-sm">Print Waybill</a>
            <details class="sl-courier-select">
                <summary class="sl-btn sl-btn-primary sl-btn-sm cursor-pointer">Choose Handover Method</summary>
                <form method="POST" action="{{ route('seller.logistics.pickup',$dbId) }}">
                    @csrf
                    <div class="sl-provider-list">
                        <label class="sl-provider-option"><input type="radio" name="handover_method" value="logistics_pickup" checked><span class="sl-radio-mark"></span><span class="sl-provider-logo">LP</span><span class="sl-provider-main"><strong>Request Logistics Pickup</strong><small>Logistics assigns an eligible rider to collect the parcel.</small></span></label>
                        <label class="sl-provider-option"><input type="radio" name="handover_method" value="seller_dropoff"><span class="sl-radio-mark"></span><span class="sl-provider-logo">DO</span><span class="sl-provider-main"><strong>Seller Drop-off</strong><small>Bring the parcel and waybill to your assigned center.</small></span></label>
                    </div>
                    <div class="sl-form-grid">
                        <label class="sl-field"><span>Preferred pickup date</span><input name="pickup_date" type="date" value="{{ now()->addDay()->toDateString() }}"></label>
                        <label class="sl-field"><span>Pickup window</span><select name="pickup_window"><option>10:00 AM - 12:00 PM</option><option>1:00 PM - 3:00 PM</option><option>3:00 PM - 5:00 PM</option></select></label>
                        <label class="sl-field sl-span-2"><span>Handover note</span><textarea name="pickup_note" rows="2" maxlength="1000">Package is sealed and the waybill is attached.</textarea></label>
                    </div>
                    <div class="px-4 pb-4"><button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Save Handover Choice</button></div>
                </form>
            </details>
        @elseif ($statusKey === 'ready-for-pickup')
            <span class="sl-status is-info">{{ data_get($order, 'pickup_status', 'Waiting for Logistics') }}</span>
            @if(data_get($order, 'pickup_status') === 'Pickup scheduled')<small class="text-muted">{{ data_get($order, 'pickup_batch') }} · {{ data_get($order, 'pickup_rider') }} · {{ data_get($order, 'pickup_batch_size') }} parcels</small>@endif
        @elseif ($statusKey === 'shipping')
            <a href="{{ route('seller.logistics',['view'=>'tracking','order'=>$id]) }}" class="sl-btn sl-btn-primary sl-btn-sm">Track Shipment</a>
        @elseif ($statusKey === 'returns')
            <form method="POST" action="{{ route('seller.orders.status',$dbId) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="completed"><button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Resolve Return</button></form>
        @endif
    </footer>
</article>
