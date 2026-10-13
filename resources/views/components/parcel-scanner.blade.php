@props([
    'action',
    'tracking' => '',
    'method' => 'MANUAL',
    'title' => 'Scan Parcel',
    'description' => 'Scan the waybill QR or Code 128 barcode, or enter the tracking number manually.',
    'button' => 'Find Parcel',
])

@php
    $normalizedMethod = in_array(strtoupper((string) $method), ['QR', 'BARCODE'], true)
        ? strtoupper((string) $method)
        : 'MANUAL';
@endphp

<section class="border border-line bg-surface p-5" data-parcel-scanner>
    <h2 class="text-[13px] font-semibold text-ink">{{ $title }}</h2>
    <p class="mt-2 text-[10px] leading-5 text-muted">{{ $description }}</p>

    <div class="mt-4 overflow-hidden border border-line bg-black">
        <video data-scanner-video class="hidden aspect-video w-full object-cover" playsinline muted></video>
        <div data-scanner-placeholder class="flex aspect-video items-center justify-center px-6 text-center text-sm text-white">Camera is off.</div>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" data-scanner-start class="bg-primary px-4 py-2 text-[10px] font-semibold text-white">Start Camera</button>
        <button type="button" data-scanner-stop class="hidden border border-line bg-white px-4 py-2 text-[10px] font-semibold text-ink">Stop Camera</button>
    </div>
    <p data-scanner-message class="mt-2 min-h-5 text-[10px] font-semibold text-muted" aria-live="polite"></p>

    <div class="my-4 flex items-center gap-3 text-[9px] text-muted"><span class="h-px flex-1 bg-line"></span>OR<span class="h-px flex-1 bg-line"></span></div>
    <form method="GET" action="{{ $action }}" class="flex flex-col gap-2 sm:flex-row" data-scanner-form>
        <input type="hidden" name="scan_method" value="{{ $normalizedMethod }}" data-scanner-method>
        <input name="tracking" value="{{ $tracking }}" required class="h-10 min-w-0 flex-1 border border-line bg-white px-3 text-[10px] text-ink" placeholder="Enter tracking number" data-scanner-input>
        <button class="bg-primary px-4 py-2 text-[10px] font-semibold text-white">{{ $button }}</button>
    </form>
</section>
