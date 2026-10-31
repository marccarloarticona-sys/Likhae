@extends('layouts.logistics.app')
@section('title', 'Wallet & Payouts')
@section('subtitle', 'Review your shipping share, request Admin payouts, and confirm receipts.')

@section('content')
<div class="mx-auto max-w-7xl lk-wallet-page lk-logistics-wallet">
    @if(session('status'))<div class="lk-wallet-alert lk-wallet-alert--success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="lk-wallet-alert lk-wallet-alert--error" role="alert">{{ $errors->first() }}</div>@endif
    <section class="lk-wallet-header">
        <div class="lk-wallet-header__copy">
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Logistics wallet</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold text-ink">Wallet &amp; Payouts</h1>
        <p class="mt-2 max-w-2xl text-[11px] leading-6 text-muted">Admin releases the verified delivery earnings by route. Inter-Hub transfers are split between Origin and Destination; Destination reserves the Rider pool for verified payouts.</p>
        </div>
        <div class="lk-wallet-actions lk-logistics-wallet__header-actions">
        <a href="{{ route('logistics.messages') }}" class="lk-logistics-wallet__header-link">Contact Support</a>
        <a href="{{ route('logistics.remittances') }}#rider-payout-requests" class="lk-logistics-wallet__header-link">Review rider payout requests</a>
        </div>
    </section>

    <section class="lk-wallet-metrics" aria-label="Payout balance summary">
        <article class="lk-wallet-metric lk-logistics-wallet__metric">
            <span class="lk-wallet-metric__label">Logistics earnings</span>
            <strong class="lk-wallet-metric__value">PHP {{ number_format((float) $payoutSummary['commission_earnings'], 2) }}</strong>
            <span class="lk-logistics-wallet__metric-detail">Your allocated center shares across completed parcels</span>
        </article>
        <article class="lk-wallet-metric lk-logistics-wallet__metric">
            <span class="lk-wallet-metric__label">Rider funds reserved</span>
            <strong class="lk-wallet-metric__value">PHP {{ number_format((float) $payoutSummary['rider_reserved'], 2) }}</strong>
            <span class="lk-logistics-wallet__metric-detail">PHP {{ number_format((float) $payoutSummary['rider_paid'], 2) }} confirmed paid to Riders</span>
        </article>
        <article class="lk-wallet-metric lk-logistics-wallet__metric">
            <span class="lk-wallet-metric__label">Paid-out balance</span>
            <strong class="lk-wallet-metric__value">PHP {{ number_format((float) $payoutSummary['paid_out'], 2) }}</strong>
            <span class="lk-logistics-wallet__metric-detail">PHP {{ number_format((float) $payoutSummary['awaiting_ack'], 2) }} awaiting receipt confirmation · PHP {{ number_format((float) $payoutSummary['paid'], 2) }} confirmed</span>
        </article>
        <article class="lk-wallet-metric lk-logistics-wallet__metric lk-logistics-wallet__metric--available">
            <span class="lk-wallet-metric__label">Unpaid · available to request</span>
            <strong class="lk-wallet-metric__value">PHP {{ number_format((float) $payoutSummary['available'], 2) }}</strong>
            <span class="lk-logistics-wallet__metric-detail">Gross amount to request, including Rider pass-through</span>
        </article>
        <article class="lk-wallet-metric lk-logistics-wallet__metric">
            <span class="lk-wallet-metric__label">Unpaid · requested from Admin</span>
            <strong class="lk-wallet-metric__value">PHP {{ number_format((float) $payoutSummary['requested'], 2) }}</strong>
            <span class="lk-logistics-wallet__metric-detail">Gross transfer requested from Admin</span>
        </article>
    </section>

    <section class="payout-section rounded-xl border border-line bg-surface lk-logistics-wallet__destination">
        <header class="payout-section-head"><div><span class="payout-overline">Payout destination</span><h2 class="font-semibold">Payment details</h2><p>Admin sends your payout to this saved destination outside Likhae. Reference and proof are recorded here after transfer.</p></div>@if($payoutDestinationSaved)<span class="payout-saved-badge"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Destination saved</span>@endif</header>
        <div class="payout-section-body">
        @if($payoutDestinationSaved)
            @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($center))
            <dl class="payout-summary">
                <div><dt class="text-muted">Channel</dt><dd class="font-semibold text-ink">{{ $center->payout_method }}@if($center->payout_bank_name) · {{ $center->payout_bank_name }}@endif</dd></div>
                <div><dt class="text-muted">Account holder</dt><dd class="font-semibold text-ink">{{ $center->payout_account_name }}</dd></div>
                <div><dt class="text-muted">{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd class="font-semibold text-ink">{{ \App\Support\PayoutAccountDetails::formatNumber($center->payout_account_number, $savedPayoutType) }}</dd></div>
            </dl>
        @else
            <p class="lk-logistics-wallet__destination-notice">Add GCash, Maya, or bank details in Account settings before requesting a payout.</p>
        @endif
        <div class="lk-wallet-actions lk-wallet-actions--inline"><a href="{{ route('logistics.profile') }}" class="lk-logistics-wallet__edit-destination">Edit payment details in Account settings</a></div>
        </div>
    </section>

    @unless($ready)
        <section class="lk-wallet-panel">Parcel payout requests will appear after the reviewed finance migrations are installed.</section>
    @else
        <section class="lk-wallet-panel">
            <header class="lk-wallet-panel__head"><span class="lk-logistics-wallet__section-kicker">Parcel earnings</span><h2>Delivery charge transfers</h2><p class="lk-wallet-panel__description">After full COD verification, request this center's route share. Destination transfers also include the reserved Rider pool.</p></header>
            <div class="mt-4 grid gap-3">
                @forelse($pools as $pool)
                    @php($payoutBreakdown = $pool->payoutBreakdown())
                    @php($grossDeliveryAmount = $payoutBreakdown['transfer_cents'] / 100)
                    @php($riderPassThrough = $payoutBreakdown['rider_reserve_cents'] / 100)
                    <article class="lk-wallet-record lk-logistics-wallet__share">
                        <div class="lk-logistics-wallet__share-copy">
                            <span class="lk-logistics-wallet__record-kicker">Delivery earnings · Logistics share</span>
                            <strong class="lk-logistics-wallet__tracking">{{ $pool->shipment?->tracking_number ?? 'Parcel' }}</strong>
                            <span class="lk-logistics-wallet__order">Route allocation: {{ $payoutBreakdown['role'] }}</span>
                            <span class="lk-logistics-wallet__order">Order {{ $pool->shipment?->sellerOrder?->order?->order_number ?? '—' }}</span>
                            <span class="lk-logistics-wallet__order">Base shipping: PHP {{ number_format((float) ($pool->allocation?->shipping_fee ?? 0), 2) }}</span>
                            <span class="lk-logistics-wallet__order">Additional Care: PHP {{ number_format((float) ($pool->allocation?->protection_amount ?? 0), 2) }}</span>
                            <span class="lk-logistics-wallet__order">Combined eligible delivery earnings: PHP {{ number_format((float) ($pool->allocation?->shipping_fee ?? 0) + (float) ($pool->allocation?->protection_amount ?? 0), 2) }}</span>
                            <strong class="lk-logistics-wallet__share-amount">Logistics keeps PHP {{ number_format((float) $pool->total_amount, 2) }}</strong>
                            <span class="lk-logistics-wallet__order">Rider pass-through: PHP {{ number_format($riderPassThrough, 2) }}</span>
                            <span class="lk-logistics-wallet__order">Gross Admin transfer: PHP {{ number_format($grossDeliveryAmount, 2) }} · {{ str($pool->status)->replace('_', ' ')->headline() }}</span>
                        </div>
                        <div class="lk-logistics-wallet__share-action">
                            @if($pool->status === 'PAID')
                                <span class="lk-logistics-wallet__status lk-logistics-wallet__status--paid">Transfer received and confirmed</span>
                            @elseif($pool->status === 'PAYOUT_REQUESTED')
                                <span class="lk-logistics-wallet__status lk-logistics-wallet__status--waiting">Awaiting Admin transfer</span>
                            @elseif($pool->status === 'REVIEW_REQUIRED')
                                <span class="lk-logistics-wallet__status lk-logistics-wallet__status--waiting">Policy reallocation outstanding · held for review</span>
                                @if(filled($pool->notes))
                                    <span class="lk-logistics-wallet__save-reminder">{{ $pool->notes }}</span>
                                @endif
                            @else
                                <span class="lk-logistics-wallet__status lk-logistics-wallet__status--pending">{{ str($pool->status)->replace('_', ' ')->headline() }}</span>
                                @if($payoutDestinationSaved)
                                    <form method="POST" action="{{ route('logistics.wallet.payout-requests.store', $pool) }}" data-confirm-title="Request full delivery charges?" data-confirm-message="Request the gross transfer of PHP {{ number_format($grossDeliveryAmount, 2) }} for parcel {{ $pool->shipment?->tracking_number }}. This includes PHP {{ number_format($riderPassThrough, 2) }} to pay the Rider." data-confirm-label="Request transfer">
                                        @csrf<button class="bg-primary font-semibold text-white">Request payout</button>
                                    </form>
                                @else
                                    <span class="lk-logistics-wallet__save-reminder">Save payout details above to request</span>
                                @endif
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="lk-logistics-wallet__empty">
                        <span class="lk-logistics-wallet__empty-icon" aria-hidden="true">₱</span>
                        <div><strong>No shipping shares ready yet</strong><p>Reconciled parcel proceeds will appear here when they are available to request.</p></div>
                    </div>
                @endforelse
            </div>
            {{ $pools->links() }}
        </section>

        <section class="lk-wallet-panel">
            <header class="lk-wallet-panel__head"><span class="lk-logistics-wallet__section-kicker">Transfer history</span><h2>Admin payout receipts</h2><p class="lk-wallet-panel__description">Review transfer proof and confirm each payout after the funds reach your saved destination. Confirmed receipts stay in this history.</p></header>
            <div class="mt-4 grid gap-3">
                @forelse($receipts as $receipt)
                    @php($payout = (array) $receipt->metadata)
                    <article class="lk-wallet-record lk-logistics-wallet__receipt">
                        <div class="lk-logistics-wallet__receipt-head">
                            <div class="lk-logistics-wallet__receipt-identity">
                                <span class="lk-logistics-wallet__record-kicker">Payout reference</span>
                                <strong>{{ $payout['payout_reference'] ?? 'Logistics payout' }}</strong>
                                <span class="lk-logistics-wallet__order">Parcel {{ $receipt->shipment?->tracking_number ?? '—' }}</span>
                                @if(isset($payout['logistics_net_share'], $payout['rider_pass_through']))
                                    <span class="lk-logistics-wallet__order">Logistics share PHP {{ number_format((float) $payout['logistics_net_share'], 2) }} · Rider pass-through PHP {{ number_format((float) $payout['rider_pass_through'], 2) }}</span>
                                @endif
                            </div>
                            <div class="lk-logistics-wallet__receipt-total">
                                <strong>PHP {{ number_format((float) $receipt->amount, 2) }}</strong>
                                @if(($payout['is_simulated'] ?? false) || \Illuminate\Support\Str::startsWith((string) ($payout['payout_external_reference'] ?? ''), 'SIM-'))
                                    <span class="lk-logistics-wallet__status lk-logistics-wallet__status--waiting">Demo only · not Paid</span>
                                @elseif($receipt->status === 'PAYOUT_PENDING_ACK')
                                    <span class="lk-logistics-wallet__status lk-logistics-wallet__status--waiting">Confirmation needed</span>
                                @else
                                    <span class="lk-logistics-wallet__status lk-logistics-wallet__status--paid">Confirmed</span>
                                @endif
                            </div>
                        </div>
                        @if(!empty($payout['payout_method']) || !empty($payout['payout_external_reference']) || !empty($payout['paid_at']))
                            <dl class="lk-logistics-wallet__receipt-details">
                                @if(!empty($payout['payout_method']))<div><dt>Sent to</dt><dd>{{ $payout['payout_method'] }}</dd></div>@endif
                                @if(!empty($payout['payout_external_reference']))<div><dt>Transfer reference</dt><dd>{{ $payout['payout_external_reference'] }}</dd></div>@endif
                                @if(!empty($payout['paid_at']))<div><dt>Transfer date</dt><dd>{{ $payout['paid_at'] }}</dd></div>@endif
                            </dl>
                        @endif
                        @if(!empty($payout['payout_proof_path']))<a class="lk-logistics-wallet__proof-link" href="{{ route('logistics.wallet.payout-proof', $receipt) }}">View transfer proof <span aria-hidden="true">↗</span></a>@endif
                        @if(($payout['is_simulated'] ?? false) || \Illuminate\Support\Str::startsWith((string) ($payout['payout_external_reference'] ?? ''), 'SIM-'))
                            <p class="lk-logistics-wallet__legacy-note">Legacy demo receipt — no transfer proof exists. This record is excluded from Paid wallet totals.</p>
                        @elseif($receipt->status === 'PAYOUT_PENDING_ACK')
                            <form method="POST" action="{{ route('logistics.remittances.logistics-payouts.acknowledge', $receipt) }}" class="lk-logistics-wallet__acknowledge">
                                @csrf
                                <label>Amount received<input name="amount_received" type="number" min="0" step="0.01" value="{{ number_format((float) $receipt->amount, 2, '.', '') }}" required></label>
                                <label class="lk-logistics-wallet__notes-field">Receipt notes<input name="notes" maxlength="1000"></label>
                                <button class="bg-primary font-semibold text-white">Acknowledge receipt</button>
                            </form>
                            <form method="POST" action="{{ route('logistics.wallet.payout-report-issue', $receipt) }}" class="lk-logistics-wallet__report-issue">
                                @csrf<button>Report payout not received</button>
                            </form>
                        @else
                            <p class="lk-logistics-wallet__confirmed-note">Receipt confirmed {{ $payout['acknowledged_at'] ?? '' }}@if(!empty($payout['receipt_notes'])) · {{ $payout['receipt_notes'] }}@endif</p>
                        @endif
                    </article>
                @empty
                    <div class="lk-logistics-wallet__empty">
                        <span class="lk-logistics-wallet__empty-icon" aria-hidden="true">✓</span>
                        <div><strong>No payout receipts yet</strong><p>Admin transfer references and proof will appear here after a payout is sent.</p></div>
                    </div>
                @endforelse
            </div>
            {{ $receipts->links() }}
        </section>
    @endunless
</div>
@endsection
