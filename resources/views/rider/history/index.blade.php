@extends('layouts.rider.app')

@section('title', 'Delivery History - LIKHAE Rider')

@section('content')
<div class="flex flex-col gap-8">
    @if(session('status'))
        <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())<div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>@endif

    <section class="flex flex-col gap-3">
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Rider History</span>
        <h1 class="text-2xl font-bold tracking-tight text-ink">Pickup and Delivery History</h1>
        <p class="text-sm text-muted">Completed assignments and delivery attempts from your rider account.</p>
    </section>

    <form method="GET" action="{{ route('rider.history') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-line bg-surface p-5">
        <label class="grid gap-1 text-xs font-semibold text-ink">From date<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="h-10 border border-line bg-white px-3"></label>
        <label class="grid gap-1 text-xs font-semibold text-ink">To date<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="h-10 border border-line bg-white px-3"></label>
        <button class="h-10 rounded-xl bg-primary px-4 text-xs font-semibold text-white">Apply date filter</button>
        @if(filled($filters['from'] ?? null) || filled($filters['to'] ?? null))<a href="{{ route('rider.history') }}" class="h-10 rounded-xl border border-line px-4 py-3 text-xs font-semibold text-ink">Clear</a>@endif
    </form>

    <section class="overflow-hidden rounded-3xl border border-line bg-surface">
        <div class="border-b border-line px-8 py-6">
            <h2 class="text-lg font-bold text-ink">History</h2>
            <p class="mt-2 text-xs text-muted">This list uses your real rider assignments only.</p>
        </div>

        <div class="divide-y divide-line">
            @forelse($history as $parcel)
                <article class="flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex gap-3">
                        <img src="{{ $parcel['image'] }}" alt="" class="h-14 w-14 rounded-xl border border-line object-cover">
                        <div>
                            <h3 class="text-base font-bold text-ink">{{ $parcel['tracking'] }}</h3>
                            <p class="mt-1 text-sm font-semibold text-ink">{{ $parcel['job_type'] }} · {{ $parcel['status_label'] }}</p>
                            <p class="mt-1 text-sm text-muted">Buyer: {{ $parcel['buyer'] }}</p>
                            <p class="mt-1 text-sm text-muted">{{ $parcel['updated'] }} · Earnings: {{ $parcel['earning'] }}</p>
                            @if($parcel['failure_reason'])
                                <p class="mt-2 text-sm text-red-700">Reason: {{ $parcel['failure_reason'] }}</p>
                            @endif
                        </div>
                    </div>
                    <span class="rounded-full bg-primary-soft px-4 py-2 text-xs font-semibold text-primary">{{ $parcel['status_label'] }}</span>
                </article>
            @empty
                <div class="p-8 text-sm text-muted">No rider history found yet.</div>
            @endforelse
        </div>
    </section>
    <div>{{ $assignments->links() }}</div>

    <section class="overflow-hidden rounded-3xl border border-line bg-surface">
        <div class="border-b border-line px-8 py-6">
            <h2 class="text-lg font-bold text-ink">Return custody history</h2>
            <p class="mt-2 text-xs text-muted">Parcels you handled as the original delivery rider or a return rider, with each recorded custody step.</p>
        </div>

        <div class="divide-y divide-line">
            @forelse($returnHistory as $return)
                <article class="grid gap-5 p-6 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-bold text-ink">{{ $return['tracking'] }}</h3>
                            <span class="rounded-full bg-primary-soft px-3 py-1 text-xs font-semibold text-primary">{{ $return['status'] }}</span>
                        </div>
                        <p class="mt-2 text-sm text-muted">{{ $return['seller'] }} · {{ $return['order'] }}</p>
                        <p class="mt-1 text-sm text-muted">Center: {{ $return['center'] }} · Your role: {{ $return['involvement'] }}</p>
                        <p class="mt-1 text-sm text-ink">Disposition: <strong>{{ $return['disposition'] }}</strong></p>
                        <p class="mt-1 text-sm text-muted">Reason: {{ $return['reason'] }}</p>
                        <p class="mt-1 text-xs text-muted">Last updated {{ $return['updated'] }}</p>
                        <p class="mt-3 rounded-xl border border-line bg-white px-4 py-3 text-sm font-medium text-ink">{{ $return['next_step'] }}</p>
                    </div>

                    <details class="self-start rounded-2xl border border-line bg-white p-4 text-sm">
                        <summary class="cursor-pointer font-semibold text-primary">Custody history ({{ $return['steps']->count() }} steps)</summary>
                        @if($return['steps']->isNotEmpty())
                            <ol class="mt-3 space-y-3 border-l border-line pl-4">
                                @foreach($return['steps'] as $step)
                                    <li>
                                        <strong class="block text-ink">{{ $step['label'] }}</strong>
                                        <span class="text-xs text-muted">{{ $step['actor'] ?: 'Account unavailable' }} · {{ $step['at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <p class="mt-3 text-xs text-muted">No custody step has been recorded yet.</p>
                        @endif
                    </details>
                </article>
            @empty
                <div class="p-8 text-sm text-muted">No return custody history found for your rider account.</div>
            @endforelse
        </div>
    </section>
    @if(method_exists($returnHistory, 'links'))
        <div>{{ $returnHistory->links() }}</div>
    @endif
</div>
@endsection
