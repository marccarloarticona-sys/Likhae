<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Waybill {{ $shipment->tracking_number }}</title>
	<style>
		body{font-family:Arial,sans-serif;margin:24px;color:#1c1917}.sheet{max-width:800px;border:2px solid;padding:24px}.head{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid;padding-bottom:16px}.brand-logo{display:block;width:194px;height:42px;max-width:100%;object-fit:contain;object-position:left center}.tracking{text-align:center;font-size:24px;font-weight:800;letter-spacing:2px;border:2px dashed;padding:18px;margin:20px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.box{border:1px solid #a8a29e;padding:14px}.items{width:100%;border-collapse:collapse;margin-top:20px}.items td,.items th{border:1px solid #d6d3d1;padding:8px;text-align:left}@media print{body{margin:0}.sheet{border:0}}
	</style>
</head>
<body>
	@php($order = $shipment->sellerOrder)
	<main class="sheet">
		<header class="head">
			<div><img class="brand-logo" src="{{ asset('images/likhae-logo-black-text.png') }}" alt="LIKHAE" width="194" height="42"><small>Official Parcel Waybill</small></div>
			<strong>{{ $order?->seller_order_number }}</strong>
		</header>
		<div class="tracking">{{ $shipment->tracking_number }}</div>
		<x-shared.waybill-barcode :tracking="$shipment->tracking_number" />
		<section class="grid">
			<div class="box"><strong>Seller</strong><p>{{ $order?->sellerProfile?->business_name }}<br>{{ $order?->sellerProfile?->businessAddress?->formatted() }}</p></div>
			<div class="box"><strong>Recipient</strong><p>{{ $order?->order?->address?->recipient_name }}<br>{{ $order?->order?->address?->formatted() }}</p></div>
		</section>
		@php($weightItems = $shipment->orderItem ? collect([$shipment->orderItem]) : ($order?->items ?? collect()))
		<section class="grid" style="margin-top:16px">
			<x-shared.waybill-weight :shipment="$shipment" :items="$weightItems" />
		</section>
		@if($shipment->orderItem)
		<table class="items">
			<thead><tr><th>Item</th><th>Variant</th><th>Qty</th></tr></thead>
			<tbody>
				<tr><td>{{ $shipment->orderItem->product_name }}<br><small>SKU: {{ $shipment->orderItem->sku ?: 'Not provided' }}</small></td><td>{{ $shipment->orderItem->variant_description ?: 'Standard' }}</td><td>{{ $shipment->orderItem->quantity }}</td></tr>
			</tbody>
		</table>
		@else
		<p>Legacy parcel contents: {{ $order?->items?->pluck('product_name')->implode(', ') }}. This tracking number covers the legacy parcel.</p>
		@endif
	</main>
	<script>window.addEventListener('load',()=>window.print())</script>
</body>
</html>
