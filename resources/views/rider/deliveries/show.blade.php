@extends('Rider.app')

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

    <section class="rounded-3xl border border-line bg-surface p-8">
        <span class="text-xs font-bold uppercase tracking-[0.2em] text-primary">Delivery Details</span>
        <h1 class="mt-3 text-2xl font-bold text-ink">{{ $parcel['tracking'] }}</h1>
        <div class="mt-3 grid gap-1 text-sm text-muted sm:grid-cols-2">
            <p><span class="font-semibold text-ink">Shipment:</span> {{ $parcel['shipment_status_label'] }}</p>
            <p><span class="font-semibold text-ink">Your assignment:</span> {{ $parcel['status_label'] }}</p>
            <p class="sm:col-span-2"><span class="font-semibold text-ink">Buyer:</span> {{ $parcel['buyer'] }}</p>
        </div>
    </section>

    @if(!$parcel['has_buyer_pin'])
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950" aria-labelledby="rider-pin-required-heading">
            <h2 id="rider-pin-required-heading" class="font-bold">DELIVERY PIN REQUIRED</h2>
            <p class="mt-2 text-sm">The Buyer has not confirmed an exact destination pin for this order. Navigation will become available once the Buyer confirms the delivery pin. No route, distance, or ETA is available yet.</p>
            @if(filled($parcel['contact']))
                <a class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-amber-900 px-4 py-2 text-sm font-semibold text-white" href="tel:{{ preg_replace('/[^0-9+]/', '', $parcel['contact']) }}">Contact Buyer · {{ $parcel['contact'] }}</a>
            @endif
            <a class="mt-3 inline-flex min-h-11 items-center rounded-xl border border-amber-900 px-4 py-2 text-sm font-semibold text-amber-950 sm:ml-2" href="{{ route('rider.messages') }}">Message Buyer</a>
        </section>
    @endif

    <x-shared.mapbox :markers="$mapMarkers" title="Delivery route map" height="220px" user-location-target="destination" :rider-gps="true" :navigation="true" />

    <section class="grid gap-6 lg:grid-cols-2">
        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Recipient</h2>
            <div class="mt-5 flex flex-wrap gap-3">@foreach($delivery->shipment?->sellerOrder?->items ?? [] as $item)<div class="flex items-center gap-3 rounded-xl border border-line p-3"><x-product-thumbnail :item="$item" size="64"/><div><strong class="block text-sm text-ink">{{ $item->product_name }}</strong><small class="text-muted">{{ $item->variant_description ?: 'Standard' }} · Qty {{ $item->quantity }}</small></div></div>@endforeach</div>
            <dl class="mt-5 grid gap-3 text-sm">
                <div><dt class="text-muted">Buyer</dt><dd class="font-semibold text-ink">{{ $parcel['buyer'] }}</dd></div>
                <div><dt class="text-muted">Contact</dt><dd class="font-semibold text-ink">{{ $parcel['contact'] }}</dd></div>
                <div><dt class="text-muted">Delivery Address</dt><dd class="font-semibold text-ink whitespace-pre-line">{{ $parcel['address'] }}</dd></div>
                <div><dt class="text-muted">COD Amount Due</dt><dd class="font-semibold text-ink">₱{{ number_format((float) $parcel['cod_expected'], 2) }}</dd></div>
            </dl>
            @if(filled($parcel['contact']))<a class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink" href="tel:{{ preg_replace('/[^0-9+]/', '', $parcel['contact']) }}">Call Buyer</a>@endif
            <a class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink sm:ml-2" href="{{ route('rider.messages') }}">Message Buyer</a>
        </article>

        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Delivery Actions</h2>
            <div class="mt-5 grid gap-3">
                @if($parcel['status'] === 'ASSIGNED')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="accept"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Accept Delivery</button></form>
                @elseif($parcel['status'] === 'ACCEPTED')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="start"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Start Delivery</button></form>
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
                            Collection reference <span class="font-normal text-muted">(optional)</span>
                            <input type="text" name="collection_reference" maxlength="100" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" placeholder="Receipt or handoff reference">
                        </label>
                        <label class="grid gap-2 text-sm font-semibold text-ink">
                            Proof of delivery photo
                            <input type="file" name="proof_file" accept="image/jpeg,image/png,image/webp" capture="environment" required class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" data-delivery-proof-input>
                            <small class="font-normal text-muted">Take a clear photo of the delivered parcel. Large photos are optimized automatically before upload.</small>
                            <span class="hidden rounded-lg border border-line bg-page-secondary px-3 py-2 text-xs font-medium text-muted" aria-live="polite" data-delivery-proof-status></span>
                        </label>
                        <div class="hidden rounded-xl border border-line bg-page-secondary p-3" data-delivery-proof-preview-wrap>
                            <img class="mx-auto max-h-64 max-w-full rounded-lg object-contain" alt="Delivery proof preview" data-delivery-proof-preview>
                            <button type="button" class="mt-3 rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-ink" data-delivery-proof-replace>Retake / Replace Photo</button>
                        </div>
                        <button type="submit" class="w-full rounded-xl bg-green-700 px-5 py-3 text-sm font-semibold text-white" data-delivery-proof-submit>Upload Proof &amp; Mark Delivered</button>
                    </form>
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" class="grid gap-3" data-delivery-failed-form>
                        @csrf @method('PATCH')<input type="hidden" name="action" value="delivery_failed">
                        <input type="hidden" name="failure_reason" data-delivery-failure-reason>
                        <label class="grid gap-2 text-sm font-semibold text-ink">Reason
                            <select required class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" data-delivery-failure-category>
                                <option value="">Select a reason</option><option>Buyer unavailable</option><option>Buyer requested reschedule</option><option>Incorrect or inaccessible location</option><option>Buyer refused parcel</option><option>Unable to contact Buyer</option><option>Other</option>
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm font-semibold text-ink">Notes
                            <textarea rows="3" required maxlength="850" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" placeholder="Add details about this delivery attempt" data-delivery-failure-notes></textarea>
                        </label>
                        <button type="submit" class="w-full rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm font-semibold text-red-700">Review Failed Delivery</button>
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
    <div class="grid gap-4 p-6"><div><h2 id="delivery-failed-heading" class="text-xl font-bold text-ink">Record Failed Delivery</h2><p class="mt-1 text-sm text-muted">The selected reason and notes will be saved for this delivery attempt. This will not automatically return the parcel.</p></div><p class="rounded-xl bg-page-secondary p-4 text-sm text-ink" data-delivery-failure-summary></p><div class="flex justify-end gap-2"><button type="button" class="rounded-xl border border-line px-4 py-3 text-sm font-semibold text-ink" data-delivery-failed-cancel>Cancel</button><button type="button" class="rounded-xl bg-red-700 px-4 py-3 text-sm font-semibold text-white" data-delivery-failed-confirm>Record Failed Delivery</button></div></div>
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
        const cashInput = form?.querySelector('[data-delivery-cash-input]');
        const confirmDialog = document.querySelector('[data-delivery-confirm-dialog]');
        const confirmedCash = confirmDialog?.querySelector('[data-confirm-delivery-cash]');
        const confirmedProof = confirmDialog?.querySelector('[data-confirm-delivery-proof]');
        const confirmButton = confirmDialog?.querySelector('[data-delivery-confirm-submit]');
        const cancelConfirm = confirmDialog?.querySelector('[data-delivery-confirm-cancel]');
        const targetBytes = 1536 * 1024;
        let optimizing = false;
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

        const optimize = async (file) => {
            if (file.size <= targetBytes) return file;

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
            if (!context) throw new Error('canvas unavailable');

            for (let attempt = 0; attempt < 5; attempt++) {
                canvas.width = width;
                canvas.height = height;
                context.drawImage(image, 0, 0, width, height);
                const quality = Math.max(0.5, 0.82 - (attempt * 0.08));
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
                if (blob && blob.size <= targetBytes) {
                    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
                }
                width = Math.round(width * 0.78);
                height = Math.round(height * 0.78);
            }

            throw new Error('photo remains too large');
        };

        input.addEventListener('change', async () => {
            const [file] = input.files;
            if (!file) return;
            showPreview(file);

            if (file.size <= targetBytes) {
                setStatus(`Photo ready to upload (${(file.size / 1024 / 1024).toFixed(1)} MB).`);
                return;
            }

            optimizing = true;
            submit.disabled = true;
            submit.classList.add('cursor-wait', 'opacity-70');
            setStatus('Optimizing photo for upload…');

            try {
                const optimized = await optimize(file);
                const files = new DataTransfer();
                files.items.add(optimized);
                input.files = files.files;
                showPreview(optimized);
                setStatus(`Photo optimized and ready to upload (${(optimized.size / 1024 / 1024).toFixed(1)} MB).`);
            } catch (error) {
                input.value = '';
                previewWrap?.classList.add('hidden');
                setStatus('This photo could not be optimized. Please choose a JPG, PNG, or WebP photo smaller than 1.5 MB.', true);
            } finally {
                optimizing = false;
                submit.disabled = false;
                submit.classList.remove('cursor-wait', 'opacity-70');
            }
        });

        replace?.addEventListener('click', () => input.click());

        form.addEventListener('submit', (event) => {
            if (optimizing) {
                event.preventDefault();
                setStatus('Please wait for photo optimization to finish.');
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
        const summary = dialog?.querySelector('[data-delivery-failure-summary]');
        const confirmButton = dialog?.querySelector('[data-delivery-failed-confirm]');
        const cancelButton = dialog?.querySelector('[data-delivery-failed-cancel]');
        let confirmed = false;
        if (!form || !category || !notes || !reasonInput) return;

        form.addEventListener('submit', (event) => {
            if (confirmed) return;
            event.preventDefault();
            reasonInput.value = `${category.value}: ${notes.value.trim()}`;
            if (!dialog?.showModal) {
                if (window.confirm(`Record failed delivery?\n\n${reasonInput.value}`)) {
                    confirmed = true;
                    form.requestSubmit();
                }
                return;
            }
            if (summary) summary.textContent = reasonInput.value;
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
