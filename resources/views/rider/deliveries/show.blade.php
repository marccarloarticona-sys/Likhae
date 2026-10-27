@extends('layouts.rider.app')

@section('title', 'Delivery Details - LIKHAE Rider')

@push('head')
    @vite(['resources/css/shared/mapbox.css', 'resources/js/shared/mapbox.js'])
@endpush

@section('content')
<div class="flex flex-col gap-8">
    @if(session('status'))
        <div class="rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700">{{ $errors->first() }}</div>
    @endif

    <section class="rounded-3xl border border-line bg-surface p-5 shadow-sm sm:p-7">
        <div class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0">
                <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Delivery details</span>
                <h1 class="mt-2 break-words font-mono text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ $parcel['tracking'] }}</h1>
                <p class="mt-2 text-sm text-muted">Buyer <span class="font-semibold text-ink">{{ $parcel['buyer'] }}</span></p>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 xl:min-w-[25rem]">
                <div class="rounded-xl border border-line bg-page-secondary px-4 py-3">
                    <span class="block text-[10px] font-bold uppercase tracking-[0.14em] text-muted">Shipment</span>
                    <p class="mt-1 text-sm font-semibold text-ink">{{ $parcel['shipment_status_label'] }}</p>
                </div>
                <div class="rounded-xl border border-line bg-page-secondary px-4 py-3">
                    <span class="block text-[10px] font-bold uppercase tracking-[0.14em] text-muted">Your assignment</span>
                    <p class="mt-1 text-sm font-semibold text-ink">{{ $parcel['status_label'] }}</p>
                </div>
            </div>
        </div>
    </section>

    @php
        $deliveryAttempts = $delivery->shipment?->deliveryAttempts ?? collect();
        $latestDeliveryAttempt = $deliveryAttempts->sortByDesc('attempt_number')->first();
        $scheduledRedeliveryAt = $latestDeliveryAttempt?->status === 'RESCHEDULED'
            ? $latestDeliveryAttempt->next_attempt_at
            : null;
        $defaultRedeliveryAt = now()->addDay()->setTime(9, 0);
        $thirdFailureWillStartReturn = $deliveryAttempts->count() >= 2;

        [$deliveryNextStep, $deliveryNextActor] = match ($parcel['status']) {
            'ASSIGNED' => ['Accept this assignment or decline it with a reason.', 'You, the delivery rider'],
            'ACCEPTED' => $returnHandoffPending
                ? ['Bring the parcel to your Logistics Center for a verified return handoff before redelivery.', 'You, then Logistics']
                : ($scheduledRedeliveryAt?->isFuture()
                    ? ['Redelivery is scheduled for '.$scheduledRedeliveryAt->timezone(config('app.timezone'))->format('M j, Y g:i A').'. Start only after the scheduled time.', 'You, the delivery rider']
                    : ['Start the delivery route when ready to depart.', 'You, the delivery rider']),
            'IN_PROGRESS' => ! $parcel['has_buyer_pin']
                ? ['Wait for the Buyer to confirm the exact destination pin; contact them if needed.', 'Buyer']
                : ['Collect the displayed COD amount if due, then upload proof of delivery or record the attempt.', 'You, the delivery rider'],
            'COMPLETED' => ['Confirm receipt in My Orders, or wait for the automatic receipt deadline.', 'Buyer'],
            default => ['Dispatch will review this assignment and route it to an eligible rider.', 'Logistics'],
        };
    @endphp
    <x-shared.status-guidance :status="$parcel['shipment_status_label'].' · '.$parcel['status_label']" :next="$deliveryNextStep" :actor="$deliveryNextActor" />

    @if(!$parcel['has_buyer_pin'])
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950" aria-labelledby="rider-pin-required-heading">
            <h2 id="rider-pin-required-heading" class="font-bold">DELIVERY PIN REQUIRED</h2>
            <p class="mt-2 text-sm">The Buyer has not confirmed an exact destination pin for this order. Navigation will become available once the Buyer confirms the delivery pin. No route, distance, or ETA is available yet.</p>
            @if(filled($parcel['contact']))
                <a class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-amber-900 px-4 py-2 text-sm font-semibold text-white" href="tel:{{ preg_replace('/[^0-9+]/', '', $parcel['contact']) }}">Contact Buyer Â· {{ $parcel['contact'] }}</a>
            @endif
            <a class="mt-3 inline-flex min-h-11 items-center rounded-xl border border-amber-900 px-4 py-2 text-sm font-semibold text-amber-950 sm:ml-2" href="{{ route('rider.messages', ['shipment_id' => $delivery->shipment_id, 'recipient_user_id' => $delivery->shipment?->sellerOrder?->order?->buyer_user_id]) }}">Message Buyer</a>
        </section>
    @endif

    <x-shared.mapbox :markers="$mapMarkers" title="Delivery route map" height="clamp(280px, 30vw, 380px)" user-location-target="destination" :rider-gps="true" :navigation="true" class="rider-delivery-route-map" />

    <section class="grid items-start gap-6 lg:grid-cols-2">
        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Recipient</h2>
            <div class="mt-5 flex flex-wrap gap-3">@foreach(($delivery->shipment?->orderItem ? collect([$delivery->shipment->orderItem]) : ($delivery->shipment?->sellerOrder?->items ?? collect())) as $item)<div class="flex items-center gap-3 rounded-xl border border-line p-3"><x-shared.product-thumbnail :item="$item" size="64"/><div><strong class="block text-sm text-ink">{{ $item->product_name }}</strong><small class="text-muted">{{ $item->variant_description ?: 'Standard' }} Â· Qty {{ $item->quantity }}</small></div></div>@endforeach</div>
            <dl class="mt-5 grid gap-3 text-sm">
                <div><dt class="text-muted">Buyer</dt><dd class="font-semibold text-ink">{{ $parcel['buyer'] }}</dd></div>
                <div><dt class="text-muted">Contact</dt><dd class="font-semibold text-ink">{{ $parcel['contact'] }}</dd></div>
                <div><dt class="text-muted">Delivery Address</dt><dd class="font-semibold text-ink whitespace-pre-line">{{ $parcel['address'] }}</dd></div>
                <div><dt class="text-muted">COD Amount Due</dt><dd class="font-semibold text-ink">₱{{ number_format((float) $parcel['cod_expected'], 2) }}</dd></div>
            </dl>
            @if(filled($parcel['contact']))<a class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink" href="tel:{{ preg_replace('/[^0-9+]/', '', $parcel['contact']) }}">Call Buyer</a>@endif
            <a class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink sm:ml-2" href="{{ route('rider.messages', ['shipment_id' => $delivery->shipment_id, 'recipient_user_id' => $delivery->shipment?->sellerOrder?->order?->buyer_user_id]) }}">Message Buyer</a>
        </article>

        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Delivery Actions</h2>
            <div class="mt-5 grid gap-3">
                @if($deliveryTrip && ($parcel['status'] === 'ASSIGNED' || ($parcel['status'] === 'ACCEPTED' && !$returnHandoffPending && !$scheduledRedeliveryAt?->isFuture())))
                    <p class="rounded-xl border border-primary/20 bg-primary-soft p-4 text-sm text-ink">Batch actions apply to every parcel in Batch #{{ $deliveryTrip->id }}.</p>
                    <a href="{{ route('rider.trips.show', $deliveryTrip->id) }}" class="w-full rounded-xl bg-primary px-5 py-3 text-center text-sm font-semibold text-white">Open delivery batch</a>
                @elseif($parcel['status'] === 'ASSIGNED')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="accept"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Accept Delivery</button></form>
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" class="grid gap-3 rounded-xl border border-line p-4">@csrf @method('PATCH')<input type="hidden" name="action" value="reject"><label class="grid gap-2 text-sm font-semibold text-ink">Reason for declining<textarea name="reason" rows="2" required maxlength="1000" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" placeholder="Explain why you cannot take this delivery">{{ old('reason') }}</textarea></label><button class="w-full rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm font-semibold text-red-700">Decline Delivery</button></form>
                @elseif($parcel['status'] === 'ACCEPTED')
                    @if($returnHandoffPending)
                        <div class="grid gap-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                            <strong>Return the parcel to Logistics first</strong>
                            <span>Staff must scan and confirm receipt before this redelivery can start.</span>
                            <a href="{{ route('rider.returns') }}" class="mt-1 inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-900 px-4 py-2 font-semibold text-white">Open Return Handoff</a>
                        </div>
                    @elseif($scheduledRedeliveryAt?->isFuture())
                        <div class="grid gap-2 rounded-xl border border-primary/20 bg-primary-soft p-4 text-sm text-ink">
                            <strong>Redelivery scheduled</strong>
                            <span>Try again {{ $scheduledRedeliveryAt->timezone(config('app.timezone'))->format('M j, Y \a\t g:i A') }}. The delivery can start after this time.</span>
                        </div>
                    @else
                        <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="start"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Start Delivery</button></form>
                    @endif
                @elseif($parcel['status'] === 'IN_PROGRESS')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" enctype="multipart/form-data" class="grid gap-3" data-delivery-proof-form>
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="delivery_success">
                        <label class="grid gap-2 text-sm font-semibold text-ink">
                            Cash received from buyer
                            <input type="number" name="collected_amount" min="0" step="0.01" value="{{ old('collected_amount') }}" required class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" data-delivery-cash-input>
                            <small class="font-normal text-muted">Enter the actual cash received. The server compares it with the authoritative COD amount; do not deduct rider earnings.</small>
                        </label>
                        <label class="grid gap-2 text-sm font-semibold text-ink">
                            Proof of delivery photo
                            <input type="file" name="proof_file" accept="image/jpeg,image/png,image/webp" capture="environment" required class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" data-delivery-proof-input>
                            <small class="font-normal text-muted">Take a clear photo of the delivered parcel. The photo will be stamped with capture time, rider GPS coordinates, GPS accuracy, and the mapped location. Allow location access when prompted.</small>
                            <span class="hidden rounded-lg border border-line bg-page-secondary px-3 py-2 text-xs font-medium text-muted" aria-live="polite" data-delivery-proof-status></span>
                            <button type="button" class="hidden w-fit rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-ink" data-delivery-location-retry>Retry GPS and stamp photo</button>
                        </label>
                        <div class="hidden rounded-xl border border-line bg-page-secondary p-3" data-delivery-proof-preview-wrap>
                            <img class="mx-auto max-h-64 max-w-full rounded-lg object-contain" alt="Delivery proof preview" data-delivery-proof-preview>
                            <button type="button" class="mt-3 rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-ink" data-delivery-proof-replace>Retake / Replace Photo</button>
                        </div>
                        <button type="submit" class="w-full rounded-xl bg-green-700 px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60" data-delivery-proof-submit disabled>Upload Proof &amp; Mark Delivered</button>
                    </form>
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" class="grid gap-3" data-delivery-failed-form data-third-failure-return="{{ $thirdFailureWillStartReturn ? 'true' : 'false' }}" data-default-next-attempt="{{ $defaultRedeliveryAt->format('Y-m-d\TH:i') }}">
                        @csrf @method('PATCH')<input type="hidden" name="action" value="delivery_failed">
                        <input type="hidden" name="failure_reason" data-delivery-failure-reason>
                        <input type="hidden" name="attempt_status" value="{{ $thirdFailureWillStartReturn ? 'FAILED' : 'RESCHEDULED' }}" data-delivery-failure-attempt-status>
                        <label class="grid gap-2 text-sm font-semibold text-ink">Reason
                            <select required class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" data-delivery-failure-category>
                                <option value="">Select a reason</option><option>Buyer unavailable</option><option>Buyer requested reschedule</option><option>Incorrect or inaccessible location</option><option>Buyer refused parcel</option><option>Unable to contact Buyer</option><option>Other</option>
                            </select>
                        </label>
                        <div class="grid gap-2 rounded-xl border border-line bg-page-secondary p-4" data-delivery-reschedule-panel>
                            <label class="grid gap-2 text-sm font-semibold text-ink">Try delivery again
                                <input type="datetime-local" name="next_attempt_at" required value="{{ $defaultRedeliveryAt->format('Y-m-d\\TH:i') }}" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" data-delivery-next-attempt>
                            </label>
                            <small class="text-xs font-normal text-muted">Default: tomorrow at 9:00 AM. The parcel must be handed back to Logistics and received before redelivery.</small>
                        </div>
                        <p class="hidden rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" data-delivery-auto-return-note>Return to Seller starts automatically for a buyer refusal or the third failed attempt. Bring the parcel to Logistics for a verified handoff.</p>
                        <label class="grid gap-2 text-sm font-semibold text-ink">Notes
                            <textarea rows="3" required maxlength="850" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" placeholder="Add details about this delivery attempt" data-delivery-failure-notes></textarea>
                        </label>
                        <button type="submit" class="w-full rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm font-semibold text-red-700" data-delivery-failure-submit>Review Failed Delivery</button>
                    </form>
                @endif
                <a href="{{ route('rider.deliveries') }}" class="rounded-xl border border-line px-5 py-3 text-center text-sm font-semibold text-ink">Back To Deliveries</a>
            </div>
        </article>
    </section>

