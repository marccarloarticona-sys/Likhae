@extends('Logistics.app')

@section('title', 'Delivery Areas - LIKHAE Logistics')

@section('content')
    <div class="space-y-6">
        <header>
            <p class="text-xs font-bold uppercase text-primary">Area Assignment</p>
            <h1 class="mt-2 text-2xl font-bold">Delivery Areas</h1>
            <p class="mt-2 text-sm text-muted">Cover one exact barangay per local rider assignment.</p>
            @if ($center->address)
                <p class="mt-2 text-xs text-muted">
                    Center municipality: {{ collect([$center->address->municipality_name, $center->address->province_name])->filter()->implode(', ') }}
                </p>
            @endif
        </header>

        @if (session('status'))
            <div class="border border-green-200 bg-green-50 p-4">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="border border-red-200 bg-red-50 p-4 text-red-700">{{ $errors->first() }}</div>
        @endif

        <x-shared.mapbox :markers="$mapMarkers" title="Delivery area map" height="220px" user-location-target="center" />

        @if ($center->address?->province_code && $center->address?->municipality_code)
            <form method="POST" action="{{ route('logistics.delivery-areas.store') }}" class="grid gap-3 border border-line bg-surface p-5 md:grid-cols-2">
                @csrf
                <h2 class="font-semibold md:col-span-2">Add barangay coverage</h2>
                <p class="text-sm text-muted md:col-span-2">The address selector is limited to this center's canonical municipality. The server checks the PSGC codes again before saving.</p>
                <label class="text-sm">Area name
                    <input name="name" value="{{ old('name') }}" required class="mt-1 w-full border border-line p-2">
                </label>
                <label class="text-sm">Area code
                    <input name="code" value="{{ old('code') }}" class="mt-1 w-full border border-line p-2">
                </label>
                <div class="md:col-span-2">
                    <x-shared.philippine-address-fields
                        :include-details="false"
                        :allowed-province-code="$center->address->province_code"
                        :allowed-municipality-code="$center->address->municipality_code"
                        field-class="mt-1 w-full border border-line p-2"
                        wrapper-class="grid gap-3 md:grid-cols-4"
                    />
                </div>
                <label class="text-sm md:col-span-2">Assign active local rider
                    <select name="rider_profile_id" class="mt-1 w-full border border-line p-2">
                        <option value="">No rider yet</option>
                        @foreach ($riders as $rider)
                            <option value="{{ $rider->id }}" @selected((string) old('rider_profile_id') === (string) $rider->id)>
                                {{ $rider->user?->name }} - {{ ucfirst(str_replace('_', ' ', $rider->vehicle_type)) }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <button class="bg-primary px-4 py-3 font-semibold text-white md:col-span-2">Save Area Assignment</button>
            </form>
        @else
            <div class="border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Configure and approve this center's canonical PSGC province and municipality before adding barangay coverage.
            </div>
        @endif

        <section class="border border-line bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="font-semibold">Uncovered barangays</h2>
                    <p class="mt-1 text-sm text-muted">Active service areas in this center's municipality are excluded.</p>
                </div>
                <span class="text-sm font-semibold text-primary">{{ $uncoveredBarangays->count() }} uncovered</span>
            </div>
            @if ($uncoveredBarangays->isNotEmpty())
                <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($uncoveredBarangays as $barangay)
                        <div class="border border-dashed border-line p-3 text-sm">
                            <strong>{{ $barangay['name'] }}</strong>
                            <span class="mt-1 block text-xs text-muted">PSGC {{ $barangay['code'] }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-muted">No uncovered barangays were returned by the PSGC address source.</p>
            @endif
        </section>

        @if ($invalidAreaReasons->isNotEmpty())
            <section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                <h2 class="font-semibold">Existing records requiring review</h2>
                <p class="mt-1">These records were preserved. They cannot receive new local rider assignments until their PSGC coverage is reviewed.</p>
                <ul class="mt-3 list-disc space-y-1 pl-5">
                    @foreach ($invalidAreaReasons as $areaId => $reason)
                        <li>{{ $areas->firstWhere('id', $areaId)?->name ?? 'Service area #'.$areaId }}: {{ $reason }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="space-y-3">
            <h2 class="font-semibold">Service areas</h2>
            @forelse ($areas as $area)
                @php
                    $isAssignable = in_array((int) $area->id, $assignableAreaIds, true);
                    $activeRiderNames = $area->riderAssignments
                        ->where('is_active', true)
                        ->pluck('riderProfile.user.name')
                        ->filter()
                        ->join(', ');
                @endphp
                <article class="border border-line bg-surface p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <strong>{{ $area->name }}</strong>
                                <span class="rounded-full px-2 py-1 text-xs {{ $area->is_active ? 'bg-green-100 text-green-800' : 'bg-stone-100 text-stone-700' }}">
                                    {{ $area->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm text-muted">
                                @forelse ($area->locations as $location)
                                    <span class="block">{{ collect([$location->barangay_name, $location->municipality_name, $location->province_name])->filter()->implode(', ') }} <span class="text-xs">(PSGC {{ $location->barangay_code }})</span></span>
                                @empty
                                    No barangay location saved.
                                @endforelse
                            </p>
                            <p class="mt-2 text-sm">Riders: {{ $activeRiderNames ?: 'None assigned' }}</p>
                            @if ($area->is_active && ! $isAssignable)
                                <p class="mt-2 text-xs text-amber-700">Not assignable: this area is not exactly one barangay in the center's canonical municipality.</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('logistics.delivery-areas.toggle', $area) }}">
                            @csrf
                            @method('PATCH')
                            <button class="border border-line px-3 py-2">{{ $area->is_active ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="border border-dashed border-line p-8 text-center text-muted">No delivery areas configured.</div>
            @endforelse
        </section>
    </div>
@endsection
