@extends('layouts.logistics.app')

@section('title', 'Rider Applications - LIKHAE Logistics')

@php
    $riders = $logisticsRiderApplications ?? [];
    $summary = $logisticsRiderSummary ?? [
        ['label' => 'Total applications', 'value' => 0, 'description' => 'All rider registrations', 'tone' => 'primary', 'icon' => 'applications'],
        ['label' => 'Pending', 'value' => 0, 'description' => 'Awaiting verification', 'tone' => 'warning', 'icon' => 'clock'],
        ['label' => 'Approved', 'value' => 0, 'description' => 'Verified rider accounts', 'tone' => 'success', 'icon' => 'check'],
        ['label' => 'Rejected', 'value' => 0, 'description' => 'Applications declined', 'tone' => 'danger', 'icon' => 'x'],
    ];
    $statusFilter = $statusFilter ?? null;
    $statusOptions = [
        \App\Models\Auth\RegistrationApplication::STATUS_PENDING => 'Pending',
        \App\Models\Auth\RegistrationApplication::STATUS_UNDER_REVIEW => 'Under review',
        \App\Models\Auth\RegistrationApplication::STATUS_APPROVED => 'Approved',
        \App\Models\Auth\RegistrationApplication::STATUS_REJECTED => 'Rejected',
        \App\Models\Auth\RegistrationApplication::STATUS_CANCELLED => 'Cancelled',
    ];
@endphp

