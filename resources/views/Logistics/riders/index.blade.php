@extends('Logistics.app')

@section('title', 'Riders - LIKHAE Logistics')

@section('content')
<div class="flex w-full flex-col gap-6">
    <section class="flex flex-col gap-5 rounded-xl border border-line bg-surface p-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Rider Management</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink sm:text-[38px]">Riders</h1>
            <p class="mt-2 max-w-[650px] text-[11px] leading-6 text-muted">
                All rider accounts registered with this logistics center, including pending and inactive accounts.
            </p>
        </div>

        <a href="{{ route('logistics.riders.applications') }}" class="inline-flex h-10 items-center justify-center rounded-lg bg-primary px-4 text-[10px] font-semibold text-white">
            Rider Applications
        </a>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach($stats as $stat)
            <article class="rounded-xl border border-line bg-surface p-4">
                <span class="text-[9px] text-muted">{{ $stat['label'] }}</span>
                <strong class="mt-2 block text-[24px] font-bold text-ink">{{ $stat['value'] }}</strong>
            </article>
        @endforeach
    </section>

    <section class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-5 py-4">
            <h2 class="text-[13px] font-semibold text-ink">Rider Accounts</h2>
        </div>

        @forelse($riders as $rider)
            @php
                $activeArea = $rider->areaAssignments->firstWhere('is_active', true)?->serviceArea?->name;
            @endphp
            <article class="flex flex-col gap-4 border-b border-line p-5 last:border-b-0 md:flex-row md:items-center md:justify-between">
                <div>
                    <strong class="block text-[11px] font-semibold text-ink">{{ $rider->user?->name ?? 'Rider' }}</strong>
                    <span class="mt-1 block text-[9px] text-muted">{{ $rider->user?->email ?? 'No email recorded' }}</span>
                    <span class="mt-1 block text-[8px] text-muted">RID-{{ str_pad((string) $rider->id, 4, '0', STR_PAD_LEFT) }} · {{ $activeArea ?: 'Unassigned' }}</span>
                    <span class="mt-1 block text-[8px] text-muted">{{ $rider->active_pickup_assignments_count }} / 10 active pickup parcels</span>
                </div>

                <div class="flex items-center gap-2">
                    <span class="rounded-full bg-primary-soft px-3 py-1 text-[8px] font-semibold text-primary">{{ str($rider->status)->headline() }}</span>
                    <a href="{{ route('logistics.riders.show', $rider) }}" class="rounded-lg border border-line px-3 py-2 text-[9px] font-semibold text-ink">View</a>
                </div>
            </article>
        @empty
            <div class="p-5 text-[10px] text-muted">No rider accounts are available.</div>
        @endforelse
    </section>

    @if($riders->hasPages())
        <div class="rounded-xl border border-line bg-surface px-5 py-4">
            {{ $riders->links() }}
        </div>
    @endif
</div>
@endsection
