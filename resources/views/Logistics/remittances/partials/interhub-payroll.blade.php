@if(! $timekeepingReady)
    <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900"><h2 class="font-semibold">Interhub payroll schema is not installed yet</h2><p class="mt-1">Run the additive timekeeping migration. Existing remittance and payout records are unchanged.</p></section>
@else
    @php($defaultRegion = $interhubDrivers->first() ? app(\App\Services\Fulfillment\InterhubTimekeepingService::class)->regionForDriver($interhubDrivers->first()) : 'PROVINCIAL')
    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-lg font-semibold text-ink">Interhub driver daily rates</h2>
        <p class="mt-1 text-xs text-muted">Set a separate rate per driver and rate category. NCR and Provincial rates can differ; the category follows the driver’s registered Logistics center. Pay is daily rate × actual minutes ÷ 480. At ₱645/day, 4 hours pays ₱322.50; the hourly equivalent displays rounded to ₱80.63 while calculations keep the precise rate. Each Time In saves its effective rate snapshot.</p>
        @if($interhubDrivers->isEmpty())<p class="mt-4 text-sm text-muted">No active Interhub drivers are assigned to this Logistics center.</p>@else
            <form method="POST" action="{{ route('logistics.interhub-attendance.rates.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-[1.3fr_.8fr_1fr_auto] xl:items-end">@csrf
                <label class="text-xs font-semibold text-ink">Interhub driver<select name="rider_profile_id" required class="mt-1 min-h-10 w-full rounded-lg border border-line bg-white px-3 text-sm">@foreach($interhubDrivers as $driver)<option value="{{ $driver->id }}" @selected(old('rider_profile_id') == $driver->id)>{{ $driver->user?->name ?? 'Driver #'.$driver->id }} · {{ app(\App\Services\Fulfillment\InterhubTimekeepingService::class)->regionForDriver($driver) }}</option>@endforeach</select></label>
                <p class="text-xs font-semibold text-ink">Rate category<strong class="mt-1 block min-h-10 rounded-lg border border-line bg-page-secondary px-3 py-2">Auto: {{ $defaultRegion }}</strong></p>
                <label class="text-xs font-semibold text-ink">Daily rate (PHP)<input type="number" name="daily_rate" value="{{ old('daily_rate') }}" min="0.01" max="100000" step="0.0001" required placeholder="e.g. 645.00" class="mt-1 min-h-10 w-full rounded-lg border border-line bg-white px-3 text-sm"></label>
                <label class="text-xs font-semibold text-ink">Effective from<input type="date" name="effective_from" value="{{ old('effective_from', now()->toDateString()) }}" required class="mt-1 min-h-10 w-full rounded-lg border border-line bg-white px-3 text-sm"></label>
                <button class="min-h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white">Save rate</button>
            </form>
        @endif
        <div class="mt-5 overflow-x-auto"><table class="w-full min-w-[600px] border-collapse text-left text-xs"><thead><tr class="border-b border-line text-muted"><th class="p-2">Driver</th><th class="p-2">Category</th><th class="p-2">Daily rate</th><th class="p-2">Effective from</th><th class="p-2">Recorded</th></tr></thead><tbody>
            @forelse($interhubRates as $rate)<tr class="border-b border-line"><td class="p-2">{{ $rate->riderProfile?->user?->name ?? 'Driver' }}</td><td class="p-2">{{ $rate->region_type }}</td><td class="p-2">₱{{ number_format((float) $rate->daily_rate, 4) }}</td><td class="p-2">{{ $rate->effective_from->format('M j, Y') }}</td><td class="p-2">{{ $rate->created_at->format('M j, Y g:i A') }} · {{ $rate->creator?->name ?? 'Logistics' }}</td></tr>@empty<tr><td colspan="5" class="p-3 text-muted">No rate history has been configured yet.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-ink">Time logs and corrections</h2><p class="mt-1 text-xs text-muted">Only Logistics can correct a scan. Each correction keeps the original values, corrected values, reason, and actor in its audit history.</p></div></div>
        <div class="mt-4 grid gap-3">
            @forelse($interhubSessions as $session)
                <article class="rounded-lg border border-line p-4">
                    <div class="flex flex-wrap justify-between gap-2 text-xs"><strong>{{ $session->riderProfile?->user?->name ?? 'Interhub driver' }} · {{ $session->manifest?->manifest_number ?? 'No manifest assigned' }}</strong><span class="font-semibold {{ $session->clock_out_at ? 'text-muted' : 'text-primary' }}">{{ $session->clock_out_at ? 'CLOSED' : 'ON SHIFT' }}</span></div>
                    <p class="mt-1 text-[11px] text-muted">{{ $session->region_type }} · Daily rate ₱{{ number_format((float) $session->daily_rate_snapshot, 4) }} · Time In {{ $session->clock_in_at->format('M j, Y g:i A') }} · Time Out {{ $session->clock_out_at?->format('M j, Y g:i A') ?? '—' }} · {{ $session->worked_minutes !== null ? number_format($session->worked_minutes / 60, 2).' hours · ₱'.number_format((float) $session->gross_pay, 2) : 'ongoing' }}</p>
                    @if($session->overtime_review)<p class="mt-1 text-xs font-semibold text-amber-800">More than 8 hours — held for overtime policy review.</p>@endif
                    <details class="mt-3"><summary class="cursor-pointer text-xs font-semibold text-primary">Correct time log / view audit history</summary>
                        <form method="POST" action="{{ route('logistics.interhub-attendance.correct', $session) }}" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf @method('PATCH')
                            <label class="text-[11px] font-semibold">Time In<input type="datetime-local" name="clock_in_at" required value="{{ $session->clock_in_at->format('Y-m-d\TH:i') }}" class="mt-1 min-h-10 w-full rounded border border-line px-2 text-sm"></label>
                            <label class="text-[11px] font-semibold">Time Out<input type="datetime-local" name="clock_out_at" value="{{ $session->clock_out_at?->format('Y-m-d\TH:i') }}" class="mt-1 min-h-10 w-full rounded border border-line px-2 text-sm"></label>
                            <label class="text-[11px] font-semibold sm:col-span-2">Required correction reason<textarea name="reason" required minlength="5" maxlength="1000" class="mt-1 min-h-16 w-full rounded border border-line px-2 py-2 text-sm" placeholder="Explain why this scan time needs correction"></textarea></label>
                            <button class="min-h-9 justify-self-start rounded bg-primary px-3 text-xs font-semibold text-white">Save correction</button>
                        </form>
                        <div class="mt-4 space-y-2 border-t border-line pt-3">@forelse($session->events as $event)<div class="text-[11px]"><strong>{{ $event->event_type }}</strong> · {{ $event->created_at->format('M j, Y g:i A') }} · {{ $event->actor?->name ?? 'Former user' }} @if($event->reason)<p class="text-muted">Reason: {{ $event->reason }}</p>@endif @if($event->old_values)<pre class="mt-1 overflow-x-auto whitespace-pre-wrap rounded bg-page-secondary p-2 text-[10px] text-muted">Before: {{ json_encode($event->old_values, JSON_PRETTY_PRINT) }}
