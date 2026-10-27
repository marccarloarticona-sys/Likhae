@extends('layouts.logistics.app')
@section('title', 'COD Remittance Verification - LIKHAE Logistics')
@section('content')
@unless($schemaReady)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-800">COD remittance verification is waiting for the additive finance tables. Existing logistics workflows remain available.</div>
@endunless
@php
    $activeTab = request()->query('tab', 'rider-remittances');
    if (! in_array($activeTab, ['rider-remittances', 'cod-proceeds', 'payouts'], true)
        || (! $parcelSettlementReady && $activeTab !== 'rider-remittances')) {
        $activeTab = 'rider-remittances';
    }
@endphp

<div class="flex flex-col gap-6">
    @if(session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="flex flex-wrap gap-2 border-b border-line pb-3" role="tablist" aria-label="COD remittance sections" data-remittance-tabs>
        <button id="tab-rider-remittances" type="button" role="tab" aria-controls="panel-rider-remittances" aria-selected="{{ $activeTab === 'rider-remittances' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'rider-remittances' ? '0' : '-1' }}" data-remittance-tab="rider-remittances" class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'rider-remittances' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }}">Rider Remittances</button>
        <button id="tab-cod-proceeds" type="button" role="tab" aria-controls="panel-cod-proceeds" aria-selected="{{ $activeTab === 'cod-proceeds' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'cod-proceeds' ? '0' : '-1' }}" data-remittance-tab="cod-proceeds" @disabled(! $parcelSettlementReady) class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'cod-proceeds' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }} disabled:cursor-not-allowed disabled:opacity-50">COD Proceeds to Admin</button>
        <button id="tab-payouts" type="button" role="tab" aria-controls="panel-payouts" aria-selected="{{ $activeTab === 'payouts' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'payouts' ? '0' : '-1' }}" data-remittance-tab="payouts" @disabled(! $parcelSettlementReady) class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'payouts' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }} disabled:cursor-not-allowed disabled:opacity-50">Payouts</button>
    </div>
    @unless($parcelSettlementReady)
        <p class="-mt-4 text-xs text-muted">COD proceeds and payout tabs are unavailable until parcel settlement is ready.</p>
    @endunless

    <div id="panel-rider-remittances" role="tabpanel" aria-labelledby="tab-rider-remittances" tabindex="0" data-remittance-panel="rider-remittances" @if($activeTab !== 'rider-remittances') hidden @endif>
        <section class="mb-6">
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Cash Reconciliation</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold text-ink">Rider Remittances</h1>
            <p class="mt-2 text-[11px] leading-6 text-muted">Acknowledge each parcel separately. A cash difference stays visible and blocks later settlement.</p>
        </section>
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
        {{ $remittances->appends(request()->query())->links() }}
    </section>
    </div>
    <div id="panel-cod-proceeds" role="tabpanel" aria-labelledby="tab-cod-proceeds" tabindex="0" data-remittance-panel="cod-proceeds" @if($activeTab !== 'cod-proceeds') hidden @endif>
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
            {{ $productProceeds->appends(request()->query())->links() }}
        </section>
    @endif
    </div>

    <div id="panel-payouts" role="tabpanel" aria-labelledby="tab-payouts" tabindex="0" data-remittance-panel="payouts" @if($activeTab !== 'payouts') hidden @endif>
    @if($parcelSettlementReady)
        <section id="rider-payout-requests" class="rounded-xl border border-line bg-surface p-6">
            <h2 class="font-display text-xl font-semibold text-ink">Rider payout requests</h2>
            <p class="mt-1 text-[11px] text-muted">Record the exact allocated Rider share. Manual payouts require a transfer reference and proof. Online channels are demo only; no money is transferred.</p>
            <div class="mt-4 grid gap-4">
                @forelse($riderPayoutRequests as $entry)
                    @php($payout = (array) $entry->metadata)
                    @php($riderProfile = $riderPayoutParticipants->get((int) ($payout['participant_id'] ?? 0))?->riderProfile)
                    <article class="rounded-lg border border-line p-4">
                        <div class="flex flex-wrap justify-between gap-3 text-[10px]"><strong>{{ $payout['payout_reference'] ?? 'Rider request' }} · {{ $entry->shipment?->tracking_number ?? 'Parcel' }}</strong><span>{{ $entry->actor?->name ?? 'Rider' }} · PHP {{ number_format((float) $entry->amount, 2) }}</span></div>
                        <p class="mt-2 text-[10px] text-muted">Payout to: {{ $riderProfile?->payout_method ?? 'Details not saved' }}@if($riderProfile?->payout_bank_name) · {{ $riderProfile->payout_bank_name }}@endif · {{ $riderProfile?->payout_account_name ?? '—' }} · {{ $riderProfile?->payout_account_number ?? '—' }}</p>
                        @if($payout['is_simulated'] ?? false)<p class="mt-2 text-[10px] font-semibold text-amber-700">SIMULATED DEMO ONLY — no money was transferred.</p>@endif
                        @php($riderPayoutMethod = old('payout_method', $riderProfile?->payout_method))
                        <form method="POST" enctype="multipart/form-data" action="{{ route('logistics.remittances.rider-payouts.record', $entry) }}" class="mt-3 flex flex-wrap items-end gap-2" data-rider-payout-form>
                            @csrf
                            <label class="grid gap-1 text-[10px] font-semibold">Amount<input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                            <label class="grid gap-1 text-[10px] font-semibold">Method<select name="payout_method" required class="rounded-lg border border-line px-3 py-2" data-rider-payout-method><option value="">Select payout method</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Manual', 'Other'] as $method)<option value="{{ $method }}" @selected($riderPayoutMethod === $method)>{{ $method }}{{ $method !== 'Manual' ? ' (simulated)' : '' }}</option>@endforeach</select></label>
                            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold" data-rider-payout-reference-field>Manual transfer reference<input name="payout_external_reference" maxlength="150" class="rounded-lg border border-line px-3 py-2" data-rider-payout-reference></label>
                            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold" data-rider-payout-proof-field>Transfer proof (required for Manual payout)<input name="payout_proof" type="file" accept=".jpg,.jpeg,.png,.pdf" class="rounded-lg border border-line px-3 py-2" data-rider-payout-proof></label>
                            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold">Notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Record / simulate payout</button>
                        </form>
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No Rider payout requests are waiting for this center.</p>
                @endforelse
            </div>
            {{ $riderPayoutRequests->appends(request()->query())->links() }}
        </section>

        <section class="rounded-xl border border-line bg-surface p-6">
            <h2 class="font-display text-xl font-semibold text-ink">Logistics share receipts</h2>
            <p class="mt-1 text-[11px] text-muted">Review Admin transfer receipts and confirm payouts that have arrived. Confirmed receipts remain available here.</p>
            <div class="mt-4 grid gap-4">
                @forelse($logisticsPayoutReceipts as $entry)
                    @php($payout = (array) $entry->metadata)
                    <article class="rounded-lg border border-line p-4">
                        <div class="flex flex-wrap justify-between gap-3 text-[10px]"><strong>{{ $payout['payout_reference'] ?? 'Logistics payout' }} · {{ $entry->shipment?->tracking_number ?? 'Parcel' }}</strong><span>PHP {{ number_format((float) $entry->amount, 2) }}</span></div>
                        <p class="mt-2 text-[10px] text-muted">{{ $payout['payout_method'] ?? '' }} {{ $payout['payout_external_reference'] ?? '' }} · Sent {{ $payout['paid_at'] ?? '' }}</p>
                        @if(!empty($payout['payout_proof_path']))<a class="mt-2 inline-block text-[10px] underline" href="{{ route('logistics.wallet.payout-proof', $entry) }}">View transfer proof</a>@endif
                        @if(($payout['is_simulated'] ?? false) || \Illuminate\Support\Str::startsWith((string) ($payout['payout_external_reference'] ?? ''), 'SIM-'))
                            <p class="mt-2 text-[10px] font-semibold text-amber-700">Legacy demo receipt — no transfer proof exists. Contact Admin to correct this old record.</p>
                        @elseif($entry->status === 'PAYOUT_PENDING_ACK')
                            <form method="POST" action="{{ route('logistics.remittances.logistics-payouts.acknowledge', $entry) }}" class="mt-3 flex flex-wrap items-end gap-2">
                                @csrf
                                <label class="grid gap-1 text-[10px] font-semibold">Amount received<input name="amount_received" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
                                <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold">Receipt notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
                                <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Acknowledge receipt</button>
                            </form>
                        @else
                            <p class="mt-2 text-[10px] text-emerald-700">Receipt confirmed {{ $payout['acknowledged_at'] ?? '' }}@if(!empty($payout['receipt_notes'])) · {{ $payout['receipt_notes'] }}@endif</p>
                        @endif
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No Logistics payout receipts have been recorded yet.</p>
                @endforelse
            </div>
            {{ $logisticsPayoutReceipts->appends(request()->query())->links() }}
        </section>
    @endif
    </div>
