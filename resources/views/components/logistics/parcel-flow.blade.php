@props(['current'])

@php
    $steps = [
        ['key' => 'receive', 'label' => '1. Receive & Scan', 'detail' => 'Verify the waybill', 'href' => route('logistics.parcels.receive')],
        ['key' => 'sort', 'label' => '2. Address & Area', 'detail' => 'Sort by destination', 'href' => route('logistics.sorting')],
        ['key' => 'assign', 'label' => '3. Area Rider', 'detail' => 'Assign eligible rider', 'href' => route('logistics.dispatch')],
        ['key' => 'monitor', 'label' => '4. Monitor', 'detail' => 'Track final delivery', 'href' => route('logistics.parcels.tracking')],
    ];
    $currentIndex = collect($steps)->search(fn ($step) => $step['key'] === $current);
@endphp

<section class="border border-line bg-surface p-5" aria-label="Sorting center parcel workflow">
    <div class="mb-4">
        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Sorting Center Workflow</p>
        <p class="mt-1 text-xs text-muted">Receive and scan, read the address, sort by destination area, then assign that area's rider.</p>
    </div>
    <ol class="grid gap-3 md:grid-cols-4">
        @foreach($steps as $index => $step)
            @php($isCurrent = $step['key'] === $current)
            @php($isDone = is_int($currentIndex) && $index < $currentIndex)
            <li class="border p-4 {{ $isCurrent ? 'border-primary bg-primary-soft' : ($isDone ? 'border-green-200 bg-green-50' : 'border-line bg-page') }}">
                <a href="{{ $step['href'] }}" class="block">
                    <strong class="block text-xs {{ $isCurrent ? 'text-primary' : 'text-ink' }}">{{ $step['label'] }}</strong>
                    <span class="mt-1 block text-[10px] text-muted">{{ $step['detail'] }}</span>
                </a>
            </li>
        @endforeach
    </ol>
</section>
