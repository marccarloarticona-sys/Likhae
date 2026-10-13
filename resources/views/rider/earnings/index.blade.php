@extends('Rider.app')

@section('title', 'Earnings - LIKHAE Rider')

@section('content')
<div class="flex flex-col gap-6">
    <section>
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Income Management</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink">My Earnings</h1>
        <p class="mt-2 max-w-[680px] text-[11px] leading-6 text-muted">{{ $earningsNotice }}</p>
    </section>

    <section class="grid gap-3 sm:grid-cols-3">
        <article class="rounded-xl border border-line bg-surface p-6"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted">Accrued / allocated</p><p class="mt-2 text-3xl font-semibold text-ink">PHP {{ number_format((float) ($poolSummary['accrued'] ?? $earningsTotal), 2) }}</p></article>
        <article class="rounded-xl border border-line bg-surface p-6"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted">Paid</p><p class="mt-2 text-3xl font-semibold text-ink">PHP {{ number_format((float) ($poolSummary['paid'] ?? 0), 2) }}</p></article>
        <article class="rounded-xl border border-line bg-surface p-6"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted">Unallocated pool</p><p class="mt-2 text-3xl font-semibold text-ink">PHP {{ number_format((float) ($poolSummary['unallocated'] ?? 0), 2) }}</p></article>
    </section>

    @php($financeSummary = $financeSummary ?? [])
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([['COD collected', 'cod_collected'], ['Unremitted cash', 'unremitted_cash'], ['Submitted cash', 'remittances_submitted'], ['Verified cash', 'verified_cash']] as [$label, $key])
            <article class="rounded-xl border border-line bg-surface p-4"><span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">{{ $label }}</span><strong class="mt-2 block text-xl text-ink">PHP {{ number_format((float) ($financeSummary[$key] ?? 0), 2) }}</strong></article>
        @endforeach
        <article class="rounded-xl border border-line bg-surface p-4"><span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">Discrepancies</span><strong class="mt-2 block text-xl text-ink">{{ number_format((int) ($financeSummary['discrepancies'] ?? 0)) }}</strong></article>
    </section>

    <section class="rounded-xl border border-line bg-surface p-6">
        <h2 class="text-[13px] font-semibold text-ink">Earnings Records</h2>
        <p class="mt-1 text-[9px] text-muted">Only approved rider pool shares become earnings. Cash remittance is never netted against this table.</p>

        <div class="mt-5 overflow-hidden rounded-xl border border-line">
            <table class="w-full min-w-[620px] text-left">
                <thead class="bg-page-secondary text-[8px] uppercase tracking-[0.12em] text-muted">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Reference</th>
                        <th class="px-4 py-3">Amount</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line text-[10px]">
                    @forelse($earningsRows ?? collect() as $row)
                        <tr>
                            <td class="px-4 py-3">{{ $row['date'] }}</td>
                            <td class="px-4 py-3">{{ $row['reference'] }}</td>
                            <td class="px-4 py-3">{{ $row['amount'] }}</td>
                            <td class="px-4 py-3">{{ $row['status'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-muted">
                                No real earnings records are available yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $earnings->links() }}</div>
    </section>
</div>
@endsection
