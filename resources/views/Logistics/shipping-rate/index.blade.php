@extends('Logistics.app')
@section('title', 'Shipping Rate')
@section('content')
<div class="mx-auto grid max-w-5xl gap-5">
    <section class="rounded-xl border border-line bg-surface p-5 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-primary">Global platform policy</span>
                <h1 class="mt-1 text-2xl font-semibold text-ink">Shipping Rate</h1>
                <p class="mt-1 text-sm text-muted">Logistics can propose a fee change. Only an Admin can approve and activate it.</p>
            </div>
            <span class="rounded-full bg-success/10 px-3 py-2 text-xs font-semibold text-success">{{ $pendingRequest ? 'Pending Admin Review' : 'GLOBAL' }}</span>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Current Approved Shipping Fee</small><h2 class="mt-1 text-2xl font-semibold text-ink">₱{{ number_format((float) $policy['shipping_fee'], 2) }}</h2>@unless($policy['initialized'])<small class="mt-1 block text-muted">Temporary config fallback until Admin initializes rate history</small>@endunless</div>
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Rider Pool</small><h2 class="mt-1 text-2xl font-semibold text-ink">{{ number_format((float) $policy['rider_pool_percentage'], 2) }}%</h2></div>
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Logistics Allocation</small><h2 class="mt-1 text-2xl font-semibold text-ink">{{ number_format((float) $policy['logistics_percentage'], 2) }}%</h2></div>
            <div class="rounded-lg border border-line p-4"><small class="text-muted">Requested Change</small><h2 class="mt-1 text-base font-semibold text-ink">{{ $pendingRequest ? '₱'.number_format((float) $pendingRequest->requested_shipping_fee, 2).' · Pending' : 'None' }}</h2></div>
        </div>
    </section>

    @if(session('status'))<div role="status" class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

    @if(! $schemaReady)
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm"><strong class="text-ink">Rate requests are not available yet.</strong><p class="mt-2 text-sm text-muted">The reviewed migration must be executed separately before request history can be stored. Checkout continues to use the current ₱50 config fallback.</p></section>
    @elseif(! $policy['initialized'])
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm"><strong class="text-ink">Admin initialization required.</strong><p class="mt-2 text-sm text-muted">An Admin must create the first persistent GLOBAL rate version before Logistics can submit a change. Checkout remains at the ₱50 fallback until then.</p></section>
    @elseif($pendingRequest)
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><h2 class="text-lg font-semibold text-ink">Pending request</h2><p class="mt-1 text-sm text-ink">Current: ₱{{ number_format((float) $pendingRequest->current_shipping_fee, 2) }} · Requested: ₱{{ number_format((float) $pendingRequest->requested_shipping_fee, 2) }} · Change: {{ $pendingRequest->change_type }} ₱{{ number_format(abs((float) $pendingRequest->change_amount), 2) }} ({{ number_format((float) $pendingRequest->change_percentage, 2) }}%)</p><p class="mt-2 text-sm text-muted">{{ $reasons[$pendingRequest->reason_code] ?? $pendingRequest->reason_code }}{{ $pendingRequest->reason_details ? ': '.$pendingRequest->reason_details : '' }}</p></div>
                <form method="POST" action="{{ route('logistics.shipping-rate-requests.cancel', $pendingRequest) }}" onsubmit="return confirm('Cancel this pending shipping rate request?')">@csrf<button type="submit" class="min-h-10 rounded-lg border border-line px-4 py-2 text-xs font-semibold text-ink hover:bg-page-secondary">Cancel Pending Request</button></form>
            </div>
        </section>
    @else
        <section class="rounded-xl border border-line bg-surface p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-ink">Request Shipping Fee Change</h2>
            <p class="mt-1 text-sm text-muted">The current fee remains active until Admin approves the exact amount you request.</p>
            <form method="POST" action="{{ route('logistics.shipping-rate-requests.store') }}" class="mt-4 grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @csrf
                <label class="text-sm font-medium text-ink">Current Shipping Fee<input type="text" value="₱{{ number_format((float) $policy['shipping_fee'], 2) }}" readonly class="mt-1 block w-full rounded-lg border border-line bg-page-secondary px-3 py-2 text-ink"></label>
                <label class="text-sm font-medium text-ink">Requested Shipping Fee<input type="number" name="requested_shipping_fee" min="0.01" max="1000000" step="0.01" value="{{ old('requested_shipping_fee') }}" required class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"></label>
                <label class="text-sm font-medium text-ink">Reason category<select name="reason_code" required class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink"><option value="">Select a reason</option>@foreach($reasons as $code => $label)<option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm font-medium text-ink sm:col-span-2 xl:col-span-3">Supporting reason / operational note<textarea name="reason_details" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border border-line bg-surface px-3 py-2 text-ink">{{ old('reason_details') }}</textarea><small class="mt-1 block font-normal text-muted">Required when selecting Other. HTML is not accepted.</small></label>
                <div><button type="submit" class="min-h-10 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white hover:opacity-90">Submit Rate Change Request</button></div>
            </form>
        </section>
    @endif

    <section class="rounded-xl border border-line bg-surface p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-ink">Request History</h2>
        <div class="mt-3 overflow-x-auto"><table class="w-full min-w-[720px] text-left text-xs"><thead><tr class="border-b border-line text-muted"><th class="p-3">Requested</th><th class="p-3">Current</th><th class="p-3">Requested fee</th><th class="p-3">Change</th><th class="p-3">Reason</th><th class="p-3">Status</th><th class="p-3">Decision</th></tr></thead><tbody>
        @forelse($requests as $rateRequest)
            <tr class="border-b border-line text-ink"><td class="p-3">{{ $rateRequest->created_at?->format('M j, Y g:i A') }}</td><td class="p-3">₱{{ number_format((float) $rateRequest->current_shipping_fee, 2) }}</td><td class="p-3">₱{{ number_format((float) $rateRequest->requested_shipping_fee, 2) }}</td><td class="p-3">{{ $rateRequest->change_type }} · {{ number_format((float) $rateRequest->change_percentage, 2) }}%</td><td class="p-3">{{ $reasons[$rateRequest->reason_code] ?? $rateRequest->reason_code }}</td><td class="p-3">{{ str($rateRequest->status)->headline() }}</td><td class="p-3">{{ $rateRequest->admin_decision_reason ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="7" class="p-4 text-muted">No shipping-rate requests yet.</td></tr>
        @endforelse
        </tbody></table></div>
        @if(method_exists($requests, 'links'))<div class="mt-3">{{ $requests->links() }}</div>@endif
    </section>

    <p class="text-xs text-muted">FUTURE SHIPPING PRICING: The shipping-rate architecture is intentionally designed so a future pricing engine may select rates using location, distance, parcel weight, parcel dimensions, or service level. This capability is NOT IMPLEMENTED — FUTURE SCOPE.</p>
</div>
@endsection
