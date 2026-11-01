@extends('layouts.logistics.app')

@section('title', 'Riders - LIKHAE Logistics')

@php
    $tabs = [
        'all' => ['label' => 'All riders', 'count' => $tabCounts['all'] ?? 0],
        'local' => ['label' => 'Local riders', 'count' => $tabCounts['local'] ?? 0],
        'interhub' => ['label' => 'Interhub riders', 'count' => $tabCounts['interhub'] ?? 0],
    ];
    $statDescriptions = [
        'Total Riders' => 'Rider accounts assigned to this center',
        'Active' => 'Active or warned rider profiles',
        'Warned' => 'Riders with an active warning',
        'Pending' => 'Profiles awaiting approval',
        'Suspended / Banned' => 'Accounts restricted from operations',
    ];
@endphp

@section('content')
<div class="rider-directory mx-auto flex w-full min-w-0 max-w-[1580px] flex-col gap-5 sm:gap-6">
    <header class="rider-directory__hero relative isolate overflow-hidden rounded-2xl border border-line bg-gradient-to-br from-[#fffaf5] via-surface to-[#ead9ca] px-5 py-6 shadow-sm dark:from-[#1b1513] dark:to-[#30231f] sm:px-7 sm:py-8">
        <div class="pointer-events-none absolute -right-12 -top-20 -z-10 h-64 w-64 rounded-full border-[36px] border-white/20 dark:border-white/[0.03]"></div>
        <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
            <div class="max-w-3xl">
                <span class="rider-directory__overline text-[9px] font-bold uppercase tracking-[0.2em] text-primary">Rider management</span>
                <h1 class="rider-directory__title mt-2 font-display text-[34px] font-semibold tracking-[-0.045em] text-ink sm:text-[42px]">Riders</h1>
                <p class="rider-directory__subtitle mt-2 max-w-2xl text-[11px] leading-5 text-muted sm:text-xs">
                    Manage Local and Interhub Rider accounts assigned to {{ $center->business_name }}.
                </p>
            </div>
            <a href="{{ route('logistics.riders.applications') }}" class="rider-directory__applications inline-flex h-10 w-fit shrink-0 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-[10px] font-semibold text-white shadow-sm transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">
                Rider applications
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
            </a>
        </div>
    </header>

    @if(session('status'))
        <div class="rounded-xl border border-success/25 bg-success-soft px-4 py-3 text-[10px] font-medium text-success" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-danger/25 bg-danger-soft px-4 py-3 text-[10px] font-medium text-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="rider-directory__stats grid" aria-label="Rider account overview">
        @foreach($stats as $stat)
            <article class="rider-directory__stat rounded-xl border border-line bg-surface p-4 shadow-sm sm:p-5">
                <span class="text-[8px] font-bold uppercase tracking-[0.12em] text-muted sm:text-[9px]">{{ $stat['label'] }}</span>
                <strong class="mt-2 block text-[25px] font-semibold tracking-tight text-ink sm:text-[28px]">{{ number_format((int) $stat['value']) }}</strong>
                <small>{{ $statDescriptions[$stat['label']] ?? '' }}</small>
            </article>
        @endforeach
    </section>

    <p class="rider-directory__notice flex items-start gap-2 px-1 text-[9px] leading-5 text-muted sm:text-[10px]">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5m0-8h.01"/></svg>
        Suspending a session signs the Rider out of active browsers. It does not change the Rider’s account status or assignments.
    </p>

    <nav class="rider-directory__tabs flex w-full items-center gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1.5 shadow-sm" aria-label="Filter rider accounts by vehicle group">
        @foreach($tabs as $key => $tab)
            <a href="{{ route('logistics.riders', ['tab' => $key, 'q' => $search ?: null]) }}"
               @if($activeTab === $key) aria-current="page" @endif
               class="rider-directory__tab inline-flex min-h-10 shrink-0 items-center gap-2 rounded-lg px-3 text-[9px] font-semibold transition sm:px-4 sm:text-[10px] {{ $activeTab === $key ? 'is-active bg-primary-soft text-primary shadow-sm' : 'text-muted hover:bg-page-secondary hover:text-ink' }}">
                {{ $tab['label'] }}
                <span class="rider-directory__tab-count rounded-full px-1.5 py-0.5 text-[8px] {{ $activeTab === $key ? 'bg-surface text-primary' : 'bg-page-secondary text-muted' }}">{{ number_format((int) $tab['count']) }}</span>
            </a>
        @endforeach
    </nav>

    <section class="rider-directory__card overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" aria-labelledby="rider-accounts-heading">
        <div class="rider-directory__card-heading flex flex-col justify-between gap-3 border-b border-line bg-surface-secondary px-5 py-4 sm:flex-row sm:items-center sm:px-6">
            <div>
                <span class="text-[8px] font-bold uppercase tracking-[0.18em] text-primary">Rider directory</span>
                <h2 id="rider-accounts-heading" class="mt-1 text-[15px] font-semibold text-ink">{{ $tabs[$activeTab]['label'] ?? 'Rider accounts' }}</h2>
            </div>
            <span class="rider-directory__result-count inline-flex w-fit items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1.5 text-[9px] text-muted">
                <strong class="font-semibold text-ink">{{ number_format($riders->total()) }}</strong>
                {{ $riders->total() === 1 ? 'result' : 'results' }}
            </span>
        </div>

        <form method="GET" action="{{ route('logistics.riders') }}" class="rider-directory__search-form grid gap-3 border-b border-line px-5 py-4 sm:grid-cols-[minmax(220px,1fr)_auto] sm:items-end sm:px-6" role="search">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <label class="rider-directory__search-field relative flex min-w-0 flex-col gap-1.5 text-[9px] font-medium text-ink-secondary">
                <span class="sr-only">Search riders</span>
                <span class="rider-directory__search-control relative block">
                    <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                    <input type="search" name="q" value="{{ $search }}" maxlength="120" placeholder="Search name, email, vehicle, or plate number" aria-label="Search name, email, vehicle, or plate number" class="rider-directory__search-input h-10 w-full rounded-lg border border-line bg-surface pl-9 pr-3 text-[10px] text-ink outline-none transition placeholder:text-muted focus:border-primary focus:ring-2 focus:ring-primary/10">
                </span>
            </label>
            <div class="rider-directory__search-actions flex items-center gap-2">
                <button type="submit" class="rider-directory__search-button inline-flex h-10 items-center justify-center rounded-lg bg-primary px-4 text-[10px] font-semibold text-white shadow-sm transition hover:bg-primary-hover">Search</button>
                @if($search !== '')
                    <a href="{{ route('logistics.riders', ['tab' => $activeTab]) }}" class="rider-directory__clear-button inline-flex h-10 items-center justify-center rounded-lg border border-line px-3 text-[9px] font-semibold text-ink transition hover:bg-page-secondary">Clear</a>
                @endif
            </div>
        </form>

        <form method="POST" action="{{ route('logistics.riders.sessions.revoke') }}" data-rider-session-form>
            @csrf
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <input type="hidden" name="q" value="{{ $search }}">
            <div class="rider-directory__bulk-actions flex flex-col justify-between gap-3 px-5 py-3 sm:flex-row sm:items-center sm:px-6">
                <span class="text-[9px] text-muted" aria-live="polite"><strong class="font-semibold text-ink" data-rider-selected-count>0</strong> selected on this page</span>
                <button type="submit" class="rider-directory__bulk-button inline-flex h-9 w-fit items-center justify-center gap-2 rounded-lg border border-danger/20 bg-danger-soft px-3 text-[9px] font-semibold text-danger transition hover:border-danger/35 hover:bg-danger/10 disabled:cursor-not-allowed disabled:opacity-50" data-rider-session-bulk-submit data-unavailable="{{ $sessionManagementAvailable ? 'false' : 'true' }}" disabled @disabled(! $sessionManagementAvailable)>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4 6v5c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V6l-8-3Z"/><path stroke-linecap="round" d="M9 12h6"/></svg>
                    Suspend selected sessions
                </button>
            </div>

            <div class="rider-directory__table-wrap overflow-x-auto border-t border-line">
                <table class="rider-directory__table w-full min-w-[1040px] border-collapse text-left">
                    <thead class="bg-page-secondary text-[8px] font-bold uppercase tracking-[0.12em] text-muted">
                        <tr>
                            <th class="w-10 px-4 py-3"><input type="checkbox" data-rider-select-all aria-label="Select all Riders on this page" class="h-3.5 w-3.5 rounded border-line text-primary focus:ring-primary" @disabled(! $sessionManagementAvailable)></th>
                            <th class="px-3 py-3">Rider</th>
                            <th class="px-3 py-3">Rider type</th>
                            <th class="px-3 py-3">Area &amp; capacity</th>
                            <th class="px-3 py-3">Active sessions</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse($riders as $rider)
                            @php
                                $activeArea = match ($rider->vehicleRole()) {
                                    'LOCAL_BARANGAY' => $rider->areaAssignments->firstWhere('is_active', true)?->serviceArea?->name ?: 'Unassigned',
                                    'INTER_LOGISTICS' => 'Hub-to-hub only',
                                    default => 'Not eligible for assignments',
                                };
                                $classification = match ($rider->vehicleRole()) {
                                    'LOCAL_BARANGAY' => 'Local delivery',
                                    'INTER_LOGISTICS' => 'Interhub driver',
                                    default => 'Unclassified vehicle',
                                };
                                $statusClass = match (strtoupper((string) $rider->status)) {
                                    'ACTIVE', 'WARNED' => 'border-success/20 bg-success-soft text-success',
                                    'PENDING' => 'border-warning/25 bg-warning-soft text-warning',
                                    'SUSPENDED', 'BANNED', 'DEACTIVATED' => 'border-danger/20 bg-danger-soft text-danger',
                                    default => 'border-line bg-page-secondary text-muted',
                                };
                            @endphp
                        <tr class="rider-directory__row transition hover:bg-page-secondary/60">
                                <td class="px-4 py-4 align-top"><input type="checkbox" name="rider_ids[]" value="{{ $rider->id }}" aria-label="Select {{ $rider->user?->name ?? 'Rider' }}" data-rider-session-checkbox class="mt-1 h-3.5 w-3.5 rounded border-line text-primary focus:ring-primary" @disabled(! $sessionManagementAvailable)></td>
                                <td class="px-3 py-4">
                                    @php
                                        $riderName = $rider->user?->name ?? 'Rider';
                                        $initials = collect(explode(' ', trim($riderName)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'R';
                                    @endphp
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="rider-directory__avatar grid h-9 w-9 shrink-0 place-items-center rounded-full border border-primary/15 bg-primary-soft text-[9px] font-bold text-primary" aria-hidden="true">{{ $initials }}</span>
                                        <span class="min-w-0">
                                            <strong class="block max-w-[220px] truncate text-[10px] font-semibold text-ink">{{ $riderName }}</strong>
                                            <span class="mt-0.5 block max-w-[240px] truncate text-[8px] text-muted">{{ $rider->user?->email ?? 'No email recorded' }}</span>
                                            <span class="mt-0.5 block text-[8px] font-medium tracking-wide text-muted-light">RID-{{ str_pad((string) $rider->id, 4, '0', STR_PAD_LEFT) }}</span>
                                            @if(($rider->pending_profile_update_count ?? 0) > 0)
                                                <span class="mt-1 inline-flex rounded-full border border-warning/25 bg-warning-soft px-2 py-0.5 text-[7px] font-semibold text-warning">{{ $rider->pending_profile_update_count }} profile update{{ $rider->pending_profile_update_count === 1 ? '' : 's' }} awaiting review</span>
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="inline-flex rounded-full border border-line bg-surface px-2.5 py-1 text-[8px] font-semibold text-ink">{{ $classification }}</span>
                                    <span class="mt-1 block text-[8px] text-muted">{{ str($rider->vehicle_type)->replace('_', ' ')->headline() }}</span>
                                </td>
                                <td class="px-3 py-4">
                                    <strong class="block max-w-[190px] truncate text-[9px] font-semibold text-ink">{{ $activeArea }}</strong>
                                    @if($rider->vehicleRole() === 'LOCAL_BARANGAY')
                                        <span class="mt-1 block text-[8px] text-muted">{{ $rider->active_assignments_count }} active assignment{{ $rider->active_assignments_count === 1 ? '' : 's' }} · {{ $rider->remaining_parcel_capacity }} capacity left</span>
                                        <span class="mt-0.5 block text-[8px] text-muted">{{ $rider->completed_deliveries_today_count }} deliveries today · {{ $rider->is_online ? 'Online' : 'Offline' }}</span>
                                    @elseif($rider->vehicleRole() === 'INTER_LOGISTICS')
                                        <span class="mt-1 block text-[8px] text-muted">{{ $rider->approved_parcel_capacity ?? '—' }} parcels · {{ $rider->approved_weight_capacity_kg ?? '—' }} kg capacity</span>
                                    @else
                                        <span class="mt-1 block text-[8px] text-muted">Not eligible for local or interhub assignment</span>
                                    @endif
                                </td>
                                <td class="px-3 py-4">
                                    @if(! $sessionManagementAvailable)
                                        <span class="text-[8px] text-muted">Unavailable</span>
                                    @elseif($rider->active_session_count > 0)
                                        <strong class="block text-[9px] font-semibold text-ink">{{ $rider->active_session_count }} active {{ \Illuminate\Support\Str::plural('session', $rider->active_session_count) }}</strong>
                                        <span class="mt-1 block text-[8px] text-muted">Can be suspended</span>
                                    @else
                                        <span class="text-[8px] text-muted">No active sessions detected</span>
                                    @endif
                                </td>
                                <td class="px-3 py-4"><span class="rider-directory__status inline-flex rounded-full border px-2.5 py-1 text-[8px] font-semibold {{ $statusClass }}">{{ str($rider->status)->replace('_', ' ')->headline() }}</span></td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('logistics.riders.show', $rider) }}" class="rider-directory__view inline-flex h-8 items-center justify-center rounded-lg border border-line bg-surface px-3 text-[8px] font-semibold text-ink transition hover:bg-page-secondary">View</a>
                                        <button type="submit" name="single_rider_id" value="{{ $rider->id }}" data-rider-session-single-submit class="rider-directory__suspend inline-flex h-8 items-center justify-center rounded-lg border border-danger/20 bg-danger-soft px-3 text-[8px] font-semibold text-danger transition hover:border-danger/35 hover:bg-danger/10 disabled:cursor-not-allowed disabled:opacity-50" @disabled(! $sessionManagementAvailable) title="Sign this Rider out of active sessions">Suspend session</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-14 text-center">
                                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-full border border-line bg-page-secondary text-muted" aria-hidden="true">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21a8 8 0 0 1 16 0"/></svg>
                                    </span>
                                    <strong class="mt-3 block text-[11px] font-semibold text-ink">{{ $search !== '' ? 'No riders found' : ($activeTab === 'all' ? 'No rider accounts yet' : 'No '.$tabs[$activeTab]['label'].' found') }}</strong>
                                    <span class="mt-1 block text-[9px] text-muted">{{ $search !== '' ? 'Try another name, email, vehicle, or plate number.' : 'Rider accounts assigned to this center will appear here.' }}</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </form>

        @if($riders->hasPages())
            <div class="rider-directory__pagination flex flex-col gap-3 border-t border-line px-5 py-4 text-[9px] text-muted sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <span>Showing {{ number_format($riders->firstItem()) }}–{{ number_format($riders->lastItem()) }} of {{ number_format($riders->total()) }} riders</span>
                {{ $riders->links() }}
            </div>
        @endif
    </section>

    @unless($sessionManagementAvailable)
        <p class="text-[9px] leading-5 text-muted">Session controls are unavailable because the configured session driver or session table does not support session management.</p>
    @endunless
