<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><title>Waybill {{ $shipment->tracking_number }}</title>
    <style>body{font-family:Arial,sans-serif;color:#1c1917;margin:24px}.label{max-width:760px;border:2px solid #292524;padding:24px}.head{display:flex;justify-content:space-between;border-bottom:2px solid #292524;padding-bottom:16px}.brand{font-size:26px;font-weight:800;color:#7f1d1d}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px}.box{border:1px solid #a8a29e;padding:14px}.box h2{font-size:11px;text-transform:uppercase;margin:0 0 8px;color:#57534e}.tracking{font-size:24px;font-weight:800;letter-spacing:2px;text-align:center;border:2px dashed #292524;padding:18px;margin:20px 0}.items{width:100%;border-collapse:collapse}.items th,.items td{border:1px solid #d6d3d1;padding:8px;text-align:left}@media print{body{margin:0}.label{border:0;max-width:none}}</style>
</head>
<body>
@php($address = $sellerOrder->order?->address)
<main class="label">
    <header class="head"><div><div class="brand">LIKHAE</div><div>Marketplace Parcel Waybill</div></div><div><strong>Order</strong><br>{{ $sellerOrder->seller_order_number }}<br><small>{{ $sellerOrder->order?->order_number }}</small></div></header>
    <div class="tracking">{{ $shipment->tracking_number }}</div>
    <x-waybill-barcode :tracking="$shipment->tracking_number" />
    <section class="grid">
        <div class="box"><h2>From</h2><strong>{{ $sellerOrder->sellerProfile?->business_name }}</strong><br>{{ $sellerOrder->sellerProfile?->businessAddress?->formatted() }}</div>
        <div class="box"><h2>Deliver to</h2><strong>{{ $address?->recipient_name }}</strong><br>{{ $address?->contact_number }}<br>{{ $address?->formatted() }}</div>
    </section>
    @if($sellerOrder->items->contains(fn ($item): bool => $item->hasAdditionalCare()))<div style="margin-top:14px;border:2px solid #b45309;background:#fffbeb;padding:10px;font-weight:800;letter-spacing:.5px">FRAGILE / ADDITIONAL CARE — HANDLE WITH CARE</div>@endif
    <table class="items" style="margin-top:20px"><thead><tr><th>Item</th><th>Variant</th><th>SKU</th><th>Qty</th></tr></thead><tbody>@foreach($sellerOrder->items as $item)<tr><td>{{ $item->product_name }}</td><td>{{ $item->variant_description ?: 'Standard' }}</td><td>{{ $item->sku }}</td><td>{{ $item->quantity }}</td></tr>@endforeach</tbody></table>
    <p><strong>Destination:</strong> {{ collect([$shipment->destination_barangay_name, $shipment->destination_municipality_name, $shipment->destination_province_name])->filter()->implode(', ') }}</p>
</main>
<script>window.addEventListener('load',()=>window.print())</script>
</body>
</html>
