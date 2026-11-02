@extends('layouts.rider.app')

@section('title', 'Interhub Wallet & Payroll - LIKHAE Rider')

@section('content')
<div class="flex flex-col gap-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase tracking-[.18em] text-primary">Interhub driver wallet</span>
            <h1 class="mt-2 text-2xl font-bold text-ink">Wallet &amp; payroll</h1>
            <p class="mt-2 max-w-3xl text-sm text-muted">Track shift pay, payroll status, and payment proof from your Logistics center. Your Interhub pay is based on recorded hours.</p>
        </div>
        <a href="{{ route('rider.dashboard') }}" class="rounded-lg border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink">Back to dashboard</a>
    </header>

    @if(session('status'))<div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-700" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700" role="alert">{{ $errors->first() }}</div>@endif

    @if(!$timekeepingReady)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Interhub wallet and payroll history will appear after the timekeeping and payroll migration is installed.</section>
    @else
        <form method="GET" action="{{ route('rider.wallet') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface p-4">
            <label class="grid gap-1 text-xs font-semibold text-ink">From date<input type="date" name="from" value="{{ $filters['from'] }}" class="h-10 rounded-lg border border-line bg-white px-3"></label>
            <label class="grid gap-1 text-xs font-semibold text-ink">To date<input type="date" name="to" value="{{ $filters['to'] }}" class="h-10 rounded-lg border border-line bg-white px-3"></label>
            <button class="h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white">Apply dates</button>
            @if($filters['from'] !== '' || $filters['to'] !== '')<a href="{{ route('rider.wallet') }}" class="h-10 rounded-lg border border-line px-4 py-3 text-xs font-semibold text-ink">Clear dates</a>@endif
        </form>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Interhub pay summary">
            <article class="rounded-xl border border-line bg-surface p-4"><p class="text-xs text-muted">Paid through payroll</p><strong class="mt-1 block text-lg text-ink">₱{{ number_format($walletSummary['paid'], 2) }}</strong><span class="text-xs text-muted">Recorded payment with transfer proof</span></article>
            <article class="rounded-xl border border-line bg-surface p-4"><p class="text-xs text-muted">Pending payment</p><strong class="mt-1 block text-lg text-ink">₱{{ number_format($walletSummary['pending'], 2) }}</strong><span class="text-xs text-muted">Included in a payroll period</span></article>
            <article class="rounded-xl border border-line bg-surface p-4"><p class="text-xs text-muted">Held for review</p><strong class="mt-1 block text-lg text-ink">₱{{ number_format($walletSummary['review'], 2) }}</strong><span class="text-xs text-muted">Logistics review is required</span></article>
            <article class="rounded-xl border border-line bg-surface p-4"><p class="text-xs text-muted">Awaiting payroll</p><strong class="mt-1 block text-lg text-ink">₱{{ number_format($walletSummary['awaiting_payroll'], 2) }}</strong><span class="text-xs text-muted">Closed shifts not yet added to a payroll period</span></article>
            <article class="rounded-xl border border-line bg-surface p-4"><p class="text-xs text-muted">Recorded shift pay</p><strong class="mt-1 block text-lg text-ink">₱{{ number_format($walletSummary['accrued'], 2) }}</strong><span class="text-xs text-muted">{{ number_format($walletSummary['hours'], 2) }} closed shift hours in this date range</span></article>
        </section>

        <section class="overflow-hidden rounded-xl border border-line bg-surface">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-semibold text-ink">Shift and payment history</h2>
                <p class="mt-1 text-xs text-muted">Each row is a recorded shift. Paid items include the reference and proof provided by Logistics.</p>
            </div>
            <div class="overflow-x-auto" role="region" aria-label="Shift and payroll history" tabindex="0">
                <table class="w-full min-w-[900px] border-collapse text-left text-xs">
                    <thead><tr class="border-b border-line text-muted"><th class="p-3">Shift date</th><th class="p-3">Transfer</th><th class="p-3">Hours</th><th class="p-3">Shift pay</th><th class="p-3">Payroll status</th><th class="p-3">Payment record</th></tr></thead>
                    <tbody>
                        @forelse($sessions as $session)
                            @php
                                $payroll = $session->payrollItem;
                                $minutes = $session->worked_minutes ?? ($session->clock_out_at ? 0 : $session->clock_in_at->diffInMinutes(now()));
                                $amount = $session->gross_pay ?? round(((float) $session->daily_rate_snapshot * $minutes) / 480, 2);
                                $payrollStatus = $session->clock_out_at ? ($payroll?->status ?? 'AWAITING PAYROLL') : 'ON SHIFT';
                            @endphp
                            <tr class="border-b border-line align-top">
                                <td class="p-3"><strong class="text-ink">{{ $session->clock_in_at->format('M j, Y') }}</strong><small class="mt-1 block text-muted">{{ $session->clock_in_at->format('g:i A') }} – {{ $session->clock_out_at?->format('g:i A') ?? 'In progress' }}</small></td>
                                <td class="p-3">{{ $session->manifest?->manifest_number ?? 'No manifest assigned' }}<small class="mt-1 block text-muted">{{ $session->region_type }} rate · ₱{{ number_format((float) $session->daily_rate_snapshot, 2) }}/day</small></td>
                                <td class="p-3">{{ number_format($minutes / 60, 2) }}h</td>
                                <td class="p-3">₱{{ number_format((float) $amount, 2) }}@if(!$session->clock_out_at)<small class="mt-1 block text-muted">Estimate</small>@endif</td>
                                <td class="p-3">
                                    <strong>{{ str($payrollStatus)->replace('_', ' ')->title() }}</strong>
                                    @if($session->overtime_review)
                                        <small class="mt-1 block text-amber-700">Overtime review</small>
                                    @endif
                                    @if($payroll?->batch)
                                        <small class="mt-1 block text-muted">Period {{ $payroll->batch->period_start?->format('M j') }}–{{ $payroll->batch->period_end?->format('M j, Y') }}</small>
                                    @endif
                                </td>
                                <td class="p-3">
                                    @if($payroll?->status === 'PAID')
                                        <strong>{{ $payroll->payment_reference }}</strong>
                                        <small class="mt-1 block text-muted">Paid {{ $payroll->paid_at?->format('M j, Y · g:i A') }}</small>
                                        @if($payroll->proof_path)
                                            <a href="{{ route('rider.attendance.payroll-items.proof', $payroll) }}" class="mt-1 inline-block font-semibold text-primary underline">View payment proof</a>
                                        @endif
                                        @if($payroll->payment_notes)
                                            <small class="mt-1 block text-muted">{{ $payroll->payment_notes }}</small>
                                        @endif
                                    @elseif($payroll?->status === 'REVIEW_REQUIRED')
                                        <span class="text-amber-700">Awaiting Logistics review</span>
                                    @elseif($payroll?->status === 'PENDING')
                                        <span class="text-muted">Payment not recorded yet</span>
                                    @elseif(!$session->clock_out_at)
                                        <span class="text-muted">Final pay is set after Time Out</span>
                                    @else
                                        <span class="text-muted">No payroll period recorded yet</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-8 text-center text-muted">No shifts match these dates yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $sessions->links() }}</div>
        </section>
    @endif
</div>
@endsection
