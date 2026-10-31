@php
    $rider = $participants->first()?->riderProfile;
    $totalCents = $participants->sum(fn ($participant) => \App\Support\FinancialRules::cents($participant->share_amount ?? 0));
@endphp
<article class="rounded-lg border border-line p-4 text-[10px]">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <strong>{{ $rider?->user?->name ?? $riderLabel }} · {{ $participants->count() }} share{{ $participants->count() === 1 ? '' : 's' }}</strong>
            <p class="mt-1 text-muted">{{ $riderLabel }} payout request not submitted</p>
        </div>
        <div class="text-right"><span class="block text-muted">Combined payout</span><strong class="text-sm">PHP {{ number_format($totalCents / 100, 2) }}</strong></div>
    </div>
    <ul class="mt-3 grid gap-2 border-t border-line pt-3">
        @foreach($participants as $participant)
            <li class="flex flex-wrap items-center justify-between gap-2">
                <span>{{ strtoupper(str_replace('_', ' ', $participant->participant_role)) }} · {{ $participant->pool?->shipment?->tracking_number ?? 'Parcel' }}</span>
                <strong>PHP {{ number_format((float) $participant->share_amount, 2) }}</strong>
            </li>
        @endforeach
    </ul>
</article>
