@extends('Logistics.app')

@section('title', 'Transfer Manifest - LIKHAE Logistics')

@section('content')
@php($releasedParcelCount = $manifest->items->filter(fn ($item) => $item->transferLeg?->status === \App\Models\Logistics\ShipmentTransferLeg::RELEASED)->count())
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Transfer preparation</span>
            <h1 class="mt-2 font-display text-[28px] font-semibold tracking-[-0.04em] text-ink">{{ $manifest->manifest_number }}</h1>
            <p class="mt-1 text-[10px] text-muted">{{ $manifest->originLogisticsCenter?->business_name }} &rarr; {{ $manifest->destinationLogisticsCenter?->business_name }} &middot; {{ $manifest->status === 'DRAFT' ? 'Preparing' : str($manifest->status)->headline() }} @if($manifest->status === 'SEALED')<span data-manifest-handoff-progress class="{{ $releasedParcelCount > 0 ? '' : 'hidden' }}">&middot; {{ $releasedParcelCount }} / {{ $manifest->items->count() }} parcels released</span>@endif</p>
            @if($manifest->status === 'DRAFT')
                <p class="mt-2 max-w-2xl text-[10px] leading-5 text-muted">Review the parcels and their measurements, assign an approved four-wheel driver, then seal the transfer. Parcels leave this hub after the driver accepts and staff scan the handoff.</p>
            @endif
        </div>
        <a href="{{ route('logistics.transfers') }}" class="rounded-lg border border-line px-4 py-3 text-[10px] font-semibold text-ink">Back to transfers</a>
    </div>

    @if(session('status'))<div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-[10px] text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[10px] text-red-700">{{ $errors->first() }}</div>@endif

    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="rounded-lg border border-line bg-white p-4">
            <h2 class="text-[12px] font-bold text-ink">Transfer driver</h2>
            @if($manifest->assignedDriver)
                <div class="mt-3 grid gap-2 text-[10px] sm:grid-cols-2 lg:grid-cols-3">
                    <p><span class="text-muted">Driver:</span> <strong>{{ $manifest->assignedDriver->user?->name ?? 'Rider' }}</strong></p>
                    <p><span class="text-muted">Rider ID:</span> <strong>RID-{{ str_pad((string) $manifest->assignedDriver->id, 4, '0', STR_PAD_LEFT) }}</strong></p>
                    <p><span class="text-muted">Vehicle:</span> <strong>{{ str($manifest->assignedDriver->vehicle_type)->replace('_', ' ')->headline() }}</strong></p>
                    <p><span class="text-muted">Plate number:</span> <strong>{{ $manifest->assignedDriver->plate_number ?: 'Not recorded' }}</strong></p>
                    <p><span class="text-muted">Approved parcel capacity:</span> <strong>{{ $manifest->assignedDriver->approved_parcel_capacity ?? 'Not recorded' }}</strong></p>
                    <p><span class="text-muted">Approved weight / volume:</span> <strong>{{ $manifest->assignedDriver->approved_weight_capacity_kg ?? '-' }} kg / {{ $manifest->assignedDriver->approved_volume_capacity_liters ?? '-' }} L</strong></p>
                    <p class="font-semibold text-primary sm:col-span-2 lg:col-span-3">Status: Assigned to this transfer</p>
                </div>
            @else
                <p class="mt-2 text-[10px] text-amber-800">No driver is assigned yet. An eligible driver is required before this manifest can be sealed.</p>
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

        <div class="mt-4 flex flex-wrap gap-2">
            @if($isOriginCenter && $manifest->status === 'DRAFT')
                <form method="POST" action="{{ route('logistics.transfers.seal', $manifest) }}" data-confirm-title="Seal transfer manifest?" data-confirm-message="Lock the parcel list and transfer details for this inter-hub manifest?" data-confirm-label="Seal manifest">@csrf<button class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Seal transfer</button></form>
            @elseif($isOriginCenter && $manifest->status === 'SEALED')
                @if($manifest->driver_accepted_at)
                    <div class="grid w-full gap-3 rounded-lg border border-line p-4" data-transfer-handoff>
                        <strong class="text-xs">Scan each parcel at handoff to the accepted transfer driver.</strong>
                        <section class="rounded-lg border border-line bg-white p-4" data-parcel-scanner data-scanner-autosubmit="false" data-scanner-fill-matching="true">
                            <h2 class="text-sm font-semibold text-ink">Scan parcels at handoff</h2>
                            <p class="mt-1 text-sm text-muted">Each scan releases its matching parcel immediately. The manifest becomes released after the final parcel.</p>
                            <label class="mt-3 grid gap-1 text-sm font-semibold text-ink">Scan parcel barcode
                                <input type="text" data-scanner-input autofocus autocomplete="off" autocapitalize="characters" spellcheck="false" enterkeyhint="done" placeholder="Scan or enter a tracking number" class="min-h-11 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm font-normal text-ink">
                            </label>
                            <div class="relative mt-3 overflow-hidden rounded-lg border border-line bg-black">
                                <video data-scanner-video class="hidden aspect-video w-full object-cover" playsinline muted></video>
                                <div data-scanner-placeholder class="flex aspect-video items-center justify-center px-6 text-center text-sm text-white">Camera is off.</div>
                                <div data-scanner-guide class="pointer-events-none absolute inset-x-6 top-1/2 z-10 hidden h-20 -translate-y-1/2 border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,.28)]" aria-hidden="true"></div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" data-scanner-start class="min-h-11 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Start Camera</button>
                                <button type="button" data-scanner-stop class="hidden min-h-11 rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-ink">Stop Camera</button>
                                <label class="hidden min-h-11 items-center gap-2 text-sm font-semibold text-ink" data-camera-select-wrap>Camera<select data-camera-select aria-label="Select camera" class="min-h-11 max-w-[14rem] rounded-lg border border-line bg-white px-2 py-2"></select></label>
                                <button type="button" data-scanner-torch class="hidden min-h-11 rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-ink" aria-pressed="false">Turn Torch On</button>
                            </div>
                            <p data-scanner-message class="mt-2 min-h-6 text-sm font-semibold text-muted" aria-live="polite"></p>
                            <p class="text-xs text-muted"><span data-handoff-progress>{{ $releasedParcelCount }} / {{ $manifest->items->count() }}</span> parcels released</p>
                            <input type="hidden" data-scanner-method value="MANUAL">
                        </section>
                        @forelse($manifest->items as $item)
                            @php($trackingNumber = (string) $item->transferLeg?->shipment?->tracking_number)
                            @php($isReleased = $item->transferLeg?->status === \App\Models\Logistics\ShipmentTransferLeg::RELEASED)
                            <form method="POST" action="{{ route('logistics.transfers.items.release', [$manifest, $item]) }}" data-transfer-parcel-row data-released="{{ $isReleased ? 'true' : 'false' }}" class="grid gap-2 border-b border-line pb-3 text-[10px] sm:grid-cols-[1fr_1fr_auto_auto] sm:items-center">
                                @csrf
                                <strong>{{ $trackingNumber }}</strong>
                                <input name="scanned_code" value="{{ $isReleased ? $trackingNumber : '' }}" data-expected-tracking="{{ $trackingNumber }}" @required(! $isReleased) @readonly($isReleased) autocomplete="off" placeholder="Scan parcel barcode" class="rounded-lg border border-line px-3 py-2">
                                <span data-scan-verification class="{{ $isReleased ? 'text-xs font-semibold text-green-700' : 'text-xs text-muted' }}">{{ $isReleased ? 'Released' : 'Awaiting scan' }}</span>
                                <input type="hidden" name="scan_method" value="MANUAL" data-scan-method-for="{{ $trackingNumber }}">
                                <button type="submit" @disabled($isReleased) class="rounded-lg bg-primary px-3 py-2 font-semibold text-white disabled:cursor-default disabled:bg-green-700">{{ $isReleased ? 'Released' : 'Release parcel' }}</button>
                            </form>
                        @empty
                            <p class="text-[10px] text-red-700">This manifest has no parcels to release.</p>
                        @endforelse
                    </div>
                @else
                    <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] text-amber-900">Waiting for the assigned driver to accept this sealed manifest.</p>
                @endif
            @elseif(! $isOriginCenter && $manifest->status === 'IN_TRANSIT')
                <p class="max-w-2xl rounded-lg border border-blue-200 bg-blue-50 p-3 text-[10px] leading-5 text-blue-900">Scan each parcel below at this destination hub. A successful scan records its arrival, transfers custody to this hub, and places it in sorting. Staff can then sort it for a local delivery trip when this is the final hub. No separate arrival step is needed.</p>
            @elseif($isOriginCenter && $manifest->status === 'RELEASED')
                <p class="rounded-lg border border-line bg-white p-3 text-[10px] text-muted">Released. The assigned driver must start transport from their Rider workspace.</p>
            @elseif($isOriginCenter && $manifest->status === 'IN_TRANSIT')
                <p class="rounded-lg border border-line bg-white p-3 text-[10px] text-muted">In transit. The destination hub records arrival and parcel receipt.</p>
            @endif

            @if($isOriginCenter && in_array($manifest->status, ['DRAFT', 'SEALED'], true) && $releasedParcelCount === 0)
                <form method="POST" action="{{ route('logistics.transfers.cancel', $manifest) }}" class="flex gap-2" data-confirm-title="Cancel transfer manifest?" data-confirm-message="Cancel this manifest and keep its parcels out of the inter-hub transfer flow?" data-confirm-label="Cancel manifest" data-confirm-tone="danger">@csrf<input name="reason" required placeholder="Cancellation reason" class="rounded-lg border border-line px-3 py-3 text-[10px]"><button class="rounded-lg border border-red-300 px-4 py-3 text-[10px] font-semibold text-red-700">Cancel</button></form>
            @endif
        </div>

        @if($isOriginCenter && $manifest->status === 'DRAFT')
            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <form method="POST" action="{{ route('logistics.transfers.items.store', $manifest) }}" class="rounded-lg border border-line p-4">
                    @csrf
                    <h2 class="text-[12px] font-semibold text-ink">Add another parcel</h2>
                    @if($readyLegs->isEmpty())
                        <p class="mt-3 rounded-lg border border-green-200 bg-green-50 p-3 text-[10px] text-green-900">
                            @if($manifest->items->isNotEmpty())
                                All ready parcels for this route are already loaded. This transfer contains {{ $manifest->items->count() }} {{ \Illuminate\Support\Str::plural('parcel', $manifest->items->count()) }}.
                            @else
                                No other sorted parcels are waiting for this route right now.
                            @endif
                        </p>
                    @else
                        <select name="transfer_leg_id" required class="mt-3 w-full rounded-lg border border-line px-3 py-3 text-[10px]">
                            <option value="">Select a ready leg</option>
                            @foreach($readyLegs as $leg)
                                <option value="{{ $leg->id }}">Leg #{{ $leg->id }} · {{ $leg->shipment?->tracking_number }} · seq {{ $leg->sequence_no }}</option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-[10px] text-muted">Only sorted parcels in this hub's custody with the same next hub are listed.</p>
                    @endif
                    <button @disabled($readyLegs->isEmpty()) class="mt-3 rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Add parcel</button>
                </form>

                <form method="POST" action="{{ route('logistics.transfers.driver.update', $manifest) }}" class="rounded-lg border border-line p-4">
                    @csrf @method('PATCH')
                    <h2 class="text-[12px] font-semibold text-ink">Assign a four-wheel driver</h2>
                    <p class="mt-1 text-[10px] text-muted">Choose an approved driver from this hub. You can change the driver until the transfer is sealed.</p>
                    @if($drivers->isEmpty())
                        <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] text-amber-900">No approved four-wheel driver is available right now. Approved riders who are busy or need capacity updates are shown below.</p>
                    @else
                        <select name="rider_profile_id" required class="mt-3 w-full rounded-lg border border-line px-3 py-3 text-[10px]">
                            <option value="">Choose a driver</option>
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
                                        @if((int) $driver->id === (int) $manifest->assigned_driver_rider_profile_id)
                                            <span class="font-semibold text-primary">Status: Assigned to this transfer</span>
                                        @else
                                            <span class="font-semibold text-green-700">Status: Available</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($unavailableDrivers->isNotEmpty())
                        <div class="mt-3 rounded-lg border border-line bg-white p-3">
                            <h3 class="text-[10px] font-bold text-ink">Approved four-wheel drivers currently unavailable</h3>
                            <div class="mt-2 grid gap-2">
                                @foreach($unavailableDrivers as $driver)
                                    <div class="rounded border border-line p-2 text-[10px]">
                                        <strong>{{ $driver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $driver->id, 4, '0', STR_PAD_LEFT) }}</strong>
                                        <span class="text-muted">· {{ str($driver->vehicle_type)->replace('_', ' ')->headline() }}</span>
                                        <p class="mt-1 text-amber-800">{{ $driver->inter_hub_unavailable_reason }}</p>
                                        @unless($driver->inter_hub_capacity_complete)
                                            <a href="{{ route('logistics.riders.show', $driver) }}" class="mt-1 inline-block font-semibold text-primary underline">Review approved vehicle capacity</a>
                                        @endunless
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <button @disabled($drivers->isEmpty()) class="mt-3 rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Assign / Change Driver</button>
                </form>
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Parcels in this transfer</h2>
        @if($isOriginCenter && $manifest->status === 'DRAFT')
            <p class="mt-1 text-[10px] text-muted">Weight is required for every parcel. Volume is optional; its capacity is checked only when a volume is entered for every parcel.</p>
            @php($itemsMissingWeight = $manifest->items->filter(fn ($item) => blank($item->transferLeg?->shipment?->parcel_weight_kg)))
            @if($itemsMissingWeight->isNotEmpty())
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] text-amber-950">
                    <strong>Weight needed for {{ $itemsMissingWeight->count() }} {{ \Illuminate\Support\Str::plural('parcel', $itemsMissingWeight->count()) }}:</strong>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach($itemsMissingWeight as $missingItem)
                            <li>{{ $missingItem->transferLeg?->shipment?->tracking_number ?? 'Parcel #'.$missingItem->transfer_leg_id }}</li>
                        @endforeach
                    </ul>
                </div>
            @else
                <p class="mt-3 rounded-lg border border-green-200 bg-green-50 p-3 text-[10px] text-green-900">All parcels have a weight recorded. You can seal the transfer when the other requirements are complete.</p>
            @endif
        @endif
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-[10px]">
                <thead><tr class="border-b border-line text-muted"><th class="px-2 py-3">Leg</th><th class="px-2 py-3">Shipment</th><th class="px-2 py-3">Status</th><th class="px-2 py-3">Receipt</th></tr></thead>
                <tbody>
                    @forelse($manifest->items as $item)
                        <tr class="border-b border-line">
                            <td class="px-2 py-3">#{{ $item->transfer_leg_id }} · {{ $item->transferLeg?->status }}</td>
                            <td class="px-2 py-3">
                                <strong class="block">{{ $item->transferLeg?->shipment?->tracking_number }}</strong>
                                @if($isOriginCenter && $manifest->status === 'DRAFT')
                                    @if(blank($item->transferLeg?->shipment?->parcel_weight_kg))
                                        <span class="mt-1 inline-block rounded bg-amber-100 px-2 py-1 text-[9px] font-semibold text-amber-900">Weight needed</span>
                                    @else
                                        <span class="mt-1 inline-block rounded bg-green-100 px-2 py-1 text-[9px] font-semibold text-green-900">Weight saved</span>
                                    @endif
                                @endif
                                @if($item->transferLeg?->shipment?->orderItem?->hasAdditionalCare())<span class="ml-2 rounded border border-amber-300 bg-amber-50 px-2 py-1 font-bold uppercase text-amber-900">Fragile / Additional Care</span>@endif
                                @if($isOriginCenter && $manifest->status === 'DRAFT' && $item->status === 'EXPECTED')
                                    <form method="POST" action="{{ route('logistics.transfers.items.measurements', [$manifest, $item]) }}" class="mt-3 grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-2">
                                        @csrf @method('PATCH')
                                        <strong class="sm:col-span-2">Measurements for {{ $item->transferLeg?->shipment?->tracking_number }}</strong>
                                        <label>Weight (kg) <span class="text-red-700">required</span><input name="parcel_weight_kg" type="number" min="0.001" max="9999999.999" step="0.001" required value="{{ $item->transferLeg?->shipment?->parcel_weight_kg }}" class="mt-1 w-full rounded border border-line px-2 py-2"></label>
                                        <label>Volume (L) <span class="text-muted">optional</span><input name="parcel_volume_liters" type="number" min="0.001" max="999999999.999" step="0.001" value="{{ $item->transferLeg?->shipment?->parcel_volume_liters }}" class="mt-1 w-full rounded border border-line px-2 py-2"></label>
                                        <button class="rounded bg-primary px-3 py-2 font-semibold text-white sm:col-span-2">Save measurements</button>
                                    </form>
                                @else
                                    <p class="mt-1 text-muted">Weight: {{ $item->transferLeg?->shipment?->parcel_weight_kg ?? 'Not recorded' }} kg &middot; Volume: {{ $item->transferLeg?->shipment?->parcel_volume_liters ? $item->transferLeg->shipment->parcel_volume_liters.' L' : 'Not recorded (optional)' }}</p>
                                @endif
                            </td>
                            <td class="px-2 py-3" data-transfer-leg-status="{{ $item->id }}">
                                <strong>{{ str($item->transferLeg?->status)->headline() }}</strong>
                                @if($item->status !== \App\Models\Logistics\TransferManifestItem::EXPECTED)
                                    <span class="block text-muted">Receipt: {{ str($item->status)->headline() }}</span>
                                @endif
                            </td>
                            <td class="px-2 py-3">
                                @if(! $isOriginCenter && in_array($manifest->status, ['IN_TRANSIT', 'ARRIVED', 'PARTIAL', 'EXCEPTION'], true) && $item->status === 'EXPECTED')
                                    <form method="POST" action="{{ route('logistics.transfers.items.receive', $item) }}" class="grid gap-2 sm:grid-cols-2">
                                        @csrf
                                        <select name="outcome" class="rounded border border-line px-2 py-2"><option value="RECEIVED">Received</option><option value="DAMAGED">Damaged</option><option value="MISSING">Missing</option></select>
                                        <input name="scanned_code" autocomplete="off" placeholder="Scan parcel barcode to receive" class="rounded border border-line px-2 py-2">
                                        <input name="notes" placeholder="Notes / exception details" class="rounded border border-line px-2 py-2 sm:col-span-2">
                                        <button class="rounded bg-primary px-3 py-2 font-semibold text-white sm:col-span-2">Scan and receive at destination</button>
                                    </form>
                                @elseif(in_array($manifest->status, ['IN_TRANSIT', 'ARRIVED', 'PARTIAL', 'EXCEPTION'], true) && $item->status === 'EXPECTED')
                                    <span class="text-[9px] text-muted">Scan this parcel at the destination hub.</span>
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
