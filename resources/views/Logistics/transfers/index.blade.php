@extends('layouts.logistics.app')

@section('title', 'Hub Transfers - LIKHAE Logistics')

@section('content')
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Parcel transfers</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink">Transfers between hubs</h1>
            <p class="mt-1 text-xs text-muted">{{ $center->business_name }} &middot; Send sorted parcels to their next hub and track incoming transfers.</p>
        </div>
        <a href="{{ route('logistics.dashboard') }}" class="rounded-lg border border-line px-4 py-3 text-[10px] font-semibold text-ink">Dashboard</a>
    </div>

    @if(session('status'))<div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-[10px] text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[10px] text-red-700">{{ $errors->first() }}</div>@endif

    @if(! $transferReady)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
            <h2 class="text-[13px] font-semibold">Transfer operations are disabled</h2>
            <p class="mt-2 text-[10px] leading-5">The transfer manifest and approved rider capacity schema is not installed. Existing parcel operations remain available.</p>
        </section>
    @else
        @php($readyLegGroups = $readyLegs->groupBy('destination_logistics_center_id'))

        <section class="rounded-xl border border-line bg-surface p-5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-[13px] font-semibold text-ink">Ready to send</h2>
                    <p class="mt-1 text-xs text-muted">Select parcels going to the same next hub. Their route is matched from the buyer's address.</p>
                </div>
                <span class="rounded-full bg-primary/5 px-3 py-2 text-[10px] font-semibold text-primary">{{ $readyLegs->count() }} {{ \Illuminate\Support\Str::plural('parcel', $readyLegs->count()) }}</span>
            </div>

            <div class="mt-4 space-y-3">
                @forelse($readyLegGroups as $destinationId => $legs)
                    @php($destination = $legs->first()->destinationLogisticsCenter)
                    <form method="POST" action="{{ route('logistics.transfers.store') }}" class="rounded-lg border border-line p-3">
                        @csrf
                        <input type="hidden" name="create_with_parcels" value="1">
                        <input type="hidden" name="destination_logistics_center_id" value="{{ $destinationId }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3">
                            <div>
                                <h3 class="text-xs font-bold text-ink">{{ $center->business_name }} &rarr; {{ $destination?->business_name ?? 'Destination hub' }}</h3>
                                <p class="mt-1 text-[9px] text-muted">{{ $legs->count() }} {{ \Illuminate\Support\Str::plural('parcel', $legs->count()) }} on this route</p>
                            </div>
                            <span class="rounded-full bg-green-50 px-3 py-1 text-[9px] font-semibold text-green-800">Ready</span>
                        </div>

                        <fieldset class="mt-3">
                            <legend class="mb-2 text-xs font-semibold text-ink">Choose one or more parcels</legend>
                            <div class="grid gap-2 md:grid-cols-2">
                                @foreach($legs as $leg)
                                    <label class="flex cursor-pointer gap-2 rounded-lg border border-line bg-white p-3 text-xs hover:border-primary">
                                        <input class="mt-0.5" type="checkbox" name="transfer_leg_ids[]" value="{{ $leg->id }}" @checked(in_array((string) $leg->id, (array) old('transfer_leg_ids', []), true))>
                                        <span class="min-w-0">
                                            <strong class="block text-ink">{{ $leg->shipment?->tracking_number ?? 'Shipment' }}</strong>
                                            <span class="mt-1 block text-[11px] text-muted">Buyer: {{ collect([$leg->shipment?->destination_barangay_name, $leg->shipment?->destination_municipality_name, $leg->shipment?->destination_province_name])->filter()->join(', ') ?: 'Address not recorded' }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="mt-3 grid gap-3 md:grid-cols-2 md:items-end">
                            @if($drivers->isNotEmpty())
                                <label class="text-xs font-semibold text-ink">Four-wheel driver <span class="font-normal text-muted">(optional for now)</span>
                                    <select name="assigned_driver_rider_profile_id" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-xs font-normal">
                                        <option value="">Assign later</option>
                                        @foreach($drivers as $driver)
                                            <option value="{{ $driver->id }}" @selected((string) old('assigned_driver_rider_profile_id') === (string) $driver->id)>{{ $driver->user?->name ?? 'Rider' }} · {{ str($driver->vehicle_type)->replace('_', ' ')->headline() }}{{ $driver->plate_number ? ' · '.$driver->plate_number : '' }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @else
                                <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[9px] text-amber-900">No available four-wheel driver. You can prepare this transfer and assign a driver before sealing.</p>
                            @endif
                            <label class="text-xs font-semibold text-ink">Note for the receiving hub <span class="font-normal text-muted">(optional)</span>
                                <input name="notes" maxlength="2000" value="{{ old('notes') }}" class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-xs font-normal" placeholder="Add a note">
                            </label>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-[11px] text-muted">Next: check parcel measurements, assign a driver, then seal the transfer.</p>
                            <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-xs font-semibold text-white">Prepare transfer</button>
                        </div>
                    </form>
                @empty
                    <p class="rounded-lg border border-dashed border-line p-5 text-[10px] text-muted">No parcels are ready to send. A parcel appears here after it is sorted for another hub and received into this hub's custody.</p>
                @endforelse
            </div>

            @if($unavailableDrivers->isNotEmpty())
                <details class="mt-4 rounded-lg border border-line bg-white p-3">
                    <summary class="cursor-pointer text-[10px] font-semibold text-ink">Why other four-wheel drivers are unavailable ({{ $unavailableDrivers->count() }})</summary>
                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                        @foreach($unavailableDrivers as $driver)
                            <div class="rounded-lg border border-line p-3 text-[10px]">
                                <strong>{{ $driver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $driver->id, 4, '0', STR_PAD_LEFT) }}</strong>
                                <span class="ml-1 text-muted">· {{ str($driver->vehicle_type)->replace('_', ' ')->headline() }}</span>
                                <p class="mt-1 text-amber-800">{{ $driver->inter_hub_unavailable_reason }}</p>
                                @unless($driver->inter_hub_capacity_complete)
                                    <a href="{{ route('logistics.riders.show', $driver) }}" class="mt-1 inline-block font-semibold text-primary underline">Review driver capacity</a>
                                @endunless
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </section>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Outgoing transfers</h2>
                <div class="mt-4 space-y-2">
                    @forelse($outgoing as $manifest)
                        <a href="{{ route('logistics.transfers.show', $manifest) }}" class="block rounded-lg border border-line p-3 hover:border-primary">
                            <div class="flex justify-between gap-3 text-[10px] font-semibold text-ink"><span>{{ $manifest->manifest_number }}</span><span>{{ $manifest->status === 'DRAFT' ? 'Preparing' : str($manifest->status)->headline() }}</span></div>
                            <p class="mt-1 text-[10px] text-muted">{{ $manifest->items_count }} {{ \Illuminate\Support\Str::plural('parcel', $manifest->items_count) }} · {{ $manifest->created_at?->format('M d, Y H:i') }}</p>
                            <p class="mt-1 text-[10px] text-ink"><strong>Transfer driver:</strong>
                                @if($manifest->assignedDriver)
                                    {{ $manifest->assignedDriver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $manifest->assignedDriver->id, 4, '0', STR_PAD_LEFT) }} · {{ str($manifest->assignedDriver->vehicle_type)->replace('_', ' ')->headline() }} · {{ $manifest->assignedDriver->plate_number ?: 'Plate not recorded' }}
                                @else
                                    <span class="text-muted">Not assigned</span>
                                @endif
                            </p>
                        </a>
                    @empty
                        <p class="text-[10px] text-muted">No outgoing transfers yet.</p>
                    @endforelse
                </div>
                {{ $outgoing->links() }}
            </section>
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Incoming transfers</h2>
                <div class="mt-4 space-y-2">
                    @forelse($incoming as $manifest)
                        <a href="{{ route('logistics.transfers.show', $manifest) }}" class="block rounded-lg border border-line p-3 hover:border-primary">
                            <div class="flex justify-between gap-3 text-[10px] font-semibold text-ink"><span>{{ $manifest->manifest_number }}</span><span>{{ $manifest->status === 'DRAFT' ? 'Preparing' : str($manifest->status)->headline() }}</span></div>
                            <p class="mt-1 text-[10px] text-muted">{{ $manifest->items_count }} {{ \Illuminate\Support\Str::plural('parcel', $manifest->items_count) }} · {{ $manifest->created_at?->format('M d, Y H:i') }}</p>
                            @if($manifest->assignedDriver)<p class="mt-1 text-[10px] text-ink"><strong>Transfer driver:</strong> {{ $manifest->assignedDriver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $manifest->assignedDriver->id, 4, '0', STR_PAD_LEFT) }}</p>@endif
                        </a>
                    @empty
                        <p class="text-[10px] text-muted">No incoming transfers yet.</p>
                    @endforelse
                </div>
                {{ $incoming->links() }}
            </section>
        </div>
    @endif
</div>
@endsection
