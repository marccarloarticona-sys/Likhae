@extends('Logistics.app')

@section('title', 'Dashboard - LIKHAE Logistics')

@php
    $stats = $logisticsStats ?? [];
    $recentParcels = $logisticsRecentParcels ?? [];
    $areas = $logisticsAreas ?? [];
    $activity = $logisticsActivity ?? [];
@endphp

@section('content')
<div class="flex w-full flex-col gap-6">
    <section class="flex flex-col gap-5 rounded-xl border border-line bg-surface p-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Logistics Dashboard</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink sm:text-[40px]">Logistics Overview</h1>
            <p class="mt-2 max-w-[680px] text-[11px] leading-6 text-muted">
                Monitor receiving, sorting, rider assignment, and delivery movement from live database records.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('logistics.parcels.tracking') }}" class="inline-flex h-10 items-center justify-center rounded-lg border border-line bg-surface px-4 text-[10px] font-semibold text-ink">
                Track Parcel
            </a>
            <a href="{{ route('logistics.parcels.receive') }}" class="inline-flex h-10 items-center justify-center rounded-lg bg-primary px-4 text-[10px] font-semibold text-white">
                Receive Parcel
            </a>
        </div>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @forelse($stats as $stat)
            <article class="rounded-xl border border-line bg-surface p-4">
                <span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">{{ $stat['label'] }}</span>
                <strong class="mt-2 block text-[26px] font-bold tracking-[-0.04em] text-ink">{{ $stat['value'] }}</strong>
                <p class="mt-1 text-[9px] text-muted">{{ $stat['description'] }}</p>
                <span class="mt-3 inline-flex rounded-full bg-page-secondary px-3 py-1 text-[8px] font-semibold text-primary">{{ $stat['change'] }}</span>
            </article>
        @empty
            <article class="rounded-xl border border-line bg-surface p-5 text-[10px] text-muted sm:col-span-2 xl:col-span-4">
                No logistics dashboard metrics are available.
            </article>
        @endforelse
    </section>

    @php($financeSummary = $financeSummary ?? [])
    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><span class="text-[10px] font-bold uppercase tracking-[0.16em] text-primary">Finance control</span><h2 class="mt-1 text-[16px] font-semibold text-ink">COD and earnings reconciliation</h2></div><a href="{{ route('logistics.remittances') }}" class="rounded-lg border border-line px-3 py-2 text-[9px] font-semibold text-ink">Review remittances</a></div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            @foreach([['Rider COD obligations', 'rider_cod_obligations'], ['Submitted cash', 'remittance_submitted'], ['Verified cash', 'verified_cash'], ['Discrepancies', 'discrepancies'], ['Logistics pool', 'logistics_pool'], ['Pending settlement', 'pending_settlement']] as [$label, $key])
                <article class="rounded-lg bg-page-secondary p-3"><span class="block text-[8px] uppercase tracking-[0.08em] text-muted">{{ $label }}</span><strong class="mt-2 block text-[15px] text-ink">{{ $key === 'discrepancies' ? number_format((int) ($financeSummary[$key] ?? 0)) : 'PHP '.number_format((float) ($financeSummary[$key] ?? 0), 2) }}</strong></article>
            @endforeach
        </div>
    </section>

    <section class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <section class="overflow-hidden rounded-xl border border-line bg-surface">
            <div class="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
                <div>
                    <h2 class="text-[13px] font-semibold text-ink">Recent Parcels</h2>
                    <p class="mt-1 text-[9px] text-muted">Latest deliveries handled by the sorting center.</p>
                </div>
                <a href="{{ route('logistics.parcels') }}" class="rounded-lg border border-line px-3 py-2 text-[9px] font-semibold text-ink">View All</a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left">
                    <thead class="bg-page-secondary text-[8px] uppercase tracking-[0.08em] text-muted">
                        <tr>
                            <th class="px-5 py-3">Parcel</th>
                            <th class="px-5 py-3">Customer</th>
                            <th class="px-5 py-3">Destination</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Time</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse($recentParcels as $parcel)
                            <tr>
                                <td class="px-5 py-4">
                                    <strong class="block text-[10px] text-ink">{{ $parcel['tracking'] }}</strong>
                                    <span class="text-[8px] text-muted">Parcel #{{ $parcel['id'] }}</span>
                                </td>
                                <td class="px-5 py-4 text-[10px] text-ink">{{ $parcel['buyer'] }}</td>
                                <td class="px-5 py-4">
                                    <strong class="block text-[10px] text-ink">{{ $parcel['destination'] }}</strong>
                                    <span class="text-[8px] text-muted">{{ $parcel['area'] }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full bg-primary-soft px-3 py-1 text-[8px] font-semibold text-primary">{{ $parcel['status'] }}</span>
                                </td>
                                <td class="px-5 py-4 text-[9px] text-muted">{{ $parcel['time'] }}</td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('logistics.parcels.show', $parcel['id']) }}" class="rounded-lg border border-line px-3 py-2 text-[9px] font-semibold text-ink">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-[10px] text-muted">No parcels have been loaded from the database.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="flex flex-col gap-5">
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Delivery Areas</h2>
                <div class="mt-4 grid gap-3">
                    @forelse($areas as $area)
                        <a href="{{ route('logistics.delivery-areas') }}" class="rounded-lg bg-page-secondary p-4">
                            <strong class="block text-[10px] text-ink">{{ $area['area'] }}</strong>
                            <span class="mt-1 block text-[8px] text-muted">{{ $area['municipality'] }}</span>
                            <span class="mt-2 block text-[8px] font-semibold text-primary">{{ $area['available'] }}/{{ $area['total'] }} riders available</span>
                        </a>
                    @empty
                        <p class="text-[10px] text-muted">No delivery area data is available.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Recent Activity</h2>
                <div class="mt-4 grid gap-3">
                    @forelse($activity as $item)
                        <article class="rounded-lg bg-page-secondary p-4">
                            <strong class="block text-[10px] text-ink">{{ $item['title'] }}</strong>
                            <p class="mt-1 text-[9px] leading-5 text-muted">{{ $item['description'] }}</p>
                            <span class="mt-2 block text-[8px] text-muted">{{ $item['time'] }}</span>
                        </article>
                    @empty
                        <p class="text-[10px] text-muted">No recent logistics activity is available.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Quick Tracking</h2>
        <form method="GET" action="{{ route('logistics.parcels.tracking') }}" class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]">
            <input name="tracking" type="text" placeholder="Enter tracking number" class="h-10 rounded-lg border border-line bg-page-secondary px-4 text-[10px] text-ink outline-none focus:border-primary">
            <button type="submit" class="h-10 rounded-lg bg-primary px-4 text-[10px] font-semibold text-white">Track Parcel</button>
        </form>
    </section>
</div>
@endsection
