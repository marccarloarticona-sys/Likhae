@extends('layouts.seller.app')

@php
    $currentMode = $mode ?? request('mode', 'index');
    $currentStatus = $status ?? request('status', 'all');
    $order = $currentMode === 'show' ? ($selectedOrderView ?? null) : null;
@endphp

@section('title', $currentMode === 'show' && $order ? 'Order #'.$order['id'] : 'Orders')
@section('active', 'orders')
@section('subtitle', 'Review purchases and move each order through fulfillment.')

@section('content')
<div class="sl-page">
    @if(session('status'))<div class="sl-card mb-4 border border-green-200 bg-green-50 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any() && ! session('parcel_split_message'))<div class="sl-card mb-4 border border-red-200 bg-red-50 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    @if ($currentMode === 'show')
        @if($order)
            <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Order Details</span><h2>Order #{{ $order['id'] }}</h2><p>Placed {{ $order['date'] }}</p></div><a href="{{ route('seller.orders',['status'=>$order['status_key'],'q'=>$search ?? null,'from_date'=>$fromDate ?? null,'to_date'=>$toDate ?? null]) }}" class="sl-btn sl-btn-ghost">Back to Orders</a></div>
            @if(filled($order['buyer_note'] ?? null))
                <section class="sl-card" data-buyer-note><h3>Buyer Note</h3><p class="whitespace-pre-line">{{ $order['buyer_note'] }}</p></section>
            @endif
            <div class="sl-order-detail-layout"><div class="sl-order-detail-main">
                <section class="sl-card sl-parcel-label-card">
                    <header class="sl-card-head">
                        <div>
                            <h2>Products and parcel labels</h2>
                            @if($order['has_unresolved_legacy_shipment'])
                                <p>This order still has a legacy parcel whose item mapping needs review.</p>
                            @else
                                <p>Each parcel has its own scan code and packed dimensions. One order waybill lists all parcels.</p>
                            @endif
                        </div>
                        <div class="sl-order-heading-actions">
                            @if(! $order['has_unresolved_legacy_shipment'] && ! $order['parcels_need_setup'] && $order['can_print_waybills'])
                                <a class="sl-btn sl-btn-primary" href="{{ route('seller.orders.waybill', $order['db_id']) }}">Print order waybill</a>
                            @endif
                            <span class="sl-status is-warning">{{ $order['status'] }}</span>
                        </div>
                    </header>

                    @if($order['has_unresolved_legacy_shipment'])
                        <div class="sl-legacy-parcel-note">
                            <strong>Legacy parcel preserved</strong>
                            @foreach($order['unresolved_legacy_shipments'] as $legacyShipment)
                                <p>Tracking {{ $legacyShipment->tracking_number }} &middot; {{ str($legacyShipment->current_status)->replace('_', ' ')->title() }}</p>
                            @endforeach
                            <p>The parcel history remains attached to its original shipment until Logistics reconciles the item mapping.</p>
                        </div>
                    @elseif($order['parcels_need_setup'])
                        <form method="POST" action="{{ route('seller.orders.parcels.ensure', $order['db_id']) }}" class="sl-parcel-setup">
                            @csrf
                            <button class="sl-btn sl-btn-primary" type="submit">Create item parcel labels</button>
                            <p>This creates the starting parcel for each product line. You can split a line before packing.</p>
                        </form>
                    @endif

                    <div class="sl-parcel-item-list">
                        @foreach($order['items'] as $item)
                            <article class="sl-parcel-item">
                                <div class="sl-parcel-item-head">
                                    <x-shared.product-thumbnail :item="$item" size="72" class="sl-order-thumb"/>
                                    <div class="sl-parcel-item-info">
                                        <strong class="sl-parcel-product-name">{{ $item->product_name }}</strong>
                                        <span class="sl-parcel-product-variant">Variant: {{ $item->variant_description ?: 'Standard' }}</span>
                                        <small>
                                            @if($item->original_unit_price !== null)
                                                Original unit price: PHP {{ number_format((float) $item->original_unit_price, 2) }} &middot;
                                            @endif
                                            Effective unit price: PHP {{ number_format((float) $item->unit_price, 2) }} &middot; Qty: {{ $item->quantity }}
                                            @if((float) $item->discount_amount > 0)
                                                &middot; Line discount: PHP {{ number_format((float) $item->discount_amount, 2) }}
                                            @endif
                                        </small>
                                        @foreach((array) $item->promotion_snapshot as $promotionSnapshot)
                                            @if((int) data_get($promotionSnapshot, 'free_reward_quantity', 0) > 0)
                                                <span class="sl-parcel-promotion">{{ data_get($promotionSnapshot, 'name', 'Buy X Get Y') }} &middot; {{ (int) data_get($promotionSnapshot, 'free_reward_quantity') }} promotional free reward item(s)</span>
                                            @else
                                                <span class="sl-parcel-promotion">{{ data_get($promotionSnapshot, 'name', 'Seller offer') }} &middot; promotional item</span>
                                            @endif
                                        @endforeach
                                    </div>
                                    <strong class="sl-parcel-item-total">PHP {{ number_format((float) $item->line_total, 2) }}</strong>
                                </div>

                                @php
                                    $itemParcels = $order['shipments_by_item']->get($item->id, collect());
                                @endphp

                                @if($itemParcels->isNotEmpty())
                                    <div class="sl-parcel-grid">
                                        @foreach($itemParcels as $parcel)
                                            @php
                                                $recordedParcelWeightKg = \App\Support\ParcelWeightEstimator::recordedWeight($parcel);
                                                $parcelWeightKg = $recordedParcelWeightKg
                                                    ?? \App\Support\ParcelWeightEstimator::productEstimate($parcel);
                                                $packedDimensionsSaved = is_numeric($parcel->parcel_length_cm)
                                                    && is_numeric($parcel->parcel_width_cm)
                                                    && is_numeric($parcel->parcel_height_cm);
                                                $parcelAlreadyPacked = $parcel->events->contains(fn ($event) => $event->status === 'PREPARING'
                                                    && str_starts_with((string) $event->notes, 'SELLER_PACKED_CHECKPOINT:'));
                                                $parcelStatus = in_array((int) $parcel->id, $order['seller_dropoff_shipment_ids'], true)
                                                    ? 'Waiting for Intake'
                                                    : str($parcel->current_status)->replace('_', ' ')->title();
                                            @endphp

                                            <article class="sl-parcel-card">
                                                <div class="sl-parcel-card-header">
                                                    <strong>Parcel {{ $loop->iteration }} of {{ $itemParcels->count() }}</strong>
                                                    <span class="sl-parcel-status">{{ $parcelStatus }}</span>
                                                </div>
                                                <p class="sl-parcel-tracking">Tracking code <strong>{{ $parcel->tracking_number }}</strong></p>
                                                <dl class="sl-parcel-facts">
                                                    <div><dt>Quantity</dt><dd>{{ $parcel->parcel_quantity }}</dd></div>
                                                    <div>
                                                        <dt>{{ $recordedParcelWeightKg !== null ? 'Packed weight' : 'Estimated weight' }}</dt>
                                                        <dd>
                                                            @if($parcelWeightKg !== null)
                                                                {{ number_format($parcelWeightKg, 3) }} kg
                                                            @else
                                                                Not available
                                                            @endif
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt>Packed size</dt>
                                                        <dd>
                                                            @if($packedDimensionsSaved)
                                                                {{ number_format((float) $parcel->parcel_length_cm, 2) }} &times;
                                                                {{ number_format((float) $parcel->parcel_width_cm, 2) }} &times;
                                                                {{ number_format((float) $parcel->parcel_height_cm, 2) }} cm
                                                            @else
                                                                Not entered
                                                            @endif
                                                        </dd>
                                                    </div>
                                                </dl>

                                                @if($order['parcel_dimensions_ready'] && in_array($order['status_key'], ['preparing', 'ready-for-pickup'], true) && $parcel->current_status === 'PREPARING' && ! $parcelAlreadyPacked)
                                                    <form method="POST" action="{{ route('seller.orders.parcels.dimensions', [$order['db_id'], $parcel->id]) }}" class="sl-parcel-dimensions-form">
                                                        @csrf
                                                        <strong>Actual packed parcel dimensions</strong>
                                                        <p>Measure the outside of this parcel in centimeters after packing. Product dimensions are not used here.</p>
                                                        <div class="sl-parcel-dimension-fields">
                                                            <label>Length (cm)<input class="sl-input" type="number" name="length_cm" min="0.01" max="99999999.99" step="0.01" value="{{ $parcel->parcel_length_cm }}" required></label>
                                                            <label>Width (cm)<input class="sl-input" type="number" name="width_cm" min="0.01" max="99999999.99" step="0.01" value="{{ $parcel->parcel_width_cm }}" required></label>
                                                            <label>Height (cm)<input class="sl-input" type="number" name="height_cm" min="0.01" max="99999999.99" step="0.01" value="{{ $parcel->parcel_height_cm }}" required></label>
                                                        </div>
                                                        <button class="sl-btn sl-btn-ghost" type="submit">Save packed dimensions</button>
                                                    </form>
                                                @endif
                                            </article>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="sl-parcel-empty">Parcel label not created yet.</p>
                                @endif

                                @if($item->quantity > 1 && $itemParcels->count() === 1 && in_array($order['status_key'], ['placed', 'confirmed', 'preparing', 'ready-for-pickup'], true) && in_array($itemParcels->first()->current_status, ['PLACED', 'CONFIRMED', 'PREPARING'], true) && $itemParcels->first()->parcel_weight_kg === null && $itemParcels->first()->parcel_length_cm === null && $itemParcels->first()->parcel_width_cm === null && $itemParcels->first()->parcel_height_cm === null && ! $itemParcels->first()->events->contains(fn ($event) => $event->status === 'PREPARING' && str_starts_with((string) $event->notes, 'SELLER_PACKED_CHECKPOINT:')))
                                    @if($order['parcel_splitting_ready'])
                                        @php
                                            $exampleFirstParcelQty = intdiv((int) $item->quantity + 1, 2);
                                            $exampleSecondParcelQty = (int) $item->quantity - $exampleFirstParcelQty;
                                            $splitInputValue = (int) session('parcel_split_item_id') === (int) $item->id
                                                ? old('parcel_quantities')
                                                : null;
                                        @endphp
                                        <form method="POST" action="{{ route('seller.orders.parcels.split', [$order['db_id'], $item->id]) }}" class="sl-parcel-split-form">
                                            @csrf
                                            <label for="parcel-quantities-{{ $item->id }}">Split into parcel quantities</label>
                                            <input id="parcel-quantities-{{ $item->id }}" class="sl-input" name="parcel_quantities" value="{{ $splitInputValue }}" placeholder="{{ $exampleFirstParcelQty }}, {{ $exampleSecondParcelQty }}" inputmode="numeric" aria-describedby="parcel-split-help-{{ $item->id }}" required>
                                            @if((int) session('parcel_split_item_id') === (int) $item->id && session('parcel_split_message'))
                                                <p class="sl-parcel-split-error" role="alert">{{ session('parcel_split_message') }}</p>
                                            @endif
                                            <p id="parcel-split-help-{{ $item->id }}">Enter 2–10 parcel quantities that add up to {{ $item->quantity }}. Each parcel gets its own tracking code, calculated weight, and separately measured packed dimensions. Shipping is charged once for the order.</p>
                                            <button class="sl-btn sl-btn-ghost" type="submit">Update parcel split</button>
                                        </form>
                                    @else
                                        <p class="sl-parcel-splitting-unavailable">Parcel splitting is temporarily unavailable.</p>
                                    @endif
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            <section class="sl-card">
                <header class="sl-card-head"><div><h2>Fulfillment Actions</h2><p>Save actual dimensions for each packed parcel before marking it packed. Then mark parcels ready; choose a Logistics center on the Seller Pickup page.</p></div></header>
                @php
    $orderPickupStatus = $order['pickup_status'] ?? '';
    [$orderNextStep, $orderNextActor] = match ($order['status_key']) {
        'placed' => ['Accept the order or cancel it with a reason.', 'Seller'],
        'confirmed' => ['Begin preparing the ordered items.', 'Seller'],
        'preparing' => ['Enter the actual dimensions of each packed parcel, then mark it packed and ready. After that, choose a Logistics center in Seller Pickup.', 'Seller'],
        'ready-for-pickup' => match ($orderPickupStatus) {
            'Waiting for Intake' => ['Bring the selected parcels with the order waybill to your assigned center for intake scanning.', 'Seller / Logistics'],
            'Waiting for Logistics' => $order['has_selected_logistics_center']
                ? (($order['pickup_fallback_acceptance_pending'] ?? false)
                    ? ['Pickup request sent to '.$order['logistics_provider'].'. The origin center must accept the route before a pickup Rider is assigned.', 'Logistics']
                    : ['Pickup request sent to '.$order['logistics_provider'].'. Wait for that center to assign a pickup rider.', 'Logistics'])
                : ['Choose a Logistics center in Seller Pickup to submit the pickup request.', 'Seller'],
            'Pickup scheduled' => ['Keep parcels ready for the rider assigned by '.($order['logistics_provider'] ?: 'your Logistics center').'.', 'Pickup rider'],
            default => ['Assign a pickup rider to collect the ready parcels.', 'Logistics'],
        },
        'shipping' => ($order['buyer_receipt_pending'] ?? false)
            ? ['Delivery is complete. The Buyer must confirm receipt before the order is completed.', 'Buyer']
            : ['Deliver the parcel and record proof or an attempt outcome.', 'Delivery rider'],
        'delivered' => ['Confirm receipt, or wait for the Buyer to confirm.', 'Buyer'],
        'returns' => ['Review the return or refund request linked to this order.', 'Seller'],
        'completed', 'cancelled' => ['No further fulfillment action is required.', 'No action pending'],
        default => ['Check parcel tracking for the next scan or handoff.', 'Logistics / delivery rider'],
    };
