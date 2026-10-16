@extends('Logistics.app')

@section('title', 'Hub Transfers - LIKHAE Logistics')

@section('content')
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Custody Operations</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink">Inter-hub transfers</h1>
            <p class="mt-1 text-[10px] text-muted">{{ $center->business_name }} · PSGC-scoped outgoing and incoming custody.</p>
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
        <section class="rounded-xl border border-line bg-surface p-5">
            <h2 class="text-[13px] font-semibold text-ink">Create outgoing manifest</h2>
            <p class="mt-1 text-[10px] text-muted">Create a draft with a driver now or assign an available inter-hub driver before sealing.</p>
            <form method="POST" action="{{ route('logistics.transfers.store') }}" class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] lg:items-end">
                @csrf
                <label class="text-[10px] font-semibold text-ink">Destination hub
                    <select name="destination_logistics_center_id" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-3 text-[10px] font-normal">
                        <option value="">Select hub</option>
                        @foreach($destinations as $destination)
                            <option value="{{ $destination->id }}" @selected((string) old('destination_logistics_center_id') === (string) $destination->id)>{{ $destination->business_name }}{{ $destination->code ? ' · '.$destination->code : '' }}</option>
                        @endforeach
                    </select>
                    @if($destinations->isEmpty())<span class="mt-1 block font-normal text-amber-800">No other active destination hubs are available.</span>@endif
                </label>
                <label class="text-[10px] font-semibold text-ink">Notes
                    <textarea name="notes" rows="1" maxlength="2000" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal">{{ old('notes') }}</textarea>
                </label>

                <fieldset class="rounded-xl border border-primary/30 bg-primary/5 p-4 lg:col-span-3">
                    <legend class="px-2 text-[12px] font-bold text-ink">Assign Inter-Hub Driver <span class="ml-1 rounded-full bg-white px-2 py-1 text-[8px] font-semibold uppercase tracking-wide text-muted">Optional for draft</span></legend>
                    <p class="mb-3 text-[10px] leading-5 text-muted">Only approved, active, available Van, Closed Van, and Truck drivers authorized by {{ $center->business_name }} appear here. Motorcycles, tricycles, and legacy Cars are excluded.</p>
                    @if($drivers->isEmpty())
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] text-amber-900">No eligible inter-hub drivers are available. You can save an unassigned draft and assign a driver after an eligible driver is approved and available.</div>
                    @else
                        <div class="grid gap-2 xl:grid-cols-2">
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-line bg-white p-3 text-[10px] text-ink">
                                <input type="radio" name="assigned_driver_rider_profile_id" value="" @checked(old('assigned_driver_rider_profile_id') === null || old('assigned_driver_rider_profile_id') === '')>
                                <span><strong class="block">Leave unassigned</strong><span class="text-muted">Assign a driver while the manifest is still a draft.</span></span>
                            </label>
                            @foreach($drivers as $driver)
                                <label class="flex cursor-pointer gap-3 rounded-lg border border-line bg-white p-3 text-[10px] text-ink hover:border-primary">
                                    <input class="mt-1" type="radio" name="assigned_driver_rider_profile_id" value="{{ $driver->id }}" @checked((string) old('assigned_driver_rider_profile_id') === (string) $driver->id)>
                                    <span class="grid flex-1 gap-1 sm:grid-cols-2">
                                        <strong class="sm:col-span-2">{{ $driver->user?->name ?? 'Rider' }} <span class="font-normal text-muted">? RID-{{ str_pad((string) $driver->id, 4, '0', STR_PAD_LEFT) }}</span></strong>
                                        <span>Vehicle: {{ str($driver->vehicle_type)->replace('_', ' ')->headline() }}</span>
                                        <span>Plate: {{ $driver->plate_number ?: 'Not recorded' }}</span>
                                        <span>Approved parcels: {{ $driver->approved_parcel_capacity }}</span>
                                        <span>Approved weight: {{ number_format((float) $driver->approved_weight_capacity_kg, 3) }} kg</span>
                                        <span>Approved volume: {{ number_format((float) $driver->approved_volume_capacity_liters, 3) }} L</span>
                                        <span class="font-semibold text-green-700">Availability: Available</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('assigned_driver_rider_profile_id')<p class="mt-2 text-[10px] font-semibold text-red-700">{{ $message }}</p>@enderror
                </fieldset>

                <button type="submit" @disabled($destinations->isEmpty()) class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 lg:col-start-3">Create Draft</button>
            </form>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Outgoing manifests</h2>
                <div class="mt-4 space-y-2">
                    @forelse($outgoing as $manifest)
                        <a href="{{ route('logistics.transfers.show', $manifest) }}" class="block rounded-lg border border-line p-3 hover:border-primary">
                            <div class="flex justify-between gap-3 text-[10px] font-semibold text-ink"><span>{{ $manifest->manifest_number }}</span><span>{{ $manifest->status }}</span></div>
                            <p class="mt-1 text-[10px] text-muted">{{ $manifest->items_count }} parcel leg(s) · {{ $manifest->created_at?->format('M d, Y H:i') }}</p>
                            <p class="mt-1 text-[10px] text-ink"><strong>Inter-hub driver:</strong>
                                @if($manifest->assignedDriver)
                                    {{ $manifest->assignedDriver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $manifest->assignedDriver->id, 4, '0', STR_PAD_LEFT) }} · {{ str($manifest->assignedDriver->vehicle_type)->replace('_', ' ')->headline() }} · {{ $manifest->assignedDriver->plate_number ?: 'Plate not recorded' }}
                                @else
                                    <span class="text-muted">Not assigned</span>
                                @endif
                            </p>
                        </a>
                    @empty
                        <p class="text-[10px] text-muted">No outgoing manifests.</p>
                    @endforelse
                </div>
                {{ $outgoing->links() }}
            </section>
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Incoming manifests</h2>
                <div class="mt-4 space-y-2">
                    @forelse($incoming as $manifest)
                        <a href="{{ route('logistics.transfers.show', $manifest) }}" class="block rounded-lg border border-line p-3 hover:border-primary">
                            <div class="flex justify-between gap-3 text-[10px] font-semibold text-ink"><span>{{ $manifest->manifest_number }}</span><span>{{ $manifest->status }}</span></div>
                            <p class="mt-1 text-[10px] text-muted">{{ $manifest->items_count }} parcel leg(s) · {{ $manifest->created_at?->format('M d, Y H:i') }}</p>
                            @if($manifest->assignedDriver)<p class="mt-1 text-[10px] text-ink"><strong>Inter-hub driver:</strong> {{ $manifest->assignedDriver->user?->name ?? 'Rider' }} · RID-{{ str_pad((string) $manifest->assignedDriver->id, 4, '0', STR_PAD_LEFT) }}</p>@endif
                        </a>
                    @empty
                        <p class="text-[10px] text-muted">No incoming manifests.</p>
                    @endforelse
                </div>
                {{ $incoming->links() }}
            </section>
        </div>
    @endif
</div>
@endsection
