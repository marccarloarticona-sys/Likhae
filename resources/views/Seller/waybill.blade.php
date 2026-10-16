<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><title>Waybill {{ $shipment->tracking_number }}</title>
    <style>
        body{font-family:Arial,sans-serif;color:#1c1917;margin:24px}.label{max-width:760px;border:2px solid #292524;padding:24px}.head{display:flex;justify-content:space-between;border-bottom:2px solid #292524;padding-bottom:16px}.brand{font-size:26px;font-weight:800;color:#7f1d1d}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px}.box{border:1px solid #a8a29e;padding:14px}.box h2{font-size:11px;text-transform:uppercase;margin:0 0 8px;color:#57534e}.tracking{font-size:24px;font-weight:800;letter-spacing:2px;text-align:center;border:2px dashed #292524;padding:18px;margin:20px 0}.item{display:grid;grid-template-columns:60px 1fr auto;gap:12px;align-items:start;border:1px solid #a8a29e;padding:12px;margin-top:14px}.item-number{font-size:11px;font-weight:800;text-transform:uppercase;color:#57534e}.item-name{font-size:16px;font-weight:700;margin:3px 0}.item-meta{font-size:12px;line-height:1.5;color:#44403c}@media print{body{margin:0}.label{border:0;max-width:none}}
    </style>
</head>
<body>
@include('Seller.partials.waybill-label', ['sellerOrder' => $sellerOrder, 'shipment' => $shipment, 'item' => $item, 'parcelNumber' => 1, 'pageBreak' => false])
<script>window.addEventListener('load',()=>window.print())</script>
</body>
</html>
