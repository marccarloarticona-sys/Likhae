@extends('layouts.seller.app')
@section('title', 'Wallet & payouts')
@section('active', 'payouts')
@section('subtitle', 'Seller proceeds become requestable after the parent order is Completed, finance review is cleared, and Admin verifies full COD remittance. Review balances and confirm verified transfers here.')

@section('content')
<div class="sl-page lk-wallet-page">
    @if(session('status'))<div class="lk-wallet-alert lk-wallet-alert--success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="lk-wallet-alert lk-wallet-alert--error" role="alert">{{ $errors->first() }}</div>@endif
    <div class="sl-page-toolbar lk-wallet-header">
        <div class="lk-wallet-header__copy"><span class="sl-eyebrow">Seller wallet</span><h2>Wallet &amp; payout history</h2><p>Your paid-out balance includes transfers recorded by Admin, whether awaiting your receipt confirmation or already confirmed. Eligible and requested proceeds remain unpaid.</p></div>
        <div class="lk-wallet-actions"><a href="{{ route('seller.messages') }}" class="sl-btn sl-btn-ghost">Contact Support</a><a href="{{ route('seller.dashboard') }}" class="sl-btn sl-btn-ghost">Dashboard</a></div>
    </div>
    @if($ready)
        @if((float) $payoutBalances['available'] > 0 || (float) $payoutBalances['on_hold'] > 0 || (float) $payoutBalances['processing'] > 0 || (float) $payoutBalances['requested'] > 0 || (float) $payoutBalances['paid_out'] > 0)
        <section class="sl-card lk-wallet-metrics lk-wallet-metrics--compact" aria-label="Payout balance summary">
            @if((float) $payoutBalances['available'] > 0)
                <article class="lk-wallet-metric lk-wallet-metric--available"><span class="lk-wallet-metric__label">Available balance</span><strong class="lk-wallet-metric__value">₱{{ number_format((float) $payoutBalances['available'], 2) }}</strong><small>Ready to cash out for eligible parcels below.@if((float) $payoutBalances['refund_debt'] > 0) Unapplied approved refund balance: ₱{{ number_format((float) $payoutBalances['refund_debt'], 2) }}@endif</small></article>
            @endif
            @if((float) $payoutBalances['on_hold'] > 0)
                <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">On hold</span><strong class="lk-wallet-metric__value">₱{{ number_format((float) $payoutBalances['on_hold'], 2) }}</strong><small>Not yet available to cash out</small></article>
            @endif
            @if((float) $payoutBalances['processing'] > 0)
                <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">Payout processing</span><strong class="lk-wallet-metric__value">₱{{ number_format((float) $payoutBalances['processing'], 2) }}</strong><small>Admin-authorized · expected in 3–5 business days</small></article>
            @endif
            @if((float) $payoutBalances['requested'] > 0)
                <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">Payout request pending</span><strong class="lk-wallet-metric__value">₱{{ number_format((float) $payoutBalances['requested'], 2) }}</strong></article>
            @endif
            @if((float) $payoutBalances['paid_out'] > 0)
                <article class="lk-wallet-metric"><span class="lk-wallet-metric__label">Paid-out balance</span><strong class="lk-wallet-metric__value">₱{{ number_format((float) $payoutBalances['paid_out'], 2) }}</strong><small>₱{{ number_format((float) $payoutBalances['awaiting_ack'], 2) }} awaiting confirmation · ₱{{ number_format((float) $payoutBalances['paid'], 2) }} confirmed</small></article>
            @endif
        </section>
        @endif
    @endif
    <section class="sl-card payout-section">
        <header class="sl-card-head payout-section-head"><div><span class="payout-overline">Payout destination</span><h2>Payment details</h2><p>Admin sends your payout to this saved destination outside Likhae. Reference and proof are recorded here after transfer.</p></div>@if($payoutDestinationSaved)<span class="payout-saved-badge"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Destination saved</span>@endif</header>
        <div class="payout-section-body">
        @if($payoutDestinationSaved)
            @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($seller))
            <dl class="payout-summary">
                <div><dt class="text-xs text-muted">Channel</dt><dd class="font-semibold">{{ $seller->payout_method }}@if($seller->payout_bank_name) · {{ $seller->payout_bank_name }}@endif</dd></div>
                <div><dt class="text-xs text-muted">Account holder</dt><dd class="font-semibold">{{ $seller->payout_account_name }}</dd></div>
                <div><dt class="text-xs text-muted">{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd class="font-semibold">{{ \App\Support\PayoutAccountDetails::formatNumber($seller->payout_account_number, $savedPayoutType) }}</dd></div>
            </dl>
        @else
            <p class="sl-empty-state">Add GCash, Maya, or bank details in Seller Account before requesting a payout.</p>
        @endif
        <a href="{{ route('seller.account', ['tab' => 'payouts']) }}" class="sl-btn sl-btn-ghost mt-3 inline-flex">Edit payment details in Account Settings</a>
        </div>
    </section>
    @unless($ready)
        <section class="sl-card lk-wallet-panel"><p class="sl-empty-state">Parcel payout receipts will appear after the finance receipt migration is installed.</p></section>
    @else
        <section class="sl-card lk-wallet-panel">
            <p class="lk-wallet-table-hint">Scroll horizontally to see all payout details.</p>
            <div class="sl-table-wrap lk-wallet-table-clip" role="region" aria-label="Parcel payout history table" tabindex="0">
                <table class="sl-table lk-wallet-table" aria-label="Parcel payout history">
                    <thead><tr><th>Receipt / status</th><th>Order</th><th>Parcel</th><th>Product amount</th><th>Platform fee</th><th>Seller payable / paid</th><th>Transfer channel / reference</th><th>Paid / acknowledged</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse($settlements as $settlement)
                        @php($isSimulatedPayout = \Illuminate\Support\Str::startsWith((string) $settlement->payout_external_reference, 'SIM-'))
                        <tr>
                            <td><strong>{{ $settlement->settlement_reference ?: 'Pending' }}</strong><br>{{ str($settlement->payout_status)->replace('_', ' ')->headline() }}
                                @if($settlement->payout_failure_reason)<br><small class="text-red-700">Failure: {{ $settlement->payout_failure_reason }}</small>@endif
                            </td>
                            <td>{{ $settlement->sellerOrder?->seller_order_number ?: $settlement->order_id }}</td>
                            <td>{{ $settlement->allocation?->shipment?->tracking_number ?: 'Parcel '.$settlement->allocation?->shipment_id }}</td>
                            <td>₱{{ number_format((float) ($settlement->allocation?->product_amount ?? 0), 2) }}</td>
                            <td>₱{{ number_format((float) $settlement->platform_commission, 2) }}</td>
                            <td>@if($settlement->seller_payable !== null)₱{{ number_format((float) $settlement->seller_payable, 2) }}@else Review required @endif
                                @if((float) $settlement->payout_amount > 0)<br><small>Paid: ₱{{ number_format((float) $settlement->payout_amount, 2) }}</small>@endif
                            </td>
                            <td>{{ $settlement->payout_method }}<br><small>{{ $settlement->payout_external_reference }}</small>@if($isSimulatedPayout)<br><small class="text-amber-700">Legacy demo record — no transfer proof</small>@elseif($settlement->payout_proof_path)<br><a href="{{ route('seller.settlements.payout-proof', $settlement) }}" class="underline">View transfer proof</a>@endif</td>
                            <td>{{ $settlement->paid_at?->format('M d, Y g:i A') ?: '—' }}@if($settlement->seller_acknowledged_at)<br><small>Receipt confirmed {{ $settlement->seller_acknowledged_at->format('M d, Y g:i A') }}</small>@elseif($settlement->payout_status === 'PAYOUT_PENDING_ACK')<br><small>Awaiting acknowledgment</small>@endif</td>
                            <td>
                                @if(in_array($settlement->payout_status, ['AVAILABLE', 'FAILED'], true) && $settlement->seller_payable !== null && (float) $settlement->seller_payable > 0 && $payoutDestinationSaved)
                                    <form method="POST" action="{{ route('seller.settlements.request-payout', $settlement) }}" data-confirm-title="Request this payout?" data-confirm-message="Send a payout request for ₱{{ number_format((float) $settlement->seller_payable, 2) }} for parcel {{ $settlement->allocation?->shipment?->tracking_number }} to Admin for review." data-confirm-label="Request payout">
                                        @csrf<button class="sl-btn sl-btn-primary">Request payout</button>
                                    </form>
                                @elseif(in_array($settlement->payout_status, ['AVAILABLE', 'FAILED'], true) && $settlement->seller_payable !== null && (float) $settlement->seller_payable > 0 && !$payoutDestinationSaved)
                                    <a href="{{ route('seller.account', ['tab' => 'payouts']) }}" class="sl-btn sl-btn-primary">Add payout details to cash out</a>
                                @elseif($settlement->payout_status === 'PAYOUT_REQUESTED')
                                    <span>Awaiting Admin review</span>
                                @elseif($settlement->payout_status === 'AUTHORIZED')
                                    <strong>Payout authorized</strong><br>
                                    <small>
                                        @if($settlement->authorized_at)
                                            @if($settlement->payout_business_days_min > 0)
                                                Expected in {{ $settlement->payout_business_days_min }}–{{ $settlement->payout_business_days_max }} business days
                                            @elseif($settlement->payout_business_days_max > 0)
                                                Expected today or within {{ $settlement->payout_business_days_max }} business days
                                            @else
                                                Expected by now; contact Admin if it has not arrived
                                            @endif
                                            <br>Authorized {{ $settlement->authorized_at->format('M d, Y') }}
                                        @else
                                            Expected in 3–5 business days
                                        @endif
                                    </small>
                                @elseif($settlement->payout_status === 'PAYOUT_PENDING_ACK')
                                    @if($isSimulatedPayout)
                                        <small class="text-amber-700">Legacy demo record. No transfer proof is available; contact Support.</small>
                                    @else
                                        <form method="POST" action="{{ route('seller.settlements.acknowledge', $settlement) }}" data-confirm-title="Confirm payout receipt?" data-confirm-message="Confirm that PHP {{ number_format((float) $settlement->payout_amount, 2) }} arrived for this parcel." data-confirm-label="Confirm receipt">
                                            @csrf<button class="sl-btn sl-btn-primary">Confirm receipt</button>
                                        </form>
                                        <form method="POST" action="{{ route('seller.settlements.report-payout-issue', $settlement) }}" class="mt-2">
                                            @csrf<button class="sl-btn sl-btn-ghost">Report payout not received</button>
                                        </form>
                                    @endif
                                @elseif($settlement->payout_status === 'PAID')
                                    <span>Received</span>
                                @elseif($settlement->payout_status === 'REVIEW_REQUIRED')
                                    <span>Waiting for Admin to verify the product discount before payout can be requested.</span>
                                @elseif(in_array($settlement->payout_status, ['ON_HOLD', 'PENDING'], true))
                                    <span>On hold until Buyer confirms Order Received and the parent order is completed.</span>
                                @else — @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9">No parcel payout records yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $settlements->links() }}
        </section>
    @endunless
</div>
@endsection
