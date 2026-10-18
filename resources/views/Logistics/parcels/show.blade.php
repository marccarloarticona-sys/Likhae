@extends('Logistics.app')
@section('title','Parcel '.$shipment->tracking_number)
@section('content')
<div class="space-y-6">
    <header><a class="text-sm text-primary" href="{{ route('logistics.parcels') }}">â† Back to Parcels</a><h1 class="mt-3 text-2xl font-bold text-ink">{{ $shipment->tracking_number }}</h1><p class="mt-1 text-sm font-semibold text-primary">{{ str($shipment->current_status)->headline() }}</p></header>
    @if(session('status'))<div role="status" class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><strong>Action could not be completed.</strong><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="flex flex-wrap gap-2">@if($shipment->sellerOrder?->sellerProfile?->user)<a class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink" href="{{ route('logistics.messages', ['recipient_user_id' => $shipment->sellerOrder->sellerProfile->user_id, 'shipment_id' => $shipment->id]) }}">Message Seller</a>@endif @php($assignedRider = $shipment->riderAssignments->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'])->sortByDesc('id')->first()) @if($assignedRider?->riderProfile?->user)<a class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink" href="{{ route('logistics.messages', ['recipient_user_id' => $assignedRider->riderProfile->user_id, 'shipment_id' => $shipment->id]) }}">Message Assigned Rider</a>@endif</div>
    <x-shared.mapbox :markers="$mapMarkers" title="Shipment location" height="220px" user-location-target="destination" navigation />
    @if($shipment->orderItem?->hasAdditionalCare())<div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold uppercase text-amber-900">Fragile / Additional Care â€” handling flag</div>@endif
    <section class="grid gap-4 md:grid-cols-2"><article class="border border-line bg-surface p-5"><h2 class="font-semibold">Order and destination</h2><dl class="mt-4 space-y-2 text-sm"><div><dt class="text-muted">Order</dt><dd>{{ $shipment->sellerOrder?->seller_order_number }}</dd></div><div><dt class="text-muted">Seller</dt><dd>{{ $shipment->sellerOrder?->sellerProfile?->business_name }}</dd></div><div><dt class="text-muted">Buyer</dt><dd>{{ $shipment->sellerOrder?->order?->buyer?->name }}</dd></div><div><dt class="text-muted">Address</dt><dd>{{ $shipment->sellerOrder?->order?->address?->formatted() }}</dd></div><div><dt class="text-muted">Delivery area</dt><dd>{{ $shipment->serviceArea?->name ?? 'Not assigned' }}</dd></div></dl></article>
    <article class="border border-line bg-surface p-5"><h2 class="font-semibold">Items</h2><div class="mt-4 space-y-3">@foreach(($shipment->orderItem ? collect([$shipment->orderItem]) : ($shipment->sellerOrder?->items ?? collect())) as $item)<div class="flex items-center justify-between gap-3 text-sm"><div class="flex items-center gap-3"><x-product-thumbnail :item="$item" size="64"/><span><strong class="block">{{ $item->product_name }}</strong><small class="text-muted">{{ $item->variant_description ?: 'Standard' }}</small></span></div><strong>Ã— {{ $item->quantity }}</strong></div>@endforeach</div></article></section>
    <section class="border border-line bg-surface p-5"><h2 class="font-semibold">Shipment history</h2><ol class="mt-4 space-y-3">@forelse($shipment->events as $event)<li class="border-l-2 border-primary pl-4"><strong class="text-sm">{{ str($event->status)->headline() }}</strong><p class="text-sm text-muted">{{ $event->notes }}</p><small class="text-muted">{{ $event->occurred_at?->format('M d, Y g:i A') }}</small></li>@empty<li class="text-sm text-muted">No events recorded.</li>@endforelse</ol></section>
    <section class="grid gap-4 xl:grid-cols-2">
        <article class="rounded-xl border border-line bg-surface p-5">
            <h2 class="font-semibold">Manual status override</h2>
            <p class="mt-1 text-sm text-muted">Use only when an operational correction is needed. The old status, new status, reason, staff member, and time are recorded in the audit log.</p>
            <form method="POST" action="{{ route('logistics.parcels.status-override', $shipment) }}" class="mt-4 space-y-3" data-confirm-title="Override shipment status?" data-confirm-message="This changes the parcel's current status and creates a permanent audit record with your reason." data-confirm-label="Record override">
                @csrf
                <label class="block text-sm font-medium">New status<select name="status" required class="mt-1 block min-h-10 w-full rounded-lg border border-line bg-white px-3">@foreach(\App\Http\Controllers\Logistics\DispatchController::MANUAL_OVERRIDE_STATUSES as $status)<option value="{{ $status }}" @selected($status === $shipment->current_status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
                <label class="block text-sm font-medium">Reason<textarea name="reason" required minlength="10" maxlength="1000" rows="3" class="mt-1 block w-full rounded-lg border border-line p-3" placeholder="Explain why the shipment status needs correction."></textarea></label>
                <button class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Save override</button>
            </form>
        </article>
        @if(!in_array($shipment->current_status, ['DELIVERED', 'COMPLETED', 'RETURNED', 'CANCELLED', 'LOST', 'DAMAGED'], true))
            <article class="rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="font-semibold text-amber-950">Report a shipment exception</h2>
                <p class="mt-1 text-sm text-amber-900">This opens an admin claim, records the exception in shipment history, and notifies the buyer and seller. Refund decisions remain with the existing claim review process.</p>
                <div class="mt-4 grid gap-5">
                    <form method="POST" action="{{ route('logistics.parcels.exceptions.store', $shipment) }}" class="space-y-3" data-confirm-title="Mark this shipment lost?" data-confirm-message="This will update the shipment, open an admin claim, and notify the buyer and seller." data-confirm-label="Report lost">
                        @csrf<input type="hidden" name="exception_type" value="LOST">
                        <label class="block text-sm font-medium">Lost parcel details<textarea name="reason" required minlength="10" maxlength="2000" rows="2" class="mt-1 block w-full rounded-lg border border-line p-3" placeholder="Describe when and where the parcel was last confirmed."></textarea></label>
                        <button class="rounded-lg border border-amber-700 px-4 py-2 text-sm font-semibold text-amber-950">Mark Lost and open claim</button>
                    </form>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('logistics.parcels.exceptions.store', $shipment) }}" class="space-y-3 border-t border-amber-200 pt-4" data-confirm-title="Report parcel damage?" data-confirm-message="The damage photo will be stored as protected claim evidence. The buyer and seller will be notified." data-confirm-label="Report damage">
                        @csrf<input type="hidden" name="exception_type" value="DAMAGED">
                        <label class="block text-sm font-medium">Damage description<textarea name="reason" required minlength="10" maxlength="2000" rows="2" class="mt-1 block w-full rounded-lg border border-line p-3" placeholder="Describe the visible damage and affected packaging or items."></textarea></label>
                        <label class="block text-sm font-medium">Damage photo (JPG, PNG, or WebP; up to 10 MB)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="mt-1 block w-full rounded-lg border border-line bg-white p-2"></label>
                        <button class="rounded-lg bg-amber-800 px-4 py-2 text-sm font-semibold text-white">Mark Damaged and open claim</button>
                    </form>
                </div>
            </article>
        @endif
    </section>
    @php($deliveryProof = $shipment->deliveryAttempts->where('status', 'DELIVERED')->whereNotNull('proof_path')->sortByDesc('attempted_at')->first())
    @if($deliveryProof)
        <section class="border border-line bg-surface p-5">
            <h2 class="font-semibold">Proof of delivery</h2>
            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($deliveryProof->proof_path) }}" target="_blank" rel="noopener" class="mt-4 inline-block">
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($deliveryProof->proof_path) }}" alt="Delivery proof for {{ $shipment->tracking_number }}" class="max-h-96 max-w-full border border-line object-contain">
            </a>
            <p class="mt-2 text-sm text-muted">Submitted {{ $deliveryProof->attempted_at?->format('M d, Y g:i A') }}</p>
        </section>
    @endif
</div>
@endsection
