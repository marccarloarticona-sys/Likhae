@extends('layouts.seller')
@section('title','Shipment Monitoring')
@section('active','logistics')
@section('subtitle','Track parcel pickup, sorting, assignment, and delivery from live shipment records.')
@section('content')
<div class="sl-page">
    @if(session('status'))<div class="sl-card mb-4 border border-green-200 bg-green-50 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-card mb-4 border border-red-200 bg-red-50 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>@endif
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Fulfillment</span><h2>Shipment Monitoring</h2><p>Every status below comes from the connected shipment record.</p></div></div>
    @if(!$pickupRequestSchemaReady)
        <section class="sl-card border border-amber-200 bg-amber-50 text-sm text-amber-900">Seller pickup requests are unavailable until their database schema is installed.</section>
    @else
        <section class="sl-card" data-seller-pickup-request-form>
            <header class="sl-card-head"><div><span class="sl-eyebrow">Seller Pickup</span><h2>Request one pickup for 1–10 ready parcels</h2><p>Parcels may come from different SellerOrders. Each keeps its own tracking number, waybill, and Rider scan state.</p></div></header>
            @if(!$pickupAddress)
                <p class="mt-3 text-sm text-amber-800">Add a registered business pickup address before requesting a Rider.</p>
            @elseif($readyPickupParcels->isEmpty())
                <p class="mt-3 text-sm text-muted">No eligible parcels are ready for a new pickup request.</p>
            @else
                <p class="mt-2 text-sm text-muted">Pickup address: <strong class="text-ink">{{ $pickupAddress->formatted() }}</strong></p>
                <form method="POST" action="{{ route('seller.logistics.pickup-requests.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="pickup_address_id" value="{{ $pickupAddress->id }}">
                    <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Select</th><th>Tracking / product</th><th>SellerOrder</th><th>Waybill</th></tr></thead><tbody>
                        @foreach($readyPickupParcels as $parcel)
                            <tr>
                                <td><input type="checkbox" name="shipment_ids[]" value="{{ $parcel->id }}" data-pickup-request-parcel aria-label="Select {{ $parcel->tracking_number }}"><input type="hidden" name="seller_order_ids[{{ $parcel->id }}]" value="{{ $parcel->seller_order_id }}"></td>
                                <td><strong>{{ $parcel->tracking_number }}</strong><small class="block">{{ $parcel->orderItem?->product_name ?? 'Parcel' }}</small></td>
                                <td>{{ $parcel->sellerOrder?->seller_order_number }}</td>
                                <td>
                                    @if($parcel->order_item_id)
                                        <a class="text-sm font-semibold text-primary underline" target="_blank" rel="noopener" href="{{ route('seller.orders.waybill.item', [$parcel->seller_order_id, $parcel->order_item_id]) }}">Open waybill</a>
                                    @else
                                        <a class="text-sm font-semibold text-primary underline" target="_blank" rel="noopener" href="{{ route('seller.orders.waybill', $parcel->seller_order_id) }}">Open waybill</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody></table></div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-muted"><strong data-pickup-request-count>0</strong> selected · choose 1 to 10 parcels.</p>
                        <button type="submit" data-pickup-request-submit disabled class="sl-btn sl-btn-primary disabled:cursor-not-allowed disabled:opacity-50">Request pickup</button>
                    </div>
                </form>
            @endif
        </section>

        <section class="space-y-4">
            <div><h2 class="text-xl font-semibold text-ink">Your pickup requests</h2><p class="mt-1 text-sm text-muted">Collected progress is counted from successful per-parcel Rider scans.</p></div>
            @forelse($pickupRequests as $pickupRequest)
                @include('Seller.partials.pickup-request-progress', ['pickupRequest' => $pickupRequest])
            @empty
                <section class="sl-card text-sm text-muted">No pickup requests yet.</section>
            @endforelse
        </section>
        <script>
            (() => {
                const form = document.querySelector('[data-seller-pickup-request-form] form');
                if (!form) return;
                const checks = [...form.querySelectorAll('[data-pickup-request-parcel]')];
                const count = form.querySelector('[data-pickup-request-count]');
                const submit = form.querySelector('[data-pickup-request-submit]');
                form.addEventListener('change', (event) => {
                    if (!event.target.matches('[data-pickup-request-parcel]')) return;
                    const checked = checks.filter((checkbox) => checkbox.checked);
                    if (checked.length > 10) event.target.checked = false;
                    const total = checks.filter((checkbox) => checkbox.checked).length;
                    count.textContent = String(total);
                    submit.disabled = total < 1 || total > 10;
                });
            })();
        </script>
    @endif
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
