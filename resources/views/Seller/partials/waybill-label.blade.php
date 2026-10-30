@php
    $address = $sellerOrder->order?->address;
    $paymentMethod = $sellerOrder->order?->payments?->first()?->method;
    $weightKnown = is_numeric($shipment->parcel_weight_kg) && (float) $shipment->parcel_weight_kg > 0;
    $codCollection = $shipment->relationLoaded('codCollection') ? $shipment->getRelation('codCollection') : null;
    $codAmount = $codCollection?->expected_amount;
    if ($paymentMethod === 'COD' && $codAmount === null) {
        $codAmount = $item
            ? \App\Support\OrderItemParcelAmount::forItem($sellerOrder, $item)['total']
            : $sellerOrder->grand_total;
    }
    @endphp
<main class="label {{ $pageBreak ? 'page-break' : '' }}">
    <header class="head"><div><img class="brand-logo" src="{{ asset('images/likhae-logo-black-text.png') }}" alt="LIKHAE" width="194" height="42"><div>Marketplace Parcel Waybill</div></div><div><strong>Order</strong><br>{{ $sellerOrder->seller_order_number }}<br><small>{{ $sellerOrder->order?->order_number }}</small></div></header>
    <div class="tracking">{{ $shipment->tracking_number }}</div>
    <x-shared.waybill-barcode :tracking="$shipment->tracking_number" />
    <section class="grid">
        <div class="box"><h2>From</h2><strong>{{ $sellerOrder->sellerProfile?->business_name }}</strong><br>{{ $sellerOrder->sellerProfile?->businessAddress?->formatted() }}</div>
        <div class="box"><h2>Deliver to</h2><strong>{{ $address?->recipient_name }}</strong><br>{{ $address?->contact_number }}<br>{{ $address?->formatted() }}</div>
    </section>
    @if($item?->hasAdditionalCare())<div style="margin-top:14px;border:2px solid #b45309;background:#fffbeb;padding:10px;font-weight:800;letter-spacing:.5px">FRAGILE / ADDITIONAL CARE — HANDLE WITH CARE</div>@endif
    @if($item)
        <article class="item" aria-label="Parcel item">
            <div class="item-number">Parcel {{ $parcelNumber }}</div>
            <div><div class="item-name">{{ $item->product_name }}</div><div class="item-meta">Variant: {{ $item->variant_description ?: 'Standard' }}<br>SKU: {{ $item->sku ?: 'Not provided' }}</div></div>
            <strong>Qty {{ $item->quantity }}</strong>
        </article>
    @else
        <p style="margin-top:18px;padding:12px;border:1px solid #a8a29e">Legacy parcel contents: {{ $sellerOrder->items->pluck('product_name')->implode(', ') }}. This tracking number covers the legacy parcel.</p>
    @endif
    <section style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px">
        <div class="box"><strong>Parcel weight</strong><br>@if($weightKnown){{ number_format((float) $shipment->parcel_weight_kg, 3) }} kg @else Weight not recorded @endif</div>
        <div class="box"><strong>Payment</strong><br>@if($paymentMethod === 'COD') COD - PHP {{ number_format((float) $codAmount, 2) }} @elseif($paymentMethod === 'ONLINE') Prepaid @else Payment method unavailable @endif</div>
    </section>
    <p><strong>Destination:</strong> {{ collect([$shipment->destination_barangay_name, $shipment->destination_municipality_name, $shipment->destination_province_name])->filter()->implode(', ') }}</p>
</main>
