@extends('Logistics.app')

@section('title', 'Parcel Scanner - LIKHAE Logistics')

@section('content')
<div class="border border-line bg-surface p-8" data-parcel-scanner>
    <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Scanner</span>
    <h1 class="mt-3 text-2xl font-bold text-ink">Scan Parcel</h1>
    <p class="mt-3 text-sm text-muted">Scan the waybill QR or barcode, or enter its tracking number manually.</p>

    <div class="mt-6 overflow-hidden border border-line bg-black">
        <video data-scanner-video class="hidden aspect-video w-full object-cover" playsinline muted></video>
        <div data-scanner-placeholder class="flex aspect-video items-center justify-center px-6 text-center text-sm text-white">Camera is off.</div>
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        <button type="button" data-scanner-start class="bg-primary px-5 py-3 text-sm font-semibold text-white">Start camera</button>
        <button type="button" data-scanner-stop class="hidden border border-line bg-white px-5 py-3 text-sm font-semibold text-ink">Stop camera</button>
    </div>
    <p data-scanner-message class="mt-2 text-xs text-muted" aria-live="polite"></p>

    @if($errors->any())
        <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
    @endif

    <form method="GET" action="{{ route('logistics.parcels.receive') }}" class="mt-6 flex flex-col gap-3 sm:flex-row" data-scanner-form>
        <input type="hidden" name="scan_method" value="{{ request('scan_method', 'MANUAL') }}" data-scanner-method>
        <input name="tracking" value="{{ old('tracking') }}" required autofocus class="min-h-12 flex-1 border border-line bg-white px-4 text-sm" placeholder="Tracking number or order number" data-scanner-input>
        <button class="bg-primary px-6 py-3 text-sm font-semibold text-white">Find parcel</button>
    </form>
</div>
@endsection
