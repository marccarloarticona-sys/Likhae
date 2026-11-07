<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Return Waybill {{ $workflow->return_tracking_number }}</title>
    <style>
        body{font-family:Arial,sans-serif;margin:24px;color:#1c1917;background:#f5f5f4}.sheet{max-width:820px;margin:0 auto;border:2px solid #292524;background:#fff;padding:24px}.head{display:flex;justify-content:space-between;align-items:center;gap:20px;border-bottom:2px solid #292524;padding-bottom:16px}.logo{display:block;width:194px;height:42px;object-fit:contain;object-position:left center}.tracking{text-align:center;font-size:24px;font-weight:800;letter-spacing:2px;border:2px dashed #57534e;padding:18px;margin:20px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.box{border:1px solid #a8a29e;padding:14px;min-height:116px}.items{width:100%;border-collapse:collapse;margin-top:18px}.items td,.items th{border:1px solid #d6d3d1;padding:8px;text-align:left}.notice{margin-top:18px;background:#fff7ed;border:1px solid #fed7aa;padding:12px;font-size:13px}.actions{max-width:820px;margin:14px auto;display:flex;gap:10px}.actions a,.actions button{border:1px solid #a8a29e;background:#fff;padding:10px 14px;font-weight:700;text-decoration:none;color:#1c1917;cursor:pointer}@media print{body{margin:0;background:#fff}.sheet{border:0;padding:18px}.actions{display:none}}
    </style>
</head>
<body>
    @php($parent = $sellerRequest->request)
    @php($shipment = $workflow->shipment)
    @php($sellerCenter = $shipment?->originLogisticsCenter?->loadMissing('address'))
    <main class="sheet">
        <header class="head">
            <div><img class="logo" src="{{ asset('images/likhae-logo-black-text.png') }}" alt="LIKHAE"><small>Buyer Return Waybill</small></div>
            <strong>{{ $shipment?->sellerOrder?->seller_order_number }}</strong>
        </header>
        <div class="tracking">{{ $workflow->return_tracking_number }}</div>
        <x-shared.waybill-barcode :tracking="$workflow->return_tracking_number" />
        @php($outboundParcels = $shipment?->sellerOrder?->shipments ?? collect())
        @php($outboundParcelNumber = $outboundParcels->search(fn ($candidate) => (int) $candidate->id === (int) $shipment?->id) + 1)
        <p><strong>Original parcel:</strong> Parcel {{ $outboundParcelNumber }} of {{ max(1, $outboundParcels->count()) }} · Tracking {{ $shipment?->tracking_number }}</p>
        <section class="grid">
            <div class="box"><strong>Return from</strong><p>{{ $parent?->order?->address?->recipient_name }}<br>{{ $parent?->order?->address?->formatted() }}</p></div>
            <div class="box"><strong>Return to seller</strong><p>{{ $shipment?->sellerOrder?->sellerProfile?->business_name }}<br>{{ $sellerCenter?->business_name }}<br>{{ $sellerCenter?->address?->formatted() }}</p></div>
        </section>
        <div class="box" style="margin-top:16px"><strong>Drop-off center</strong><p>{{ $workflow->buyerDropoffCenter?->business_name }}<br>{{ $workflow->buyerDropoffCenter?->address?->formatted() }}</p><small>Take the parcel to this center. Logistics will scan this return tracking number and route it to the seller.</small></div>
        <table class="items">
            <thead><tr><th>Item</th><th>Variant</th><th>Return quantity</th></tr></thead>
            <tbody>
                @foreach($sellerRequest->items as $item)
                    <tr><td>{{ $item->orderItem?->product_name ?? 'Order item' }}<br><small>SKU: {{ $item->orderItem?->sku ?: 'Not provided' }}</small></td><td>{{ $item->orderItem?->variant_description ?: 'Standard' }}</td><td>{{ $item->quantity }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <div class="notice"><strong>Before you drop it off:</strong> Print this waybill and attach it securely to the parcel. Keep your drop-off receipt. The refund is processed after the seller receives and inspects the returned items.</div>
    </main>
    <div class="actions"><button type="button" onclick="window.print()">Print return waybill</button><a href="{{ route('buyer.returns.show', $sellerRequest) }}">Back to return request</a></div>
    <script>window.addEventListener('load',()=>window.print())</script>
</body>
</html>