@section('content')
<div class="mx-auto flex w-full max-w-[1680px] flex-col gap-5 sm:gap-6">
    <header class="relative isolate overflow-hidden rounded-2xl border border-line bg-gradient-to-br from-surface via-surface to-[#efe0d2] px-5 py-6 shadow-sm dark:to-[#2a211e] sm:px-7 sm:py-8">
        <div class="pointer-events-none absolute -right-12 -top-20 -z-10 h-64 w-64 rounded-full border-[36px] border-white/20 dark:border-white/[0.03]"></div>
        <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
            <div class="max-w-3xl">
                <span class="inline-flex items-center gap-2 text-[9px] font-bold uppercase tracking-[0.2em] text-primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>
                    Rider onboarding
                </span>
                <h1 class="mt-2 font-display text-[30px] font-semibold tracking-[-0.045em] text-ink sm:text-[38px]">Rider applications</h1>
                <p class="mt-2 max-w-2xl text-[11px] leading-5 text-muted sm:text-xs">
                    Review rider sign-ups assigned to your logistics center and check each application before making a decision.
                </p>
            </div>
            <span class="inline-flex w-fit shrink-0 items-center gap-2 rounded-full border border-line bg-surface/80 px-3 py-2 text-[9px] font-semibold text-ink-secondary shadow-sm">
                <svg class="h-3.5 w-3.5 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 4h8l4 4v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 4v5h5M8 13h8M8 17h5" />
                </svg>
                Center review queue
            </span>
        </div>
    </header>

    @if(session('success'))
        <div class="rounded-xl border border-success/25 bg-success-soft px-4 py-3 text-[11px] font-medium text-success" role="status">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="rounded-xl border border-danger/25 bg-danger-soft px-4 py-3 text-[11px] font-medium text-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-label="Rider application statistics">
        @foreach($summary as $item)
            @php
                $toneClass = match($item['tone'] ?? 'primary') {
                    'warning' => 'bg-warning-soft text-warning',
                    'success' => 'bg-success-soft text-success',
                    'danger' => 'bg-danger-soft text-danger',
                    default => 'bg-primary-soft text-primary',
                };
            @endphp
            <article class="rounded-xl border border-line bg-surface p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:p-5">
                <div class="flex items-start justify-between gap-3">
                    <span class="text-[8px] font-bold uppercase tracking-[0.12em] text-muted sm:text-[9px]">{{ $item['label'] }}</span>
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg {{ $toneClass }}">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 4h8l4 4v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3Z" />
                            <path d="M15 4v5h5M8 13h8M8 17h5" />
                        </svg>
                    </span>
                </div>
                <strong class="mt-2 block text-[24px] font-semibold tracking-tight text-ink sm:text-[28px]">{{ number_format((int) $item['value']) }}</strong>
                <p class="mt-1 text-[9px] leading-4 text-muted sm:text-[10px]">{{ $item['description'] }}</p>
            </article>
        @endforeach
    </section>

    <section class="overflow-hidden rounded-2xl border border-line bg-surface shadow-sm" aria-labelledby="rider-application-queue-title">
        <div class="flex flex-col justify-between gap-3 border-b border-line bg-surface-secondary px-5 py-4 sm:flex-row sm:items-center sm:px-6">
            <div>
                <span class="text-[8px] font-bold uppercase tracking-[0.18em] text-primary">Review queue</span>
                <h2 id="rider-application-queue-title" class="mt-1 text-[15px] font-semibold text-ink">Applicant list</h2>
            </div>
            <span class="inline-flex w-fit items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1.5 text-[9px] text-muted">
                <strong class="font-semibold text-ink">{{ number_format(count($riders)) }}</strong>
                {{ count($riders) === 1 ? 'result' : 'results' }}
            </span>
        </div>

        <form method="GET" action="{{ route('logistics.riders.applications') }}" class="grid gap-3 border-b border-line px-5 py-4 sm:grid-cols-[minmax(220px,1fr)_auto] sm:items-end sm:px-6" aria-label="Filter rider applications">
            <label class="flex flex-col gap-1.5 text-[9px] font-medium text-ink-secondary">
                Application status
                <select name="status" class="h-10 w-full rounded-lg border border-line bg-surface px-3 text-[10px] text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/10">
                    <option value="">All application statuses</option>
                    @foreach($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-[10px] font-semibold text-white shadow-sm transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">
                    Apply filter
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16M7 12h10m-7 7h4" /></svg>
                </button>
                @if($statusFilter)
                    <a href="{{ route('logistics.riders.applications') }}" class="text-[9px] font-semibold text-muted underline decoration-line underline-offset-4 transition hover:text-primary">Clear filter</a>
                @endif
            </div>
        </form>

        <div class="hidden grid-cols-[minmax(0,1.5fr)_minmax(170px,1fr)_minmax(145px,.9fr)_minmax(120px,.7fr)_auto] gap-4 bg-page-secondary px-6 py-3 text-[8px] font-bold uppercase tracking-[0.12em] text-muted xl:grid">
            <span>Applicant</span>
            <span>Vehicle</span>
            <span>Submitted</span>
            <span>Status</span>
            <span class="sr-only">Action</span>
        </div>

        <div>
            @forelse($riders as $rider)
                @php
                    $nameParts = collect(explode(' ', trim((string) ($rider['name'] ?? 'Rider Applicant'))))->filter();
                    $initials = $nameParts->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
                    $statusSlug = \Illuminate\Support\Str::slug($rider['status'] ?? 'pending');
                    $statusClass = match($statusSlug) {
                        'approved' => 'border-success/20 bg-success-soft text-success',
                        'rejected', 'cancelled' => 'border-danger/20 bg-danger-soft text-danger',
                        'under-review' => 'border-warning/25 bg-warning-soft text-warning',
                        default => 'border-primary/20 bg-primary-soft text-primary',
                    };
                @endphp
                <article class="grid gap-4 border-b border-line px-5 py-4 last:border-b-0 sm:px-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(170px,1fr)_minmax(145px,.9fr)_minmax(120px,.7fr)_auto] xl:items-center">
                    <div class="flex min-w-0 items-center gap-3 xl:col-span-1">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-primary/15 bg-primary-soft text-[10px] font-bold uppercase text-primary" aria-hidden="true">{{ $initials ?: 'R' }}</span>
                        <span class="min-w-0">
                            <strong class="block truncate text-[11px] font-semibold text-ink">{{ $rider['name'] }}</strong>
                            <span class="mt-0.5 block truncate text-[9px] text-muted">{{ $rider['email'] }}</span>
                            @if(!empty($rider['application_number']))
                                <span class="mt-0.5 block truncate text-[8px] font-medium tracking-wide text-muted-light">{{ $rider['application_number'] }}</span>
                            @endif
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-4 xl:contents">
                        <div class="min-w-0">
                            <span class="block text-[8px] font-bold uppercase tracking-[0.1em] text-muted xl:hidden">Vehicle</span>
                            <strong class="mt-1 block truncate text-[10px] font-semibold text-ink xl:mt-0">{{ $rider['vehicle'] }}</strong>
                            <span class="mt-0.5 block truncate text-[9px] text-muted">{{ $rider['plate'] }}</span>
                        </div>
                        <div class="min-w-0">
                            <span class="block text-[8px] font-bold uppercase tracking-[0.1em] text-muted xl:hidden">Submitted</span>
                            <strong class="mt-1 block text-[10px] font-medium text-ink-secondary xl:mt-0">{{ $rider['submitted'] }}</strong>
                            <span class="mt-0.5 block truncate text-[9px] text-muted">{{ $rider['center'] }}</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 xl:contents">
                        <span class="inline-flex w-fit items-center rounded-full border px-2.5 py-1 text-[8px] font-semibold {{ $statusClass }}">{{ $rider['status'] }}</span>
                        <a href="{{ route('logistics.riders.application.show', $rider['id']) }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-line bg-surface px-3 text-[9px] font-semibold text-ink transition hover:border-primary/30 hover:bg-primary-soft hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                            Review application
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
                        </a>
                    </div>
                </article>
            @empty
                <div class="flex flex-col items-center px-6 py-14 text-center sm:py-16">
                    <span class="grid h-12 w-12 place-items-center rounded-full border border-success/20 bg-success-soft text-success" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6" /></svg>
                    </span>
                    <strong class="mt-4 text-[12px] font-semibold text-ink">{{ $statusFilter ? 'No applications match this status' : 'You’re all caught up' }}</strong>
                    <p class="mt-1 max-w-sm text-[10px] leading-5 text-muted">
                        {{ $statusFilter ? 'Try another status filter to see more rider applications.' : 'There are no rider applications assigned to your center right now.' }}
                    </p>
                    @if($statusFilter)
                        <a href="{{ route('logistics.riders.applications') }}" class="mt-4 inline-flex h-9 items-center rounded-lg border border-line px-3 text-[9px] font-semibold text-ink transition hover:bg-surface-secondary">Clear filter</a>
                    @endif
                </div>
            @endforelse
        </div>
    </section>
</div>
@endsection
