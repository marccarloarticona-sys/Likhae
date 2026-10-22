@extends('layouts.seller.app')
@section('title', 'Wallet & payouts')
@section('active', 'payouts')
@section('subtitle', 'Review your seller payable balance, request payouts, and acknowledge receipts.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar">
        <div><span class="sl-eyebrow">Seller wallet</span><h2>Wallet &amp; payout history</h2><p>Request payout for eligible parcel proceeds. Admin transfers funds outside Likhae, then records the reference and proof here.</p></div>
        <div class="flex flex-wrap gap-2"><a href="{{ route('seller.messages') }}" class="sl-btn sl-btn-ghost">Contact Support</a><a href="{{ route('seller.dashboard') }}" class="sl-btn sl-btn-ghost">Dashboard</a></div>
    </div>
    @if($ready)
        <section class="sl-card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px">
            @foreach([['Available to request', 'available'], ['Requested from Admin', 'requested'], ['Awaiting receipt confirmation', 'awaiting_ack'], ['Paid', 'paid']] as [$label, $key])
                <div><small style="display:block;color:var(--sl-muted);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">{{ $label }}</small><strong style="display:block;margin-top:7px;font-size:20px;color:var(--sl-ink)">₱{{ number_format((float) $payoutBalances[$key], 2) }}</strong></div>
            @endforeach
        </section>
    @endif
    <section class="sl-card">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Payout destination</span><h2>Payment details</h2><p>Admin sends your payout to this saved destination outside Likhae. Reference and proof are recorded here after transfer.</p></div></header>
        @if($payoutDestinationSaved)
            @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($seller))
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt class="text-xs text-muted">Channel</dt><dd class="font-semibold">{{ $seller->payout_method }}@if($seller->payout_bank_name) · {{ $seller->payout_bank_name }}@endif</dd></div>
                <div><dt class="text-xs text-muted">Account holder</dt><dd class="font-semibold">{{ $seller->payout_account_name }}</dd></div>
                <div><dt class="text-xs text-muted">{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd class="font-semibold">{{ \App\Support\PayoutAccountDetails::formatNumber($seller->payout_account_number, $savedPayoutType) }}</dd></div>
            </dl>
        @else
            <p class="sl-empty-state">Add GCash, Maya, or bank details in Seller Account before requesting a payout.</p>
        @endif
        <a href="{{ route('seller.account', ['tab' => 'payouts']) }}" class="sl-btn sl-btn-ghost mt-3 inline-flex">Edit payment details in Account Settings</a>
    </section>
    @unless($ready)
        <section class="sl-card"><p class="sl-empty-state">Parcel payout receipts will appear after the finance receipt migration is installed.</p></section>
    @else
        <section class="sl-card">
            <div class="sl-table-wrap">
                <table class="sl-table">
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
                            <td>{{ $settlement->paid_at?->format('M d, Y g:i A') ?: '—' }}<br><small>{{ $settlement->seller_acknowledged_at?->format('M d, Y g:i A') ?: 'Awaiting acknowledgment' }}</small></td>
                            <td>
                                @if(in_array($settlement->payout_status, ['PENDING', 'FAILED'], true) && $settlement->seller_payable !== null && (float) $settlement->seller_payable > 0 && $payoutDestinationSaved)
                                    <form method="POST" action="{{ route('seller.settlements.request-payout', $settlement) }}" data-confirm-title="Request this payout?" data-confirm-message="Send a payout request for ₱{{ number_format((float) $settlement->seller_payable, 2) }} for parcel {{ $settlement->allocation?->shipment?->tracking_number }} to Admin for review." data-confirm-label="Request payout">
                                        @csrf<button class="sl-btn sl-btn-primary">Request payout</button>
                                    </form>
                                @elseif($settlement->payout_status === 'PAYOUT_REQUESTED')
                                    <span>Awaiting Admin review</span>
                                @elseif($settlement->payout_status === 'AUTHORIZED')
                                    <span>Approved · awaiting payout</span>
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
