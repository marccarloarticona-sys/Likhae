@extends('Rider.app')

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
</div>
@endsection
