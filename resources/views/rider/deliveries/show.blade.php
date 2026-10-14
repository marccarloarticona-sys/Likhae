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
        <p class="mt-3 text-sm text-muted">{{ $parcel['status_label'] }} - Buyer: {{ $parcel['buyer'] }}</p>
    </section>

    <x-shared.mapbox :markers="$mapMarkers" title="Delivery route map" height="220px" user-location-target="destination" :rider-gps="true" :navigation="true" />

    <section class="grid gap-6 lg:grid-cols-2">
        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Recipient</h2>
            <div class="mt-5 flex flex-wrap gap-3">@foreach($delivery->shipment?->sellerOrder?->items ?? [] as $item)<div class="flex items-center gap-3 rounded-xl border border-line p-3"><x-product-thumbnail :item="$item" size="64"/><div><strong class="block text-sm text-ink">{{ $item->product_name }}</strong><small class="text-muted">{{ $item->variant_description ?: 'Standard' }} · Qty {{ $item->quantity }}</small></div></div>@endforeach</div>
            <dl class="mt-5 grid gap-3 text-sm">
                <div><dt class="text-muted">Buyer</dt><dd class="font-semibold text-ink">{{ $parcel['buyer'] }}</dd></div>
                <div><dt class="text-muted">Contact</dt><dd class="font-semibold text-ink">{{ $parcel['contact'] }}</dd></div>
                <div><dt class="text-muted">Delivery Address</dt><dd class="font-semibold text-ink whitespace-pre-line">{{ $parcel['address'] }}</dd></div>
                <div><dt class="text-muted">Order Amount</dt><dd class="font-semibold text-ink">{{ $parcel['amount'] }}</dd></div>
            </dl>
        </article>

        <article class="rounded-3xl border border-line bg-surface p-8">
            <h2 class="text-lg font-bold text-ink">Delivery Actions</h2>
            <div class="mt-5 grid gap-3">
                @if($parcel['status'] === 'ASSIGNED')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" data-confirm-title="Accept delivery assignment?" data-confirm-message="Accept responsibility for delivering this parcel?" data-confirm-label="Accept delivery">@csrf @method('PATCH')<input type="hidden" name="action" value="accept"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Accept Delivery</button></form>
                @elseif($parcel['status'] === 'ACCEPTED')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" data-confirm-title="Start delivery?" data-confirm-message="Mark this delivery as in progress?" data-confirm-label="Start delivery">@csrf @method('PATCH')<input type="hidden" name="action" value="start"><button class="w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white">Start Delivery</button></form>
                @elseif($parcel['status'] === 'IN_PROGRESS')
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" enctype="multipart/form-data" class="grid gap-3" data-delivery-proof-form data-confirm-title="Confirm delivery?" data-confirm-message="Submit this proof and mark the parcel as delivered?" data-confirm-label="Complete delivery">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="delivery_success">
                        <label class="grid gap-2 text-sm font-semibold text-ink">
                            Cash collected from buyer
                            <input type="number" name="collected_amount" min="0" step="0.01" value="{{ old('collected_amount', preg_replace('/[^0-9.]/', '', $parcel['amount'])) }}" required class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm">
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
                        <button type="submit" class="w-full rounded-xl bg-green-700 px-5 py-3 text-sm font-semibold text-white" data-delivery-proof-submit>Upload Proof &amp; Mark Delivered</button>
                    </form>
                    <form method="POST" action="{{ route('rider.shipments.transition', $delivery) }}" class="grid gap-3">
                        @csrf @method('PATCH')<input type="hidden" name="action" value="delivery_failed">
                        <textarea name="failure_reason" rows="3" required class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm" placeholder="Reason for failed delivery"></textarea>
                        <button class="w-full rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm font-semibold text-red-700">Record Delivery Failed</button>
                    </form>
                @endif
                <a href="{{ route('rider.deliveries') }}" class="rounded-xl border border-line px-5 py-3 text-center text-sm font-semibold text-ink">Back To Deliveries</a>
            </div>
        </article>
    </section>

</div>
<script>
    (() => {
        const form = document.querySelector('[data-delivery-proof-form]');
        const input = form?.querySelector('[data-delivery-proof-input]');
        const status = form?.querySelector('[data-delivery-proof-status]');
        const submit = form?.querySelector('[data-delivery-proof-submit]');
        const targetBytes = 1536 * 1024;
        let optimizing = false;

        if (!form || !input || !status || !submit) return;

        const setStatus = (message, isError = false) => {
            status.textContent = message;
            status.classList.remove('hidden', 'border-red-200', 'bg-red-50', 'text-red-700', 'border-line', 'bg-page-secondary', 'text-muted');
            status.classList.add(isError ? 'border-red-200' : 'border-line', isError ? 'bg-red-50' : 'bg-page-secondary', isError ? 'text-red-700' : 'text-muted');
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
                setStatus(`Photo optimized and ready to upload (${(optimized.size / 1024 / 1024).toFixed(1)} MB).`);
            } catch (error) {
                input.value = '';
                setStatus('This photo could not be optimized. Please choose a JPG, PNG, or WebP photo smaller than 1.5 MB.', true);
            } finally {
                optimizing = false;
                submit.disabled = false;
                submit.classList.remove('cursor-wait', 'opacity-70');
            }
        });

        form.addEventListener('submit', (event) => {
            if (optimizing) {
                event.preventDefault();
                setStatus('Please wait for photo optimization to finish.');
            }
        });
    })();
</script>
@endsection
