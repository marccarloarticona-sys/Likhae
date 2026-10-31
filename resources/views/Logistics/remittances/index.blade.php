@extends('layouts.logistics.app')
@section('title', 'COD Remittance Verification - LIKHAE Logistics')
@section('content')
@unless($schemaReady)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-800">COD remittance verification is waiting for the additive finance tables. Existing logistics workflows remain available.</div>
@endunless
@php
    $activeTab = request()->query('tab', 'rider-remittances');
    if (! in_array($activeTab, ['rider-remittances', 'cod-proceeds', 'rider-payouts', 'interhub-payouts', 'logistics-payouts'], true)
        || (! $parcelSettlementReady && ! in_array($activeTab, ['rider-remittances', 'interhub-payouts'], true))) {
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

    <div class="flex flex-wrap gap-2 border-b border-line pb-3" role="tablist" aria-label="Remittance and payout sections" data-remittance-tabs>
        <button id="tab-rider-remittances" type="button" role="tab" aria-controls="panel-rider-remittances" aria-selected="{{ $activeTab === 'rider-remittances' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'rider-remittances' ? '0' : '-1' }}" data-remittance-tab="rider-remittances" class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'rider-remittances' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }}">Rider Remittances</button>
        <button id="tab-cod-proceeds" type="button" role="tab" aria-controls="panel-cod-proceeds" aria-selected="{{ $activeTab === 'cod-proceeds' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'cod-proceeds' ? '0' : '-1' }}" data-remittance-tab="cod-proceeds" @disabled(! $parcelSettlementReady) class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'cod-proceeds' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }} disabled:cursor-not-allowed disabled:opacity-50">COD Proceeds to Admin</button>
        <button id="tab-rider-payouts" type="button" role="tab" aria-controls="panel-rider-payouts" aria-selected="{{ $activeTab === 'rider-payouts' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'rider-payouts' ? '0' : '-1' }}" data-remittance-tab="rider-payouts" @disabled(! $parcelSettlementReady) class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'rider-payouts' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }} disabled:cursor-not-allowed disabled:opacity-50">Rider Payouts</button>
        <button id="tab-interhub-payouts" type="button" role="tab" aria-controls="panel-interhub-payouts" aria-selected="{{ $activeTab === 'interhub-payouts' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'interhub-payouts' ? '0' : '-1' }}" data-remittance-tab="interhub-payouts" @disabled(! $timekeepingReady) class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'interhub-payouts' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }} disabled:cursor-not-allowed disabled:opacity-50">Interhub Payouts</button>
        <button id="tab-logistics-payouts" type="button" role="tab" aria-controls="panel-logistics-payouts" aria-selected="{{ $activeTab === 'logistics-payouts' ? 'true' : 'false' }}" tabindex="{{ $activeTab === 'logistics-payouts' ? '0' : '-1' }}" data-remittance-tab="logistics-payouts" @disabled(! $parcelSettlementReady) class="min-h-11 rounded-lg border px-4 py-2 text-sm font-semibold {{ $activeTab === 'logistics-payouts' ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink hover:bg-page-secondary' }} disabled:cursor-not-allowed disabled:opacity-50">Logistics Payouts</button>
    </div>
    @unless($parcelSettlementReady)
        <p class="-mt-4 text-xs text-muted">COD proceeds and Rider/Logistics payouts are unavailable until parcel settlement is ready. Interhub payroll is a separate timekeeping workflow.</p>
    @endunless

    <div id="panel-rider-remittances" role="tabpanel" aria-labelledby="tab-rider-remittances" tabindex="0" data-remittance-panel="rider-remittances" @if($activeTab !== 'rider-remittances') hidden @endif>
        <section class="mb-6">
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Cash Reconciliation</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold text-ink">Rider Remittances</h1>
            <p class="mt-2 text-[11px] leading-6 text-muted">Review parcel COD and any Rider-funded buyer shortfalls. A reconciled full-batch cash handoff can proceed to Admin; an unresolved batch cash difference stays blocked.</p>
        </section>
    <section class="rounded-xl border border-line bg-surface p-6">
        <div class="grid gap-4">
            @forelse($remittances as $remittance)
                @php
                    $remittanceItems = $remittance->items;
                    $parcelCodTotal = $remittanceItems->sum(fn ($item) => (float) ($item->codCollection?->expected_amount ?? 0));
                    $buyerCollectedTotal = $remittanceItems->sum(fn ($item) => (float) ($item->codCollection?->collected_amount ?? 0));
                    $riderTopUpCents = $remittanceItems->sum(fn ($item) => $item->codCollection?->status === \App\Models\Finance\CodCollection::REMITTED_RIDER_TOPUP
                        ? max(0, \App\Support\FinancialRules::cents($item->codCollection->expected_amount) - \App\Support\FinancialRules::cents($item->codCollection->collected_amount ?? 0))
                        : 0);
                @endphp
                <article class="rounded-lg border border-line p-4">
                    <dl class="grid gap-3 sm:grid-cols-4">
                        <div><dt class="text-[10px] text-muted">Rider</dt><dd class="mt-1 text-sm font-semibold text-ink">{{ $remittance->riderProfile?->user?->name ?? 'Rider' }}</dd></div>
                        <div><dt class="text-[10px] text-muted">Date remitted</dt><dd class="mt-1 text-sm font-semibold text-ink">{{ $remittance->submitted_at?->format('M d, Y g:i A') ?? '—' }}</dd></div>
                        <div><dt class="text-[10px] text-muted">Actual cash received</dt><dd class="mt-1 text-sm font-semibold text-ink">{{ $remittance->verified_amount === null ? 'Awaiting acknowledgment' : 'PHP '.number_format((float) $remittance->verified_amount, 2) }}</dd></div>
                        <div><dt class="text-[10px] text-muted">Parcels</dt><dd class="mt-1 text-sm font-semibold text-ink">{{ $remittanceItems->count() }} {{ str('parcel')->plural($remittanceItems->count()) }}</dd></div>
                    </dl>
                    <details class="mt-3 border-t border-line pt-3">
                        <summary class="cursor-pointer text-[11px] font-semibold text-primary">View details</summary>
                        <div class="mt-3">
                    <div class="flex flex-wrap justify-between gap-3 text-[10px]">
                        <strong>{{ $remittance->remittance_reference }} · Receipt RCPT-{{ $remittance->remittance_reference }} · {{ $remittance->riderProfile?->user?->name }}</strong>
                        <span class="font-semibold">{{ str($remittance->status)->replace('_', ' ')->headline() }}</span>
                    </div>
                    <div class="mt-3 overflow-x-auto rounded-lg border border-line">
                        <table class="w-full min-w-[680px] text-left text-[10px]">
                            <thead class="bg-page-secondary text-muted">
                                <tr>
                                    <th class="px-3 py-2 font-semibold">Parcel / order</th>
                                    <th class="px-3 py-2 font-semibold">COD collection</th>
                                    <th class="px-3 py-2 text-right font-semibold">COD due</th>
                                    <th class="px-3 py-2 text-right font-semibold">Cash collected</th>
                                    <th class="px-3 py-2 text-right font-semibold">Rider top-up</th>
                                    <th class="px-3 py-2 font-semibold">Collection status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @forelse($remittanceItems as $item)
                                    @php($itemCollection = $item->codCollection)
                                    <tr>
                                        <td class="px-3 py-2 font-semibold">{{ $itemCollection?->shipment?->tracking_number ?? 'Parcel unavailable' }}<div class="mt-1 font-normal text-muted">{{ $itemCollection?->order?->order_number ?? 'Order unavailable' }}</div></td>
                                        <td class="px-3 py-2">{{ $itemCollection?->collection_reference ?? 'Unavailable' }}</td>
                                        <td class="px-3 py-2 text-right font-semibold">PHP {{ number_format((float) ($itemCollection?->expected_amount ?? 0), 2) }}</td>
                                        <td class="px-3 py-2 text-right">{{ $itemCollection?->collected_amount === null ? 'Not recorded' : 'PHP '.number_format((float) $itemCollection->collected_amount, 2) }}</td>
                                        <td class="px-3 py-2 text-right">{{ $itemCollection?->status === \App\Models\Finance\CodCollection::REMITTED_RIDER_TOPUP ? 'PHP '.number_format(max(0, \App\Support\FinancialRules::cents($itemCollection->expected_amount) - \App\Support\FinancialRules::cents($itemCollection->collected_amount ?? 0)) / 100, 2) : '—' }}</td>
                                        <td class="px-3 py-2">{{ str($itemCollection?->status ?? 'unavailable')->replace('_', ' ')->headline() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-3 py-3 text-muted">No parcel items are attached to this remittance.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="border-t border-line bg-page-secondary font-semibold">
                                <tr>
                                    <td colspan="2" class="px-3 py-2">Total for {{ $remittanceItems->count() }} parcel{{ $remittanceItems->count() === 1 ? '' : 's' }}</td>
                                    <td class="px-3 py-2 text-right">PHP {{ number_format($parcelCodTotal, 2) }}</td>
                                    <td class="px-3 py-2 text-right">PHP {{ number_format($buyerCollectedTotal, 2) }}</td>
                                    <td class="px-3 py-2 text-right">PHP {{ number_format($riderTopUpCents / 100, 2) }}</td>
                                    <td class="px-3 py-2">Parcel breakdown</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <dl class="mt-3 grid gap-2 text-[10px] sm:grid-cols-2">
                        <div><dt class="text-muted">Expected from rider</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->expected_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Cash handed over</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->submitted_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Actual total cash received</dt><dd class="font-semibold">{{ $remittance->verified_amount === null ? 'Awaiting your confirmation' : 'PHP '.number_format((float) $remittance->verified_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Sender / recipient</dt><dd class="font-semibold">{{ $remittance->riderProfile?->user?->name ?? 'Rider' }} → {{ $center->business_name }}</dd></div>
                        <div><dt class="text-muted">Submitted</dt><dd class="font-semibold">{{ $remittance->submitted_at?->format('M d, Y g:i A') ?? '—' }}</dd></div>
                        @if($remittance->verified_at)<div><dt class="text-muted">Acknowledged</dt><dd class="font-semibold">{{ $remittance->verified_at->format('M d, Y g:i A') }}</dd></div>@endif
                        @if((float) $remittance->discrepancy_amount > 0)<div class="text-amber-800"><dt>Discrepancy</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->discrepancy_amount, 2) }} · {{ $remittance->status === 'DISCREPANCY' ? 'Unresolved' : 'Awaiting reconciliation' }}</dd></div>@endif
                    </dl>
                    @if($remittanceItems->contains(fn ($item) => $item->codCollection?->status === \App\Models\Finance\CodCollection::REMITTED_DISCREPANCY))
                        <p class="mt-3 rounded-lg bg-amber-50 p-3 text-[10px] font-semibold text-amber-800">The buyer paid an amount different from the parcel COD expected. This collection discrepancy remains open.</p>
                    @endif
                    @if($riderTopUpCents > 0)
                        <p class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-[10px] font-semibold text-emerald-800">The Rider covered PHP {{ number_format($riderTopUpCents / 100, 2) }} in buyer COD shortfalls. Logistics verified the full batch cash; the original buyer-collected amounts are preserved.</p>
                    @endif
                    @if($remittance->status === 'SUBMITTED')
                        <form method="POST" action="{{ route('logistics.remittances.verify', $remittance) }}" class="mt-3 flex flex-wrap gap-2" data-confirm-title="Acknowledge Rider cash?" data-confirm-message="Enter the total cash physically received for the batch. A mismatch against the full COD due will remain open and block settlement." data-confirm-label="Acknowledge cash">
                            @csrf
                            <label class="grid gap-1 text-[10px] font-semibold">Actual total cash physically received (PHP)<input name="verified_amount" type="number" min="0" step="0.01" required placeholder="Enter total cash received" class="rounded-lg border border-line px-3 py-2"><small class="font-normal text-muted">Count the cash for all parcels above, then enter the total once.</small></label>
                            <label class="grid gap-1 text-[10px] font-semibold">Notes<input name="notes" maxlength="1000" placeholder="Receipt notes" class="rounded-lg border border-line px-3 py-2"></label>
                            <button class="self-end rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Confirm amount</button>
                        </form>
                    @elseif($remittance->status === 'VERIFIED')
                        <form method="POST" action="{{ route('logistics.remittances.reconcile', $remittance) }}" class="mt-3" data-confirm-title="Reconcile Rider cash?" data-confirm-message="Finalize the batch cash handoff. If submitted and verified batch cash both equal full COD due, documented Rider top-ups can proceed with the parcels to Admin." data-confirm-label="Reconcile receipt">
                            @csrf
                            <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Reconcile receipt</button>
                        </form>
                    @elseif($remittance->status === 'DISCREPANCY')
                        <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-[10px] font-semibold text-amber-900">This discrepancy is locked for independent Admin review. The original Logistics verification cannot resolve its own discrepancy.</p>
                    @endif
                        </div>
                    </details>
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
            <h2 class="font-display text-xl font-semibold text-ink">Full COD remittance to Admin</h2>
            <p class="mt-1 text-[11px] text-muted">Select parcels for one handoff. Their Rider cash has already been reconciled, including any Rider-funded buyer shortfalls. Send the full COD due to Admin without charging Logistics for the same shortfall again.</p>
            @php($oldProceedsEntryIds = is_array(old('entry_ids')) ? array_map('strval', old('entry_ids')) : [])
            @php($pendingProceedsEntries = $productProceeds->getCollection()->where('status', 'PENDING'))
            <form method="POST" action="{{ route('logistics.product-proceeds.batch-submit') }}" class="mt-4 grid gap-4" data-admin-cod-batch-form data-confirm-title="Remit selected full COD to Admin?" data-confirm-message="The selected parcels already have reconciled Rider cash. Submit their full COD amount to Admin." data-confirm-label="Submit full COD batch">
                @csrf
            <div class="mt-4 grid gap-4" data-admin-cod-list>
                @forelse($productProceeds as $entry)
                    @php($parcel = $entry->shipment)
                    @php($collection = $parcel?->codCollection)
                    @php($riderRemittance = $collection?->remittanceItem?->remittance)
                    @php($receipt = (array) $entry->metadata)
                    @php($fullCodAmount = (float) ($collection?->expected_amount ?? $receipt['amount_expected'] ?? $entry->amount))
                    @php($riderTopUpCents = $collection?->status === \App\Models\Finance\CodCollection::REMITTED_RIDER_TOPUP ? max(0, \App\Support\FinancialRules::cents($collection->expected_amount) - \App\Support\FinancialRules::cents($collection->collected_amount ?? 0)) : 0)
                    <article class="rounded-lg border border-line p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-[10px]">
                            @if($entry->status === 'PENDING')
                                <label class="flex items-start gap-2 font-semibold"><input type="checkbox" name="entry_ids[]" value="{{ $entry->id }}" class="mt-0.5" data-admin-cod-selection data-expected-cents="{{ \App\Support\FinancialRules::cents($fullCodAmount) }}" @checked(in_array((string) $entry->id, $oldProceedsEntryIds, true))><span>{{ $receipt['batch_receipt_reference'] ?? 'Parcel remittance' }} · {{ $parcel?->tracking_number ?? 'Parcel unavailable' }}</span></label>
                            @else
                                <strong>{{ $receipt['batch_receipt_reference'] ?? $receipt['receipt_reference'] ?? 'Parcel remittance' }} · {{ $parcel?->tracking_number ?? 'Parcel unavailable' }}</strong>
                            @endif
                            <span class="font-semibold">{{ str($entry->status)->replace('_', ' ')->headline() }}</span>
                        </div>
                        <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <span class="text-[10px] text-muted">Full COD to Admin</span>
                                <strong class="block text-sm">PHP {{ number_format($fullCodAmount, 2) }}</strong>
                                <span class="mt-1 block text-[10px] text-muted">Received by Logistics from Rider: {{ $riderRemittance?->verified_at?->format('M d, Y g:i A') ?? 'Date not recorded' }}</span>
                            </div>
                            @if($entry->status !== 'PENDING')<span class="text-[10px] text-muted">Received {{ isset($receipt['amount_received']) ? 'PHP '.number_format((float) $receipt['amount_received'], 2) : 'awaiting Admin confirmation' }}</span>@endif
                        </div>
                        <details class="mt-3 rounded-lg border border-line px-3 py-2">
                            <summary class="cursor-pointer text-[10px] font-semibold text-primary">View remittance details</summary>
                            <div class="mt-3 overflow-x-auto rounded-lg border border-line">
                                <table class="w-full min-w-[640px] text-left text-[10px]">
                                    <thead class="bg-page-secondary text-muted"><tr><th class="px-3 py-2">Parcel</th><th class="px-3 py-2">Order</th><th class="px-3 py-2">COD collection</th><th class="px-3 py-2 text-right">COD due</th><th class="px-3 py-2 text-right">Buyer paid</th><th class="px-3 py-2 text-right">Rider top-up</th></tr></thead>
                                    <tbody><tr class="divide-y divide-line">
                                        <td class="px-3 py-2 font-semibold">{{ $parcel?->tracking_number ?? 'Unavailable' }}</td>
                                        <td class="px-3 py-2">{{ $parcel?->sellerOrder?->order?->order_number ?? 'Order unavailable' }}</td>
                                        <td class="px-3 py-2">{{ $collection?->collection_reference ?? 'Unavailable' }}</td>
                                        <td class="px-3 py-2 text-right">PHP {{ number_format((float) ($collection?->expected_amount ?? $fullCodAmount), 2) }}</td>
                                        <td class="px-3 py-2 text-right">{{ $collection?->collected_amount === null ? 'Not recorded' : 'PHP '.number_format((float) $collection->collected_amount, 2) }}</td>
                                        <td class="px-3 py-2 text-right">{{ $riderTopUpCents > 0 ? 'PHP '.number_format($riderTopUpCents / 100, 2) : '—' }}</td>
                                    </tr></tbody>
                                </table>
                            </div>
                            <dl class="mt-3 grid gap-2 text-[10px] sm:grid-cols-2 lg:grid-cols-3">
                                <div><dt class="text-muted">Full COD due / submitted</dt><dd class="font-semibold">PHP {{ number_format((float) ($receipt['amount_submitted'] ?? ($entry->status === 'PENDING' ? $fullCodAmount : $entry->amount)), 2) }}</dd></div>
                                <div><dt class="text-muted">Delivery charge split</dt><dd class="font-semibold">Logistics PHP {{ number_format((float) $entry->allocation?->logistics_allocation, 2) }} · Rider PHP {{ number_format((float) $entry->allocation?->rider_allocation, 2) }}</dd></div>
                                @if(isset($receipt['batch_expected_amount']))<div><dt class="text-muted">Batch COD total</dt><dd class="font-semibold">{{ $receipt['batch_receipt_reference'] }} · PHP {{ number_format((float) $receipt['batch_expected_amount'], 2) }} ({{ $receipt['batch_parcel_count'] ?? 1 }} parcels)</dd></div>@endif
                                @if(isset($receipt['batch_cash_from_rider_amount']))<div><dt class="text-muted">Cash counted from Rider batch</dt><dd class="font-semibold">PHP {{ number_format((float) $receipt['batch_cash_from_rider_amount'], 2) }}</dd></div>@endif
                                @if((float) ($receipt['batch_logistics_top_up_amount'] ?? 0) > 0)<div><dt class="text-muted">Logistics top-up for batch</dt><dd class="font-semibold">PHP {{ number_format((float) $receipt['batch_logistics_top_up_amount'], 2) }}</dd></div>@endif
                                <div><dt class="text-muted">Handoff method</dt><dd class="font-semibold">{{ $receipt['handoff_method'] ?? 'Not recorded' }}</dd></div>
                                <div><dt class="text-muted">Transfer reference</dt><dd class="font-semibold">{{ $receipt['handoff_external_reference'] ?? 'Not recorded' }}</dd></div>
                                <div><dt class="text-muted">Submitted</dt><dd class="font-semibold">{{ $receipt['submitted_at'] ?? 'Not submitted' }}</dd></div>
                                <div><dt class="text-muted">Actual amount received</dt><dd class="font-semibold">{{ isset($receipt['amount_received']) ? 'PHP '.number_format((float) $receipt['amount_received'], 2) : 'Awaiting Admin confirmation' }}</dd></div>
                                <div><dt class="text-muted">Discrepancy</dt><dd class="font-semibold">PHP {{ number_format((float) ($receipt['discrepancy_amount'] ?? 0), 2) }}</dd></div>
                                @if(filled($receipt['notes'] ?? null))<div class="sm:col-span-2 lg:col-span-3"><dt class="text-muted">Notes</dt><dd class="font-semibold">{{ $receipt['notes'] }}</dd></div>@endif
                            </dl>
                        </details>
                        @if($entry->status !== 'PENDING')
                            <p class="mt-2 text-[10px] text-muted">Includes full product and delivery charges. The Logistics and Rider shares are recorded separately after Admin verifies this receipt.</p>
                        @endif
                    </article>
                @empty
                    <p class="text-[10px] text-muted">No parcel product proceeds are waiting for handoff.</p>
                @endforelse
            </div>
            @if($pendingProceedsEntries->isNotEmpty())
                <div class="grid w-full gap-4 rounded-lg border border-line bg-surface p-4" data-admin-cod-batch-controls>
                <div class="rounded-lg border border-primary/20 bg-primary-soft p-4">
                    <dl class="grid gap-2 text-xs sm:grid-cols-3">
                        <div class="flex justify-between gap-2"><dt class="font-semibold text-ink">Selected COD due</dt><dd class="font-bold text-ink" data-admin-cod-total>PHP 0.00</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Verified cash received from Rider</dt><dd class="font-semibold text-ink" data-admin-cod-rider-cash>PHP 0.00</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-muted">Selected parcels</dt><dd class="font-semibold text-ink" data-admin-cod-count>0</dd></div>
                    </dl>
                </div>
                <p class="text-[10px] text-muted" aria-live="polite" data-admin-cod-match-status>Select parcels to remit the reconciled full COD amount.</p>
                <p class="text-[10px] text-muted">Any buyer collection shortfall was already covered in the Rider-to-Logistics remittance. No additional Logistics top-up is needed for that shortfall.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-[10px] font-semibold">Handoff method<select name="handoff_method" class="rounded-lg border border-line px-3 py-2"><option value="Manual cash handoff">Manual cash handoff</option><option value="Online">Online</option></select></label>
                    <label class="grid gap-1 text-[10px] font-semibold">Transfer reference<input name="handoff_external_reference" maxlength="150" value="{{ old('handoff_external_reference') }}" class="rounded-lg border border-line px-3 py-2"></label>
                    <label class="grid gap-1 text-[10px] font-semibold sm:col-span-2">Notes<input name="notes" maxlength="1000" value="{{ old('notes') }}" class="rounded-lg border border-line px-3 py-2"></label>
                </div>
                <button type="submit" data-admin-cod-submit class="w-fit rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Submit selected COD to Admin</button>
                </div>
            @endif
            </form>
            {{ $productProceeds->appends(request()->query())->links() }}
        </section>
    @endif
    </div>

    <div id="panel-rider-payouts" role="tabpanel" aria-labelledby="tab-rider-payouts" tabindex="0" data-remittance-panel="rider-payouts" @if($activeTab !== 'rider-payouts') hidden @endif>
    @if($parcelSettlementReady)
        <section id="rider-payout-requests" class="rounded-xl border border-line bg-surface p-6">
            <h2 class="font-display text-xl font-semibold text-ink">Rider payout records</h2>
            <p class="mt-1 text-[11px] text-muted">For pickup and final-mile Rider shares, record the exact allocated amount after Admin sends and Logistics confirms the full delivery charges. Interhub driver shares are listed in their own tab. Only an actual manual transfer with a transaction reference and saved proof can be confirmed as Paid.</p>
            <div class="mt-4 grid gap-4">
                @forelse($riderPayoutRequests as $entry)
                    @include('logistics.remittances.partials.payout-request', ['entry' => $entry, 'payoutParticipants' => $riderPayoutParticipants, 'requestLabel' => 'Rider'])
                @empty
                    <p class="text-[10px] text-muted">No Rider payout requests are waiting for this center.</p>
                @endforelse
            </div>
            {{ $riderPayoutRequests->appends(request()->except('tab') + ['tab' => 'rider-payouts'])->links() }}
        </section>

        <section class="mt-4 rounded-xl border border-line bg-surface p-6">
            <h2 class="font-display text-xl font-semibold text-ink">Eligible Rider shares awaiting a request</h2>
            <p class="mt-1 text-[11px] text-muted">These Rider shares passed the COD and Admin transfer checks. The Rider must request payout in Wallet before Logistics can record a transfer.</p>
            <div class="mt-4 grid gap-3">
                @forelse($availableRiderParticipants->groupBy('rider_profile_id') as $participants)
                    @include('logistics.remittances.partials.eligible-rider-share-group', ['participants' => $participants, 'riderLabel' => 'Rider'])
                @empty
                    <p class="text-[10px] text-muted">No eligible Rider shares are waiting for a request from this center's Riders.</p>
                @endforelse
            </div>
        </section>
    @endif
    </div>

    <div id="panel-interhub-payouts" role="tabpanel" aria-labelledby="tab-interhub-payouts" tabindex="0" data-remittance-panel="interhub-payouts" @if($activeTab !== 'interhub-payouts') hidden @endif>
        <section class="mb-2"><span class="text-[10px] font-bold uppercase tracking-[.18em] text-primary">Employee timekeeping and payroll</span><h1 class="mt-2 font-display text-[30px] font-semibold text-ink">Interhub Payouts</h1><p class="mt-1 text-xs text-muted">Interhub driver pay is based on approved time-in/out records. It is separate from parcel settlements and Pickup/Delivery Rider shares.</p></section>
        @include('logistics.remittances.partials.interhub-payroll')
        @if($interhubPayoutRequests->isNotEmpty())
            <details class="mt-4 rounded-xl border border-line bg-surface p-5"><summary class="cursor-pointer text-sm font-semibold text-muted">Historical per-parcel records ({{ $interhubPayoutRequests->total() }})</summary><p class="mt-2 text-xs text-muted">Preserved for history only. New Interhub pay is generated from shifts.</p><div class="mt-3 space-y-2">@foreach($interhubPayoutRequests as $entry)<p class="text-xs">{{ $entry->shipment?->tracking_number ?? 'Parcel' }} · PHP {{ number_format((float) $entry->amount, 2) }} · {{ str($entry->status)->replace('_',' ')->headline() }} · {{ $entry->created_at->format('M j, Y g:i A') }}</p>@endforeach</div></details>
        @endif
    </div>

    <div id="panel-logistics-payouts" role="tabpanel" aria-labelledby="tab-logistics-payouts" tabindex="0" data-remittance-panel="logistics-payouts" @if($activeTab !== 'logistics-payouts') hidden @endif>
    @if($parcelSettlementReady)
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
        const form = document.querySelector('[data-admin-cod-batch-form]');
        const parcelList = form?.querySelector('[data-admin-cod-list]');
        const batchControls = form?.querySelector('[data-admin-cod-batch-controls]');
        const selections = [...(form?.querySelectorAll('[data-admin-cod-selection]') ?? [])];
        const totalOutput = form?.querySelector('[data-admin-cod-total]');
        const riderCashOutput = form?.querySelector('[data-admin-cod-rider-cash]');
        const countOutput = form?.querySelector('[data-admin-cod-count]');
        const statusOutput = form?.querySelector('[data-admin-cod-match-status]');
        const submitButtons = [...(form?.querySelectorAll('[data-admin-cod-submit]') ?? [])];
        if (!form || !parcelList || !batchControls || !totalOutput || !riderCashOutput || !countOutput || !statusOutput || submitButtons.length === 0) return;

        const formatPesos = (cents) => `PHP ${(cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const updateBatch = () => {
            const checked = selections.filter((selection) => selection.checked);
            const expectedCents = checked.reduce((sum, selection) => sum + Number(selection.dataset.expectedCents || 0), 0);
            totalOutput.textContent = formatPesos(expectedCents);
            riderCashOutput.textContent = formatPesos(expectedCents);
            countOutput.textContent = `${checked.length} ${checked.length === 1 ? 'parcel' : 'parcels'}`;
            statusOutput.textContent = checked.length === 0
                ? 'Select parcels to remit their reconciled full COD amount.'
                : `Verified Rider cash matches the selected COD total of ${formatPesos(expectedCents)}. Full COD will be sent to Admin.`;
            submitButtons.forEach((button) => { button.disabled = checked.length === 0; });
        };

        const positionControlsAfterLastSelectedParcel = () => {
            const checked = selections.filter((selection) => selection.checked);
            const lastSelectedCard = checked.length ? checked[checked.length - 1].closest('article') : null;
            if (lastSelectedCard) lastSelectedCard.after(batchControls);
            else parcelList.after(batchControls);
        };

        selections.forEach((selection) => selection.addEventListener('change', () => {
            positionControlsAfterLastSelectedParcel();
            updateBatch();
        }));
        positionControlsAfterLastSelectedParcel();
        updateBatch();
    })();

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
