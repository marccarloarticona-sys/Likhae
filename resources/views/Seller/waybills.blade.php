<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Parcel Waybills {{ $sellerOrder->seller_order_number }}</title>
    <style>
        *{box-sizing:border-box}
        body{font-family:Arial,sans-serif;color:#1c1917;margin:24px}
        .waybill{max-width:900px;border:2px solid #292524;padding:22px;margin:0 auto 20px}
        .waybill.page-break{break-after:page;page-break-after:always}
        .head{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #292524;padding-bottom:14px}
        .brand-logo{display:block;width:194px;height:42px;max-width:100%;object-fit:contain;object-position:left center}
        .order-ref{text-align:right}
        .order-ref strong{font-size:20px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}
        .box{border:1px solid #a8a29e;padding:12px;line-height:1.45}
        .box h2{font-size:11px;text-transform:uppercase;margin:0 0 6px;color:#57534e}
        .packages{width:100%;border-collapse:collapse;margin-top:18px;table-layout:fixed}
        .packages th,.packages td{border:1px solid #a8a29e;padding:9px;text-align:left;vertical-align:top}
        .packages th{background:#f5f5f4;font-size:11px;text-transform:uppercase}
        .package-title{font-weight:800}
        .package-meta{font-size:12px;line-height:1.5;color:#44403c;margin-top:3px}
        .package-code{font:700 12px/1.3 monospace;letter-spacing:.5px;white-space:nowrap}
        .parcel-barcode-row td{padding:10px 16px}
        .barcode-label{display:flex;justify-content:space-between;align-items:center;gap:12px}
        .package-barcode{width:100%;margin:4px 0 0}
        .package-barcode .waybill-barcode{width:100%!important;max-width:none!important;margin:0!important;padding:4px!important}
        .package-barcode .waybill-barcode__svg svg{width:100%!important;height:auto!important}
        .package-barcode .waybill-barcode strong{display:none!important}
        .summary{display:flex;justify-content:space-between;gap:14px;margin-top:16px}
        .summary .box{flex:1}
        .note{font-size:11px;color:#57534e;margin:12px 0 0}
        @media print{
            body{margin:0}
            .waybill{border:0;max-width:none;margin:0;padding:18px}
            .waybill.page-break{break-after:page;page-break-after:always}
            .packages tr{break-inside:avoid;page-break-inside:avoid}
        }
    </style>
</head>
<body>
@php
    $address = $sellerOrder->order?->address;
    $paymentMethod = $sellerOrder->order?->payments?->first()?->method;
@endphp
@foreach($shipments as $index => $shipment)
    @php
        $item = $shipment->orderItem;
        $codCollection = $shipment->relationLoaded('codCollection') ? $shipment->getRelation('codCollection') : null;
        $codAmount = $codCollection?->expected_amount;
        if ($paymentMethod === 'COD' && $codAmount === null) {
            $codAmount = $item
                ? \App\Support\OrderItemParcelAmount::forShipment($sellerOrder, $shipment)['total']
                : ($totalParcelCount === 1 ? (float) $sellerOrder->grand_total : null);
        }
    @endphp
    <main class="waybill {{ $loop->last ? '' : 'page-break' }}">
        <header class="head">
            <div>
                <img class="brand-logo" src="{{ asset('images/likhae-logo-black-text.png') }}" alt="LIKHAE" width="194" height="42">
                <strong>Seller Parcel Waybill</strong>
            </div>
            <div class="order-ref"><strong>{{ $sellerOrder->seller_order_number }}</strong><br><small>Order {{ $sellerOrder->order?->order_number }}</small><br><small>Parcel {{ $index + 1 }} of {{ $totalParcelCount }}</small></div>
        </header>

        <section class="grid">
            <div class="box"><h2>From</h2><strong>{{ $sellerOrder->sellerProfile?->business_name }}</strong><br>{{ $sellerOrder->sellerProfile?->businessAddress?->formatted() }}</div>
            <div class="box"><h2>Deliver to</h2><strong>{{ $address?->recipient_name }}</strong><br>{{ $address?->contact_number }}<br>{{ $address?->formatted() }}</div>
        </section>

        <table class="packages">
            <colgroup><col style="width:10%"><col style="width:35%"><col style="width:15%"><col style="width:40%"></colgroup>
            <thead><tr><th>Parcel</th><th>Product and quantity</th><th>Weight</th><th>Packed size (L × W × H)</th></tr></thead>
            <tbody>
                <tr>
                    <td><span class="package-title">{{ $index + 1 }} of {{ $totalParcelCount }}</span></td>
                    <td>
                        <span class="package-title">{{ $item?->product_name ?? 'Order parcel' }}</span>
                        @if($item)<div class="package-meta">Variant: {{ $item->variant_description ?: 'Standard' }}@if($item->sku)<br>SKU: {{ $item->sku }}@endif</div>@endif
                        <div class="package-meta">Quantity: {{ $shipment->parcel_quantity }}</div>
                        @if($item?->hasAdditionalCare())<div class="package-meta"><strong>FRAGILE / HANDLE WITH CARE</strong></div>@endif
                    </td>
                    <td>
                        @php($weight = \App\Support\ParcelWeightEstimator::recordedWeight($shipment) ?? ($item ? \App\Support\ParcelWeightEstimator::productEstimate($shipment) : null))
                        @if($weight !== null){{ number_format($weight, 3) }} kg @else Not recorded @endif
                    </td>
                    <td>
                        @if($shipment->parcel_length_cm !== null && $shipment->parcel_width_cm !== null && $shipment->parcel_height_cm !== null)
                            {{ number_format((float) $shipment->parcel_length_cm, 2) }} × {{ number_format((float) $shipment->parcel_width_cm, 2) }} × {{ number_format((float) $shipment->parcel_height_cm, 2) }} cm
                        @else
                            Not recorded
                        @endif
                    </td>
                </tr>
                <tr class="parcel-barcode-row">
                    <td colspan="4">
                        <div class="barcode-label"><strong>Parcel scan code</strong><span class="package-code">{{ $shipment->tracking_number }}</span></div>
                        <div class="package-barcode"><x-shared.waybill-barcode :tracking="$shipment->tracking_number" :height="110" /></div>
                    </td>
                </tr>
            </tbody>
        </table>

        <section class="summary">
            <div class="box"><strong>Payment</strong><br>@if($paymentMethod === 'COD') COD · {{ $codAmount !== null ? 'PHP '.number_format((float) $codAmount, 2) : 'See order payment details' }} @elseif($paymentMethod === 'ONLINE') Prepaid @else Payment method unavailable @endif</div>
        </section>
        <p class="note">This waybill is for parcel {{ $index + 1 }} only. Use its scan code for Logistics intake and tracking.</p>
    </main>
@endforeach
<script>window.addEventListener('load',()=>window.print())</script>
</body>
</html>
