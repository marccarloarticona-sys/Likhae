@extends('layouts.logistics.app')

@section('title', 'Logistics Analytics & Reports - LIKHAE')

@section('content')
<div class="space-y-6 pb-8">
    <header class="relative overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
        <div class="absolute -right-10 -top-20 h-64 w-64 rounded-full bg-primary/10 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-col gap-5 p-6 sm:p-8 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <span class="inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/5 px-3 py-1 text-xs font-bold uppercase tracking-wider text-primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary"></span> Logistics workspace
                </span>
                <h1 class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl">Analytics &amp; Reports</h1>
                <p class="mt-2 text-sm text-muted sm:text-base">A clear view of parcel movement and saved transactions for <strong class="text-ink">{{ $center->business_name }}</strong>.</p>
            </div>
            <div class="rounded-xl border border-line bg-page/70 px-4 py-3 text-sm">
                <span class="block text-xs font-semibold uppercase tracking-wide text-muted">Your logistics center</span>
                <strong class="mt-1 block">{{ $center->business_name }}</strong>
                <span class="mt-1 block text-xs text-muted">Private report data for this account</span>
            </div>
        </div>
    </header>

    @php
        $analyticsTabUrl = route('logistics.reports', [
            'tab' => 'analytics',
            'analytics_period' => $analyticsFilters['period'],
            'analytics_from' => $analyticsFilters['from_date'],
            'analytics_to' => $analyticsFilters['to_date'],
            'analytics_rider_id' => $analyticsFilters['rider_id'],
            'analytics_area_id' => $analyticsFilters['area_id'],
        ]);
        $reportsTabUrl = route('logistics.reports', array_merge($financialFilters, [
            'tab' => 'reports',
            'shipment_status' => $shipmentStatus,
        ]));
    @endphp
    <nav class="flex gap-1 rounded-xl border border-line bg-surface-muted p-1.5" aria-label="Logistics reports sections">
        <a href="{{ $analyticsTabUrl }}" @class([
            'inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition',
            'bg-surface text-primary shadow-sm' => $activeTab === 'analytics',
            'text-muted hover:bg-surface/70 hover:text-ink' => $activeTab !== 'analytics',
        ]) @if($activeTab === 'analytics') aria-current="page" @endif>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 19V5m0 14h16M7 15l4-4 3 2 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Analytics
        </a>
        <a href="{{ $reportsTabUrl }}" @class([
            'inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition',
            'bg-surface text-primary shadow-sm' => $activeTab === 'reports',
            'text-muted hover:bg-surface/70 hover:text-ink' => $activeTab !== 'reports',
        ]) @if($activeTab === 'reports') aria-current="page" @endif>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke-linejoin="round"/><path d="M14 3v6h6M9 13h6M9 17h6" stroke-linecap="round"/></svg>
            Reports
        </a>
    </nav>

    @if($activeTab === 'analytics')
        <section class="space-y-5" aria-label="Logistics analytics">
            <form method="GET" action="{{ route('logistics.reports') }}" class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
                <input type="hidden" name="tab" value="analytics">
                <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-primary">Operational snapshot</span>
                        <h2 class="mt-1 text-lg font-bold">Filter your analytics</h2>
                    </div>
                    <p class="text-xs text-muted">Parcel counts use parcel creation date and current status. Editing dates selects a custom range.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <label class="grid gap-1.5 text-xs font-semibold text-muted">Time frame
                        <select id="analytics-period" name="analytics_period" class="min-h-11 rounded-lg border border-line bg-page px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="7d" @selected($analyticsFilters['period'] === '7d')>Last 7 days</option>
                            <option value="30d" @selected($analyticsFilters['period'] === '30d')>Last 30 days</option>
                            <option value="90d" @selected($analyticsFilters['period'] === '90d')>Last 90 days</option>
                            <option value="custom" @selected($analyticsFilters['period'] === 'custom')>Custom range</option>
                        </select>
                    </label>
                    <label class="grid gap-1.5 text-xs font-semibold text-muted">From date
                        <input type="date" name="analytics_from" value="{{ $analyticsFilters['from_date'] }}" data-analytics-custom-date class="min-h-11 rounded-lg border border-line bg-page px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    </label>
                    <label class="grid gap-1.5 text-xs font-semibold text-muted">To date
                        <input type="date" name="analytics_to" value="{{ $analyticsFilters['to_date'] }}" data-analytics-custom-date class="min-h-11 rounded-lg border border-line bg-page px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                    </label>
                    <label class="grid gap-1.5 text-xs font-semibold text-muted">Delivery rider
                        <select name="analytics_rider_id" class="min-h-11 rounded-lg border border-line bg-page px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">All riders</option>
                            @foreach($financialRiders as $rider)
                                <option value="{{ $rider->id }}" @selected((string) ($analyticsFilters['rider_id'] ?? '') === (string) $rider->id)>{{ $rider->user?->name ?? 'Rider '.$rider->id }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1.5 text-xs font-semibold text-muted">Service area
                        <select name="analytics_area_id" class="min-h-11 rounded-lg border border-line bg-page px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            <option value="">All areas</option>
                            @foreach($financialAreas as $area)
                                <option value="{{ $area->id }}" @selected((string) ($analyticsFilters['area_id'] ?? '') === (string) $area->id)>{{ $area->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-muted">Showing <strong class="text-ink">{{ \Illuminate\Support\Carbon::parse($analyticsFilters['from_date'])->format('M d, Y') }}</strong> through <strong class="text-ink">{{ \Illuminate\Support\Carbon::parse($analyticsFilters['to_date'])->format('M d, Y') }}</strong>.</p>
                    <div class="flex gap-2">
                        <a href="{{ route('logistics.reports', ['tab' => 'analytics']) }}" class="inline-flex min-h-10 items-center rounded-lg border border-line px-4 text-sm font-semibold hover:bg-page">Reset</a>
                        <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-primary px-5 text-sm font-semibold text-white shadow-sm hover:opacity-90">
                            Apply filters
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                </div>
            </form>

            @php
                $metricCards = [
                    ['label' => 'Parcels in range', 'value' => $analyticsMetrics['total'], 'note' => 'Visible to this center', 'tone' => 'border-l-primary', 'icon' => 'M4 7h16M4 12h16M4 17h10'],
                    ['label' => 'Ready for pickup', 'value' => $analyticsMetrics['pending'], 'note' => 'Awaiting pickup', 'tone' => 'border-l-amber-500', 'icon' => 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
                    ['label' => 'In transit', 'value' => $analyticsMetrics['in_transit'], 'note' => 'Moving through delivery', 'tone' => 'border-l-sky-500', 'icon' => 'M3 12h13m0 0-4-4m4 4-4 4m4-8h3l2 4v4h-5'],
                    ['label' => 'Delivered', 'value' => $analyticsMetrics['delivered'], 'note' => number_format($analyticsMetrics['success_rate'], 1).'% of delivered / failed outcomes', 'tone' => 'border-l-emerald-500', 'icon' => 'm5 12 4 4L19 6'],
                    ['label' => 'Exceptions', 'value' => $analyticsMetrics['exceptions'], 'note' => 'Failed, returned, lost, damaged or cancelled', 'tone' => 'border-l-rose-500', 'icon' => 'M12 9v4m0 4h.01M10 3h4l7 17H3L10 3Z'],
                    ['label' => 'Registered riders', 'value' => $analyticsMetrics['riders'], 'note' => 'Riders assigned to this center', 'tone' => 'border-l-violet-500', 'icon' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m6-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-7.8a4 4 0 0 1 0 7.6m2 4.2a4 4 0 0 1 2 3.8V21'],
                ];
            @endphp
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                @foreach($metricCards as $card)
                    <article class="rounded-xl border border-line border-l-4 {{ $card['tone'] }} bg-surface p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $card['label'] }}</span>
                            <span class="rounded-lg bg-page p-2 text-primary"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $card['icon'] }}"/></svg></span>
                        </div>
                        <strong class="mt-3 block text-3xl tracking-tight">{{ number_format($card['value']) }}</strong>
                        <span class="mt-1 block text-xs text-muted">{{ $card['note'] }}</span>
                    </article>
                @endforeach
            </div>

            <div class="grid gap-5 xl:grid-cols-5">
                <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6 xl:col-span-3" aria-labelledby="shipment-trend-title">
                    <header class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-primary">Shipment volume</span>
                            <h2 id="shipment-trend-title" class="mt-1 text-xl font-bold">Parcels created over time</h2>
                            <p class="mt-1 text-sm text-muted">{{ ucfirst($analyticsGranularity) }} totals for the selected period</p>
                        </div>
                        <div class="rounded-lg bg-primary/5 px-3 py-2 text-right">
                            <span class="block text-[10px] font-bold uppercase tracking-wide text-muted">Peak bucket</span>
                            <strong class="text-lg text-primary">{{ number_format($analyticsChartMax) }}</strong>
                        </div>
                    </header>
                    <div class="mt-5 overflow-hidden rounded-xl bg-page/60 p-2 sm:p-4">
                        <svg class="h-56 w-full overflow-visible text-primary" viewBox="0 0 900 220" role="img" aria-label="Shipment creation trend">
                            <defs><linearGradient id="logisticsTrendFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="currentColor" stop-opacity=".20"/><stop offset="100%" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>
                            <g stroke="currentColor" stroke-opacity=".12" stroke-dasharray="4 6"><path d="M34 38H866"/><path d="M34 106H866"/><path d="M34 174H866"/></g>
                            <path d="{{ $analyticsChartArea }}" fill="url(#logisticsTrendFill)" class="text-primary"/>
                            <path d="{{ $analyticsChartLine }}" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-primary"/>
                            @foreach($analyticsChartPoints as $point)
                                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.5" fill="currentColor" class="text-primary">
                                    <title>{{ $point['label'] }}: {{ $point['total'] }} parcels</title>
                                </circle>
                            @endforeach
                            @php($chartCount = count($analyticsChartPoints))
                            @foreach($analyticsChartPoints as $index => $point)
                                @if($index === 0 || $index === $chartCount - 1 || $index % max(1, (int) floor($chartCount / 4)) === 0)
                                    <text x="{{ $point['x'] }}" y="207" text-anchor="middle" fill="currentColor" font-size="12" class="text-muted">{{ $point['label'] }}</text>
                                @endif
                            @endforeach
                        </svg>
                        @if($analyticsMetrics['total'] === 0)
                            <p class="-mt-2 pb-2 text-center text-sm text-muted">No parcels were created in this date range.</p>
                        @endif
                    </div>
                </section>

                <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6 xl:col-span-2" aria-labelledby="status-breakdown-title">
                    <header>
                        <span class="text-xs font-bold uppercase tracking-wider text-primary">Current workload</span>
                        <h2 id="status-breakdown-title" class="mt-1 text-xl font-bold">Status breakdown</h2>
                        <p class="mt-1 text-sm text-muted">Current status of parcels created within this date range.</p>
                    </header>
                    <div class="mt-5 space-y-4">
                        @forelse($analyticsStatusBreakdown as $status)
                            @php($barWidth = $analyticsMetrics['total'] > 0 ? min(100, ($status['total'] / $analyticsMetrics['total']) * 100) : 0)
                            <div>
                                <div class="mb-1.5 flex items-center justify-between gap-3 text-sm">
                                    <span class="font-medium">{{ $status['label'] }}</span>
                                    <span class="font-bold tabular-nums">{{ number_format($status['total']) }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-page"><div class="h-full rounded-full bg-primary" style="width: {{ $barWidth }}%"></div></div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-line px-4 py-10 text-center text-sm text-muted">No parcels match the selected filters.</div>
                        @endforelse
                    </div>
                    <div class="mt-5 rounded-xl bg-page/70 p-4">
                        <div class="flex items-center justify-between text-sm"><span class="text-muted">Delivery success rate <span class="text-xs">(delivered ÷ delivered and failed)</span></span><strong>{{ number_format($analyticsMetrics['success_rate'], 1) }}%</strong></div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface"><div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, $analyticsMetrics['success_rate']) }}%"></div></div>
                    </div>
                </section>
            </div>

            @if($financeReady)
                <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="financial-snapshot-title">
                    <header class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-primary">Saved financial activity</span>
                            <h2 id="financial-snapshot-title" class="mt-1 text-xl font-bold">Financial snapshot</h2>
                        </div>
                        <a href="{{ $reportsTabUrl }}" class="text-sm font-semibold text-primary hover:underline">Open filtered reports <span aria-hidden="true">→</span></a>
                    </header>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article class="rounded-xl bg-page/70 p-4"><span class="text-xs font-semibold uppercase tracking-wide text-muted">Delivery charges</span><strong class="mt-2 block text-xl">PHP {{ number_format($analyticsFinancialSummary['delivery_charges'], 2) }}</strong><span class="mt-1 block text-xs text-muted">Shipping plus Additional Care</span></article>
                        <article class="rounded-xl bg-page/70 p-4"><span class="text-xs font-semibold uppercase tracking-wide text-muted">Logistics share</span><strong class="mt-2 block text-xl">PHP {{ number_format($analyticsFinancialSummary['logistics_share'], 2) }}</strong><span class="mt-1 block text-xs text-muted">Persisted center allocation</span></article>
                        <article class="rounded-xl bg-page/70 p-4"><span class="text-xs font-semibold uppercase tracking-wide text-muted">Product proceeds received</span><strong class="mt-2 block text-xl">PHP {{ number_format($analyticsFinancialSummary['proceeds_received'], 2) }}</strong><span class="mt-1 block text-xs text-muted">Recorded receipts in range</span></article>
                        <article class="rounded-xl bg-page/70 p-4"><span class="text-xs font-semibold uppercase tracking-wide text-muted">Payouts awaiting acknowledgment</span><strong class="mt-2 block text-xl">PHP {{ number_format($analyticsFinancialSummary['payout_pending_ack'], 2) }}</strong><span class="mt-1 block text-xs text-muted">Confirmed paid: PHP {{ number_format($analyticsFinancialSummary['payout_paid'], 2) }}</span></article>
                    </div>
                    <p class="mt-3 text-xs text-muted">Financial amounts use saved ledger entries filtered to this logistics center. Admin product commission, when present, belongs to Admin and is not a Logistics commission.</p>
                </section>
            @endif
        </section>
    @else
        <section class="space-y-5" aria-label="Downloadable logistics reports">
            <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
                <header class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-primary">Ready-to-share reports</span>
                        <h2 class="mt-1 text-xl font-bold">Choose your data and time frame</h2>
                        <p class="mt-1 text-sm text-muted">Filter the shipment PDF and financial exports before you download a copy. The shipment PDF uses parcel creation date; financial exports use transaction date.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('logistics.reports.shipments.csv', $shipmentExportFilters) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-line px-4 text-sm font-semibold hover:bg-page">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v4h14v-4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            Shipment CSV
                        </a>
                        <a href="{{ route('logistics.reports.export', $shipmentExportFilters) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white shadow-sm hover:opacity-90">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 17v4h14v-4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            Shipment PDF
                        </a>
                    </div>
                </header>

                <form method="GET" action="{{ route('logistics.reports') }}" class="mt-5 rounded-xl border border-line bg-page/60 p-4 sm:p-5">
                    <input type="hidden" name="tab" value="reports">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        <label class="grid gap-1.5 text-xs font-semibold text-muted">From date
                            <input type="date" name="date_from" value="{{ $financialFilters['date_from'] ?? '' }}" class="min-h-11 rounded-lg border border-line bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                        </label>
                        <label class="grid gap-1.5 text-xs font-semibold text-muted">To date
                            <input type="date" name="date_to" value="{{ $financialFilters['date_to'] ?? '' }}" class="min-h-11 rounded-lg border border-line bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                        </label>
                        <label class="grid gap-1.5 text-xs font-semibold text-muted">Shipment status
                            <select name="shipment_status" class="min-h-11 rounded-lg border border-line bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <option value="">All shipment statuses</option>
                                @foreach($shipmentStatusOptions as $status)
                                    <option value="{{ $status }}" @selected($shipmentStatus === $status)>{{ str($status)->replace('_', ' ')->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if($financeReady)
                            <label class="grid gap-1.5 text-xs font-semibold text-muted">Transaction status
                                <select name="status" class="min-h-11 rounded-lg border border-line bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                    <option value="">All transaction statuses</option>
                                    @foreach($financialStatuses as $status)
                                        <option value="{{ $status }}" @selected(($financialFilters['status'] ?? null) === $status)>{{ str($status)->replace('_', ' ')->headline() }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        <label class="grid gap-1.5 text-xs font-semibold text-muted">Delivery rider
                            <select name="rider_id" class="min-h-11 rounded-lg border border-line bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <option value="">All riders</option>
                                @foreach($financialRiders as $rider)
                                    <option value="{{ $rider->id }}" @selected((string) ($financialFilters['rider_id'] ?? '') === (string) $rider->id)>{{ $rider->user?->name ?? 'Rider '.$rider->id }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grid gap-1.5 text-xs font-semibold text-muted">Service area
                            <select name="area_id" class="min-h-11 rounded-lg border border-line bg-surface px-3 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                                <option value="">All areas</option>
                                @foreach($financialAreas as $area)
                                    <option value="{{ $area->id }}" @selected((string) ($financialFilters['area_id'] ?? '') === (string) $area->id)>{{ $area->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
                        <p class="max-w-2xl text-xs text-muted">The shipment PDF uses shipment status and all matching date, rider, and area filters. Financial CSV/PDF use transaction status with date, rider, and area filters.</p>
                        <div class="flex gap-2">
                            <a href="{{ route('logistics.reports', ['tab' => 'reports']) }}" class="inline-flex min-h-10 items-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:bg-page">Clear</a>
                            <button type="submit" class="inline-flex min-h-10 items-center rounded-lg bg-primary px-5 text-sm font-semibold text-white hover:opacity-90">Apply filters</button>
                        </div>
                    </div>
                </form>
            </section>

            @unless($financeReady)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">Financial exports are unavailable until the reviewed finance migrations are installed. Filtered shipment PDFs remain available.</div>
            @else
                <section class="space-y-5 rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6" aria-labelledby="financial-reports-title">
                    <header class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-primary">Saved parcel transactions</span>
                            <h2 id="financial-reports-title" class="mt-1 text-xl font-bold">Financial report</h2>
                            <p class="mt-1 text-sm text-muted">Transaction totals come from saved parcel allocations and Logistics ledger entries.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('logistics.reports.transactions.csv', $financialFilters) }}" class="inline-flex min-h-10 items-center rounded-lg border border-line px-4 text-sm font-semibold hover:bg-page">Download CSV</a>
                            <a href="{{ route('logistics.reports.transactions.pdf', $financialFilters) }}" class="inline-flex min-h-10 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:opacity-90">Download transactions PDF</a>
                        </div>
                    </header>

                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">No Logistics commission rate is configured in the saved finance rules. “Admin product commission” below is the persisted product commission allocated to Admin; it is not a Logistics commission. The Logistics delivery share is reported separately.</div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Parcels with allocation</span><strong class="mt-2 block text-2xl">{{ number_format($financialSummary['parcel_count']) }}</strong></article>
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Base shipping charges</span><strong class="mt-2 block text-xl">PHP {{ number_format($financialSummary['shipping_fees'], 2) }}</strong></article>
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Additional Care charges</span><strong class="mt-2 block text-xl">PHP {{ number_format($financialSummary['additional_care'], 2) }}</strong></article>
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Combined delivery charges</span><strong class="mt-2 block text-xl">PHP {{ number_format($financialSummary['delivery_charges'], 2) }}</strong></article>
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Logistics delivery share</span><strong class="mt-2 block text-xl">PHP {{ number_format($financialSummary['logistics_share'], 2) }}</strong></article>
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Admin product commission</span><strong class="mt-2 block text-xl">PHP {{ number_format($financialSummary['admin_product_commission'], 2) }}</strong></article>
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Product proceeds received</span><strong class="mt-2 block text-xl">PHP {{ number_format($financialSummary['proceeds_received'], 2) }}</strong><span class="mt-1 block text-xs text-muted">Expected: PHP {{ number_format($financialSummary['proceeds_expected'], 2) }}</span></article>
                        <article class="rounded-xl border border-line bg-page/50 p-4"><span class="text-xs font-semibold text-muted">Payout requested / pending acknowledgment</span><strong class="mt-2 block text-lg">PHP {{ number_format($financialSummary['payout_requested'], 2) }} / {{ number_format($financialSummary['payout_pending_ack'], 2) }}</strong><span class="mt-1 block text-xs text-muted">Paid: PHP {{ number_format($financialSummary['payout_paid'], 2) }}</span></article>
                    </div>

                    @if($financialSummary['proceeds_discrepancies'] > 0)
                        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-950">{{ $financialSummary['proceeds_discrepancies'] }} product-proceeds transaction(s) have an unresolved discrepancy. Review submitted and received values in the table.</p>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="font-bold">Transaction ledger</h3>
                            <p class="mt-1 text-xs text-muted">Each row is a saved ledger movement. Split allocation rows can repeat parcel allocation details; summary totals count each allocation once.</p>
                        </div>
                        <span class="rounded-full bg-page px-3 py-1 text-xs font-semibold text-muted">{{ number_format($financialTransactions->total()) }} matching transactions</span>
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="min-w-full divide-y divide-line text-left text-xs">
                            <thead class="bg-page text-muted">
                                <tr>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Date</th>
                                    <th class="min-w-48 px-3 py-3 font-semibold">Parcel / order</th>
                                    <th class="px-3 py-3 font-semibold">Transaction</th>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Amount / status</th>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Base shipping</th>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Additional Care</th>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Combined delivery</th>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Logistics share</th>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Rider pass-through</th>
                                    <th class="whitespace-nowrap px-3 py-3 font-semibold">Admin commission</th>
                                    <th class="min-w-36 px-3 py-3 font-semibold">Rider / area</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line bg-surface">
                                @forelse($financialTransactions as $transaction)
                                    <tr class="align-top hover:bg-page/50">
                                        <td class="whitespace-nowrap px-3 py-3">{{ $transaction['date'] ?: '—' }}</td>
                                        <td class="px-3 py-3"><strong>{{ $transaction['tracking'] ?: '—' }}</strong><span class="mt-1 block text-muted">{{ $transaction['order'] ?: '—' }}</span><span class="mt-1 block text-muted">{{ $transaction['destination'] ?: '—' }}</span></td>
                                        <td class="px-3 py-3"><span class="font-semibold">{{ str($transaction['transaction'])->replace('_', ' ')->headline() }}</span><span class="mt-1 block text-muted">{{ str($transaction['direction'])->lower() }}</span></td>
                                        <td class="whitespace-nowrap px-3 py-3"><strong>PHP {{ number_format((float) $transaction['amount'], 2) }}</strong><span class="mt-1 block">{{ str($transaction['status'])->replace('_', ' ')->headline() }}</span>
                                            @if($transaction['expected'] !== '' || $transaction['submitted'] !== '' || $transaction['received'] !== '' || $transaction['discrepancy'] !== '')
                                                <span class="mt-1 block min-w-56 whitespace-normal text-muted">Expected {{ $transaction['expected'] !== '' ? 'PHP '.number_format((float) $transaction['expected'], 2) : '—' }} · submitted {{ $transaction['submitted'] !== '' ? 'PHP '.number_format((float) $transaction['submitted'], 2) : '—' }} · received {{ $transaction['received'] !== '' ? 'PHP '.number_format((float) $transaction['received'], 2) : '—' }} · difference {{ $transaction['discrepancy'] !== '' ? 'PHP '.number_format((float) $transaction['discrepancy'], 2) : '—' }}</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $transaction['shipping_fee'] !== '' ? 'PHP '.number_format((float) $transaction['shipping_fee'], 2) : '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $transaction['additional_care'] !== '' ? 'PHP '.number_format((float) $transaction['additional_care'], 2) : '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $transaction['delivery_earnings'] !== '' ? 'PHP '.number_format((float) $transaction['delivery_earnings'], 2) : '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $transaction['logistics_share'] !== '' ? 'PHP '.number_format((float) $transaction['logistics_share'], 2) : '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $transaction['rider_pass_through'] !== '' ? 'PHP '.number_format((float) $transaction['rider_pass_through'], 2) : '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $transaction['admin_product_commission'] !== '' ? 'PHP '.number_format((float) $transaction['admin_product_commission'], 2) : '—' }}</td>
                                        <td class="px-3 py-3">{{ $transaction['rider'] ?: '—' }}<span class="mt-1 block text-muted">{{ $transaction['area'] ?: '—' }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="11" class="px-3 py-12 text-center"><span class="mx-auto grid h-10 w-10 place-items-center rounded-full bg-page text-muted">—</span><strong class="mt-3 block">No matching transactions</strong><span class="mt-1 block text-sm text-muted">Try a wider date range or clear one of the filters.</span></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="pt-1">{{ $financialTransactions->links() }}</div>
                </section>
            @endunless
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-analytics-custom-date]').forEach((field) => {
        field.addEventListener('change', () => {
            const period = document.getElementById('analytics-period');
            if (period) period.value = 'custom';
        });
    });
</script>
@endpush
