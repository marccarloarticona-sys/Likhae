@extends('Logistics.app')

@section('title', 'Logistics Operations Dashboard - LIKHAE')

@php
    $dateQuery = array_filter([
        'from_date' => $filters['from_date'] ?? null,
        'to_date' => $filters['to_date'] ?? null,
    ]);
    $cards = [
        'pending' => ['label' => 'Pending', 'detail' => 'Ready for pickup', 'tone' => 'text-amber-700 bg-amber-50', 'icon' => 'M4 7h16v13H4z M8 7V4h8v3 M8 12h8'],
        'in_transit' => ['label' => 'In Transit', 'detail' => 'Moving through logistics', 'tone' => 'text-primary bg-primary-soft', 'icon' => 'M3 6h11v11H3z M14 10h4l3 3v4h-7z M7 19h.01 M18 19h.01'],
        'delivered' => ['label' => 'Delivered', 'detail' => 'Delivered or buyer confirmed', 'tone' => 'text-emerald-700 bg-emerald-50', 'icon' => 'M4 12l5 5L20 6'],
        'failed' => ['label' => 'Failed', 'detail' => 'Failed delivery or returned', 'tone' => 'text-red-700 bg-red-50', 'icon' => 'M5 4v17 M5 4h11l-2 4 2 4H5'],
    ];
    $totalCategorized = array_sum($counts);
    $operations = [
        'incoming' => ['Incoming parcels', 'Local pickups or incoming transfer in motion'],
        'outgoing' => ['Outgoing parcels', 'Ready, released, or moving on a transfer leg'],
        'sorting' => ['Awaiting sorting', 'At sorting center'],
        'rider_assignment' => ['Awaiting rider assignment', 'Sorted without an active delivery assignment'],
        'pickup' => ['Active pickups', 'Pickup rider assignment in progress'],
        'last_mile' => ['Active last-mile', 'Assigned or out for delivery'],
        'manifests' => ['Active inter-hub manifests', 'Separate manifest records'],
    ];
@endphp