</div>
<dialog class="w-[calc(100%-2rem)] max-w-lg rounded-2xl p-0 shadow-2xl backdrop:bg-black/50" data-delivery-confirm-dialog aria-labelledby="delivery-confirm-heading">
    <div class="grid gap-4 p-6">
        <div><h2 id="delivery-confirm-heading" class="text-xl font-bold text-ink">Confirm Successful Delivery</h2><p class="mt-1 text-sm text-muted">This will record the parcel as delivered and submit the proof photo.</p></div>
        <dl class="grid gap-2 rounded-xl bg-page-secondary p-4 text-sm"><div><dt class="text-muted">Buyer</dt><dd class="font-semibold text-ink">{{ $parcel['buyer'] }}</dd></div><div><dt class="text-muted">COD expected</dt><dd class="font-semibold text-ink">₱{{ number_format((float) $parcel['cod_expected'], 2) }}</dd></div><div><dt class="text-muted">Cash received</dt><dd class="font-semibold text-ink" data-confirm-delivery-cash>Not entered</dd></div><div><dt class="text-muted">Proof photo</dt><dd class="font-semibold text-ink" data-confirm-delivery-proof>Not attached</dd></div></dl>
        <div class="flex flex-wrap justify-end gap-2"><button type="button" class="rounded-xl border border-line px-4 py-3 text-sm font-semibold text-ink" data-delivery-confirm-cancel>Cancel</button><button type="button" class="rounded-xl bg-green-700 px-4 py-3 text-sm font-semibold text-white" data-delivery-confirm-submit>Confirm Delivery</button></div>
    </div>
