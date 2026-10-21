@extends('layouts.logistics.app')
@section('title', 'COD Remittance Verification - LIKHAE Logistics')
@section('content')
@unless($schemaReady)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-800">COD remittance verification is waiting for the additive finance tables. Existing logistics workflows remain available.</div>
@endunless

<div class="flex flex-col gap-6">
    <section>
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Cash Reconciliation</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold text-ink">Rider Remittances</h1>
        <p class="mt-2 text-[11px] leading-6 text-muted">Acknowledge each parcel separately. A cash difference stays visible and blocks later settlement.</p>
    </section>
    @if(session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
    @endif
    <section class="rounded-xl border border-line bg-surface p-6">
        <div class="grid gap-4">
            @forelse($remittances as $remittance)
                @php($parcel = $remittance->items->first()?->codCollection?->shipment)
                @php($collection = $remittance->items->first()?->codCollection)
                <article class="rounded-lg border border-line p-4">
                    <div class="flex flex-wrap justify-between gap-3 text-[10px]">
                        <strong>{{ $remittance->remittance_reference }} · {{ $remittance->riderProfile?->user?->name }}</strong>
                        <span class="font-semibold">{{ str($remittance->status)->replace('_', ' ')->headline() }}</span>
                    </div>
                    <dl class="mt-3 grid gap-2 text-[10px] sm:grid-cols-2">
                        <div><dt class="text-muted">Parcel / order</dt><dd class="font-semibold">{{ $parcel?->tracking_number ?? 'Unavailable' }} · {{ $collection?->order?->order_number ?? 'Order unavailable' }}</dd></div>
                        <div><dt class="text-muted">Collection reference</dt><dd class="font-semibold">{{ $collection?->collection_reference ?? 'Unavailable' }}</dd></div>
                        <div><dt class="text-muted">Expected from rider</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->expected_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Cash handed over</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->submitted_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Cash received</dt><dd class="font-semibold">{{ $remittance->verified_amount === null ? 'Awaiting your confirmation' : 'PHP '.number_format((float) $remittance->verified_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Sender / recipient</dt><dd class="font-semibold">{{ $remittance->riderProfile?->user?->name ?? 'Rider' }} → {{ $center->business_name }}</dd></div>
                        <div><dt class="text-muted">Submitted</dt><dd class="font-semibold">{{ $remittance->submitted_at?->format('M d, Y g:i A') ?? '—' }}</dd></div>
                        @if($remittance->verified_at)<div><dt class="text-muted">Acknowledged</dt><dd class="font-semibold">{{ $remittance->verified_at->format('M d, Y g:i A') }}</dd></div>@endif
                        @if((float) $remittance->discrepancy_amount > 0)<div class="text-amber-800"><dt>Discrepancy</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->discrepancy_amount, 2) }} · {{ $remittance->status === 'DISCREPANCY' ? 'Unresolved' : 'Awaiting reconciliation' }}</dd></div>@endif
                    </dl>
                    @if($collection?->status === \App\Models\Finance\CodCollection::REMITTED_DISCREPANCY)
                        <p class="mt-3 rounded-lg bg-amber-50 p-3 text-[10px] font-semibold text-amber-800">The buyer paid an amount different from the parcel COD expected. This collection discrepancy remains open.</p>
                    @endif
                    @if($remittance->status === 'SUBMITTED')
                        <form method="POST" action="{{ route('logistics.remittances.verify', $remittance) }}" class="mt-3 flex flex-wrap gap-2" data-confirm-title="Acknowledge parcel cash?" data-confirm-message="Enter the amount physically received. A difference will remain open and will not advance to settlement." data-confirm-label="Acknowledge cash">
                            @csrf
                            <label class="grid gap-1 text-[10px] font-semibold">Amount physically received<input name="verified_amount" type="number" min="0" step="0.01" required placeholder="Received cash" class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid gap-1 text-[10px] font-semibold">Notes<input name="notes" maxlength="1000" placeholder="Receipt notes" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="self-end rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Confirm amount</button>
                        </form>
                    @elseif($remittance->status === 'VERIFIED')
                        <form method="POST" action="{{ route('logistics.remittances.reconcile', $remittance) }}" class="mt-3" data-confirm-title="Reconcile parcel receipt?" data-confirm-message="Finalize this parcel receipt. Any difference from the rider's recorded cash remains unresolved and blocks onward settlement." data-confirm-label="Reconcile receipt">
                            @csrf
                            <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Reconcile receipt</button>
                        </form>
                    @elseif($remittance->status === 'DISCREPANCY')
                        <form method="POST" action="{{ route('logistics.remittances.resolve', $remittance) }}" class="mt-3 flex flex-wrap gap-2" data-confirm-title="Resolve this cash discrepancy?" data-confirm-message="Confirm the final cash received equals the expected amount and explain how the difference was resolved." data-confirm-label="Resolve discrepancy">
                            @csrf
                            <label class="grid gap-1 text-[10px] font-semibold">Final cash received<input name="final_received_amount" type="number" min="0" step="0.01" value="{{ number_format((float) $remittance->expected_amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid min-w-56 flex-1 gap-1 text-[10px] font-semibold">Resolution notes<input name="resolution" maxlength="2000" required placeholder="Explain the discrepancy resolution" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="self-end rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Resolve</button>
                        </form>
                    @endif
                </article>
            @empty
                <p class="text-[10px] text-muted">No remittances are waiting for this center.</p>
            @endforelse
        </div>
        {{ $remittances->links() }}
    </section>
    @if($parcelSettlementReady)
        <section class="rounded-xl border border-line bg-surface p-6">
            <h2 class="font-display text-xl font-semibold text-ink">COD proceeds and Logistics share to Admin</h2>
            <p class="mt-1 text-[11px] text-muted">This handoff is product proceeds plus the Logistics share of shipping. The Rider share is paid separately by Logistics. Submit after the Rider cash receipt is reconciled.</p>
            <div class="mt-4 grid gap-4">
                @forelse($productProceeds as $entry)
                    @php($parcel = $entry->shipment)
                    <article class="rounded-lg border border-line p-4">
                        <div class="flex flex-wrap justify-between gap-3 text-[10px]"><strong>{{ $entry->metadata['receipt_reference'] ?? 'Parcel '.$parcel?->tracking_number }} Â· {{ $parcel?->sellerOrder?->order?->order_number ?? 'Order unavailable' }}</strong><span>{{ str($entry->status)->replace('_', ' ')->headline() }}</span></div>
                        <dl class="mt-3 grid gap-2 text-[10px] sm:grid-cols-3">
                            <div><dt class="text-muted">Amount to Admin</dt><dd class="font-semibold">PHP {{ number_format((float) $entry->amount, 2) }}</dd><small>Product PHP {{ number_format((float) $entry->allocation?->product_amount, 2) }} + Logistics shipping share PHP {{ number_format((float) $entry->allocation?->logistics_allocation, 2) }}</small></div>
                            <div><dt class="text-muted">Parcel</dt><dd class="font-semibold">{{ $parcel?->tracking_number ?? 'Unavailable' }}</dd></div>
                            <div><dt class="text-muted">Rider shipping share paid by Logistics</dt><dd class="font-semibold">PHP {{ number_format((float) $entry->allocation?->rider_allocation, 2) }}</dd></div>
                        </dl>
                        @if($entry->status === 'PENDING')
                            <form method="POST" action="{{ route('logistics.product-proceeds.submit', $entry) }}" class="mt-3 flex flex-wrap items-end gap-2" data-confirm-title="Remit product proceeds to Admin?" data-confirm-message="Record the product proceeds handoff for this parcel. Shipping remains settled in the separate rider and Logistics pools." data-confirm-label="Submit handoff">
                                @csrf
                                <label class="grid gap-1 text-[10px] font-semibold">Amount handed over<input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                                <label class="grid gap-1 text-[10px] font-semibold">Handoff method<select name="handoff_method" class="rounded-lg border border-line px-3 py-2"><option value="Manual cash handoff">Manual cash handoff</option><option value="Online">Online</option></select></label>
                                <label class="grid gap-1 text-[10px] font-semibold">Transfer reference<input name="handoff_external_reference" maxlength="150" class="rounded-lg border border-line px-3 py-2"></label>
                                <label class="grid min-w-56 flex-1 gap-1 text-[10px] font-semibold">Notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                                <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Submit to Admin</button>
                            </form>
                        @else
                            <p class="mt-3 text-[10px] text-muted">Submitted {{ $entry->metadata['submitted_at'] ?? '—' }} Â· received {{ isset($entry->metadata['amount_received']) ? 'PHP '.number_format((float) $entry->metadata['amount_received'], 2) : 'awaiting Admin confirmation' }} Â· discrepancy PHP {{ number_format((float) ($entry->metadata['discrepancy_amount'] ?? 0), 2) }}</p>
                        @endif
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No parcel product proceeds are waiting for handoff.</p>
                @endforelse
            </div>
            {{ $productProceeds->links() }}
        </section>
    @endif

    @if($parcelSettlementReady)
        <section id="rider-payout-requests" class="rounded-xl border border-line bg-surface p-6">
            <h2 class="font-display text-xl font-semibold text-ink">Rider payout requests</h2>
            <p class="mt-1 text-[11px] text-muted">Record the exact allocated Rider share. Online channels create demo references only; no money is transferred.</p>
            <div class="mt-4 grid gap-4">
                @forelse($riderPayoutRequests as $entry)
                    @php($payout = (array) $entry->metadata)
                    @php($riderProfile = $riderPayoutParticipants->get((int) ($payout['participant_id'] ?? 0))?->riderProfile)
                    <article class="rounded-lg border border-line p-4">
                        <div class="flex flex-wrap justify-between gap-3 text-[10px]"><strong>{{ $payout['payout_reference'] ?? 'Rider request' }} · {{ $entry->shipment?->tracking_number ?? 'Parcel' }}</strong><span>{{ $entry->actor?->name ?? 'Rider' }} · PHP {{ number_format((float) $entry->amount, 2) }}</span></div>
                        <p class="mt-2 text-[10px] text-muted">Payout to: {{ $riderProfile?->payout_method ?? 'Details not saved' }}@if($riderProfile?->payout_bank_name) · {{ $riderProfile->payout_bank_name }}@endif · {{ $riderProfile?->payout_account_name ?? '—' }} · {{ $riderProfile?->payout_account_number ?? '—' }}</p>
                        @if($payout['is_simulated'] ?? false)<p class="mt-2 text-[10px] font-semibold text-amber-700">SIMULATED DEMO ONLY — no money was transferred.</p>@endif
                        <form method="POST" action="{{ route('logistics.remittances.rider-payouts.record', $entry) }}" class="mt-3 flex flex-wrap items-end gap-2">
                            @csrf
                            <label class="grid gap-1 text-[10px] font-semibold">Amount<input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid gap-1 text-[10px] font-semibold">Method<select name="payout_method" required class="rounded-lg border border-line px-3 py-2"><option value="">Select payout method</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Manual', 'Other'] as $method)<option value="{{ $method }}" @selected($riderProfile?->payout_method === $method)>{{ $method }}{{ $method !== 'Manual' ? ' (simulated)' : '' }}</option>@endforeach</select></label>
                            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold">Manual reference (online demo generates one)<input name="payout_external_reference" maxlength="150" class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold">Notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Record / simulate payout</button>
                        </form>
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No Rider payout requests are waiting for this center.</p>
                @endforelse
            </div>
            {{ $riderPayoutRequests->links() }}
        </section>

        <section class="rounded-xl border border-line bg-surface p-6">
            <h2 class="font-display text-xl font-semibold text-ink">Logistics share receipts</h2>
            <p class="mt-1 text-[11px] text-muted">Acknowledge the Logistics shipping share paid by Admin.</p>
            <div class="mt-4 grid gap-4">
                @forelse($logisticsPayoutReceipts as $entry)
                    @php($payout = (array) $entry->metadata)
                    <article class="rounded-lg border border-line p-4">
                        <div class="flex flex-wrap justify-between gap-3 text-[10px]"><strong>{{ $payout['payout_reference'] ?? 'Logistics payout' }} · {{ $entry->shipment?->tracking_number ?? 'Parcel' }}</strong><span>PHP {{ number_format((float) $entry->amount, 2) }}</span></div>
                        <p class="mt-2 text-[10px] text-muted">{{ $payout['payout_method'] ?? '' }} {{ $payout['payout_external_reference'] ?? '' }} · Sent {{ $payout['paid_at'] ?? '' }}</p>
                        @if($payout['is_simulated'] ?? false)<p class="mt-2 text-[10px] font-semibold text-amber-700">SIMULATED DEMO ONLY — no money was transferred.</p>@endif
                        <form method="POST" action="{{ route('logistics.remittances.logistics-payouts.acknowledge', $entry) }}" class="mt-3 flex flex-wrap items-end gap-2">
                            @csrf
                            <label class="grid gap-1 text-[10px] font-semibold">Amount received<input name="amount_received" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold">Receipt notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Acknowledge receipt</button>
                        </form>
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No Logistics payouts are awaiting acknowledgment.</p>
                @endforelse
            </div>
            {{ $logisticsPayoutReceipts->links() }}
        </section>
    @endif
</div>
@endsection
