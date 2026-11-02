@extends('layouts.rider.app')
@section('title', 'Inter-Hub Transfers - LIKHAE')
@section('content')
<div class="flex flex-col gap-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase tracking-[.18em] text-primary">Interhub driver</span>
            <h1 class="mt-2 text-2xl font-bold text-ink">Inter-hub transfers</h1>
            <p class="mt-2 max-w-3xl text-sm text-muted">Review every inter-hub manifest assigned to you, including completed trips and their recorded custody milestones.</p>
        </div>
        <a href="{{ route('rider.dashboard') }}" class="rounded-lg border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink">Back to dashboard</a>
    </header>

    @if(session('status'))<div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700" role="alert">{{ $errors->first() }}</div>@endif

    @if(!$ready)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Inter-hub transfer history is unavailable until the transfer custody migrations are installed.</div>
    @else
        <nav class="flex flex-wrap gap-2" aria-label="Transfer list filter">
            @foreach(['all' => 'All transfers', 'active' => 'In progress', 'history' => 'Completed history'] as $key => $label)
                <a href="{{ route('rider.transfers', ['view' => $key, 'from' => $filters['from'], 'to' => $filters['to']]) }}"
                   @if($filters['view'] === $key) aria-current="page" @endif
                   class="rounded-lg border px-4 py-2 text-xs font-semibold {{ $filters['view'] === $key ? 'border-primary bg-primary text-white' : 'border-line bg-surface text-ink' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('rider.transfers') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface p-4">
            <input type="hidden" name="view" value="{{ $filters['view'] }}">
            <label class="grid gap-1 text-xs font-semibold text-ink">From date<input type="date" name="from" value="{{ $filters['from'] }}" class="h-10 rounded-lg border border-line bg-white px-3"></label>
            <label class="grid gap-1 text-xs font-semibold text-ink">To date<input type="date" name="to" value="{{ $filters['to'] }}" class="h-10 rounded-lg border border-line bg-white px-3"></label>
            <button class="h-10 rounded-lg bg-primary px-4 text-xs font-semibold text-white">Apply dates</button>
            @if($filters['from'] !== '' || $filters['to'] !== '')<a href="{{ route('rider.transfers', ['view' => $filters['view']]) }}" class="h-10 rounded-lg border border-line px-4 py-3 text-xs font-semibold text-ink">Clear dates</a>@endif
        </form>

        <section class="overflow-hidden rounded-xl border border-line bg-surface" aria-label="Assigned transfer manifests">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-semibold text-ink">{{ $filters['view'] === 'history' ? 'Completed transfer history' : ($filters['view'] === 'active' ? 'Transfers in progress' : 'All assigned transfers') }}</h2>
                <p class="mt-1 text-xs text-muted">{{ $manifests->total() }} {{ \Illuminate\Support\Str::plural('manifest', $manifests->total()) }} · newest first</p>
            </div>
            <div class="divide-y divide-line">
                @forelse($manifests as $manifest)
                    <article class="p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <a href="{{ route('rider.transfers.show', $manifest->id) }}" class="text-sm font-bold text-primary underline">{{ $manifest->manifest_number }}</a>
                                <p class="mt-1 text-sm text-ink">{{ $manifest->originLogisticsCenter?->business_name ?? 'Origin hub' }} <span aria-hidden="true">→</span> {{ $manifest->destinationLogisticsCenter?->business_name ?? 'Destination hub' }}</p>
                                <p class="mt-1 text-xs text-muted">Manifest created {{ $manifest->created_at?->format('M j, Y · g:i A') ?? 'Date unavailable' }}</p>
                            </div>
                            <span class="rounded-full bg-page-secondary px-3 py-1.5 text-xs font-semibold text-ink">{{ str($manifest->status)->replace('_', ' ')->title() }}</span>
                        </div>
                        <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-5">
                            @foreach([
                                ['Accepted', $manifest->driver_accepted_at],
                                ['Released', $manifest->released_at],
                                ['Departed', $manifest->departed_at],
                                ['Arrived', $manifest->arrived_at],
                                ['Received', $manifest->received_at],
                            ] as [$label, $time])
                                <div><dt class="text-muted">{{ $label }}</dt><dd class="mt-1 font-semibold text-ink">{{ $time?->format('M j, Y · g:i A') ?? '—' }}</dd></div>
                            @endforeach
                        </dl>
                        @if($manifest->status === \App\Models\Logistics\TransferManifest::CANCELLED && $manifest->cancelled_at)
                            <p class="mt-3 text-xs text-muted">Cancelled {{ $manifest->cancelled_at->format('M j, Y · g:i A') }}</p>
                        @endif
                    </article>
                @empty
                    <div class="p-8 text-center text-sm text-muted">{{ $filters['view'] === 'history' ? 'No completed transfers match these dates yet.' : 'No assigned transfer manifests match these filters.' }}</div>
                @endforelse
            </div>
        </section>
        <div>{{ $manifests->links() }}</div>
    @endif
</div>
@endsection
