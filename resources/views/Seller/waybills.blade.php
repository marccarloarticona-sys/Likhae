<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Parcel Waybills {{ $sellerOrder->seller_order_number }}</title>
    <style>
        @page{size:4in 6in portrait;margin:0}
        *{box-sizing:border-box}
        body{font-family:Arial,sans-serif;color:#1c1917;margin:18px;background:#f5f5f4;font-size:11px}
        .waybill{width:min(100%,390px);min-height:560px;border:1.5px solid #292524;padding:14px;margin:0 auto 18px;background:#fff}
        .waybill.page-break{break-after:page;page-break-after:always}
        .head{display:flex;justify-content:space-between;align-items:center;border-bottom:1.5px solid #292524;padding-bottom:8px;gap:8px}
        .brand-logo{display:block;width:142px;height:31px;max-width:100%;object-fit:contain;object-position:left center}
        .head strong{font-size:11px}
        .order-ref{text-align:right}
        .order-ref strong{font-size:14px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:9px}
        .box{border:1px solid #a8a29e;padding:7px;line-height:1.25;overflow-wrap:anywhere}
        .box h2{font-size:8px;text-transform:uppercase;margin:0 0 4px;color:#57534e}
        .package-info{display:grid;grid-template-columns:1.25fr .75fr;gap:7px;margin-top:9px}
        .package-product,.package-specs{border:1px solid #a8a29e;padding:7px;line-height:1.25;min-width:0}
        .package-product h2,.package-specs h2{font-size:8px;text-transform:uppercase;margin:0 0 4px;color:#57534e}
        .package-title{font-weight:800;font-size:12px;overflow-wrap:anywhere}
        .package-line+.package-line{border-top:1px solid #d6d3d1;margin-top:5px;padding-top:5px}
        .package-meta{font-size:9px;line-height:1.3;color:#44403c;margin-top:2px;overflow-wrap:anywhere}
        .package-specs>div+div{border-top:1px solid #d6d3d1;margin-top:5px;padding-top:5px}
        .package-specs small{display:block;color:#57534e;font-size:8px;font-weight:700;text-transform:uppercase}
        .package-code{font:700 9px/1.2 monospace;letter-spacing:.2px;overflow-wrap:anywhere;text-align:right}
        .scan-card{border:1px solid #a8a29e;padding:11px;margin-top:11px}
        .barcode-label{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:9px}
        .barcode-label strong{font-size:9px;text-transform:uppercase}
        .scan-codes{display:block}
        .package-barcode{width:100%;margin:0;min-width:0}
        .package-barcode .waybill-barcode{width:100%!important;max-width:none!important;margin:0!important;padding:4px!important}
        .package-barcode .waybill-barcode__svg svg{width:100%!important;height:auto!important}
        .package-barcode .waybill-barcode strong{display:none!important}
        .waybill-qr{display:grid;place-items:center;width:82px;height:82px;padding:2px;background:#fff}
        .waybill-qr svg{display:block;width:100%!important;height:100%!important}
        .attempts{display:grid;grid-template-columns:minmax(0,1fr) 108px minmax(0,1fr);align-items:stretch;gap:6px;margin-top:9px}
        .attempt-card{border:1px solid #a8a29e;padding:6px}
        .attempt-card h2{margin:0 0 5px;font-size:8px;text-transform:uppercase;letter-spacing:.3px}
        .attempt-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:3px}
        .attempt-slot{display:grid;place-items:center;min-width:0;min-height:32px;border:1px solid #d6d3d1;padding:4px}
        .attempt-number{font-size:15px;font-weight:700;line-height:1}
        .attempt-qr{display:grid;align-content:center;justify-items:center;gap:4px;text-align:center;font-size:7px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
        .attempt-qr .waybill-qr{width:94px;height:94px}
        @media print{
            body{margin:0;background:#fff}
            .waybill{width:4in;max-width:4in;height:6in;min-height:6in;border:1px solid #292524;margin:0 auto;padding:.12in}
            .waybill.page-break{break-after:page;page-break-after:always}
            .attempts,.head,.grid,.package-info,.scan-card{break-inside:avoid;page-break-inside:avoid}
            *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
        }
    </style>
</head>
<body>
@php
    $address = $sellerOrder->order?->address;
@endphp
@foreach($shipments as $index => $shipment)
    @php
        $item = $shipment->orderItem;
        $parcelItems = $shipment->combined_order_items
            ? $sellerOrder->items->whereIn('id', $shipment->combined_order_item_ids ?? [])->values()
            : collect([$item])->filter();
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
            <div class="box"><h2>From store</h2><strong>{{ $sellerOrder->sellerProfile?->business_name }}</strong><br>{{ $sellerOrder->sellerProfile?->businessAddress?->formatted() }}</div>
            <div class="box"><h2>Deliver to</h2><strong>{{ $address?->recipient_name }}</strong><br>{{ $address?->contact_number }}<br>{{ $address?->formatted() }}</div>
        </section>

        @php($weight = \App\Support\ParcelWeightEstimator::recordedWeight($shipment) ?? ($item ? \App\Support\ParcelWeightEstimator::productEstimate($shipment) : null))
        <section class="package-info">
            <div class="package-product">
                <h2>Parcel {{ $index + 1 }} of {{ $totalParcelCount }} · Contents</h2>
                @forelse($parcelItems as $parcelItem)
                    <div class="package-line">
                        <strong class="package-title">{{ $parcelItem->product_name ?: 'Order item' }}</strong>
                        <div class="package-meta">
                            {{ $parcelItem->variant_description ?: 'Standard' }}@if($parcelItem->sku) &middot; SKU {{ $parcelItem->sku }}@endif
                            &middot; Qty {{ $shipment->combined_order_items ? $parcelItem->quantity : $shipment->parcel_quantity }}
                        </div>
                    </div>
                @empty
                    <strong class="package-title">{{ $item?->product_name ?? 'Order parcel' }}</strong>
                    <div class="package-meta">Qty {{ $shipment->parcel_quantity }}</div>
                @endforelse
                @if($parcelItems->isNotEmpty())
                    <div class="package-meta"><strong>Total quantity: {{ $shipment->combined_order_items ? $parcelItems->sum('quantity') : $shipment->parcel_quantity }}</strong></div>
                @endif
                @if($parcelItems->contains(fn ($parcelItem) => $parcelItem->hasAdditionalCare()))<div class="package-meta"><strong>FRAGILE / HANDLE WITH CARE</strong></div>@endif
            </div>
            <div class="package-specs">
                <div><small>Weight</small><strong>@if($weight !== null){{ number_format($weight, 3) }} kg @else Not recorded @endif</strong></div>
                <div><small>Packed size (L × W × H)</small><strong>@if($shipment->parcel_length_cm !== null && $shipment->parcel_width_cm !== null && $shipment->parcel_height_cm !== null){{ number_format((float) $shipment->parcel_length_cm, 1) }} × {{ number_format((float) $shipment->parcel_width_cm, 1) }} × {{ number_format((float) $shipment->parcel_height_cm, 1) }} cm @else Not recorded @endif</strong></div>
            </div>
        </section>

        <section class="scan-card">
            <div class="barcode-label"><strong>Scan parcel</strong><span class="package-code">{{ $shipment->tracking_number }}</span></div>
            <div class="scan-codes">
                <div class="package-barcode"><x-shared.waybill-barcode :tracking="$shipment->tracking_number" :height="72" /></div>
            </div>
        </section>

        <section class="attempts" aria-label="Delivery and return attempt records">
            <div class="attempt-card">
                <h2>Delivery attempts</h2>
                <div class="attempt-list">
                    @for($attemptNumber = 1; $attemptNumber <= 3; $attemptNumber++)
                        <div class="attempt-slot">
                            <span class="attempt-number">{{ $attemptNumber }}</span>
                        </div>
                    @endfor
                </div>
            </div>
            <div class="attempt-qr"><span>Scan QR</span><x-shared.waybill-qr :tracking="$shipment->tracking_number" :size="128" /></div>
            <div class="attempt-card">
                <h2>Return attempts</h2>
                <div class="attempt-list">
                    @for($attemptNumber = 1; $attemptNumber <= 3; $attemptNumber++)
                        <div class="attempt-slot">
                            <span class="attempt-number">{{ $attemptNumber }}</span>
                        </div>
                    @endfor
                </div>
            </div>
        </section>

    </main>
@endforeach
<script>window.addEventListener('load',()=>window.print())</script>
</body>
</html>
