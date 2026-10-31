@php($payout = (array) $entry->metadata)
@php($riderProfile = $payoutParticipants->get((int) ($payout['participant_id'] ?? 0))?->riderProfile)
@php($legacySimulation = ($payout['is_simulated'] ?? false) || \Illuminate\Support\Str::startsWith((string) ($payout['payout_external_reference'] ?? ''), 'SIM-'))
<article class="rounded-lg border border-line p-4">
    <div class="flex flex-wrap justify-between gap-3 text-[10px]"><strong>{{ $payout['payout_reference'] ?? $requestLabel }} · {{ $entry->shipment?->tracking_number ?? 'Parcel' }}</strong><span>{{ $entry->actor?->name ?? $requestLabel }} · PHP {{ number_format((float) $entry->amount, 2) }}</span></div>
    <p class="mt-2 text-[10px] text-muted">Payout to: {{ $riderProfile?->payout_method ?? 'Details not saved' }}@if($riderProfile?->payout_bank_name) · {{ $riderProfile->payout_bank_name }}@endif · {{ $riderProfile?->payout_account_name ?? '—' }} · {{ $riderProfile?->payout_account_number ?? '—' }}</p>
    @if($entry->status === 'PAID' && !$legacySimulation)
        <p class="mt-2 text-[10px] font-semibold text-emerald-700">{{ $requestLabel }} confirmed receipt {{ $payout['acknowledged_at'] ?? '' }}. Transfer {{ $payout['payout_external_reference'] ?? '' }}</p>
    @elseif($entry->status === 'PAYOUT_PENDING_ACK' && !$legacySimulation)
        <p class="mt-2 text-[10px] text-muted">Transfer {{ $payout['payout_external_reference'] ?? '' }} is awaiting the {{ strtolower($requestLabel) }}'s receipt confirmation.</p>
        <form method="POST" action="{{ route('logistics.remittances.rider-payouts.fail', $entry) }}" class="mt-3 flex flex-wrap items-end gap-2">
            @csrf
            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold">Verified failure reason<input name="reason" maxlength="2000" required class="rounded-lg border border-line px-3 py-2"></label>
            <button class="rounded-lg border border-red-200 px-3 py-2 text-[10px] font-semibold text-red-700">Record failed transfer</button>
        </form>
    @else
        @if($legacySimulation)<p class="mt-2 text-[10px] font-semibold text-amber-700">Legacy demo only — no money was transferred. Replace it with an actual manual transfer and proof.</p>@endif
        @if(!empty($payout['last_failed_attempt']['failure_reason']))<p class="mt-2 text-[10px] font-semibold text-red-700">Last transfer failed: {{ $payout['last_failed_attempt']['failure_reason'] }}</p>@endif
        <form method="POST" enctype="multipart/form-data" action="{{ route('logistics.remittances.rider-payouts.record', $entry) }}" class="mt-3 flex flex-wrap items-end gap-2" data-rider-payout-form>
            @csrf
            <label class="grid gap-1 text-[10px] font-semibold">Amount<input name="amount" type="number" min="0" step="0.01" value="{{ number_format((float) $entry->amount, 2, '.', '') }}" required class="rounded-lg border border-line px-3 py-2"></label>
            <label class="grid gap-1 text-[10px] font-semibold">Method<select name="payout_method" required class="rounded-lg border border-line px-3 py-2" data-rider-payout-method><option value="Manual" selected>Manual transfer with proof</option><option value="GCash" disabled>GCash (demo only)</option><option value="Maya" disabled>Maya (demo only)</option><option value="Bank Transfer" disabled>Bank Transfer (demo only)</option><option value="Other" disabled>Other (demo only)</option></select></label>
            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold" data-rider-payout-reference-field>Manual transfer reference<input name="payout_external_reference" maxlength="150" required class="rounded-lg border border-line px-3 py-2" data-rider-payout-reference></label>
            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold" data-rider-payout-proof-field>Transfer proof<input name="payout_proof" type="file" accept=".jpg,.jpeg,.png,.pdf" required class="rounded-lg border border-line px-3 py-2" data-rider-payout-proof></label>
            <label class="grid min-w-48 flex-1 gap-1 text-[10px] font-semibold">Notes<input name="notes" maxlength="1000" class="rounded-lg border border-line px-3 py-2"></label>
            <button class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">{{ $legacySimulation ? 'Replace with verified transfer' : 'Record verified transfer' }}</button>
        </form>
    @endif
</article>
