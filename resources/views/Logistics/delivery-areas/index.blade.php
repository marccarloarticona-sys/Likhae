@extends('layouts.logistics.app')

@section('title', 'Delivery Areas - LIKHAE Logistics')

@push('styles')
    <style>
        .delivery-area-address > label:nth-of-type(-n + 2) {
            display: none;
        }

        .delivery-area-address > label {
            min-width: 0;
        }

        @media (max-width: 640px) {
            .delivery-area-address {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
@endpush

@section('content')
    @php
        $remoteCoverageEnabled = (bool) config('likhae.automatic_seller_pickup_matching', false);
    @endphp
    <div class="delivery-areas-page mx-auto w-full max-w-[1500px] space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-4 rounded-2xl border border-line bg-surface px-5 py-5 shadow-sm sm:px-7 sm:py-6">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Rider management</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Delivery Areas</h1>
                <p class="mt-2 text-sm text-muted">Assign local riders to a single barangay. {{ $remoteCoverageEnabled ? 'During automatic pickup matching, you can explicitly configure exact remote PSGC areas your center serves.' : 'Areas outside your center’s municipality require explicit administrator-approved coverage.' }}</p>
            </div>
            @if ($center->address)
                <div class="rounded-xl border border-line bg-page px-4 py-3 text-xs text-muted">
                    <span class="block text-[9px] font-bold uppercase tracking-[0.14em]">Center municipality</span>
                    <strong class="mt-1 block text-sm font-semibold text-ink">{{ collect([$center->address->municipality_name, $center->address->province_name])->filter()->implode(', ') }}</strong>
                </div>
            @endif
        </header>

        @if (session('status'))
            <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">{{ $errors->first() }}</div>
        @endif

        @if ($centerCanonicalMunicipality)
            <form method="POST" action="{{ route('logistics.delivery-areas.store') }}" class="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
                @csrf
                <div class="border-b border-line px-5 py-4 sm:px-6">
                    <h2 class="text-base font-bold text-ink">Add barangay coverage</h2>
                    <p class="mt-1 text-xs leading-5 text-muted">{{ $remoteCoverageEnabled ? 'Choose the exact barangay your center serves. Pickup matching will never use distance alone as authorization.' : 'Choose a barangay in your center’s municipality. Coverage is verified against official PSGC codes when saved.' }}</p>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                    <label class="grid min-w-0 gap-1.5 text-xs font-semibold text-ink">Area name
                        <input name="name" value="{{ old('name') }}" required class="min-h-11 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm font-normal text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/10">
                    </label>
                    <label class="grid min-w-0 gap-1.5 text-xs font-semibold text-ink"><span class="flex items-center gap-2">Area code <span class="font-normal text-muted">Optional</span></span>
                        <input name="code" value="{{ old('code') }}" class="min-h-11 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm font-normal text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/10">
                    </label>
                    <x-shared.philippine-address-fields
                        :address="$center->address"
                        :include-details="false"
                        :allowed-province-code="$remoteCoverageEnabled ? null : $centerCanonicalMunicipality['province_code']"
                        :allowed-municipality-code="$remoteCoverageEnabled ? null : $centerCanonicalMunicipality['municipality_code']"
                        :region-code="$remoteCoverageEnabled ? null : $centerCanonicalMunicipality['region_code']"
                        field-class="min-h-11 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/10"
                        label-class="grid min-w-0 gap-1.5 text-xs font-semibold text-ink"
                        wrapper-class="delivery-area-address grid min-w-0 gap-4 sm:col-span-2 sm:grid-cols-2"
                    />
                    <label class="grid min-w-0 gap-1.5 text-xs font-semibold text-ink sm:col-span-2">Assign active local rider
                        <select name="rider_profile_id" class="min-h-11 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm font-normal text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/10">
                            <option value="">No rider yet</option>
                            @foreach ($riders as $rider)
                                <option value="{{ $rider->id }}" @selected((string) old('rider_profile_id') === (string) $rider->id)>
                                    {{ $rider->user?->name }} - {{ ucfirst(str_replace('_', ' ', $rider->vehicle_type)) }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex justify-end border-t border-line pt-4 sm:col-span-2">
                        <button class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/25 sm:w-auto">Save Area Assignment</button>
                    </div>
                </div>
            </form>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Configure this center with a resolvable province and municipality before adding barangay coverage.
            </div>
        @endif

        <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-ink">Uncovered barangays</h2>
                    <p class="mt-1 text-xs text-muted">Barangays in this municipality without an active service area.</p>
                </div>
                <span class="rounded-full border border-line bg-page px-3 py-1.5 text-xs font-bold text-primary">{{ $uncoveredBarangays->count() }} uncovered</span>
            </div>
            @if ($uncoveredBarangays->isNotEmpty())
                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($uncoveredBarangays as $barangay)
                        <div class="rounded-xl border border-line bg-page px-4 py-3.5 transition hover:border-primary/40">
                            <strong class="block text-sm font-semibold text-ink">{{ $barangay['name'] }}</strong>
                            <span class="mt-1 block text-[11px] text-muted">PSGC {{ $barangay['code'] }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-5 rounded-xl border border-dashed border-line bg-page p-5 text-sm text-muted">No uncovered barangays were returned by the PSGC address source.</p>
            @endif
        </section>

        @if ($invalidAreaReasons->isNotEmpty())
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 sm:p-6">
                <h2 class="font-semibold">Existing records requiring review</h2>
                <p class="mt-1">These records were preserved. They cannot receive new local rider assignments until their PSGC coverage is reviewed.</p>
                <ul class="mt-3 list-disc space-y-1 pl-5">
                    @foreach ($invalidAreaReasons as $areaId => $reason)
                        <li>{{ $areas->firstWhere('id', $areaId)?->name ?? 'Service area #'.$areaId }}: {{ $reason }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="space-y-4">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Assignments</p>
                <h2 class="mt-1 text-lg font-bold text-ink">Service areas</h2>
            </div>
            @forelse ($areas as $area)
                @php
                    $isAssignable = in_array((int) $area->id, $assignableAreaIds, true);
                    $activeRiderNames = $area->riderAssignments
                        ->where('is_active', true)
                        ->pluck('riderProfile.user.name')
                        ->filter()
                        ->join(', ');
                @endphp
                <article class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <strong class="text-sm font-bold text-ink">{{ $area->name }}</strong>
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $area->is_active ? 'bg-green-100 text-green-800' : 'bg-stone-100 text-stone-700' }}">
                                    {{ $area->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <p class="mt-3 space-y-1 text-xs leading-5 text-muted">
                                @forelse ($area->locations as $location)
                                    <span class="block">{{ collect([$location->barangay_name, $location->municipality_name, $location->province_name])->filter()->implode(', ') }} <span class="text-xs">(PSGC {{ $location->barangay_code }})</span></span>
                                @empty
                                    No barangay location saved.
                                @endforelse
                            </p>
                            <p class="mt-3 inline-flex rounded-lg bg-page px-3 py-2 text-xs font-medium text-ink">Riders: {{ $activeRiderNames ?: 'None assigned' }}</p>
                            @if ($area->is_active && ! $isAssignable)
                                <p class="mt-2 text-xs text-amber-700">Not assignable: this area is not exactly one barangay in the center's canonical municipality.</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('logistics.delivery-areas.toggle', $area) }}" data-confirm-title="{{ $area->is_active ? 'Deactivate delivery area?' : 'Activate delivery area?' }}" data-confirm-message="{{ $area->is_active ? 'Riders will no longer receive new assignments for this delivery area.' : 'This delivery area will become available for new assignments.' }}" data-confirm-label="{{ $area->is_active ? 'Deactivate' : 'Activate' }}" @if($area->is_active) data-confirm-tone="danger" @endif>
                            @csrf
                            @method('PATCH')
                            <button class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink transition hover:border-primary hover:text-primary">{{ $area->is_active ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-line bg-surface p-8 text-center text-sm text-muted">No delivery areas configured.</div>
            @endforelse
        </section>
    </div>
@endsection