After: {{ json_encode($event->new_values, JSON_PRETTY_PRINT) }}</pre>@endif</div>@empty<p class="text-xs text-muted">No audit events.</p>@endforelse</div>
                    </details>
                </article>
            @empty<p class="text-sm text-muted">No Interhub shift logs yet.</p>@endforelse
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-lg font-semibold text-ink">Generate payroll period</h2><p class="mt-1 text-xs text-muted">Closed shifts are grouped by Time Out date, added once, and retain their original rate and calculated pay. Shifts over 8 hours stay held for review.</p>
        <form method="POST" action="{{ route('logistics.interhub-attendance.payroll.store') }}" class="mt-4 grid gap-3 sm:grid-cols-3 sm:items-end">@csrf
            <label class="text-xs font-semibold">Period start<input type="date" name="period_start" value="{{ old('period_start', now()->startOfMonth()->toDateString()) }}" required class="mt-1 min-h-10 w-full rounded-lg border border-line bg-white px-3 text-sm"></label>
            <label class="text-xs font-semibold">Period end<input type="date" name="period_end" value="{{ old('period_end', now()->toDateString()) }}" required class="mt-1 min-h-10 w-full rounded-lg border border-line bg-white px-3 text-sm"></label>
            <button class="min-h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white">Generate payroll</button>
        </form>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-lg font-semibold text-ink">Payroll history and payment records</h2>
        <div class="mt-4 space-y-4">
            @forelse($interhubPayrollBatches as $batch)
                <article class="rounded-lg border border-line p-4"><div class="flex flex-wrap justify-between gap-2"><strong class="text-sm">{{ $batch->period_start->format('M j, Y') }} – {{ $batch->period_end->format('M j, Y') }}</strong><span class="text-xs text-muted">{{ $batch->items->count() }} shifts · {{ $batch->items->where('status', 'PAID')->count() }} paid</span></div>
                    @forelse($batch->items->groupBy('rider_profile_id') as $items)
                        @php($driver = $items->first()->riderProfile)
                        @php($payrollTotal = $items->whereIn('status', ['PENDING', 'PAID'])->sum('amount'))
                        @php($overtimeHold = $items->where('status', 'REVIEW_REQUIRED')->sum('amount'))
                        <div class="mt-3 rounded border border-line p-3"><div class="flex flex-wrap justify-between gap-2 text-xs"><strong>{{ $driver?->user?->name ?? 'Interhub driver' }}</strong><span class="text-right"><strong>Payroll total ₱{{ number_format((float) $payrollTotal, 2) }}</strong>@if($overtimeHold > 0)<br><span class="text-amber-800">Overtime estimate held ₱{{ number_format((float) $overtimeHold, 2) }}</span>@endif · {{ $items->count() }} shifts</span></div>
                            @foreach($items as $item)
                                <div class="mt-3 border-t border-line pt-3 text-xs"><div class="flex flex-wrap justify-between gap-2"><span>{{ $item->session?->manifest?->manifest_number ?? 'No manifest' }} · {{ $item->session?->clock_in_at?->format('M j') }} · {{ number_format($item->worked_minutes / 60, 2) }}h · ₱{{ number_format((float) $item->amount, 2) }}</span><strong>{{ str($item->status)->replace('_', ' ')->headline() }}</strong></div>
                                    @if($item->status === 'PENDING')<form method="POST" enctype="multipart/form-data" action="{{ route('logistics.interhub-attendance.payroll-items.paid', $item) }}" class="mt-3 grid gap-2 sm:grid-cols-2">@csrf<label class="text-[11px] font-semibold">Payment reference<input name="payment_reference" required maxlength="160" class="mt-1 min-h-9 w-full rounded border border-line px-2 text-sm" placeholder="Bank / GCash transaction reference"></label><label class="text-[11px] font-semibold">Proof of payment<input type="file" name="proof" required accept=".jpg,.jpeg,.png,.pdf" class="mt-1 block min-h-9 w-full text-xs"></label><label class="text-[11px] font-semibold sm:col-span-2">Notes (optional)<input name="payment_notes" maxlength="1000" class="mt-1 min-h-9 w-full rounded border border-line px-2 text-sm"></label><button class="min-h-9 justify-self-start rounded bg-primary px-3 text-xs font-semibold text-white">Record paid</button></form>
                                    @elseif($item->status === 'REVIEW_REQUIRED')<p class="mt-2 text-[11px] text-amber-800">More than 8 hours: hold this item until an overtime rule is configured. The displayed amount is the base-rate estimate.</p>
                                    @else<p class="mt-2 text-[11px] text-muted">Paid {{ $item->paid_at?->format('M j, Y g:i A') }} · Ref {{ $item->payment_reference }} · <a class="font-semibold text-primary underline" href="{{ route('logistics.interhub-attendance.payroll-items.proof', $item) }}">View proof</a> · {{ $item->payment_notes }}</p>@endif
                                </div>
                            @endforeach
                        </div>
                    @empty<p class="mt-3 text-xs text-muted">No completed shifts were included in this pay period.</p>@endforelse
                </article>
            @empty<p class="text-sm text-muted">No payroll period has been generated yet.</p>@endforelse
        </div>
        {{ $interhubPayrollBatches->links() }}
    </section>
@endif