</div>
<script>
    (() => {
        document.querySelectorAll('[data-rider-payout-form]').forEach((form) => {
            const method = form.querySelector('[data-rider-payout-method]');
            const reference = form.querySelector('[data-rider-payout-reference]');
            const referenceField = form.querySelector('[data-rider-payout-reference-field]');
            const proof = form.querySelector('[data-rider-payout-proof]');
            const proofField = form.querySelector('[data-rider-payout-proof-field]');
            if (!method || !reference || !proof) return;

            const updateManualFields = () => {
                const isManual = method.value === 'Manual';
                reference.required = isManual;
                reference.disabled = !isManual;
                proof.required = isManual;
                proof.disabled = !isManual;
                if (!isManual) proof.value = '';
                if (referenceField) referenceField.hidden = !isManual;
                if (proofField) proofField.hidden = !isManual;
            };

            method.addEventListener('change', updateManualFields);
            updateManualFields();
        });
    })();

    (() => {
        const tabList = document.querySelector('[data-remittance-tabs]');
        if (!tabList) return;

        const tabs = [...tabList.querySelectorAll('[data-remittance-tab]')];
        const panels = [...document.querySelectorAll('[data-remittance-panel]')];
        const activateTab = (tab, focus = false) => {
            if (!tab || tab.disabled) return;
            const selected = tab.dataset.remittanceTab;
            tabs.forEach((candidate) => {
                const active = candidate === tab;
                candidate.setAttribute('aria-selected', String(active));
                candidate.tabIndex = active ? 0 : -1;
                candidate.classList.toggle('border-primary', active);
                candidate.classList.toggle('bg-primary', active);
                candidate.classList.toggle('text-white', active);
                candidate.classList.toggle('border-line', !active);
                candidate.classList.toggle('bg-surface', !active);
                candidate.classList.toggle('text-ink', !active);
            });
            panels.forEach((panel) => { panel.hidden = panel.dataset.remittancePanel !== selected; });

            const url = new URL(window.location.href);
            url.searchParams.set('tab', selected);
            window.history.replaceState(null, '', url);
            if (focus) tab.focus();
        };

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => activateTab(tab));
            tab.addEventListener('keydown', (event) => {
                const enabledTabs = tabs.filter((candidate) => !candidate.disabled);
                const index = enabledTabs.indexOf(tab);
                const nextIndex = event.key === 'ArrowRight'
                    ? (index + 1) % enabledTabs.length
                    : event.key === 'ArrowLeft'
                        ? (index - 1 + enabledTabs.length) % enabledTabs.length
                        : event.key === 'Home'
                            ? 0
                            : event.key === 'End'
                                ? enabledTabs.length - 1
                                : -1;
                if (nextIndex < 0) return;
                event.preventDefault();
                activateTab(enabledTabs[nextIndex], true);
            });
        });
    })();
</script>
@endsection
