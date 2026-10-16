@extends('layouts.seller')
@section('title','Shipment Monitoring')
@section('active','logistics')
@section('subtitle','Track parcel pickup, sorting, assignment, and delivery from live shipment records.')
@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Fulfillment</span><h2>Shipment Monitoring</h2><p>Every status below comes from the connected shipment record.</p></div></div>
    <section class="sl-card"><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Tracking</th><th>Order</th><th>Destination</th><th>Rider</th><th>Status</th><th>Updated</th></tr></thead><tbody>
        @forelse($orders as $order)
            @foreach($order->shipments as $shipment)
                @php($assignment = $shipment->riderAssignments?->whereIn('status',['ASSIGNED','ACCEPTED','IN_PROGRESS'])->sortByDesc('id')->first())
                <tr id="shipment-{{ $shipment->id }}"><td><strong>{{ $shipment->tracking_number }}</strong><small class="block">{{ $shipment->orderItem?->product_name }}</small></td><td>{{ $order->seller_order_number }}</td><td>{{ $order->order?->address?->formatted() }}</td><td>{{ $assignment?->riderProfile?->user?->name ?? 'Awaiting assignment' }}</td><td><span class="sl-status is-info">{{ str($shipment->current_status)->headline() }}</span></td><td>{{ $shipment->updated_at?->diffForHumans() }}</td></tr>
            @endforeach
        @empty<tr><td colspan="6">No shipments yet.</td></tr>@endforelse
    </tbody></table></div></section>{{ $orders->links() }}
</div>
@endsection