@section('content')
<div class="flex min-w-0 flex-col gap-6">
    <header class="rounded-xl border border-line bg-surface p-5 shadow-sm sm:p-6">
        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Control center</span>
                <h1 class="mt-2 font-display text-2xl font-semibold tracking-tight text-ink sm:text-3xl">Logistics Operations Dashboard</h1>
                <p class="mt-2 text-sm text-muted">Monitor parcels, delivery progress, and logistics performance.</p>
                <p class="mt-1 text-xs font-semibold text-primary">{{ $center->business_name }}</p>
            </div>
            <a href="{{ route('logistics.dashboard', $dateQuery) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-line px-4 text-xs font-semibold text-ink hover:bg-page-secondary focus-visible:outline-2 focus-visible:outline-primary">Refresh Statistics</a>
        </div>
        <form action="{{ route('logistics.dashboard') }}" method="GET" class="mt-5 grid gap-3 border-t border-line pt-5 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto_auto_auto] xl:items-end" aria-label="Shipment creation date filter">
            <label class="text-xs font-semibold text-ink">From Date
                <input type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" class="mt-1 block min-h-10 w-full rounded-lg border border-line bg-page-secondary px-3 text-sm text-ink focus:border-primary">
            </label>
            <label class="text-xs font-semibold text-ink">To Date
                <input type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" class="mt-1 block min-h-10 w-full rounded-lg border border-line bg-page-secondary px-3 text-sm text-ink focus:border-primary">
            </label>
            <button type="submit" class="min-h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white hover:opacity-90">Apply Filter</button>
            <a href="{{ route('logistics.dashboard') }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-line px-4 text-xs font-semibold text-ink">Clear Filter</a>
            <a href="{{ route('logistics.dashboard', ['from_date' => now()->toDateString(), 'to_date' => now()->toDateString()]) }}" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-line px-4 text-xs font-semibold text-ink">Today</a>
        </form>
        <p class="mt-2 text-xs text-muted">Dates filter parcel creation in {{ config('app.timezone') }}. Both selected dates are included.</p>
        @if($errors->any())
            <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800" role="alert">{{ $errors->first() }}</div>
        @endif
    </header>

    <section aria-labelledby="shipment-stats-heading">
        <div class="mb-3 flex items-baseline justify-between gap-3">
            <h2 id="shipment-stats-heading" class="text-lg font-semibold text-ink">Shipment Statistics</h2>
            <span class="text-xs text-muted">{{ number_format($totalCategorized) }} parcels in these four categories</span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach($cards as $key => $card)
                <a href="{{ route('logistics.parcels', ['status' => $key, ...$dateQuery]) }}" class="flex min-h-32 gap-3 rounded-xl border border-line bg-surface p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-primary focus-visible:outline-2 focus-visible:outline-primary" aria-label="View {{ $card['label'] }} parcels">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg {{ $card['tone'] }}">
                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current stroke-[1.7] stroke-linecap-round stroke-linejoin-round" aria-hidden="true"><path d="{{ $card['icon'] }}"/></svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xs font-semibold text-muted">{{ $card['label'] }}</span>
                        <strong class="mt-1 block text-3xl leading-none text-ink" data-kpi="{{ $key }}">{{ number_format($counts[$key]) }}</strong>
                        <span class="mt-2 block text-[11px] text-muted">{{ $card['detail'] }}</span>
                        <span class="mt-2 block text-[11px] font-semibold text-primary">{{ $totalCategorized ? round($counts[$key] / $totalCategorized * 100, 1) : 0 }}% of categorized parcels →</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(300px,0.75fr)]">
        <section class="rounded-xl border border-line bg-surface shadow-sm" aria-labelledby="status-overview-heading">
            <div class="border-b border-line px-5 py-4">
                <span class="text-[10px] font-bold uppercase tracking-[0.16em] text-primary">Performance</span>
                <h2 id="status-overview-heading" class="mt-1 text-base font-semibold text-ink">Shipment Status Overview</h2>
                <p class="mt-1 text-xs text-muted">Current parcel status, using the same counts as the cards.</p>
            </div>
            <div class="space-y-5 p-5">
                @forelse($cards as $key => $card)
                    <div role="img" aria-label="{{ $card['label'] }}: {{ $counts[$key] }} parcels">
                        <div class="mb-1 flex justify-between text-xs"><span class="font-semibold text-ink">{{ $card['label'] }}</span><span class="text-muted">{{ number_format($counts[$key]) }}</span></div>
                        <div class="h-3 overflow-hidden rounded-full bg-page-secondary"><div class="h-full rounded-full bg-primary" style="width: {{ $totalCategorized ? round($counts[$key] / $totalCategorized * 100, 2) : 0 }}%"></div></div>
                    </div>
                @empty
                    <p class="text-sm text-muted">No parcel statuses in this range.</p>
                @endforelse
                @if(!$totalCategorized)<p class="text-xs text-muted">No categorized parcels were created in the selected range.</p>@endif
            </div>
        </section>

        <section class="rounded-xl border border-line bg-surface shadow-sm" aria-labelledby="operations-heading">
            <div class="border-b border-line px-5 py-4">
                <span class="text-[10px] font-bold uppercase tracking-[0.16em] text-primary">Operations</span>
                <h2 id="operations-heading" class="mt-1 text-base font-semibold text-ink">Logistics Operational Overview</h2>
                <p class="mt-1 text-xs text-muted">These work queues can overlap. Manifest counts are separate from parcels.</p>
            </div>
            <div class="grid gap-2 p-5 sm:grid-cols-2 xl:grid-cols-1">
                @foreach($operations as $key => [$label, $detail])
                    <div class="flex items-center justify-between gap-3 rounded-lg bg-page-secondary px-4 py-3">
                        <div><span class="block text-xs font-semibold text-ink">{{ $label }}</span><span class="block text-[10px] text-muted">{{ $detail }}</span></div>
                        <strong class="text-lg text-primary">{{ number_format($operational[$key]) }}</strong>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <section class="rounded-xl border border-line bg-surface shadow-sm" aria-labelledby="activity-heading">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
            <div><span class="text-[10px] font-bold uppercase tracking-[0.16em] text-primary">Parcel history</span><h2 id="activity-heading" class="mt-1 text-base font-semibold text-ink">Recent Shipment Activity</h2></div>
            <a href="{{ route('logistics.parcels', $dateQuery) }}" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink">View All</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-xs">
                <thead class="bg-page-secondary text-[10px] uppercase tracking-wide text-muted"><tr><th class="px-5 py-3">Tracking</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Origin / Destination</th><th class="px-5 py-3">Last event</th><th class="px-5 py-3">Rider</th><th class="px-5 py-3"></th></tr></thead>
                <tbody class="divide-y divide-line">
                    @forelse($recentShipments as $shipment)
                        <tr>
                            <td class="px-5 py-3 font-semibold text-ink">{{ $shipment->tracking_number }}</td>
                            <td class="px-5 py-3">{{ str($shipment->current_status)->headline() }}</td>
                            <td class="px-5 py-3 text-muted">{{ $shipment->originLogisticsCenter?->business_name ?? '—' }} / {{ $shipment->destinationLogisticsCenter?->business_name ?? '—' }}</td>
                            <td class="px-5 py-3 text-muted">{{ $shipment->latestEvent?->occurred_at?->format('M d, Y g:i A') ?? 'No event yet' }}</td>
                            <td class="px-5 py-3 text-muted">{{ $shipment->latestRiderAssignment?->riderProfile?->user?->name ?? 'Unassigned' }}</td>
                            <td class="px-5 py-3 text-right"><a href="{{ route('logistics.parcels.show', $shipment) }}" class="font-semibold text-primary underline-offset-2 hover:underline">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-center text-muted">No authorized parcels were created in the selected range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5 shadow-sm" aria-labelledby="quick-actions-heading">
        <h2 id="quick-actions-heading" class="text-base font-semibold text-ink">Quick Actions</h2>
        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            @foreach([
                ['Incoming Parcels', 'logistics.parcels.receive'],
                ['Parcel Sorting', 'logistics.sorting'],
                ['Rider Management', 'logistics.riders'],
                ['Pickup Management', 'logistics.pickups'],
                ['Inter-hub Transfers', 'logistics.transfers'],
                ['Delivery Monitoring', 'logistics.parcels.tracking'],
                ['Reports', 'logistics.reports'],
            ] as [$label, $route])
                <a href="{{ route($route) }}" class="rounded-lg border border-line bg-page-secondary px-4 py-3 text-xs font-semibold text-ink hover:border-primary focus-visible:outline-2 focus-visible:outline-primary">{{ $label }} →</a>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5 shadow-sm" aria-labelledby="finance-heading">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="finance-heading" class="text-base font-semibold text-ink">COD and earnings reconciliation</h2><p class="mt-1 text-xs text-muted">All-time finance totals; separate from the shipment creation-date filter.</p></div><a href="{{ route('logistics.remittances') }}" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink">Review remittances</a></div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach([['Rider COD obligations', 'rider_cod_obligations'], ['Submitted cash', 'remittance_submitted'], ['Verified cash', 'verified_cash'], ['Discrepancies', 'discrepancies'], ['Logistics pool', 'logistics_pool'], ['Pending settlement', 'pending_settlement']] as [$label, $key])
                <div class="rounded-lg bg-page-secondary p-3"><span class="block text-[11px] text-muted">{{ $label }}</span><strong class="mt-1 block text-sm text-ink">{{ $key === 'discrepancies' ? number_format((int) $financeSummary[$key]) : 'PHP '.number_format((float) $financeSummary[$key], 2) }}</strong></div>
            @endforeach
        </div>
    </section>
</div>
@endsection
