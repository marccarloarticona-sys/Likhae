@extends('Logistics.app')

@section('title', 'Hub Transfers - LIKHAE Logistics')

@section('content')
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Custody Operations</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink">Inter-hub transfers</h1>
            <p class="mt-1 text-[10px] text-muted">{{ $center->business_name }} · PSGC-scoped outgoing and incoming custody.</p>
        </div>
        <a href="{{ route('logistics.dashboard') }}" class="rounded-lg border border-line px-4 py-3 text-[10px] font-semibold text-ink">Dashboard</a>
    </div>

    @if(session('status'))<div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-[10px] text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[10px] text-red-700">{{ $errors->first() }}</div>@endif

    @if(! $transferReady)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
            <h2 class="text-[13px] font-semibold">Transfer operations are disabled</h2>
            <p class="mt-2 text-[10px] leading-5">The additive transfer schema has not been installed. Existing parcel operations remain available; no cross-hub movement is downgraded or silently activated.</p>
        </section>
    @else
        <section class="rounded-xl border border-line bg-surface p-5">
            <h2 class="text-[13px] font-semibold text-ink">Create outgoing manifest</h2>
            <form method="POST" action="{{ route('logistics.transfers.store') }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
                @csrf
                <label class="text-[10px] font-semibold text-ink">Destination hub<select name="destination_logistics_center_id" required class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-3 text-[10px] font-normal"><option value="">Select hub</option>@foreach($destinations as $destination)<option value="{{ $destination->id }}">{{ $destination->business_name }}{{ $destination->code ? ' · '.$destination->code : '' }}</option>@endforeach</select></label>
                <label class="text-[10px] font-semibold text-ink">Notes<textarea name="notes" rows="1" maxlength="2000" class="mt-1 w-full rounded-lg border border-line px-3 py-3 text-[10px] font-normal"></textarea></label>
                <button type="submit" class="rounded-lg bg-primary px-4 py-3 text-[10px] font-semibold text-white">Create Draft</button>
            </form>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Outgoing manifests</h2>
                <div class="mt-4 space-y-2">@forelse($outgoing as $manifest)<a href="{{ route('logistics.transfers.show', $manifest) }}" class="block rounded-lg border border-line p-3 hover:border-primary"><div class="flex justify-between gap-3 text-[10px] font-semibold text-ink"><span>{{ $manifest->manifest_number }}</span><span>{{ $manifest->status }}</span></div><p class="mt-1 text-[10px] text-muted">{{ $manifest->items_count }} parcel leg(s) · {{ $manifest->created_at?->format('M d, Y H:i') }}</p></a>@empty<p class="text-[10px] text-muted">No outgoing manifests.</p>@endforelse</div>
            </section>
            <section class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-[13px] font-semibold text-ink">Incoming manifests</h2>
                <div class="mt-4 space-y-2">@forelse($incoming as $manifest)<a href="{{ route('logistics.transfers.show', $manifest) }}" class="block rounded-lg border border-line p-3 hover:border-primary"><div class="flex justify-between gap-3 text-[10px] font-semibold text-ink"><span>{{ $manifest->manifest_number }}</span><span>{{ $manifest->status }}</span></div><p class="mt-1 text-[10px] text-muted">{{ $manifest->items_count }} parcel leg(s) · {{ $manifest->created_at?->format('M d, Y H:i') }}</p></a>@empty<p class="text-[10px] text-muted">No incoming manifests.</p>@endforelse</div>
            </section>
        </div>
    @endif
</div>
@endsection
