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

<article class="sl-order-card" data-order-card data-status="{{ $statusKey }}" data-search="{{ mb_strtolower($id.' '.data_get($order, 'buyer').' '.data_get($order, 'product_search')) }}">
    <header class="sl-order-card-head"><div><span class="sl-order-id">Order #{{ $id }}</span><small>Placed {{ data_get($order, 'date') }}</small></div><span class="sl-status {{ $statusTone }}" data-order-status>{{ $status }}</span></header>
    <div class="sl-order-card-body">
        <div class="min-w-0 flex-1" style="grid-column: 1 / -1">
            <strong>Products in this seller order</strong>
            <ul class="mt-2 divide-y divide-stone-200">
                @foreach(data_get($order, 'items', collect()) as $item)
                    <li class="flex items-center gap-3 py-3 first:pt-1 last:pb-1">
                        <x-shared.product-thumbnail :item="$item" size="56" class="sl-order-thumb"/>
                        <div class="min-w-0 flex-1"><strong class="block">{{ $item->product_name }}</strong><small class="block">Variant: {{ $item->variant_description ?: 'Standard' }} · Qty: {{ $item->quantity }} · Unit price: ₱{{ number_format((float) $item->unit_price, 2) }}</small></div>
                        <strong class="shrink-0">₱{{ number_format((float) $item->line_total, 2) }}</strong>
                    </li>
                @endforeach
            </ul>
            @if(data_get($order, 'delivery'))<small class="mt-2 block">Shared shipment: {{ str(data_get($order, 'delivery.current_status'))->replace('_', ' ')->title() }} · Tracking {{ data_get($order, 'delivery.tracking_number') }}</small>@endif
        </div>
        <dl class="sl-order-details" style="grid-column: 1 / -1"><div><dt>Buyer</dt><dd>{{ data_get($order,'buyer') }}</dd></div><div><dt>Payment</dt><dd>{{ data_get($order,'payment') }}</dd></div><div><dt>Shipping</dt><dd>{{ data_get($order,'shipping') }}</dd></div><div><dt>Total</dt><dd><strong>₱{{ number_format((float) data_get($order,'total'),2) }}</strong></dd></div></dl>
    </div>
    <p class="sl-order-provider"><strong>Logistics:</strong> {{ data_get($order, 'logistics_provider', 'Not assigned') }} <span aria-hidden="true">·</span> <strong>Rider:</strong> {{ data_get($order, 'shipping', 'Waiting for Logistics') }}</p>
    @if(filled(data_get($order, 'buyer_note')))
        <section class="sl-order-buyer-note" data-buyer-note>
            <strong>Buyer note</strong>
            <p>{{ data_get($order, 'buyer_note') }}</p>
        </section>
    @endif
    @if(data_get($order, 'pickup_progress_total', 0) > 0)
        <p class="mt-2 text-sm text-muted">Pickup progress: {{ data_get($order, 'pickup_progress_collected') }}/{{ data_get($order, 'pickup_progress_total') }} parcels collected · {{ data_get($order, 'pickup_rider') ?: 'Waiting for rider assignment' }}</p>
    @endif
    <footer class="sl-order-card-actions">
        <a href="{{ route('seller.orders',['mode'=>'show','order'=>$id]) }}" class="sl-btn sl-btn-ghost sl-btn-sm">View Details</a>
        @if ($statusKey === 'placed')
            <form method="POST" action="{{ route('seller.orders.status',$dbId) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="confirm"><button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Accept Order</button></form>
        @elseif ($statusKey === 'confirmed')
            <form method="POST" action="{{ route('seller.orders.status',$dbId) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="prepare"><button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Begin Preparation</button></form>
        @elseif ($statusKey === 'preparing')
            <a target="_blank" href="{{ route('seller.orders.waybill',$dbId) }}" class="sl-btn sl-btn-ghost sl-btn-sm">Print Waybill</a>
            @if($order['parcels_need_setup'])
                <a href="{{ route('seller.orders',['mode'=>'show','order'=>$id]) }}" class="sl-btn sl-btn-primary sl-btn-sm">Set Up Parcel Labels</a>
            @elseif($order['unpacked_parcels']->isNotEmpty())
                <form method="POST" action="{{ route('seller.orders.status',$dbId) }}" class="w-full space-y-2">
                    @csrf @method('PATCH')<input type="hidden" name="action" value="pack">
                    <strong class="block">Pack every parcel before pickup</strong>
                    @foreach($order['unpacked_parcels'] as $parcel)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shipment_ids[]" value="{{ $parcel['shipment']->id }}" checked><span>{{ $parcel['item']->product_name }} &middot; {{ $parcel['shipment']->tracking_number }}</span></label>
                    @endforeach
                    <button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Mark selected parcels packed</button>
                </form>
            @elseif($order['ready_parcels']->isNotEmpty())
                <details class="sl-courier-select">
                    <summary class="sl-btn sl-btn-primary sl-btn-sm cursor-pointer">Choose Handover Method</summary>
                    <form method="POST" action="{{ route('seller.logistics.pickup',$dbId) }}">
                        @csrf
                        <div class="sl-provider-list">
                            <label class="sl-provider-option"><input type="radio" name="handover_method" value="logistics_pickup" checked><span class="sl-radio-mark"></span><span class="sl-provider-logo">LP</span><span class="sl-provider-main"><strong>Request Logistics Pickup</strong><small>Logistics assigns an eligible rider to collect the parcel.</small></span></label>
                            <label class="sl-provider-option"><input type="radio" name="handover_method" value="seller_dropoff"><span class="sl-radio-mark"></span><span class="sl-provider-logo">DO</span><span class="sl-provider-main"><strong>Seller Drop-off</strong><small>Bring the parcel and waybill to your assigned center.</small></span></label>
                        </div>
                        <strong class="block px-4 pt-4">Select 1 to 10 packed parcels for this batch (the first parcel is selected by default)</strong>
                        <div class="space-y-1 px-4 pt-2">
                            @foreach($order['ready_parcels']->take(10) as $parcel)
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shipment_ids[]" value="{{ $parcel['shipment']->id }}" @if($loop->first) checked @endif><span>{{ $parcel['item']->product_name }} &middot; {{ $parcel['shipment']->tracking_number }}</span></label>
                            @endforeach
                        </div>
                        <div class="sl-form-grid">
                            <label class="sl-field"><span>Preferred pickup date</span><input name="pickup_date" type="date" min="{{ today()->toDateString() }}" value="{{ now()->addDay()->toDateString() }}"></label>
                            <label class="sl-field"><span>Pickup window</span><select name="pickup_window"><option>10:00 AM - 12:00 PM</option><option>1:00 PM - 3:00 PM</option><option>3:00 PM - 5:00 PM</option></select></label>
                            <label class="sl-field sl-span-2"><span>Handover note</span><textarea name="pickup_note" rows="2" maxlength="1000">Package is sealed and the waybill is attached.</textarea></label>
                        </div>
                        <div class="px-4 pb-4"><button class="sl-btn sl-btn-primary sl-btn-sm" type="submit">Save Handover Choice</button></div>
                    </form>
                </details>
            @endif
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
