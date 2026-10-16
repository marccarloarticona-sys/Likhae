@extends('Logistics.app')

@section('title', 'Transfer Manifest - LIKHAE Logistics')

@section('content')
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Transfer manifest</span>
            <h1 class="mt-2 font-display text-[28px] font-semibold tracking-[-0.04em] text-ink">{{ $manifest->manifest_number }}</h1>
            <p class="mt-1 text-[10px] text-muted">{{ $manifest->originLogisticsCenter?->business_name }} &rarr; {{ $manifest->destinationLogisticsCenter?->business_name }} · {{ $manifest->status }}</p>
        </div>
        <a href="{{ route('logistics.transfers') }}" class="rounded-lg border border-line px-4 py-3 text-[10px] font-semibold text-ink">Back to transfers</a>
    </div>

    @if(session('status'))<div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-[10px] text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[10px] text-red-700">{{ $errors->first() }}</div>@endif

    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="rounded-lg border border-line bg-white p-4">
            <h2 class="text-[12px] font-bold text-ink">Assigned Inter-Hub Driver</h2>
            @if($manifest->assignedDriver)
                <div class="mt-3 grid gap-2 text-[10px] sm:grid-cols-2 lg:grid-cols-3">
                    <p><span class="text-muted">Driver:</span> <strong>{{ $manifest->assignedDriver->user?->name ?? 'Rider' }}</strong></p>
                    <p><span class="text-muted">Rider ID:</span> <strong>RID-{{ str_pad((string) $manifest->assignedDriver->id, 4, '0', STR_PAD_LEFT) }}</strong></p>
                    <p><span class="text-muted">Vehicle:</span> <strong>{{ str($manifest->assignedDriver->vehicle_type)->replace('_', ' ')->headline() }}</strong></p>
                    <p><span class="text-muted">Plate number:</span> <strong>{{ $manifest->assignedDriver->plate_number ?: 'Not recorded' }}</strong></p>
                    <p><span class="text-muted">Approved parcel capacity:</span> <strong>{{ $manifest->assignedDriver->approved_parcel_capacity ?? 'Not recorded' }}</strong></p>
                    <p><span class="text-muted">Approved weight / volume:</span> <strong>{{ $manifest->assignedDriver->approved_weight_capacity_kg ?? '-' }} kg / {{ $manifest->assignedDriver->approved_volume_capacity_liters ?? '-' }} L</strong></p>
                    <p class="font-semibold text-green-700 sm:col-span-2 lg:col-span-3">Availability: Assigned to this manifest</p>
                </div>
            @else
                <p class="mt-2 text-[10px] text-amber-800">No driver is assigned yet. An eligible driver is required before this manifest can be sealed.</p>
            @endif
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            @if($isOriginCenter && $manifest->status === 'DRAFT')
                <form method="POST" action="{{ route('logistics.transfers.seal', $manifest) }}">@csrf<button class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Seal Manifest</button></form>
            @elseif($isOriginCenter && $manifest->status === 'SEALED')
                @if($manifest->driver_accepted_at)
                    <form method="POST" action="{{ route('logistics.transfers.release', $manifest) }}" class="grid w-full gap-3 rounded-lg border border-line p-4">
                        @csrf
                        <strong class="text-xs">Scan each parcel at handoff to the accepted transfer driver.</strong>
                        @forelse($manifest->items as $item)
                            <label class="grid gap-2 text-[10px] sm:grid-cols-[1fr_1fr] sm:items-center"><span>{{ $item->transferLeg?->shipment?->tracking_number }}</span><input name="scanned_codes[{{ $item->id }}]" required autocomplete="off" placeholder="Scan parcel barcode" class="rounded-lg border border-line px-3 py-2"></label>
                        @empty
                            <p class="text-[10px] text-red-700">This manifest has no parcels to release.</p>
                        @endforelse
                        <button @disabled($manifest->items->isEmpty()) class="justify-self-start rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white disabled:opacity-50">Release manifest</button>
                    </form>
                @else
                    <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] text-amber-900">Waiting for the assigned driver to accept this sealed manifest.</p>
                @endif
            @elseif(! $isOriginCenter && $manifest->status === 'IN_TRANSIT')
                <form method="POST" action="{{ route('logistics.transfers.arrive', $manifest) }}">@csrf<button class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Record Arrival</button></form>
            @elseif($isOriginCenter && $manifest->status === 'RELEASED')
                <p class="rounded-lg border border-line bg-white p-3 text-[10px] text-muted">Released. The assigned driver must start transport from their Rider workspace.</p>
            @elseif($isOriginCenter && $manifest->status === 'IN_TRANSIT')
                <p class="rounded-lg border border-line bg-white p-3 text-[10px] text-muted">In transit. The destination hub records arrival and parcel receipt.</p>
            @endif

            @if($isOriginCenter && in_array($manifest->status, ['DRAFT', 'SEALED'], true))
                <form method="POST" action="{{ route('logistics.transfers.cancel', $manifest) }}" class="flex gap-2">@csrf<input name="reason" required placeholder="Cancellation reason" class="rounded-lg border border-line px-3 py-3 text-[10px]"><button class="rounded-lg border border-red-300 px-4 py-3 text-[10px] font-semibold text-red-700">Cancel</button></form>
            @endif
        </div>

        @if($isOriginCenter && $manifest->status === 'DRAFT')
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <form method="POST" action="{{ route('logistics.transfers.items.store', $manifest) }}" class="rounded-lg border border-line p-4">
                    @csrf
                    <h2 class="text-[12px] font-semibold text-ink">Load ready parcel leg</h2>
                    @if($readyLegs->isEmpty())
                        <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] text-amber-900">No eligible ready parcels are currently in this hub for the selected destination.</p>
                    @else
                        <select name="transfer_leg_id" required class="mt-3 w-full rounded-lg border border-line px-3 py-3 text-[10px]">
                            <option value="">Select a ready leg</option>
                            @foreach($readyLegs as $leg)
                                <option value="{{ $leg->id }}">Leg #{{ $leg->id }} · {{ $leg->shipment?->tracking_number }} · seq {{ $leg->sequence_no }}</option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-[10px] text-muted">Only ready parcels in origin custody for this hub pair are shown. Duplicate active loading is rejected server-side.</p>
                    @endif
                    <button @disabled($readyLegs->isEmpty()) class="mt-3 rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Add Leg</button>
                </form>

                <form method="POST" action="{{ route('logistics.transfers.driver.update', $manifest) }}" class="rounded-lg border border-line p-4">
                    @csrf @method('PATCH')
                    <h2 class="text-[12px] font-semibold text-ink">Assign Inter-Hub Driver</h2>
                    <p class="mt-1 text-[10px] text-muted">Changing the driver is available while this manifest is a draft.</p>
                    @if($drivers->isEmpty())
                        <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] text-amber-900">No eligible driver is currently available for this origin hub.</p>
                    @else
                        <select name="rider_profile_id" required class="mt-3 w-full rounded-lg border border-line px-3 py-3 text-[10px]">
                            <option value="">Select an available driver</option>
                            @foreach($drivers as $driver)
                                <option value="{{ $driver->id }}" @selected((string) old('rider_profile_id', $manifest->assigned_driver_rider_profile_id) === (string) $driver->id)>
                                    {{ $driver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $driver->id, 4, '0', STR_PAD_LEFT) }} · {{ str($driver->vehicle_type)->replace('_', ' ')->headline() }} · {{ $driver->plate_number ?: 'No plate' }}
                                </option>
                            @endforeach
                        </select>
                        <div class="mt-3 grid gap-2">
                            @foreach($drivers as $driver)
                                <div class="rounded-lg border border-line bg-white p-3 text-[10px]">
                                    <strong>{{ $driver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $driver->id, 4, '0', STR_PAD_LEFT) }}</strong>
                                    <div class="mt-1 grid gap-1 text-muted sm:grid-cols-2">
                                        <span>Vehicle: {{ str($driver->vehicle_type)->replace('_', ' ')->headline() }}</span>
                                        <span>Plate: {{ $driver->plate_number ?: 'Not recorded' }}</span>
                                        <span>Approved parcels: {{ $driver->approved_parcel_capacity }}</span>
                                        <span>Approved weight: {{ number_format((float) $driver->approved_weight_capacity_kg, 3) }} kg</span>
                                        <span>Approved volume: {{ number_format((float) $driver->approved_volume_capacity_liters, 3) }} L</span>
                                        <span class="font-semibold text-green-700">Availability: Available</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <button @disabled($drivers->isEmpty()) class="mt-3 rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Assign / Change Driver</button>
                </form>
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Manifest items and custody</h2>
        @if($isOriginCenter && $manifest->status === 'DRAFT')
            <p class="mt-1 text-[10px] text-muted">Record the packed weight and volume measured at this hub for each parcel before sealing. The assigned vehicle's approved capacity is checked after each update and again when sealing.</p>
        @endif
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-[10px]">
                <thead><tr class="border-b border-line text-muted"><th class="px-2 py-3">Leg</th><th class="px-2 py-3">Shipment</th><th class="px-2 py-3">Status</th><th class="px-2 py-3">Receipt</th></tr></thead>
                <tbody>
                    @forelse($manifest->items as $item)
                        <tr class="border-b border-line">
                            <td class="px-2 py-3">#{{ $item->transfer_leg_id }} · {{ $item->transferLeg?->status }}</td>
                            <td class="px-2 py-3">
                                {{ $item->transferLeg?->shipment?->tracking_number }}
                                @if($item->transferLeg?->shipment?->orderItem?->hasAdditionalCare())<span class="ml-2 rounded border border-amber-300 bg-amber-50 px-2 py-1 font-bold uppercase text-amber-900">Fragile / Additional Care</span>@endif
                                @if($isOriginCenter && $manifest->status === 'DRAFT' && $item->status === 'EXPECTED')
                                    <form method="POST" action="{{ route('logistics.transfers.items.measurements', [$manifest, $item]) }}" class="mt-3 grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-2">
                                        @csrf @method('PATCH')
                                        <label>Weight (kg)<input name="parcel_weight_kg" type="number" min="0.001" max="9999999.999" step="0.001" required value="{{ $item->transferLeg?->shipment?->parcel_weight_kg }}" class="mt-1 w-full rounded border border-line px-2 py-2"></label>
                                        <label>Volume (L)<input name="parcel_volume_liters" type="number" min="0.001" max="999999999.999" step="0.001" required value="{{ $item->transferLeg?->shipment?->parcel_volume_liters }}" class="mt-1 w-full rounded border border-line px-2 py-2"></label>
                                        <button class="rounded bg-primary px-3 py-2 font-semibold text-white sm:col-span-2">Save measured values</button>
                                    </form>
                                @else
                                    <p class="mt-1 text-muted">{{ $item->transferLeg?->shipment?->parcel_weight_kg ?? 'Weight missing' }} kg · {{ $item->transferLeg?->shipment?->parcel_volume_liters ?? 'Volume missing' }} L</p>
                                @endif
                            </td>
                            <td class="px-2 py-3">{{ $item->status }}</td>
                            <td class="px-2 py-3">
                                @if(! $isOriginCenter && in_array($manifest->status, ['ARRIVED', 'PARTIAL', 'EXCEPTION'], true) && $item->status === 'EXPECTED')
                                    <form method="POST" action="{{ route('logistics.transfers.items.receive', $item) }}" class="grid gap-2 sm:grid-cols-2">
                                        @csrf
                                        <select name="outcome" class="rounded border border-line px-2 py-2"><option value="RECEIVED">Received</option><option value="DAMAGED">Damaged</option><option value="MISSING">Missing</option></select>
                                        <input name="scanned_code" autocomplete="off" placeholder="Scan received parcel barcode" class="rounded border border-line px-2 py-2">
                                        <input name="notes" placeholder="Notes / exception details" class="rounded border border-line px-2 py-2 sm:col-span-2">
                                        <button class="rounded bg-primary px-3 py-2 font-semibold text-white sm:col-span-2">Record item receipt</button>
                                    </form>
                                @elseif(in_array($manifest->status, ['ARRIVED', 'PARTIAL', 'EXCEPTION'], true) && $item->status === 'EXPECTED')
                                    <span class="text-[9px] text-muted">Destination hub records receipt.</span>
                                @else
                                    &mdash;
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-2 py-5 text-muted">No items loaded. Add at least one eligible ready parcel before sealing.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
