@extends('layouts.logistics.app')
@section('title', 'Wallet & Payouts')
@section('subtitle', 'Review your shipping share, request Admin payouts, and confirm receipts.')

@section('content')
<div class="mx-auto max-w-7xl space-y-5">
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-700">{{ $errors->first() }}</div>@endif
    <section>
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Logistics wallet</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold text-ink">Wallet &amp; Payouts</h1>
        <p class="mt-2 max-w-2xl text-[11px] leading-6 text-muted">Save where you want to receive your shipping share. Request each reconciled share from Admin; Admin transfers funds outside Likhae and records the reference and proof here.</p>
        <a href="{{ route('logistics.messages') }}" class="mt-3 inline-flex rounded-lg border border-line px-3 py-2 text-[10px] font-semibold text-ink">Contact Support</a>
        <a href="{{ route('logistics.remittances') }}#rider-payout-requests" class="mt-3 inline-flex rounded-lg border border-line px-3 py-2 text-[10px] font-semibold text-ink">Review Rider payout requests</a>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Available to request', 'available'], ['Requested from Admin', 'requested'], ['Awaiting receipt confirmation', 'awaiting_ack'], ['Paid', 'paid']] as [$label, $key])
            <article class="rounded-xl border border-line bg-surface p-5"><span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">{{ $label }}</span><strong class="mt-2 block text-xl text-ink">PHP {{ number_format((float) $payoutSummary[$key], 2) }}</strong></article>
        @endforeach
    </section>

    <section class="rounded-xl border border-line bg-surface p-6">
        <h2 class="text-[13px] font-semibold text-ink">Payment details</h2>
        <p class="mt-1 text-[10px] text-muted">Admin sends your payout to this saved destination outside Likhae. Reference and proof are recorded here after transfer.</p>
        @if($payoutDestinationSaved)
            @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($center))
            <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2">
                <div><dt class="text-muted">Channel</dt><dd class="font-semibold text-ink">{{ $center->payout_method }}@if($center->payout_bank_name) · {{ $center->payout_bank_name }}@endif</dd></div>
                <div><dt class="text-muted">Account holder</dt><dd class="font-semibold text-ink">{{ $center->payout_account_name }}</dd></div>
                <div><dt class="text-muted">{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd class="font-semibold text-ink">{{ \App\Support\PayoutAccountDetails::formatNumber($center->payout_account_number, $savedPayoutType) }}</dd></div>
            </dl>
        @else
            <p class="mt-3 text-[10px] text-amber-700">Add GCash, Maya, or bank details in Account settings before requesting a payout.</p>
        @endif
        <a href="{{ route('logistics.profile') }}" class="mt-3 inline-flex rounded-lg border border-line px-3 py-2 text-[10px] font-semibold text-ink">Edit payment details in Account settings</a>
    </section>

    @unless($ready)
        <section class="rounded-xl border border-line bg-surface p-6 text-[11px] text-muted">Parcel payout requests will appear after the reviewed finance migrations are installed.</section>
    @else
        <section class="rounded-xl border border-line bg-surface p-6">
            <h2 class="text-[13px] font-semibold text-ink">Shipping shares</h2>
            <p class="mt-1 text-[10px] text-muted">Only shares with reconciled parcel proceeds can be requested.</p>
            <div class="mt-4 grid gap-3">
                @forelse($pools as $pool)
                    <article class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-line p-4 text-[10px]">
                        <div><strong>{{ $pool->shipment?->tracking_number ?? 'Parcel' }}</strong><span class="ml-2 text-muted">{{ $pool->shipment?->sellerOrder?->order?->order_number ?? 'Order' }}</span><p class="mt-1 text-muted">Share PHP {{ number_format((float) $pool->total_amount, 2) }} · {{ str($pool->status)->replace('_', ' ')->headline() }}</p></div>
                        @if($pool->status === 'PAYOUT_REQUESTED')
                            <span class="font-semibold text-amber-700">Awaiting Admin review</span>
                        @elseif($payoutDestinationSaved)
                            <form method="POST" action="{{ route('logistics.wallet.payout-requests.store', $pool) }}" data-confirm-title="Request this Logistics payout?" data-confirm-message="Request PHP {{ number_format((float) $pool->total_amount, 2) }} for parcel {{ $pool->shipment?->tracking_number }} from Admin?" data-confirm-label="Request payout">
                                @csrf<button class="rounded-lg bg-primary px-4 py-2 font-semibold text-white">Request payout</button>
                            </form>
                        @else
                            <span class="text-amber-700">Save payout details above to request</span>
                        @endif
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No reconciled shipping shares are available to request.</p>
                @endforelse
            </div>
            {{ $pools->links() }}
        </section>

        <section class="rounded-xl border border-line bg-surface p-6">
            <h2 class="text-[13px] font-semibold text-ink">Admin payout receipts</h2>
            <p class="mt-1 text-[10px] text-muted">Review transfer proof and confirm each payout after the funds reach your saved destination. Confirmed receipts stay in this history.</p>
            <div class="mt-4 grid gap-3">
                @forelse($receipts as $receipt)
                    @php($payout = (array) $receipt->metadata)
                    <article class="rounded-lg border border-line p-4 text-[10px]">
                        <div class="flex flex-wrap justify-between gap-3"><strong>{{ $payout['payout_reference'] ?? 'Logistics payout' }} · {{ $receipt->shipment?->tracking_number ?? 'Parcel' }}</strong><span>PHP {{ number_format((float) $receipt->amount, 2) }}</span></div>
                        <p class="mt-2 text-muted">{{ $payout['payout_method'] ?? '' }} {{ $payout['payout_external_reference'] ?? '' }} · Sent {{ $payout['paid_at'] ?? '' }}</p>
                        @if(!empty($payout['payout_proof_path']))<a class="mt-2 inline-block underline" href="{{ route('logistics.wallet.payout-proof', $receipt) }}">View transfer proof</a>@endif
                        @if(($payout['is_simulated'] ?? false) || \Illuminate\Support\Str::startsWith((string) ($payout['payout_external_reference'] ?? ''), 'SIM-'))
                            <p class="mt-2 font-semibold text-amber-700">Legacy demo receipt — no transfer proof exists. Contact Admin to correct this old record.</p>
                        @elseif($receipt->status === 'PAYOUT_PENDING_ACK')
                            <form method="POST" action="{{ route('logistics.remittances.logistics-payouts.acknowledge', $receipt) }}" class="mt-3 flex flex-wrap items-end gap-3">
                                @csrf
                                <label class="grid gap-1 font-semibold">Amount received<input name="amount_received" type="number" min="0" step="0.01" value="{{ number_format((float) $receipt->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                                <label class="grid min-w-48 flex-1 gap-1 font-semibold">Receipt notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                                <button class="rounded-lg bg-primary px-3 py-2 font-semibold text-white">Acknowledge receipt</button>
                            </form>
                            <form method="POST" action="{{ route('logistics.wallet.payout-report-issue', $receipt) }}" class="mt-2">
                                @csrf<button class="rounded-lg border border-line px-3 py-2 font-semibold text-ink">Report payout not received</button>
                            </form>
                        @else
                            <p class="mt-2 text-emerald-700">Receipt confirmed {{ $payout['acknowledged_at'] ?? '' }}@if(!empty($payout['receipt_notes'])) · {{ $payout['receipt_notes'] }}@endif</p>
                        @endif
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No Admin payout receipts have been recorded yet.</p>
                @endforelse
            </div>
            {{ $receipts->links() }}
        </section>
    @endunless
</div>
@endsection
