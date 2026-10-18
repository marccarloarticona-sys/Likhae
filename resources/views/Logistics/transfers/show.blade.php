@extends('Logistics.app')

@section('title', 'Transfer Manifest - LIKHAE Logistics')

@section('content')
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Transfer manifest</span>
            <h1 class="mt-2 font-display text-[28px] font-semibold tracking-[-0.04em] text-ink">{{ $manifest->manifest_number }}</h1>
            <p class="mt-1 text-[10px] text-muted">{{ $manifest->originLogisticsCenter?->business_name }} → {{ $manifest->destinationLogisticsCenter?->business_name }} · {{ $manifest->status }}</p>
        </div>
        <a href="{{ route('logistics.transfers') }}" class="rounded-lg border border-line px-4 py-3 text-[10px] font-semibold text-ink">Back to transfers</a>
    </div>

    @if(session('status'))<div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-[10px] text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[10px] text-red-700">{{ $errors->first() }}</div>@endif

    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="flex flex-wrap gap-2">
            @if($manifest->status === 'DRAFT')
                <form method="POST" action="{{ route('logistics.transfers.seal', $manifest) }}" data-confirm-title="Seal transfer manifest?" data-confirm-message="Lock the parcel list and transfer details for this inter-hub manifest?" data-confirm-label="Seal manifest">@csrf<button class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Seal Manifest</button></form>
            @elseif($manifest->status === 'SEALED')
                <form method="POST" action="{{ route('logistics.transfers.release', $manifest) }}" class="grid w-full gap-4 rounded-lg border border-line p-4" data-confirm-title="Release manifest custody?" data-confirm-message="Confirm the scanned parcels and transfer physical custody from the origin hub to the assigned rider." data-confirm-label="Release custody">@csrf
                    <div><h2 class="text-[12px] font-semibold text-ink">Sender handover scan</h2><p class="mt-1 text-[10px] text-muted">Scan or manually enter every parcel tracking number before the origin hub releases custody.</p></div>
                    @foreach($manifest->items as $item)
                        <div class="grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(14rem,1fr)] sm:items-start">
                            <div class="text-[10px] text-ink"><span class="font-semibold">Leg #{{ $item->transfer_leg_id }}</span><br>{{ $item->transferLeg?->shipment?->tracking_number }}</div>
                            <x-parcel-scan-input :name="'scanned_codes['.$item->transfer_leg_id.']'" :method-name="'scan_methods['.$item->transfer_leg_id.']'" :id="'release-'.$item->id" />
                        </div>
                    @endforeach
                    <button class="justify-self-start rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Confirm sender handover and release</button>
                </form>
            @elseif($manifest->status === 'RELEASED')
                <form method="POST" action="{{ route('logistics.transfers.depart', $manifest) }}" data-confirm-title="Dispatch this transfer?" data-confirm-message="Record that the rider and manifest have departed the origin hub." data-confirm-label="Dispatch transfer">@csrf<button class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Dispatch / Depart</button></form>
            @elseif($manifest->status === 'IN_TRANSIT')
                <form method="POST" action="{{ route('logistics.transfers.arrive', $manifest) }}" data-confirm-title="Record transfer arrival?" data-confirm-message="Mark this manifest as arrived at its destination hub. Individual parcel intake scans will still be required." data-confirm-label="Record arrival">@csrf<button class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Record Arrival</button></form>
            @endif
            @if(in_array($manifest->status, ['DRAFT','SEALED']))
                <form method="POST" action="{{ route('logistics.transfers.cancel', $manifest) }}" class="flex gap-2" data-confirm-title="Cancel transfer manifest?" data-confirm-message="Cancel this manifest and keep its parcels out of the inter-hub transfer flow?" data-confirm-label="Cancel manifest" data-confirm-tone="danger">@csrf<input name="reason" required placeholder="Cancellation reason" class="rounded-lg border border-line px-3 py-3 text-[10px]"><button class="rounded-lg border border-red-300 px-4 py-3 text-[10px] font-semibold text-red-700">Cancel</button></form>
            @endif
        </div>

        @if($manifest->transfer_commission_type)
            <div class="mt-4 rounded-lg border border-line bg-slate-50 px-4 py-3 text-[10px] text-ink">
                <span class="font-semibold">Transfer commission snapshot:</span>
                @if($manifest->transfer_commission_type === 'PERCENT')
                    {{ number_format((float) $manifest->transfer_commission_value, 2) }}% of ₱{{ number_format((float) $manifest->transfer_commission_base, 2) }} shipping fees
                @else
                    ₱{{ number_format((float) $manifest->transfer_commission_value, 2) }} per parcel
                @endif
                <span class="ml-2 font-semibold">Total: ₱{{ number_format((float) $manifest->transfer_commission_amount, 2) }}</span>
            </div>
        @elseif($manifest->status === 'DRAFT')
            <p class="mt-4 text-[10px] text-muted">The configured transfer commission rule is captured when this manifest is sealed.</p>
        @endif

        @if($manifest->status === 'DRAFT')
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <form method="POST" action="{{ route('logistics.transfers.items.store', $manifest) }}" class="rounded-lg border border-line p-4">@csrf<h2 class="text-[12px] font-semibold text-ink">Load ready parcel leg</h2><select name="transfer_leg_id" required class="mt-3 w-full rounded-lg border border-line px-3 py-3 text-[10px]"><option value="">Select a ready leg</option>@foreach($readyLegs as $leg)<option value="{{ $leg->id }}">Leg #{{ $leg->id }} · {{ $leg->shipment?->tracking_number }} · seq {{ $leg->sequence_no }}</option>@endforeach</select><p class="mt-2 text-[10px] text-muted">Only ready parcels in this origin/destination pair are shown. Duplicate active loading is rejected server-side.</p><button class="mt-3 rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Add Leg</button></form>
                <form method="POST" action="{{ route('logistics.transfers.driver.update', $manifest) }}" class="rounded-lg border border-line p-4">@csrf @method('PATCH')<h2 class="text-[12px] font-semibold text-ink">Assign approved inter driver</h2><select name="rider_profile_id" required class="mt-3 w-full rounded-lg border border-line px-3 py-3 text-[10px]"><option value="">Select driver</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}">{{ $driver->user?->name ?: 'Rider #'.$driver->id }} · {{ str($driver->vehicle_type)->headline() }}</option>@endforeach</select><p class="mt-2 text-[10px] text-muted">The server requires same origin hub, active RIDER account, inter vehicle, and complete approved capacity.</p><button class="mt-3 rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Assign Driver</button></form>
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Manifest items and custody</h2>
        <div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-[10px]"><thead><tr class="border-b border-line text-muted"><th class="px-2 py-3">Leg</th><th class="px-2 py-3">Shipment</th><th class="px-2 py-3">Status</th><th class="px-2 py-3">Receipt</th></tr></thead><tbody>
            @forelse($manifest->items as $item)
                <tr class="border-b border-line">
                    <td class="px-2 py-3">#{{ $item->transfer_leg_id }} · {{ $item->transferLeg?->status }}</td>
                    <td class="px-2 py-3">{{ $item->transferLeg?->shipment?->tracking_number }} @if($item->transferLeg?->shipment?->sellerOrder?->items?->contains(fn ($orderItem): bool => $orderItem->hasAdditionalCare()))<span class="ml-2 rounded border border-amber-300 bg-amber-50 px-2 py-1 font-bold uppercase text-amber-900">Fragile / Additional Care</span>@endif</td>
                    <td class="px-2 py-3">{{ $item->status }}</td>
                    <td class="px-2 py-3">
                        @if(in_array($manifest->status, ['ARRIVED','PARTIAL','EXCEPTION']) && $item->status === 'EXPECTED')
                            <form method="POST" action="{{ route('logistics.transfers.items.receive', $item) }}" class="grid min-w-56 gap-2" data-confirm-title="Record parcel intake?" data-confirm-message="Save the selected custody outcome for this parcel at the destination hub?" data-confirm-label="Confirm intake">@csrf
                                <select name="outcome" class="rounded border border-line px-2 py-2"><option value="RECEIVED">Received</option><option value="DAMAGED">Damaged</option><option value="MISSING">Missing</option></select>
                                <x-parcel-scan-input name="scanned_code" method-name="scan_method" :id="'receive-'.$item->id" :required="false" />
                                <input name="notes" placeholder="Notes for damage or missing parcel" class="rounded border border-line px-2 py-2">
                                <button class="rounded bg-primary px-3 py-2 font-semibold text-white">Confirm receipt</button>
                            </form>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-2 py-5 text-muted">No items loaded.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
