@extends('layouts.rider.app')
@section('title', 'COD Remittances - LIKHAE Rider')
@section('content')
@unless($schemaReady)<div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-800">COD remittance controls are ready but waiting for the additive finance tables. Existing delivery workflows remain available.</div>@endunless
@php($oldCollectionIds = is_array(old('collection_ids')) ? array_map('strval', old('collection_ids')) : [])
<div class="flex flex-col gap-6">
    @if(session('status'))<div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>@endif
    <section><span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Cash Control</span><h1 class="mt-2 font-display text-[32px] font-semibold text-ink">COD Remittances</h1><p class="mt-2 text-[11px] leading-6 text-muted">Remit the full cash collected. Rider earnings are tracked separately and must never be deducted from this amount.</p></section>
    <section class="rounded-xl border border-line bg-surface p-6">
        <h2 class="text-[13px] font-semibold text-ink">Unremitted COD collections</h2>
        <p class="mt-1 text-[10px] text-muted">Select the parcels in this handoff. Remit the full COD due; cover any buyer collection shortfall from your own cash. Logistics will verify the cash received.</p>
        <form method="POST" action="{{ route('rider.remittances.store') }}" class="mt-4 grid gap-3" data-rider-remittance-form data-confirm-title="Submit cash remittance?" data-confirm-message="Confirm the selected parcels and matching cash total before submitting this remittance. Any difference from COD due remains visible for Logistics to reconcile." data-confirm-label="Submit remittance">
            @csrf
            <div class="grid gap-2">
                @forelse($collections as $collection)
                    <label class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3 text-[10px]">
                        <span class="flex items-start gap-2">
                            <input type="checkbox" name="collection_ids[]" value="{{ $collection->id }}" class="mt-0.5" data-remittance-selection data-expected-cents="{{ \App\Support\FinancialRules::cents($collection->expected_amount) }}" data-collected-cents="{{ \App\Support\FinancialRules::cents($collection->collected_amount) }}" @checked(in_array((string) $collection->id, $oldCollectionIds, true))>
                            <span>{{ $collection->shipment?->tracking_number }} · {{ $collection->order?->order_number }} · {{ $collection->collection_reference }} @if($collection->status === \App\Models\Finance\CodCollection::DISCREPANCY)<strong class="text-amber-700">Buyer collection discrepancy</strong>@endif</span>
                        </span>
                        <strong>Cash claimed ₱{{ number_format((float) $collection->collected_amount, 2) }} / COD expected ₱{{ number_format((float) $collection->expected_amount, 2) }}</strong>
                    </label>
                @empty
                    <p class="text-[10px] text-muted">No collected COD parcels are waiting for remittance.</p>
                @endforelse
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/20 bg-primary-soft px-3 py-3 text-xs">
                <dl class="grid w-full gap-2 sm:grid-cols-3">
                    <div class="flex justify-between gap-2"><dt class="font-semibold text-ink">COD due to remit</dt><dd class="font-bold text-ink" data-remittance-selected-total>₱0.00</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-muted">Collected from buyers</dt><dd class="font-semibold text-ink" data-remittance-collected-total>₱0.00</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-muted">Rider top-up needed</dt><dd class="font-semibold text-ink" data-remittance-rider-topup>₱0.00</dd></div>
                </dl>
            </div>
            <p class="text-[10px] text-muted" aria-live="polite" data-remittance-match-status>Select at least one parcel.</p>
            <label class="grid gap-1 text-[10px] font-semibold">Actual cash handed to Logistics
                <input name="submitted_amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" value="{{ old('submitted_amount') }}" required class="rounded-lg border border-line px-3 py-2" data-rider-money-input>
                <small class="font-normal text-muted">Enter the full cash amount handed to Logistics, including any Rider top-up. Return any buyer overpayment before handoff. Thousands separators and two decimal places are formatted for you.</small>
            </label>
            <label class="grid gap-1 text-[10px] font-semibold">Notes<input name="notes" maxlength="1000" value="{{ old('notes') }}" class="rounded-lg border border-line px-3 py-2"></label>
            <button class="w-fit rounded-lg bg-primary px-4 py-2 text-[10px] font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50" type="submit" data-remittance-submit disabled>Submit parcel remittances</button>
        </form>
    </section>
    <section class="rounded-xl border border-line bg-surface p-6">
        <h2 class="text-[13px] font-semibold text-ink">Remittance history</h2>
        <p class="mt-1 text-[10px] text-muted">Each receipt keeps the selected parcel list, COD amounts, and Logistics confirmation together.</p>
        <div class="mt-4 grid gap-3">
            @forelse($remittances as $remittance)
                @php($remittanceItems = $remittance->items)
                @php($parcelCodCents = $remittanceItems->sum(fn ($item) => \App\Support\FinancialRules::cents($item->codCollection?->expected_amount ?? 0)))
                @php($buyerCollectedCents = $remittanceItems->sum(fn ($item) => \App\Support\FinancialRules::cents($item->codCollection?->collected_amount ?? 0)))
                @php($riderTopUpCents = $remittanceItems->sum(fn ($item) => $item->codCollection?->status === \App\Models\Finance\CodCollection::REMITTED_RIDER_TOPUP ? max(0, \App\Support\FinancialRules::cents($item->codCollection->expected_amount) - \App\Support\FinancialRules::cents($item->codCollection->collected_amount ?? 0)) : 0))
                <article class="rounded-lg border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-[10px]">
                        <strong>{{ $remittance->remittance_reference }}</strong>
                        <span class="font-semibold">{{ str($remittance->status)->replace('_', ' ')->headline() }}</span>
                    </div>
                    <dl class="mt-3 grid gap-2 text-[10px] sm:grid-cols-3">
                        <div><dt class="text-muted">COD due for these parcels</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->expected_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Cash handed to Logistics</dt><dd class="font-semibold">PHP {{ number_format((float) $remittance->submitted_amount, 2) }}</dd></div>
                        <div><dt class="text-muted">Logistics confirmed receiving</dt><dd class="font-semibold">{{ $remittance->verified_amount === null ? 'Pending confirmation' : 'PHP '.number_format((float) $remittance->verified_amount, 2) }}</dd></div>
                    </dl>
                    <details class="mt-3 rounded-lg border border-line px-3 py-2">
                        <summary class="cursor-pointer text-[10px] font-semibold text-primary">View parcel details ({{ $remittanceItems->count() }})</summary>
                        <div class="mt-3 overflow-x-auto rounded-lg border border-line">
                            <table class="w-full min-w-[680px] text-left text-[10px]">
                                <thead class="bg-page-secondary text-muted"><tr><th class="px-3 py-2">Parcel / order</th><th class="px-3 py-2">COD collection</th><th class="px-3 py-2 text-right">COD due</th><th class="px-3 py-2 text-right">Buyer cash collected</th><th class="px-3 py-2 text-right">Rider top-up</th><th class="px-3 py-2">Status</th></tr></thead>
                                <tbody class="divide-y divide-line">
                                    @forelse($remittanceItems as $item)
                                        @php($collection = $item->codCollection)
                                        <tr>
                                            <td class="px-3 py-2 font-semibold">{{ $collection?->shipment?->tracking_number ?? 'Parcel unavailable' }}<div class="mt-1 font-normal text-muted">{{ $collection?->order?->order_number ?? 'Order unavailable' }}</div></td>
                                            <td class="px-3 py-2">{{ $collection?->collection_reference ?? 'Unavailable' }}</td>
                                            <td class="px-3 py-2 text-right">PHP {{ number_format((float) ($collection?->expected_amount ?? 0), 2) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $collection?->collected_amount === null ? 'Not recorded' : 'PHP '.number_format((float) $collection->collected_amount, 2) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $collection?->status === \App\Models\Finance\CodCollection::REMITTED_RIDER_TOPUP ? 'PHP '.number_format(max(0, \App\Support\FinancialRules::cents($collection->expected_amount) - \App\Support\FinancialRules::cents($collection->collected_amount ?? 0)) / 100, 2) : '—' }}</td>
                                            <td class="px-3 py-2">{{ str($collection?->status ?? 'unavailable')->replace('_', ' ')->headline() }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-3 py-3 text-muted">No parcel items are attached to this receipt.</td></tr>
                                    @endforelse
                                </tbody>
                                <tfoot class="border-t border-line bg-page-secondary font-semibold"><tr><td colspan="2" class="px-3 py-2">Total ({{ $remittanceItems->count() }} parcels)</td><td class="px-3 py-2 text-right">PHP {{ number_format($parcelCodCents / 100, 2) }}</td><td class="px-3 py-2 text-right">PHP {{ number_format($buyerCollectedCents / 100, 2) }}</td><td class="px-3 py-2 text-right">PHP {{ number_format($riderTopUpCents / 100, 2) }}</td><td class="px-3 py-2">COD handoff</td></tr></tfoot>
                            </table>
                        </div>
                        <dl class="mt-3 grid gap-2 text-[10px] sm:grid-cols-2">
                            <div><dt class="text-muted">Submitted</dt><dd class="font-semibold">{{ $remittance->submitted_at?->format('M d, Y g:i A') ?? '—' }}</dd></div>
                            @if($remittance->verified_at)<div><dt class="text-muted">Logistics confirmation</dt><dd class="font-semibold">{{ $remittance->verified_at->format('M d, Y g:i A') }}</dd></div>@endif
                            @if(filled($remittance->notes))<div class="sm:col-span-2"><dt class="text-muted">Notes</dt><dd class="font-semibold">{{ $remittance->notes }}</dd></div>@endif
                        </dl>
                    </details>
                    @if((float) $remittance->discrepancy_amount > 0)<p class="mt-3 rounded-lg bg-amber-50 p-3 text-[10px] font-semibold text-amber-800">Discrepancy PHP {{ number_format((float) $remittance->discrepancy_amount, 2) }} · Logistics review required.</p>@endif
                </article>
            @empty
                <p class="text-[10px] text-muted">No remittances submitted yet.</p>
            @endforelse
        </div>
        {{ $remittances->links() }}
    </section>
</div>
<script>
    (() => {
        const input = document.querySelector('[data-rider-money-input]');
        const form = document.querySelector('[data-rider-remittance-form]');
        const selections = [...document.querySelectorAll('[data-remittance-selection]')];
        const selectedTotal = document.querySelector('[data-remittance-selected-total]');
        const collectedTotal = document.querySelector('[data-remittance-collected-total]');
        const riderTopup = document.querySelector('[data-remittance-rider-topup]');
        const matchStatus = document.querySelector('[data-remittance-match-status]');
        const submit = document.querySelector('[data-remittance-submit]');
        if (!input || !form || !selectedTotal || !collectedTotal || !riderTopup || !matchStatus || !submit) return;

        const formatPesos = (cents) => `₱${(cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

        const parseAmountCents = (value) => {
            const raw = String(value || '').replaceAll(',', '').trim();
            if (!/^\d+(?:\.\d{0,2})?$/.test(raw)) return null;
            const [whole, fraction = ''] = raw.split('.');
            const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0') || 0);
            return Number.isSafeInteger(cents) ? cents : null;
        };

        const updateMatch = () => {
            const checked = selections.filter((selection) => selection.checked);
            const expectedCents = checked.reduce((sum, selection) => sum + Number(selection.dataset.expectedCents || 0), 0);
            const buyerCollectedCents = checked.reduce((sum, selection) => sum + Number(selection.dataset.collectedCents || 0), 0);
            const riderTopupCents = checked.reduce((sum, selection) => sum + Math.max(
                0,
                Number(selection.dataset.expectedCents || 0) - Number(selection.dataset.collectedCents || 0),
            ), 0);
            const submittedCents = parseAmountCents(input.value);
            selectedTotal.textContent = formatPesos(expectedCents);
            collectedTotal.textContent = formatPesos(buyerCollectedCents);
            riderTopup.textContent = formatPesos(riderTopupCents);
            submit.disabled = checked.length === 0 || submittedCents !== expectedCents;
            matchStatus.textContent = checked.length === 0
                ? 'Select at least one parcel.'
                : submittedCents === null
                    ? 'Enter the actual cash amount.'
                    : submittedCents === expectedCents
                        ? `Full COD due matches. Logistics will verify the ${formatPesos(expectedCents)} handoff.`
                        : `Enter ${formatPesos(expectedCents)}. The Rider must cover any shortfall from buyer collections.`;
        };

        const formatAmount = (complete = false) => {
            const original = input.value;
            const caret = input.selectionStart ?? original.length;
            const logicalCaret = original.slice(0, caret).replaceAll(',', '').replace(/[^\d.]/g, '').length;
            let raw = original.replaceAll(',', '').replace(/[^\d.]/g, '');
            const decimalIndex = raw.indexOf('.');
            if (decimalIndex >= 0) {
                raw = raw.slice(0, decimalIndex + 1) + raw.slice(decimalIndex + 1).replaceAll('.', '');
            }
            if (raw.startsWith('.')) raw = `0${raw}`;

            let hasDecimal = raw.includes('.');
            let [whole, fraction = ''] = raw.split('.');
            whole ||= hasDecimal ? '0' : '';
            fraction = fraction.slice(0, 2);
            if (complete && raw) {
                hasDecimal = true;
                fraction = fraction.padEnd(2, '0');
            }

            const groupedWhole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            const formatted = `${groupedWhole}${hasDecimal ? `.${fraction}` : ''}`;
            input.value = formatted;

            if (document.activeElement === input && typeof input.setSelectionRange === 'function') {
                let position = 0;
                let logicalPosition = 0;
                while (position < formatted.length && logicalPosition < logicalCaret) {
                    if (formatted[position] !== ',') logicalPosition++;
                    position++;
                }
                input.setSelectionRange(position, position);
            }
        };

        input.addEventListener('input', () => formatAmount());
        input.addEventListener('blur', () => formatAmount(true));
        input.addEventListener('input', updateMatch);
        selections.forEach((selection) => selection.addEventListener('change', updateMatch));
        formatAmount(true);
        updateMatch();
    })();
</script>
@endsection