</div>
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.querySelector('[data-rider-session-form]');
        if (!form) return;

        const selectAll = form.querySelector('[data-rider-select-all]');
        const checkboxes = Array.from(form.querySelectorAll('[data-rider-session-checkbox]'));
        const selectedCount = form.querySelector('[data-rider-selected-count]');
        const bulkSubmit = form.querySelector('[data-rider-session-bulk-submit]');
        const unavailable = bulkSubmit?.dataset.unavailable === 'true';

        const updateSelection = () => {
            const selectable = checkboxes.filter((checkbox) => !checkbox.disabled);
            const selected = selectable.filter((checkbox) => checkbox.checked);
            if (selectedCount) selectedCount.textContent = String(selected.length);
            if (bulkSubmit) bulkSubmit.disabled = unavailable || selected.length === 0;
            if (selectAll) {
                selectAll.checked = selectable.length > 0 && selected.length === selectable.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < selectable.length;
            }
        };

        selectAll?.addEventListener('change', () => {
            checkboxes.filter((checkbox) => !checkbox.disabled).forEach((checkbox) => { checkbox.checked = selectAll.checked; });
            updateSelection();
        });
        form.addEventListener('change', updateSelection);
        form.addEventListener('submit', (event) => {
            const isSingle = event.submitter?.matches('[data-rider-session-single-submit]') === true;
            const selected = checkboxes.filter((checkbox) => !checkbox.disabled && checkbox.checked).length;
            if (!isSingle && selected === 0) {
                event.preventDefault();
                return;
            }
            const message = isSingle
                ? 'Suspend this Rider’s active sessions and sign them out? They will need to sign in again.'
                : `Suspend ${selected} selected Rider session${selected === 1 ? '' : 's'} and sign them out? They will need to sign in again.`;
            if (!window.confirm(message)) event.preventDefault();
        });
        updateSelection();
    })();
</script>
@endpush