</dialog>
<dialog class="w-[calc(100%-2rem)] max-w-lg rounded-2xl p-0 shadow-2xl backdrop:bg-black/50" data-delivery-failed-dialog aria-labelledby="delivery-failed-heading">
    <div class="grid gap-4 p-6"><div><h2 id="delivery-failed-heading" class="text-xl font-bold text-ink" data-delivery-failed-title>Review Failed Delivery</h2><p class="mt-1 text-sm text-muted" data-delivery-failed-note>A normal failed attempt is scheduled for redelivery tomorrow. The parcel must be handed back to Logistics first.</p></div><p class="whitespace-pre-line rounded-xl bg-page-secondary p-4 text-sm text-ink" data-delivery-failure-summary></p><div class="flex justify-end gap-2"><button type="button" class="rounded-xl border border-line px-4 py-3 text-sm font-semibold text-ink" data-delivery-failed-cancel>Cancel</button><button type="button" class="rounded-xl bg-red-700 px-4 py-3 text-sm font-semibold text-white" data-delivery-failed-confirm>Schedule Redelivery</button></div></div>
</dialog>
<script>
    (() => {
        const form = document.querySelector('[data-delivery-proof-form]');
        const input = form?.querySelector('[data-delivery-proof-input]');
        const status = form?.querySelector('[data-delivery-proof-status]');
        const submit = form?.querySelector('[data-delivery-proof-submit]');
        const previewWrap = form?.querySelector('[data-delivery-proof-preview-wrap]');
        const preview = form?.querySelector('[data-delivery-proof-preview]');
        const replace = form?.querySelector('[data-delivery-proof-replace]');
        const retryStamp = form?.querySelector('[data-delivery-location-retry]');
        const cashInput = form?.querySelector('[data-delivery-cash-input]');
        const confirmDialog = document.querySelector('[data-delivery-confirm-dialog]');
        const confirmedCash = confirmDialog?.querySelector('[data-confirm-delivery-cash]');
        const confirmedProof = confirmDialog?.querySelector('[data-confirm-delivery-proof]');
        const confirmButton = confirmDialog?.querySelector('[data-delivery-confirm-submit]');
        const cancelConfirm = confirmDialog?.querySelector('[data-delivery-confirm-cancel]');
        const targetBytes = 1536 * 1024;
        let optimizing = false;
        let stampedProofReady = false;
        let selectedPhoto = null;
        let photoCapturedAt = null;
        let confirmedDelivery = false;
        let previewUrl = null;

        if (!form || !input || !status || !submit) return;

        const setStatus = (message, isError = false) => {
            status.textContent = message;
            status.classList.remove('hidden', 'border-red-200', 'bg-red-50', 'text-red-700', 'border-line', 'bg-page-secondary', 'text-muted');
            status.classList.add(isError ? 'border-red-200' : 'border-line', isError ? 'bg-red-50' : 'bg-page-secondary', isError ? 'text-red-700' : 'text-muted');
        };

        const showPreview = (file) => {
            if (!preview || !previewWrap) return;
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(file);
            preview.src = previewUrl;
            previewWrap.classList.remove('hidden');
        };

        const loadImage = (file) => new Promise((resolve, reject) => {
            const image = new Image();
            const url = URL.createObjectURL(file);
            image.onload = () => { URL.revokeObjectURL(url); resolve(image); };
            image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('unsupported image')); };
            image.src = url;
        });

        const formatManilaTime = (date) => new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
        }).format(date) + ' PHT';

        const getCurrentPosition = () => new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('This device/browser does not provide GPS location.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(resolve, (error) => {
                const message = error.code === error.PERMISSION_DENIED
                    ? 'Location permission is blocked. Allow location access in your browser settings, then retry.'
                    : error.code === error.POSITION_UNAVAILABLE
                        ? 'The device could not determine its GPS location. Move outdoors or near a window, then retry.'
                        : 'GPS is taking too long. Check that location is enabled, then retry.';
                reject(new Error(message));
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
        });

        const lookupPlace = async (latitude, longitude) => {
            const token = document.querySelector('[data-likhae-map]')?.dataset.mapToken;
            if (!token) return 'Address lookup unavailable';

            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 7000);
            try {
                const url = new URL('https://api.mapbox.com/search/geocode/v6/reverse');
                url.searchParams.set('access_token', token);
                url.searchParams.set('longitude', longitude);
                url.searchParams.set('latitude', latitude);
                url.searchParams.set('language', 'en');
                url.searchParams.set('limit', '1');
                url.searchParams.set('permanent', 'true');
                const response = await fetch(url, { signal: controller.signal });
                if (!response.ok) return 'Address lookup unavailable';
                const result = await response.json();
                const properties = result.features?.[0]?.properties;
                return properties?.full_address || properties?.place_formatted || properties?.name || 'Address lookup unavailable';
            } catch (error) {
                return 'Address lookup unavailable';
            } finally {
                window.clearTimeout(timeout);
            }
        };

        const wrapText = (context, text, maxWidth) => {
            const words = String(text).split(/\s+/).filter(Boolean);
            const lines = [];
            let line = '';
            for (const word of words) {
                const candidate = line ? `${line} ${word}` : word;
                if (line && context.measureText(candidate).width > maxWidth) {
                    lines.push(line);
                    line = word;
                } else {
                    line = candidate;
                }
            }
            if (line) lines.push(line);
            return lines.length ? lines : ['GPS coordinates'];
        };

        const stampPhoto = async (file, capturedAt) => {
            const position = await getCurrentPosition();
            const latitude = position.coords.latitude;
            const longitude = position.coords.longitude;
            const accuracy = Math.max(1, Math.round(position.coords.accuracy));
            const place = await lookupPlace(latitude, longitude);
            const gpsFixAt = new Date(position.timestamp || Date.now());
            const image = await loadImage(file);
            let width = image.naturalWidth;
            let height = image.naturalHeight;
            const longestEdge = 1920;
            if (Math.max(width, height) > longestEdge) {
                const scale = longestEdge / Math.max(width, height);
                width = Math.round(width * scale);
                height = Math.round(height * scale);
            }

            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Photo stamping is unavailable in this browser.');

            for (let attempt = 0; attempt < 8; attempt++) {
                canvas.width = width;
                canvas.height = height;
                context.drawImage(image, 0, 0, width, height);

                const padding = Math.max(12, Math.round(width * 0.022));
                const fontSize = Math.max(15, Math.round(width * 0.022));
                const lineHeight = Math.round(fontSize * 1.35);
                const textWidth = width - padding * 2;
                context.font = `600 ${fontSize}px Arial, sans-serif`;
                const locationLines = wrapText(context, `Location: ${place}`, textWidth);
                const lines = [
                    'LIKHAE  |  DELIVERY PROOF',
                    `Photo time: ${formatManilaTime(capturedAt)}`,
                    `GPS fix: ${formatManilaTime(gpsFixAt)}`,
                    `Latitude: ${latitude.toFixed(6)}   Longitude: ${longitude.toFixed(6)}`,
                    `GPS accuracy: +/- ${accuracy} m`,
                    ...locationLines,
                ];
                const panelHeight = padding * 2 + lineHeight * lines.length;
                const panelTop = height - panelHeight;
                context.fillStyle = 'rgba(0, 0, 0, 0.76)';
                context.fillRect(0, panelTop, width, panelHeight);
                context.fillStyle = '#ffffff';
                context.textBaseline = 'top';
                lines.forEach((line, index) => {
                    context.font = `${index === 0 ? '700' : '600'} ${index === 0 ? Math.round(fontSize * 1.05) : fontSize}px Arial, sans-serif`;
                    context.fillText(line, padding, panelTop + padding + index * lineHeight, textWidth);
                });

                const quality = Math.max(0.48, 0.86 - attempt * 0.055);
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
                if (blob && blob.size <= targetBytes) {
                    const filename = file.name.replace(/\.[^.]+$/, '') + '-proof.jpg';
                    return new File([blob], filename, { type: 'image/jpeg', lastModified: capturedAt.getTime() });
                }
                width = Math.max(1, Math.round(width * 0.84));
                height = Math.max(1, Math.round(height * 0.84));
            }
            throw new Error('The stamped photo is still too large to upload. Take a new photo and retry.');
        };

        const processSelectedPhoto = async () => {
            if (!selectedPhoto || !photoCapturedAt || optimizing) return;
            optimizing = true;
            stampedProofReady = false;
            submit.disabled = true;
            submit.classList.add('cursor-wait', 'opacity-70');
            retryStamp?.classList.add('hidden');
            previewWrap?.classList.add('hidden');
            setStatus('Getting your GPS location and stamping the proof photo…');

            try {
                const stamped = await stampPhoto(selectedPhoto, photoCapturedAt);
                const files = new DataTransfer();
                files.items.add(stamped);
                input.files = files.files;
                stampedProofReady = true;
                showPreview(stamped);
                retryStamp?.classList.add('hidden');
                setStatus(`Stamped proof photo is ready (${(stamped.size / 1024 / 1024).toFixed(1)} MB). Check the preview before uploading.`);
            } catch (error) {
                setStatus(error instanceof Error ? error.message : 'Could not stamp this photo. Please retry.', true);
                retryStamp?.classList.remove('hidden');
            } finally {
                optimizing = false;
                submit.disabled = !stampedProofReady;
                submit.classList.remove('cursor-wait', 'opacity-70');
            }
        };

        input.addEventListener('change', () => {
            const [file] = input.files;
            if (!file) return;
            selectedPhoto = file;
            photoCapturedAt = new Date(file.lastModified || Date.now());
            stampedProofReady = false;
            processSelectedPhoto();
        });

        retryStamp?.addEventListener('click', processSelectedPhoto);
        replace?.addEventListener('click', () => {
            input.value = '';
            input.click();
        });
        form.addEventListener('submit', (event) => {
            if (optimizing) {
                event.preventDefault();
                setStatus('Please wait while the proof photo is stamped.');
                return;
            }
            if (!stampedProofReady) {
                event.preventDefault();
                setStatus('A stamped proof photo with GPS location is required. Choose a photo and complete the GPS step first.', true);
                return;
            }
            if (confirmedDelivery) return;

            event.preventDefault();
            if (!confirmDialog?.showModal) {
                if (window.confirm('Confirm successful delivery and submit the proof photo?')) {
                    confirmedDelivery = true;
                    form.requestSubmit(submit);
                }
                return;
            }
            if (confirmedCash) confirmedCash.textContent = `₱${Number(cashInput.value || 0).toFixed(2)}`;
            if (confirmedProof) confirmedProof.textContent = input.files.length ? input.files[0].name : 'Not attached';
            confirmDialog.showModal();
        });

        cancelConfirm?.addEventListener('click', () => confirmDialog.close());
        confirmButton?.addEventListener('click', () => {
            confirmedDelivery = true;
            confirmDialog.close();
            form.requestSubmit(submit);
        });
    })();

    (() => {
        const form = document.querySelector('[data-delivery-failed-form]');
        const dialog = document.querySelector('[data-delivery-failed-dialog]');
        const category = form?.querySelector('[data-delivery-failure-category]');
        const notes = form?.querySelector('[data-delivery-failure-notes]');
        const reasonInput = form?.querySelector('[data-delivery-failure-reason]');
        const attemptStatus = form?.querySelector('[data-delivery-failure-attempt-status]');
        const nextAttempt = form?.querySelector('[data-delivery-next-attempt]');
        const reschedulePanel = form?.querySelector('[data-delivery-reschedule-panel]');
        const autoReturnNote = form?.querySelector('[data-delivery-auto-return-note]');
        const summary = dialog?.querySelector('[data-delivery-failure-summary]');
        const dialogTitle = dialog?.querySelector('[data-delivery-failed-title]');
        const dialogNote = dialog?.querySelector('[data-delivery-failed-note]');
        const confirmButton = dialog?.querySelector('[data-delivery-failed-confirm]');
        const cancelButton = dialog?.querySelector('[data-delivery-failed-cancel]');
        const failureSubmit = form?.querySelector('[data-delivery-failure-submit]');
        const thirdFailureWillReturn = form?.dataset.thirdFailureReturn === 'true';
        const defaultNextAttempt = form?.dataset.defaultNextAttempt || '';
        let confirmed = false;
        if (!form || !category || !notes || !reasonInput || !attemptStatus || !nextAttempt) return;

        const syncFailureMode = () => {
            const buyerRefusalPattern = /(?:buyer|recipient|customer)\s+(?:refused|rejected|declined)(?:\s+the)?\s+(?:parcel|delivery)/i;
            const buyerRefused = category.value === 'Buyer refused parcel' || buyerRefusalPattern.test(notes.value);
            const startsReturn = buyerRefused || thirdFailureWillReturn;
            attemptStatus.value = startsReturn ? 'FAILED' : 'RESCHEDULED';
            reschedulePanel?.classList.toggle('hidden', startsReturn);
            autoReturnNote?.classList.toggle('hidden', !startsReturn);
            nextAttempt.disabled = startsReturn;
            nextAttempt.required = !startsReturn;

            if (startsReturn) {
                nextAttempt.value = '';
                if (autoReturnNote) {
                    autoReturnNote.textContent = buyerRefused
                        ? 'Buyer refusal starts Return to Seller automatically. Bring the parcel to Logistics for a verified handoff.'
                        : 'This third failed attempt starts Return to Seller automatically. Bring the parcel to Logistics for a verified handoff.';
                }
                if (failureSubmit) failureSubmit.textContent = 'Review Return to Seller';
            } else {
                if (!nextAttempt.value) nextAttempt.value = defaultNextAttempt;
                if (failureSubmit) failureSubmit.textContent = 'Schedule Redelivery';
            }

            return startsReturn;
        };

        category.addEventListener('change', syncFailureMode);
        notes.addEventListener('input', syncFailureMode);
        syncFailureMode();

        form.addEventListener('submit', (event) => {
            if (confirmed) return;
            event.preventDefault();
            reasonInput.value = `${category.value}: ${notes.value.trim()}`;
            const startsReturn = syncFailureMode();
            const actionSummary = startsReturn
                ? 'Return to Seller starts automatically. Bring the parcel to Logistics for a verified handoff.'
                : `Redelivery scheduled for ${nextAttempt.value.replace('T', ' ')}. The parcel must be handed back to Logistics first.`;
            if (!dialog?.showModal) {
                if (window.confirm(`${startsReturn ? 'Start Return to Seller?' : 'Schedule redelivery?'}\n\n${reasonInput.value}\n\n${actionSummary}`)) {
                    confirmed = true;
                    form.requestSubmit();
                }
                return;
            }
            if (summary) summary.textContent = `${reasonInput.value}\n${actionSummary}`;
            if (dialogTitle) dialogTitle.textContent = startsReturn ? 'Start Return to Seller' : 'Schedule Redelivery';
            if (dialogNote) dialogNote.textContent = startsReturn
                ? 'This action starts the return workflow. The parcel remains in your custody until Logistics confirms the handoff.'
                : 'This records the failed attempt and schedules another delivery for the selected time.';
            if (confirmButton) confirmButton.textContent = startsReturn ? 'Start Return to Seller' : 'Schedule Redelivery';
            dialog.showModal();
        });

        cancelButton?.addEventListener('click', () => dialog.close());
        confirmButton?.addEventListener('click', () => {
            confirmed = true;
            dialog.close();
            form.requestSubmit();
        });
    })();

</script>
@endsection