@endphp
                <x-shared.status-guidance :status="$order['status']" :next="$orderNextStep" :actor="$orderNextActor" />
                <div class="sl-action-panel">
                    <span class="sl-action-panel-icon">&#10003;</span>
                    <div class="sl-action-copy">
                        <strong>{{ $order['status'] }}</strong>
                        <p>
                            @if(($order['pickup_status'] ?? '') === 'Waiting for Intake')
                                Take the selected parcels with the order waybill to your assigned center for intake scanning.
                            @elseif(($order['pickup_status'] ?? '') === 'Waiting for Logistics')
                                @if($order['has_selected_logistics_center'])
                                    @if($order['pickup_fallback_acceptance_pending'] ?? false)
                                        Pickup request sent to {{ $order['logistics_provider'] }}. Waiting for the origin center to accept the route; Rider assignment follows acceptance.
                                    @else
                                        Pickup request sent to {{ $order['logistics_provider'] }}. Waiting for that center to assign a rider.
                                    @endif
                                @else
                                    Choose your Logistics center in Seller Pickup to submit a pickup request.
                                @endif
                            @else
                                Ready parcels can be picked up while other parcels remain in preparation.
                            @endif
                        </p>
                    </div>
                    <div class="sl-action-controls">
                        @if($order['status_key']==='placed')
                            <form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="confirm"><button class="sl-btn sl-btn-primary" type="submit">Accept Order</button></form>
                        @endif
                        @if($order['status_key']==='confirmed')
                            <form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="prepare"><button class="sl-btn sl-btn-primary" type="submit">Begin Preparation</button></form>
                        @endif
                        @if(in_array($order['status_key'], ['preparing', 'ready-for-pickup'], true))
                            @if($order['unpacked_parcels']->isNotEmpty())
                                <form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}" class="w-full space-y-2">@csrf @method('PATCH')<input type="hidden" name="action" value="pack"><strong class="block">Pack parcels</strong>
                                    @foreach($order['unpacked_parcels'] as $parcel)
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shipment_ids[]" value="{{ $parcel['shipment']->id }}" @disabled(! $parcel['can_mark_packed']) @if($parcel['can_mark_packed']) checked @endif><span>{{ $parcel['item']->product_name }} &middot; {{ $parcel['shipment']->tracking_number }}@if(! $parcel['can_mark_packed']) <small class="block text-amber-800">Save packed parcel dimensions above first.</small>@endif</span></label>
                                    @endforeach
                                    <button class="sl-btn sl-btn-primary" type="submit" @disabled($order['unpacked_parcels']->where('can_mark_packed', true)->isEmpty())>Mark selected parcels packed</button>
                                </form>
                            @endif
                            @if($order['ready_parcels']->isNotEmpty() && $order['unpacked_parcels']->isEmpty())
                                <form method="POST" action="{{ route('seller.logistics.pickup',$order['db_id']) }}" class="w-full space-y-2">@csrf<strong class="block">Choose handover for up to 10 packed parcels</strong>
                                    <div class="sl-handover-options">
                                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line p-3 text-sm"><input type="radio" name="handover_method" value="logistics_pickup" checked><span><strong class="block">Request Logistics pickup</strong><small class="text-muted">Mark these parcels ready, then choose a Logistics center on the next screen.</small></span></label>
                                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-line p-3 text-sm"><input type="radio" name="handover_method" value="seller_dropoff"><span><strong class="block">Seller drop-off</strong><small class="text-muted">Print the order waybill and bring the selected parcels to your assigned center for intake scanning.</small></span></label>
                                    </div>
                                    @foreach($order['ready_parcels']->take(10) as $parcel)
                                        <label class="flex flex-wrap items-center gap-2 text-sm"><input type="checkbox" name="shipment_ids[]" value="{{ $parcel['shipment']->id }}" @if($loop->first) checked @endif><span>{{ $parcel['item']->product_name }} &middot; {{ $parcel['shipment']->tracking_number }}</span></label>
                                    @endforeach
                                    <p class="text-xs text-stone-600">Select 1 to 10 parcels from this order. Remaining parcels can go in another pickup batch.</p>
                                    <button class="sl-btn sl-btn-primary" type="submit">Confirm handover method</button>
                                </form>
                            @endif
                        @endif
                        @if($order['status_key']==='ready-for-pickup')
                            @if(($order['pickup_status'] ?? '') === 'Pickup scheduled')
                                <span class="sl-status is-info">Pickup scheduled &middot; {{ $order['pickup_rider'] }}</span>
                            @elseif(($order['pickup_status'] ?? '') === 'Waiting for Logistics')
                                @if($order['has_selected_logistics_center'])
                                    <span class="sl-status is-info">Waiting for {{ $order['logistics_provider'] }}</span>
                                    <a class="sl-btn sl-btn-ghost" href="{{ route('seller.logistics') }}">View pickup requests</a>
                                @else
                                    <a class="sl-btn sl-btn-primary" href="{{ route('seller.logistics') }}">Choose Logistics center &amp; request pickup</a>
                                @endif
                            @endif
                        @endif
                        @if($order['can_cancel'])
                            <form method="POST" action="{{ route('seller.orders.status',$order['db_id']) }}" class="sl-cancel-order-form">@csrf @method('PATCH')<input type="hidden" name="action" value="cancel"><label class="sl-field"><span>Cancellation reason</span><textarea name="cancellation_reason" maxlength="500" required rows="2" placeholder="Explain why you are cancelling this order"></textarea></label><button class="sl-btn sl-btn-ghost" type="submit">Cancel Order</button></form>
                        @endif
                    </div>
                </div>
                </section></div><aside class="sl-order-detail-side"><section class="sl-card sl-order-provider-detail"><h3>Seller Pickup</h3><p>{{ data_get($order, 'pickup_status', 'Waiting for Logistics') }}</p><dl class="sl-detail-list"><div><dt>Logistics center</dt><dd>{{ data_get($order, 'logistics_provider', 'Not selected') }}</dd></div><div><dt>Pickup Batch</dt><dd>{{ data_get($order, 'pickup_batch', 'Not assigned') }}</dd></div><div><dt>Assigned Rider</dt><dd>{{ data_get($order, 'pickup_rider', 'Waiting for Logistics') }}</dd></div><div><dt>Parcels in this pickup</dt><dd>{{ data_get($order, 'pickup_batch_size', '—') }}</dd></div>@if(($order['pickup_status'] ?? '') === 'Picked up')<div><dt>Collected by Rider</dt><dd>{{ data_get($order, 'pickup_collected_at')?->format('M d, Y g:i A') ?? 'Time unavailable' }}</dd></div><div><dt>Now heading to</dt><dd>{{ data_get($order, 'pickup_hub', 'Assigned logistics center') }}</dd></div>@endif</dl></section><section class="sl-card"><h3>Buyer Information</h3><dl class="sl-detail-list"><div><dt>Name</dt><dd>{{ $order['buyer'] }}</dd></div><div><dt>Delivery Address</dt><dd>{{ $order['shipping_address'] }}</dd></div></dl><a href="{{ route('seller.messages',['buyer'=>$order['buyer_id']]) }}" class="sl-btn sl-btn-soft sl-btn-block">Message Buyer</a></section><section class="sl-card"><h3>Payment Summary</h3><dl class="sl-price-list">
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
            <section class="sl-card sl-order-filters"><form method="GET" action="{{ route('seller.orders') }}" class="sl-search-input"><input type="hidden" name="status" value="returns"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="{{ request('q') }}" placeholder="Search request, tracking, order, buyer, or product"><select name="refund_status" class="sl-field"><option value="">All refund statuses</option>@foreach(['SELLER_REVIEW','APPROVED','REJECTED','REFUND_SENT','CONFIRMED','ESCALATED','ADMIN_REFUND_REQUIRED','DISPUTE_RESOLVED'] as $refundFilter)<option value="{{ $refundFilter }}" @selected(($refundStatus ?? '') === $refundFilter)>{{ str($refundFilter)->headline() }}</option>@endforeach</select><button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">Search</button></form></section>
            @if($returnWorkflowSchemaReady)
                <section class="sl-card">
                    <header class="sl-card-head">
                        <div>
                            <span class="sl-eyebrow">Failed-delivery returns</span>
                            <h2>Return parcels</h2>
                            <p>Review the return route and confirm receipt after the rider hands the parcel to you.</p>
                        </div>
                    </header>
                    <div class="sl-table-wrap">
                        <table class="sl-table">
                            <thead><tr><th>Return tracking / order</th><th>Product / reason</th><th>Rider handoff</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                @forelse($sellerReturnWorkflows as $returnWorkflow)
                                    @php
    $returnShipment = $returnWorkflow->shipment;
    $returnTrackingNumber = $returnWorkflow->return_tracking_number ?: $returnShipment?->tracking_number;
    $awaitingSellerReceipt = $returnWorkflow->status === \App\Models\Logistics\ShipmentReturnWorkflow::AWAITING_SELLER_CONFIRMATION;
    $sellerReceived = $returnWorkflow->status === \App\Models\Logistics\ShipmentReturnWorkflow::SELLER_RECEIVED;
@endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $returnTrackingNumber }}</strong>
                                            <small>{{ $returnShipment?->sellerOrder?->seller_order_number }}</small>
                                        </td>
                                        <td>
                                            {{ $returnShipment?->orderItem?->product_name ?: 'Return parcel' }}
                                            <small>{{ $returnWorkflow->reason }}</small>
                                        </td>
                                        <td>
                                            @if($returnWorkflow->seller_handoff_at)
                                                <strong>{{ $returnWorkflow->sellerHandoffBy?->name ?: 'Return rider' }}</strong>
                                                <small>Handed off {{ $returnWorkflow->seller_handoff_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</small>
                                            @else
                                                <strong>{{ $returnWorkflow->logisticsCenter?->business_name ?: 'Return in transit' }}</strong>
                                                <small>Waiting for the rider to hand over the parcel.</small>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="sl-status {{ $sellerReceived ? 'is-success' : ($awaitingSellerReceipt ? 'is-warning' : 'is-info') }}">
                                                {{ $sellerReceived ? 'Seller receipt confirmed' : ($awaitingSellerReceipt ? 'Awaiting your confirmation' : str($returnWorkflow->status)->replace('_', ' ')->title()) }}
                                            </span>
                                            @if($awaitingSellerReceipt)
                                                <small>The rider recorded the handoff. Confirm after receiving the parcel.</small>
                                            @elseif($sellerReceived)
                                                <small>Confirmed by {{ $returnWorkflow->sellerReceivedBy?->name ?: 'the seller' }}@if($returnWorkflow->seller_received_at) · {{ $returnWorkflow->seller_received_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}@endif</small>
                                            @else
                                                <small>Waiting for the next return custody step.</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if($awaitingSellerReceipt)
                                                <form method="POST" action="{{ route('seller.logistics.returns.confirm', $returnWorkflow) }}" class="grid min-w-44 gap-1">
                                                    @csrf
                                                    <input name="notes" maxlength="1000" class="w-full rounded border border-line p-1 text-xs" placeholder="Optional receipt note" aria-label="Receipt note for {{ $returnTrackingNumber }}">
                                                    <button class="sl-btn sl-btn-primary sl-btn-sm">Confirm seller receipt</button>
                                                </form>
                                            @elseif($sellerReceived)
                                                <span class="text-sm text-green-700">Receipt confirmed</span>
                                            @else
                                                <span class="text-sm text-muted">No action yet</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">No failed-delivery return parcels are in progress.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($sellerReturnWorkflows->count() === 30)
                        <p class="mt-3 text-sm text-muted">Showing the 30 most recently updated return parcels.</p>
                    @endif
                </section>
            @else
                <section class="sl-card border border-amber-200 bg-amber-50 text-sm text-amber-900">Failed-delivery return parcels will appear here after the return-workflow migration is installed.</section>
            @endif
            <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Seller-first workflow</span><h2>Returns & Refunds</h2><p>Only refund requests for your Seller-owned items are shown.</p></div></header><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Request</th><th>Order / Buyer</th><th>Products</th><th>Requested</th><th>Status</th><th></th></tr></thead><tbody>@forelse($refundRequests ?? [] as $refund)<tr><td><strong>{{ $refund->request?->request_number }}</strong><small>{{ $refund->created_at?->format('M d, Y g:i A') }}</small></td><td>{{ $refund->request?->order?->order_number }}<small>{{ $refund->request?->order?->buyer?->name }}</small></td><td>{{ $refund->items->map(fn($item)=>$item->orderItem?->product_name)->filter()->join(', ') ?: 'Items' }}</td><td>PHP {{ number_format((float)$refund->requested_amount,2) }}</td><td><span class="sl-status is-warning">{{ str($refund->status)->headline() }}</span></td><td><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.orders.refunds.show',$refund) }}">View Request</a></td></tr>@empty<tr><td colspan="6">No Seller refund requests yet.</td></tr>@endforelse</tbody></table></div>@if($refundRequests?->hasPages())<div class="ad-card-body">{{ $refundRequests->links() }}</div>@endif</section>
        @else
            <section class="sl-card sl-order-filters">
                <form method="GET" action="{{ route('seller.orders') }}" class="sl-order-filter-form">
                    <input type="hidden" name="status" value="{{ $currentStatus }}">
                    <label class="sl-order-filter-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                        <input type="search" name="q" value="{{ $search ?? '' }}" placeholder="Search order, buyer, or product" aria-label="Search order, buyer, or product">
                    </label>
                    <div class="sl-order-filter-controls">
                        <label class="sl-field"><span>From date</span><input type="date" name="from_date" value="{{ $fromDate ?? '' }}"></label>
                        <label class="sl-field"><span>To date</span><input type="date" name="to_date" value="{{ $toDate ?? '' }}"></label>
                        <button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">Apply Filters</button>
                    </div>
                </form>
            </section>
            <div class="sl-order-list">@php($visibleOrders = $currentStatus === 'all' ? $sellerOrders : $sellerOrders->where('status_key', $currentStatus)) @forelse($visibleOrders as $item)<x-seller.order-card :order="$item" />@empty<section class="sl-card"><x-seller.empty-state title="No orders in this stage" message="Orders will appear here when they enter this fulfillment stage." action="View All Orders" :href="route('seller.orders')" /></section>@endforelse</div>@if($orders->hasPages())<div class="ad-card-body">{{ $orders->links() }}</div>@endif<div class="sl-no-results sl-card" data-order-empty hidden>No orders match your search.</div>
        @endif
    @endif
</div>
@endsection
