@extends('Logistics.app')

@section('title', 'Rider Profile - LIKHAE Logistics')

@php
    $name = $rider->user?->name ?: 'Rider Account';
    $activeAreas = $rider->areaAssignments
        ->where('is_active', true)
        ->pluck('serviceArea.name')
        ->filter()
        ->values();
    $activeAreaId = $rider->areaAssignments->firstWhere('is_active', true)?->service_area_id;
    $activeAssignments = (int) ($rider->active_assignments_count ?? 0);
    $ratingAverage = (float) ($rider->rating_average ?? 0);
    $ratingCount = (int) ($rider->rating_count ?? 0);
    $manifestSchemaReady = (bool) ($manifestSchemaReady ?? false);
    $initials = collect(explode(' ', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: 'R';

    $details = [
        ['Full Name', $name],
        ['Email', $rider->user?->email ?: 'Not recorded'],
        ['Contact', $rider->user?->contact_number ?: 'Not recorded'],
        ['Vehicle', $rider->vehicle_type ? str($rider->vehicle_type)->headline() : 'Not recorded'],
        ['Plate Number', $rider->plate_number ?: 'Not recorded'],
        ['Delivery Area', $activeAreas->join(', ') ?: 'Unassigned'],
        ['Status', $rider->status ? str($rider->status)->headline() : 'Not recorded'],
        ['Active Deliveries', $activeAssignments],
        ['Active Pickup Parcels', (int) ($rider->active_pickup_assignments_count ?? 0).' / 10'],
    ];
@endphp

@section('content')
<div class="flex w-full flex-col gap-6">
    <nav class="flex flex-wrap items-center gap-2 text-[10px] text-muted">
        <a href="{{ route('logistics.dashboard') }}" class="transition hover:text-primary">Dashboard</a>
        <span>/</span>
        <a href="{{ route('logistics.riders') }}" class="transition hover:text-primary">Riders</a>
        <span>/</span>
        <span class="font-semibold text-ink">{{ $name }}</span>
    </nav>

    @if(session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-[10px] text-green-800">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[10px] text-red-700">{{ $errors->first() }}</div>
    @endif

    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="flex flex-col gap-5 md:flex-row md:items-center">
            <div class="grid h-20 w-20 place-items-center rounded-full bg-primary-soft text-primary">
                <span class="text-[20px] font-bold">{{ $initials }}</span>
            </div>

            <div>
                <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Rider Management</span>
                <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink">{{ $name }}</h1>
                <p class="mt-1 text-[10px] text-muted">Rider ID: RID-{{ str_pad((string) data_get($rider, 'id', 0), 4, '0', STR_PAD_LEFT) }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <div>
            <h2 class="text-[13px] font-semibold text-ink">Approved Transfer Capacity</h2>
            <p class="mt-1 text-[10px] leading-5 text-muted">Set approved parcel, weight, and volume limits. Parcel count is capped by vehicle: Van 50, Closed Van 75, Truck 100. Missing values block sealing.</p>
        </div>
        @if($manifestSchemaReady)
            <form method="POST" action="{{ route('logistics.riders.capacity.update', $rider) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                @csrf
                @method('PATCH')
                <label class="text-[10px] font-semibold text-ink">Parcels (max {{ \\App\\Support\\RiderVehicleType::interLogisticsParcelLimit($rider->vehicle_type) }})<input type="number" min="1" max="{{ \\App\\Support\\RiderVehicleType::interLogisticsParcelLimit($rider->vehicle_type) }}" name="approved_parcel_capacity" value="{{ old('approved_parcel_capacity', $rider->approved_parcel_capacity) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal"></label>
                <label class="text-[10px] font-semibold text-ink">Weight kg<input type="number" step="0.001" min="0.001" name="approved_weight_capacity_kg" value="{{ old('approved_weight_capacity_kg', $rider->approved_weight_capacity_kg) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal"></label>
                <label class="text-[10px] font-semibold text-ink">Volume liters<input type="number" step="0.001" min="0.001" name="approved_volume_capacity_liters" value="{{ old('approved_volume_capacity_liters', $rider->approved_volume_capacity_liters) }}" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal"></label>
                <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white sm:col-span-3 sm:justify-self-start">Save Capacity</button>
            </form>
        @else
            <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[10px] text-amber-800">Transfer schema is not installed. Cross-hub manifest operations remain disabled.</p>
        @endif
    </section>

    <x-shared.mapbox :markers="$mapMarkers" title="Rider operations map" height="220px" user-location-target="center" />

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Rider Information</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($details as $item)
                <div class="rounded-lg bg-page-secondary p-4">
                    <span class="text-[8px] uppercase text-muted">{{ $item[0] }}</span>
                    <strong class="mt-2 block break-words text-[10px] font-semibold text-ink">{{ $item[1] }}</strong>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Rider Status and Discipline</h2>
        <p class="mt-1 text-[10px] leading-5 text-muted">Warned riders may continue working. Suspended and banned riders lose access and cannot receive new assignments. Add a reason for warnings and restrictions.</p>
        <form method="POST" action="{{ route('logistics.riders.moderation.update', $rider) }}" class="mt-4 grid gap-3 sm:grid-cols-[180px_minmax(0,1fr)_auto] sm:items-end">
            @csrf
            @method('PATCH')
            <label class="text-[10px] font-semibold text-ink">Status
                <select name="status" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-3 text-[10px] font-normal">
                    @foreach(['ACTIVE' => 'Active', 'WARNED' => 'Warned', 'SUSPENDED' => 'Suspended', 'BANNED' => 'Banned'] as $value => $label)
                        <option value="{{ $value }}" @selected($rider->status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-[10px] font-semibold text-ink">Reason
                <textarea name="reason" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-[10px] font-normal" placeholder="Required for warn, suspend, or ban"></textarea>
            </label>
            <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Save Status</button>
        </form>
        <div class="mt-5 border-t border-line pt-4">
            <h3 class="text-[11px] font-semibold text-ink">Moderation History</h3>
            @forelse($rider->moderationLogs as $log)
                <div class="mt-3 flex flex-col gap-1 border-b border-line pb-3 text-[9px] last:border-0">
                    <span class="font-semibold text-ink">{{ str(str_replace('rider.', '', $log->event))->headline() }} · {{ $log->created_at?->format('M j, Y g:i A') }}</span>
                    <span class="text-muted">By {{ $log->actor?->name ?? 'Former staff member' }}{{ data_get($log->new_values, 'reason') ? ' — '.data_get($log->new_values, 'reason') : '' }}</span>
                </div>
            @empty
                <p class="mt-2 text-[9px] text-muted">No moderation actions recorded.</p>
            @endforelse
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-[13px] font-semibold text-ink">Delivery Area Assignment</h2>
                <p class="mt-1 text-[10px] leading-5 text-muted">Choose the active destination coverage for this rider. Reassigning ends the previous active area assignment.</p>
            </div>
            <span class="rounded-full bg-primary-soft px-3 py-1 text-[8px] font-semibold text-primary">{{ $activeAreas->join(', ') ?: 'Unassigned' }}</span>
        </div>

        <form method="POST" action="{{ route('logistics.riders.area.update', $rider) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            @csrf
            @method('PATCH')
            <label class="flex-1 text-[10px] font-semibold text-ink">
                Delivery area
                <select name="service_area_id" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-3 text-[10px] font-normal" aria-label="Delivery area">
                    <option value="">Unassigned</option>
                    @foreach($serviceAreas as $area)
                        @php($areaLocation = $area->locations->map(fn ($location) => collect([$location->barangay_name, $location->municipality_name, $location->province_name])->filter()->implode(', '))->join('; '))
                        <option value="{{ $area->id }}" @selected((int) $activeAreaId === (int) $area->id)>{{ $area->name }}{{ $areaLocation ? ' — '.$areaLocation : '' }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Save Assignment</button>
        </form>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Buyer Ratings</h2>
        <div class="mt-3 flex items-baseline gap-2">
            <strong class="text-2xl font-semibold text-ink">{{ number_format($ratingAverage, 1) }}</strong>
            <span class="text-xs text-muted">/ 5</span>
            <span class="text-sm text-yellow-500" aria-label="{{ number_format($ratingAverage, 1) }} out of 5 stars">{{ str_repeat('★', (int) round($ratingAverage)) }}<span class="text-stone-300">{{ str_repeat('★', 5 - (int) round($ratingAverage)) }}</span></span>
            <span class="text-xs text-muted">({{ $ratingCount }} {{ \Illuminate\Support\Str::plural('rating', $ratingCount) }})</span>
        </div>
    </section>

</div>
@endsection
