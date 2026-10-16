@props([
    'action',
    'tracking' => '',
    'method' => 'MANUAL',
    'title' => 'Scan Parcel',
    'description' => 'Scan the waybill QR or Code 128 barcode, or enter the tracking number manually.',
    'button' => 'Find Parcel',
    'formMethod' => 'GET',
    'inputName' => 'tracking',
    'inputLabel' => 'Tracking number',
    'inputPlaceholder' => 'Enter tracking number',
])

@php
    $normalizedMethod = in_array(strtoupper((string) $method), ['QR', 'BARCODE'], true)
        ? strtoupper((string) $method)
        : 'MANUAL';
    $normalizedFormMethod = in_array(strtoupper((string) $formMethod), ['GET', 'POST'], true)
        ? strtoupper((string) $formMethod)
        : 'GET';
@endphp

<section class="border border-line bg-surface p-5" data-parcel-scanner>
    <h2 class="text-[13px] font-semibold text-ink">{{ $title }}</h2>
    <p class="mt-2 text-[10px] leading-5 text-muted">{{ $description }}</p>

    <div class="relative mt-4 overflow-hidden border border-line bg-black">
        <video data-scanner-video class="hidden aspect-video w-full object-cover" playsinline muted></video>
        <div data-scanner-placeholder class="relative z-0 flex aspect-video items-center justify-center px-6 text-center text-sm text-white">Camera is off.</div>
        <div data-scanner-guide class="pointer-events-none absolute inset-x-6 top-1/2 z-10 hidden h-20 -translate-y-1/2 border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,.28)]" aria-hidden="true"></div>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" data-scanner-start class="bg-primary px-4 py-2 text-[10px] font-semibold text-white">Start Camera</button>
        <button type="button" data-scanner-stop class="hidden border border-line bg-white px-4 py-2 text-[10px] font-semibold text-ink">Stop Camera</button>
        <label class="hidden items-center gap-2 text-[10px] font-semibold text-ink" data-camera-select-wrap>
            Camera
            <select data-camera-select aria-label="Select camera" class="max-w-[14rem] border border-line bg-white px-2 py-2"></select>
        </label>
        <button type="button" data-scanner-torch class="hidden border border-line bg-white px-4 py-2 text-[10px] font-semibold text-ink" aria-pressed="false">Turn Torch On</button>
    </div>
    <p data-scanner-message class="mt-2 min-h-5 text-[10px] font-semibold text-muted" aria-live="polite"></p>

    <div class="my-4 flex items-center gap-3 text-[9px] text-muted"><span class="h-px flex-1 bg-line"></span>OR<span class="h-px flex-1 bg-line"></span></div>
    <form method="{{ $normalizedFormMethod }}" action="{{ $action }}" class="flex flex-col gap-2 sm:flex-row" data-scanner-form>
        @if($normalizedFormMethod === 'POST') @csrf @endif
        <input type="hidden" name="scan_method" value="{{ $normalizedMethod }}" data-scanner-method>
        <input name="{{ $inputName }}" value="{{ $tracking }}" required class="h-10 min-w-0 flex-1 border border-line bg-white px-3 text-[10px] text-ink" placeholder="{{ $inputPlaceholder }}" aria-label="{{ $inputLabel }}" data-scanner-input>
        <button class="bg-primary px-4 py-2 text-[10px] font-semibold text-white">{{ $button }}</button>
    </form>
</section>
