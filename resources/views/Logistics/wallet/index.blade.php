@extends('layouts.logistics.app')
@section('title', 'Wallet & Payouts')
@section('subtitle', 'Review your shipping share, request Admin payouts, and confirm receipts.')

@section('content')
<div class="mx-auto max-w-7xl space-y-5">
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-700">{{ $errors->first() }}</div>@endif
    <section>
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Logistics wallet</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold text-ink">Wallet &amp; Payouts</h1>
        <p class="mt-2 max-w-2xl text-[11px] leading-6 text-muted">Save the online destination for your shipping share. Request each reconciled share from Admin. Online payouts are simulated only; no money is transferred.</p>
        <a href="{{ route('logistics.remittances') }}#rider-payout-requests" class="mt-3 inline-flex rounded-lg border border-line px-3 py-2 text-[10px] font-semibold text-ink">Review Rider payout requests</a>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Available to request', 'available'], ['Requested from Admin', 'requested'], ['Awaiting receipt confirmation', 'awaiting_ack'], ['Paid', 'paid']] as [$label, $key])
            <article class="rounded-xl border border-line bg-surface p-5"><span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">{{ $label }}</span><strong class="mt-2 block text-xl text-ink">PHP {{ number_format((float) $payoutSummary[$key], 2) }}</strong></article>
        @endforeach
    </section>

    <section class="rounded-xl border border-line bg-surface p-6">
        <h2 class="text-[13px] font-semibold text-ink">Online payout details</h2>
        <p class="mt-1 text-[10px] text-muted">Admin uses these details for simulated demo payouts. No money is transferred. Account name and number are encrypted at rest.</p>
        @if($payoutDestinationReady)
            <form method="POST" action="{{ route('logistics.wallet.payout-destination.update') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                @csrf @method('PUT')
                <label class="grid gap-1 text-xs font-semibold text-ink">Payment channel<select name="payout_method" required class="h-10 rounded-lg border border-line bg-white px-3"><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected($center->payout_method === $method)>{{ $method }}</option>@endforeach</select></label>
                <label class="grid gap-1 text-xs font-semibold text-ink">Bank name (for bank transfer)<input name="payout_bank_name" maxlength="120" value="{{ $center->payout_bank_name }}" class="h-10 rounded-lg border border-line bg-white px-3"></label>
                <label class="grid gap-1 text-xs font-semibold text-ink">Account holder name<input name="payout_account_name" maxlength="150" value="{{ $center->payout_account_name }}" required class="h-10 rounded-lg border border-line bg-white px-3"></label>
                <label class="grid gap-1 text-xs font-semibold text-ink">Account number or mobile<input name="payout_account_number" maxlength="150" value="{{ $center->payout_account_number }}" required class="h-10 rounded-lg border border-line bg-white px-3"></label>
                <button class="w-fit rounded-lg bg-primary px-4 py-2 text-[10px] font-semibold text-white">Save payment details</button>
            </form>
        @else
            <p class="mt-3 text-[10px] text-amber-700">Payout details will be available after the additive payout destination migration is installed.</p>
        @endif
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
            <p class="mt-1 text-[10px] text-muted">Confirm each Admin transfer after the funds reach your saved destination.</p>
            <div class="mt-4 grid gap-3">
                @forelse($receipts as $receipt)
                    @php($payout = (array) $receipt->metadata)
                    <article class="rounded-lg border border-line p-4 text-[10px]">
                        <div class="flex flex-wrap justify-between gap-3"><strong>{{ $payout['payout_reference'] ?? 'Logistics payout' }} · {{ $receipt->shipment?->tracking_number ?? 'Parcel' }}</strong><span>PHP {{ number_format((float) $receipt->amount, 2) }}</span></div>
                        <p class="mt-2 text-muted">{{ $payout['payout_method'] ?? '' }} {{ $payout['payout_external_reference'] ?? '' }} · Sent {{ $payout['paid_at'] ?? '' }}</p>
                        @if($payout['is_simulated'] ?? false)<p class="mt-2 font-semibold text-amber-700">SIMULATED DEMO ONLY — no money was transferred.</p>@endif
                        <form method="POST" action="{{ route('logistics.remittances.logistics-payouts.acknowledge', $receipt) }}" class="mt-3 flex flex-wrap items-end gap-3">
                            @csrf
                            <label class="grid gap-1 font-semibold">{{ ($payout['is_simulated'] ?? false) ? 'Demo amount' : 'Amount received' }}<input name="amount_received" type="number" min="0" step="0.01" value="{{ number_format((float) $receipt->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid min-w-48 flex-1 gap-1 font-semibold">Receipt notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="rounded-lg bg-primary px-3 py-2 font-semibold text-white">{{ ($payout['is_simulated'] ?? false) ? 'Acknowledge demo record' : 'Acknowledge receipt' }}</button>
                        </form>
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No Admin payouts are awaiting confirmation.</p>
                @endforelse
            </div>
            {{ $receipts->links() }}
        </section>
    @endunless
</div>
@endsection
