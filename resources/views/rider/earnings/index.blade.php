@extends('layouts.rider.app')

@section('title', 'Wallet & Earnings - LIKHAE Rider')

@section('content')
<div class="lk-wallet-page">
    @if(session('status'))<div class="lk-wallet-alert lk-wallet-alert--success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="lk-wallet-alert lk-wallet-alert--error" role="alert">{{ $errors->first() }}</div>@endif
    <section class="lk-wallet-header">
        <div class="lk-wallet-header__copy">
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Income Management</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink">My Earnings</h1>
        <p class="mt-2 max-w-[680px] text-[11px] leading-6 text-muted">{{ $earningsNotice }}</p>
        </div>
    </section>

    <form method="GET" action="{{ route('rider.earnings') }}" class="lk-wallet-panel lk-wallet-filter">
        <label class="grid gap-1 text-xs font-semibold text-ink">From date<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="h-10 border border-line bg-white px-3"></label>
        <label class="grid gap-1 text-xs font-semibold text-ink">To date<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="h-10 border border-line bg-white px-3"></label>
        <button class="h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white">Apply date filter</button>
        @if(filled($filters['from'] ?? null) || filled($filters['to'] ?? null))<a href="{{ route('rider.earnings') }}" class="h-10 rounded-lg border border-line px-4 py-3 text-xs font-semibold text-ink">Clear</a>@endif
    </form>

    <section class="lk-wallet-metrics lk-wallet-metrics--three" aria-label="Payout balance summary">
        @foreach([['Available to request', 'available'], ['Requested from Logistics', 'requested'], ['Awaiting receipt confirmation', 'awaiting_ack'], ['Paid', 'paid'], ['Unallocated pool', 'unallocated'], ['Allocated earnings in selected period', 'accrued']] as [$label, $key])
            <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">{{ $label }}</span><strong class="lk-wallet-metric__value">PHP {{ number_format((float) ($poolSummary[$key] ?? 0), 2) }}</strong></article>
        @endforeach
    </section>

    <section class="lk-wallet-panel">
        <header class="lk-wallet-panel__head"><h2>Online payout details</h2><p class="lk-wallet-panel__description">Your Logistics center uses these details for demo payout records. Online payouts are simulated only; no money is transferred. Account name and number are encrypted at rest.</p></header>
        @if($payoutDestinationReady)
            @php($riderPayoutMethod = old('payout_method', $rider->payout_method))
            <form method="POST" action="{{ route('rider.wallet.payout-destination.update') }}" class="lk-wallet-form" data-payout-destination-form>
                @csrf @method('PUT')
                <label>Payment channel<select name="payout_method" required><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected($riderPayoutMethod === $method)>{{ $method }}</option>@endforeach</select></label>
                <label data-payout-bank-field @if($riderPayoutMethod !== 'Bank Transfer') hidden @endif>Bank name<input name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name', $rider->payout_bank_name) }}" autocomplete="organization"></label>
                <label>Account holder name<input name="payout_account_name" maxlength="150" value="{{ old('payout_account_name', $rider->payout_account_name) }}" required autocomplete="name"></label>
                <label>Account number or mobile<input name="payout_account_number" maxlength="150" value="{{ old('payout_account_number', $rider->payout_account_number) }}" required autocomplete="off"></label>
                <button class="w-fit rounded-lg bg-primary px-4 py-2 text-[10px] font-semibold text-white">Save payment details</button>
            </form>
        @else
            <p class="lk-wallet-alert">Payout details will be available after the additive payout destination migration is installed.</p>
        @endif
    </section>

    <section class="lk-wallet-metrics lk-wallet-metrics--two" aria-label="Recent earnings summary">
        <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">Today's earnings</span><strong class="lk-wallet-metric__value">PHP {{ number_format((float) $dailyEarnings, 2) }}</strong></article>
        <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">This week's earnings</span><strong class="lk-wallet-metric__value">PHP {{ number_format((float) $weeklyEarnings, 2) }}</strong></article>
    </section>

    @php($financeSummary = $financeSummary ?? [])
    <section class="lk-wallet-metrics lk-wallet-metrics--five" aria-label="Cash handling summary">
        @foreach([['COD collected', 'cod_collected'], ['Unremitted cash', 'unremitted_cash'], ['Submitted cash', 'remittances_submitted'], ['Verified cash', 'verified_cash']] as [$label, $key])
            <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">{{ $label }}</span><strong class="lk-wallet-metric__value">PHP {{ number_format((float) ($financeSummary[$key] ?? 0), 2) }}</strong></article>
        @endforeach
        <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">Discrepancies</span><strong class="lk-wallet-metric__value">{{ number_format((int) ($financeSummary['discrepancies'] ?? 0)) }}</strong></article>
    </section>

    @if($payoutDestinationSaved && ($eligiblePayoutParticipants ?? collect())->isNotEmpty())
        <section class="lk-wallet-panel">
            <header class="lk-wallet-panel__head"><h2>Request a payout from Logistics</h2><p class="lk-wallet-panel__description">These shares are available after Logistics reconciles the Rider cash handoff. Select the parcels to request.</p></header>
            <form method="POST" action="{{ route('rider.earnings.payout-requests.store') }}" class="mt-4 grid gap-3">
                @csrf
                @foreach($eligiblePayoutParticipants as $participant)
                    <label class="lk-wallet-record flex flex-wrap items-center justify-between gap-3">
                        <span><input type="checkbox" name="participant_ids[]" value="{{ $participant->id }}" class="mr-2">{{ $participant->pool?->shipment?->tracking_number ?? 'Parcel' }} · {{ str($participant->participant_role)->replace('_', ' ')->headline() }}</span>
                        <strong>PHP {{ number_format((float) $participant->share_amount, 2) }}</strong>
                    </label>
                @endforeach
                <button class="w-fit rounded-lg bg-primary px-4 py-2 text-[10px] font-semibold text-white">Request selected payouts</button>
            </form>
        </section>
    @endif
    @if($payoutDestinationReady && !$payoutDestinationSaved && ($eligiblePayoutParticipants ?? collect())->isNotEmpty())
        <p class="lk-wallet-alert">Save your online payout details above before requesting your earnings.</p>
    @endif

    @if(($riderPayoutReceipts ?? collect())->isNotEmpty())
        <section class="lk-wallet-panel">
            <header class="lk-wallet-panel__head"><h2>Payout receipts awaiting your confirmation</h2></header>
            <div class="mt-3 grid gap-3">
                @foreach($riderPayoutReceipts as $receipt)
                    @php($payout = (array) $receipt->metadata)
                    <article class="lk-wallet-record">
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

    <section class="lk-wallet-panel">
        <header class="lk-wallet-panel__head"><h2>Earnings Records</h2><p class="lk-wallet-panel__description">Only approved rider pool shares become earnings. Cash remittance is never netted against this table.</p></header>

        <p class="lk-wallet-table-hint">Scroll horizontally to see all earnings details.</p>
        <div class="lk-wallet-table-clip" role="region" aria-label="Earnings records table" tabindex="0">
            <table class="lk-wallet-table">
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
