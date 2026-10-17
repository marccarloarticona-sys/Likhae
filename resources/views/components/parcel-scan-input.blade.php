@props([
    'name',
    'methodName',
    'id',
    'value' => '',
    'required' => true,
])

<div class="grid min-w-56 gap-2" data-parcel-scanner data-scanner-autosubmit="true">
    <div class="overflow-hidden rounded-lg border border-line bg-black">
        <video data-scanner-video class="hidden aspect-video w-full object-cover" playsinline muted></video>
        <div data-scanner-placeholder class="flex aspect-video items-center justify-center px-4 text-center text-[10px] text-white">Camera is off.</div>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" data-scanner-start class="rounded-lg bg-primary px-3 py-2 text-[10px] font-semibold text-white">Scan QR / Barcode</button>
        <button type="button" data-scanner-stop class="hidden rounded-lg border border-line bg-white px-3 py-2 text-[10px] font-semibold text-ink">Stop Camera</button>
    </div>
    <input id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @required($required) autocomplete="off" data-scanner-input class="min-w-0 rounded-lg border border-line bg-white px-3 py-2 text-[10px] text-ink" placeholder="Scan or enter tracking number">
    <input type="hidden" name="{{ $methodName }}" value="MANUAL" data-scanner-method>
    <p data-scanner-message class="min-h-4 text-[9px] text-muted" aria-live="polite">Camera scan or manual tracking entry is accepted.</p>
</div>
