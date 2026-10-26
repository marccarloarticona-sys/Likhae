@extends('layouts.logistics.app')
@section('title','Logistics Reports - LIKHAE')
@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-bold uppercase text-primary">Analytics</p>
            <h1 class="mt-2 text-2xl font-bold">Logistics Reports</h1>
            <p class="mt-2 text-sm text-muted">Live shipment totals and persisted parcel transactions for {{ $center->business_name }}.</p>
        </div>
        <a href="{{ route('logistics.reports.export') }}" class="bg-primary px-4 py-2 font-semibold text-white">Download shipment PDF</a>
    </header>

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($shipmentsByStatus as $status=>$total)
            <article class="border border-line bg-surface p-5">
                <span class="text-xs text-muted">{{ str($status)->headline() }}</span>
                <strong class="mt-2 block text-3xl">{{ $total }}</strong>
            </article>
        @endforeach
        <article class="border border-line bg-surface p-5">
            <span class="text-xs text-muted">Registered Riders</span>
            <strong class="mt-2 block text-3xl">{{ $riderCount }}</strong>
        </article>
    </section>

    <section class="space-y-5 border border-line bg-surface p-5 sm:p-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-bold uppercase text-primary">Financial activity</p>
                <h2 class="mt-1 text-xl font-bold">Transactions and shipping share</h2>
                <p class="mt-1 text-sm text-muted">Amounts come from saved parcel allocations and Logistics ledger entries.</p>
            </div>
            @if($financeReady)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('logistics.reports.transactions.csv', $financialFilters) }}" class="border border-line px-4 py-2 text-sm font-semibold">Download CSV</a>
                    <a href="{{ route('logistics.reports.transactions.pdf', $financialFilters) }}" class="bg-primary px-4 py-2 text-sm font-semibold text-white">Download transactions PDF</a>
                </div>
            @endif
        </div>

        @unless($financeReady)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Financial reporting is unavailable until the reviewed finance migrations are installed. Shipment totals above remain available.
            </div>
        @else
            <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                No Logistics commission rate is configured in the saved finance rules. “Admin product commission” below is the persisted product commission allocated to Admin; it is not a Logistics commission. The Logistics amount is reported separately as a shipping share.
            </div>

            <form method="GET" action="{{ route('logistics.reports') }}" class="grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2 lg:grid-cols-6">
                <label class="grid gap-1 text-xs font-semibold">From
                    <input type="date" name="date_from" value="{{ $financialFilters['date_from'] ?? '' }}" class="rounded border border-line px-3 py-2">
                </label>
                <label class="grid gap-1 text-xs font-semibold">To
                    <input type="date" name="date_to" value="{{ $financialFilters['date_to'] ?? '' }}" class="rounded border border-line px-3 py-2">
                </label>
                <label class="grid gap-1 text-xs font-semibold">Transaction status
                    <select name="status" class="rounded border border-line px-3 py-2">
                        <option value="">All statuses</option>
                        @foreach($financialStatuses as $status)
                            <option value="{{ $status }}" @selected(($financialFilters['status'] ?? null) === $status)>{{ str($status)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-semibold">Delivery rider
                    <select name="rider_id" class="rounded border border-line px-3 py-2">
                        <option value="">All riders</option>
                        @foreach($financialRiders as $rider)
                            <option value="{{ $rider->id }}" @selected((string) ($financialFilters['rider_id'] ?? '') === (string) $rider->id)>{{ $rider->user?->name ?? 'Rider '.$rider->id }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-semibold">Service area
                    <select name="area_id" class="rounded border border-line px-3 py-2">
                        <option value="">All areas</option>
                        @foreach($financialAreas as $area)
                            <option value="{{ $area->id }}" @selected((string) ($financialFilters['area_id'] ?? '') === (string) $area->id)>{{ $area->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button class="flex-1 rounded bg-primary px-4 py-2 text-sm font-semibold text-white">Apply</button>
                    <a href="{{ route('logistics.reports') }}" class="border border-line px-3 py-2 text-sm font-semibold">Clear</a>
                </div>
            </form>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article class="border border-line p-4"><span class="text-xs text-muted">Parcels with allocation</span><strong class="mt-1 block text-2xl">{{ number_format($financialSummary['parcel_count']) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Base shipping charges</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['shipping_fees'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Additional Care charges</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['additional_care'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Combined eligible delivery earnings</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['delivery_charges'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Logistics delivery share allocated</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['logistics_share'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Admin product commission</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['admin_product_commission'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Product proceeds expected</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['proceeds_expected'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Product proceeds received</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['proceeds_received'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Payout requested / awaiting acknowledgment</span><strong class="mt-1 block text-sm">PHP {{ number_format($financialSummary['payout_requested'], 2) }} / PHP {{ number_format($financialSummary['payout_pending_ack'], 2) }}</strong></article>
                <article class="border border-line p-4"><span class="text-xs text-muted">Payout confirmed paid</span><strong class="mt-1 block text-2xl">PHP {{ number_format($financialSummary['payout_paid'], 2) }}</strong></article>
            </div>
            @if($financialSummary['proceeds_discrepancies'] > 0)
                <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900">{{ $financialSummary['proceeds_discrepancies'] }} product-proceeds transaction(s) have an unresolved discrepancy. See each transaction’s submitted and received amounts below.</p>
            @endif

            <div class="overflow-x-auto border border-line">
                <table class="min-w-full divide-y divide-line text-left text-xs">
                    <thead class="bg-surface-muted text-muted">
                        <tr>
                            <th class="px-3 py-3 font-semibold">Date</th>
                            <th class="px-3 py-3 font-semibold">Parcel / order</th>
                            <th class="px-3 py-3 font-semibold">Transaction</th>
                            <th class="px-3 py-3 font-semibold">Amount / status</th>
                            <th class="px-3 py-3 font-semibold">Base shipping</th>
                            <th class="px-3 py-3 font-semibold">Additional Care</th>
                            <th class="px-3 py-3 font-semibold">Combined delivery</th>
                            <th class="px-3 py-3 font-semibold">Logistics share</th>
                            <th class="px-3 py-3 font-semibold">Admin commission</th>
                            <th class="px-3 py-3 font-semibold">Rider / area</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse($financialTransactions as $transaction)
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-3 py-3">{{ $transaction['date'] ?: '—' }}</td>
                                <td class="px-3 py-3"><strong>{{ $transaction['tracking'] ?: '—' }}</strong><span class="mt-1 block text-muted">{{ $transaction['order'] ?: '—' }}</span><span class="mt-1 block text-muted">{{ $transaction['destination'] ?: '—' }}</span></td>
                                <td class="px-3 py-3"><span class="font-semibold">{{ str($transaction['transaction'])->replace('_', ' ')->headline() }}</span><span class="mt-1 block text-muted">{{ str($transaction['direction'])->lower() }}</span></td>
                                <td class="px-3 py-3"><strong>PHP {{ number_format((float) $transaction['amount'], 2) }}</strong><span class="mt-1 block">{{ str($transaction['status'])->replace('_', ' ')->headline() }}</span>
                                    @if($transaction['expected'] !== '' || $transaction['submitted'] !== '' || $transaction['received'] !== '' || $transaction['discrepancy'] !== '')
                                        <span class="mt-1 block text-muted">Expected {{ $transaction['expected'] !== '' ? 'PHP '.number_format((float) $transaction['expected'], 2) : '—' }} · submitted {{ $transaction['submitted'] !== '' ? 'PHP '.number_format((float) $transaction['submitted'], 2) : '—' }} · received {{ $transaction['received'] !== '' ? 'PHP '.number_format((float) $transaction['received'], 2) : '—' }} · difference {{ $transaction['discrepancy'] !== '' ? 'PHP '.number_format((float) $transaction['discrepancy'], 2) : '—' }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3">{{ $transaction['shipping_fee'] !== '' ? 'PHP '.number_format((float) $transaction['shipping_fee'], 2) : '—' }}</td>
                                <td class="px-3 py-3">{{ $transaction['additional_care'] !== '' ? 'PHP '.number_format((float) $transaction['additional_care'], 2) : '—' }}</td>
                                <td class="px-3 py-3">{{ $transaction['delivery_earnings'] !== '' ? 'PHP '.number_format((float) $transaction['delivery_earnings'], 2) : '—' }}</td>
                                <td class="px-3 py-3">{{ $transaction['logistics_share'] !== '' ? 'PHP '.number_format((float) $transaction['logistics_share'], 2) : '—' }}</td>
                                <td class="px-3 py-3">{{ $transaction['admin_product_commission'] !== '' ? 'PHP '.number_format((float) $transaction['admin_product_commission'], 2) : '—' }}</td>
                                <td class="px-3 py-3">{{ $transaction['rider'] ?: '—' }}<span class="mt-1 block text-muted">{{ $transaction['area'] ?: '—' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="px-3 py-8 text-center text-muted">No financial transactions match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted">Base shipping, Additional Care, combined delivery earnings, Logistics share, and Admin product commission appear on each parcel’s allocation entry. Proceeds handoffs and payouts are separate transactions and are not added into those allocation totals.</p>
            {{ $financialTransactions->links() }}
        @endunless
    </section>
</div>
@endsection
