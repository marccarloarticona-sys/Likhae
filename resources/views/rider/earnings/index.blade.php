@extends('layouts.rider.app')

@section('title', 'Wallet & Earnings - LIKHAE Rider')

@section('content')
<div class="flex flex-col gap-6">
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-700">{{ $errors->first() }}</div>@endif
    <section>
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Income Management</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink">My Wallet &amp; Earnings</h1>
        <p class="mt-2 max-w-[680px] text-[11px] leading-6 text-muted">{{ $earningsNotice }}</p>
    </section>

    <form method="GET" action="{{ route('rider.earnings') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface p-5">
        <label class="grid gap-1 text-xs font-semibold text-ink">From date<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="h-10 border border-line bg-white px-3"></label>
        <label class="grid gap-1 text-xs font-semibold text-ink">To date<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="h-10 border border-line bg-white px-3"></label>
        <button class="h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white">Apply date filter</button>
        @if(filled($filters['from'] ?? null) || filled($filters['to'] ?? null))<a href="{{ route('rider.earnings') }}" class="h-10 rounded-lg border border-line px-4 py-3 text-xs font-semibold text-ink">Clear</a>@endif
    </form>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach([['Available to request', 'available'], ['Requested from Logistics', 'requested'], ['Awaiting receipt confirmation', 'awaiting_ack'], ['Paid', 'paid'], ['Unallocated pool', 'unallocated'], ['Allocated earnings in selected period', 'accrued']] as [$label, $key])
            <article class="rounded-xl border border-line bg-surface p-5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted">{{ $label }}</p><p class="mt-2 text-2xl font-semibold text-ink">PHP {{ number_format((float) ($poolSummary[$key] ?? 0), 2) }}</p></article>
        @endforeach
    </section>

    <section class="rounded-xl border border-line bg-surface p-6">
        <h2 class="text-[13px] font-semibold text-ink">Online payout details</h2>
        <p class="mt-1 text-[10px] text-muted">Your Logistics center uses these details for demo payout records. Online payouts are simulated only; no money is transferred. Account name and number are encrypted at rest.</p>
        @if($payoutDestinationReady)
            <form method="POST" action="{{ route('rider.wallet.payout-destination.update') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                @csrf @method('PUT')
                <label class="grid gap-1 text-xs font-semibold text-ink">Payment channel<select name="payout_method" required class="h-10 rounded-lg border border-line bg-white px-3"><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected($rider->payout_method === $method)>{{ $method }}</option>@endforeach</select></label>
                <label class="grid gap-1 text-xs font-semibold text-ink">Bank name (for bank transfer)<input name="payout_bank_name" maxlength="120" value="{{ $rider->payout_bank_name }}" class="h-10 rounded-lg border border-line bg-white px-3"></label>
                <label class="grid gap-1 text-xs font-semibold text-ink">Account holder name<input name="payout_account_name" maxlength="150" value="{{ $rider->payout_account_name }}" required class="h-10 rounded-lg border border-line bg-white px-3"></label>
                <label class="grid gap-1 text-xs font-semibold text-ink">Account number or mobile<input name="payout_account_number" maxlength="150" value="{{ $rider->payout_account_number }}" required class="h-10 rounded-lg border border-line bg-white px-3"></label>
                <button class="w-fit rounded-lg bg-primary px-4 py-2 text-[10px] font-semibold text-white">Save payment details</button>
            </form>
        @else
            <p class="mt-3 text-[10px] text-amber-700">Payout details will be available after the additive payout destination migration is installed.</p>
        @endif
    </section>

    <section class="grid gap-3 sm:grid-cols-2">
        <article class="rounded-xl border border-line bg-surface p-5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted">Today's earnings</p><p class="mt-2 text-2xl font-semibold text-ink">PHP {{ number_format((float) $dailyEarnings, 2) }}</p></article>
        <article class="rounded-xl border border-line bg-surface p-5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted">This week's earnings</p><p class="mt-2 text-2xl font-semibold text-ink">PHP {{ number_format((float) $weeklyEarnings, 2) }}</p></article>
    </section>

    @php($financeSummary = $financeSummary ?? [])
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([['COD collected', 'cod_collected'], ['Unremitted cash', 'unremitted_cash'], ['Submitted cash', 'remittances_submitted'], ['Verified cash', 'verified_cash']] as [$label, $key])
            <article class="rounded-xl border border-line bg-surface p-4"><span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">{{ $label }}</span><strong class="mt-2 block text-xl text-ink">PHP {{ number_format((float) ($financeSummary[$key] ?? 0), 2) }}</strong></article>
        @endforeach
        <article class="rounded-xl border border-line bg-surface p-4"><span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">Discrepancies</span><strong class="mt-2 block text-xl text-ink">{{ number_format((int) ($financeSummary['discrepancies'] ?? 0)) }}</strong></article>
    </section>

    @if($payoutDestinationSaved && ($eligiblePayoutParticipants ?? collect())->isNotEmpty())
        <section class="rounded-xl border border-line bg-surface p-6">
            <h2 class="text-[13px] font-semibold text-ink">Request a payout from Logistics</h2>
            <p class="mt-1 text-[10px] text-muted">These shares are available after Logistics reconciles the Rider cash handoff. Select the parcels to request.</p>
            <form method="POST" action="{{ route('rider.earnings.payout-requests.store') }}" class="mt-4 grid gap-3">
                @csrf
                @foreach($eligiblePayoutParticipants as $participant)
                    <label class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3 text-[10px]">
                        <span><input type="checkbox" name="participant_ids[]" value="{{ $participant->id }}" class="mr-2">{{ $participant->pool?->shipment?->tracking_number ?? 'Parcel' }} · {{ str($participant->participant_role)->replace('_', ' ')->headline() }}</span>
                        <strong>PHP {{ number_format((float) $participant->share_amount, 2) }}</strong>
                    </label>
                @endforeach
                <button class="w-fit rounded-lg bg-primary px-4 py-2 text-[10px] font-semibold text-white">Request selected payouts</button>
            </form>
        </section>
    @endif
    @if($payoutDestinationReady && !$payoutDestinationSaved && ($eligiblePayoutParticipants ?? collect())->isNotEmpty())
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-[10px] text-amber-800">Save your online payout details above before requesting your earnings.</p>
    @endif

    @if(($riderPayoutReceipts ?? collect())->isNotEmpty())
        <section class="rounded-xl border border-line bg-surface p-6">
            <h2 class="text-[13px] font-semibold text-ink">Payout receipts awaiting your confirmation</h2>
            <div class="mt-3 grid gap-3">
                @foreach($riderPayoutReceipts as $receipt)
                    @php($payout = (array) $receipt->metadata)
                    <article class="rounded-lg border border-line p-4 text-[10px]">
                        <div class="flex flex-wrap justify-between gap-3"><strong>{{ $payout['payout_receipt_reference'] ?? $payout['payout_reference'] ?? 'Rider payout' }} · {{ $receipt->shipment?->tracking_number ?? 'Parcel' }}</strong><span>PHP {{ number_format((float) $receipt->amount, 2) }}</span></div>
                        <p class="mt-2 text-muted">{{ $payout['payout_method'] ?? 'Payment method unavailable' }} {{ $payout['payout_external_reference'] ?? '' }} · Sent {{ $payout['paid_at'] ?? '' }}</p>
                        @if($payout['is_simulated'] ?? false)<p class="mt-2 font-semibold text-amber-700">SIMULATED DEMO ONLY — no money was transferred.</p>@endif
                        <form method="POST" action="{{ route('rider.earnings.payouts.acknowledge', $receipt) }}" class="mt-3 flex flex-wrap items-end gap-2">
                            @csrf
                            <label class="grid gap-1 font-semibold">{{ ($payout['is_simulated'] ?? false) ? 'Demo amount' : 'Amount received' }}<input name="amount_received" type="number" min="0" step="0.01" value="{{ number_format((float) $receipt->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid min-w-48 flex-1 gap-1 font-semibold">Receipt note<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="rounded-lg bg-primary px-3 py-2 font-semibold text-white">{{ ($payout['is_simulated'] ?? false) ? 'Acknowledge demo record' : 'Confirm receipt' }}</button>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

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
                            <td class="px-4 py-3">{{ $row['status'] }}@if($row['is_simulated'] ?? false)<br><small class="font-semibold text-amber-700">SIMULATED — NO MONEY TRANSFERRED</small>@endif</td>
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
