@extends('layouts.buyer.app')
@section('title', 'Return & Refund Request')
@section('active', 'orders')

@section('content')
<style>
    .rr-page{width:min(100%,980px)!important}.rr-card{border:1px solid #eadbce;border-radius:22px;background:#fffdf9;box-shadow:0 12px 30px rgba(86,28,23,.07)}.rr-step{display:flex;align-items:center;gap:9px;color:#947d73;font-size:12px;font-weight:700}.rr-step.is-active{color:#8f382d}.rr-step span{display:grid;place-items:center;width:30px;height:30px;border:1px solid #d9c9bf;border-radius:50%;background:#fff;font-size:12px}.rr-step.is-active span{border-color:#a64034;background:#a64034;color:#fff}.rr-line{height:1px;flex:1;background:#eadbce}.rr-panel{display:none}.rr-panel.is-active{display:block}.rr-option{display:flex;align-items:flex-start;gap:12px;padding:15px;border:1px solid #eadbce;border-radius:15px;background:#fff;cursor:pointer;transition:.18s}.rr-option:hover,.rr-option:has(input:checked){border-color:#b24a3c;background:#fff7f2;box-shadow:0 0 0 3px rgba(178,74,60,.1)}.rr-option input{margin-top:3px;accent-color:#a64034}.rr-item{display:flex;align-items:center;gap:13px;padding:12px;border:1px solid #eadbce;border-radius:15px;background:#fff}.rr-item:has(input:checked){border-color:#b24a3c;background:#fff7f2}.rr-field{width:100%;border:1px solid #d9c9bf;border-radius:11px;background:#fff;padding:11px 13px;color:#321d17;outline:0}.rr-field:focus{border-color:#a64034;box-shadow:0 0 0 3px rgba(178,74,60,.1)}.rr-muted{color:#806f67}.rr-summary{border:1px solid #eadbce;border-radius:15px;background:#fff7f2}.rr-preview{display:flex;flex-wrap:wrap;gap:8px}.rr-preview img{width:70px;height:70px;object-fit:cover;border-radius:9px;border:1px solid #eadbce}.rr-help{font-size:11px;color:#947d73}.dark .rr-card,.dark .rr-option,.dark .rr-item{border-color:#4b3831;background:#241b18;color:#f7eee9}.dark .rr-option:hover,.dark .rr-option:has(input:checked),.dark .rr-item:has(input:checked){border-color:#d36c5d;background:#30211d}.dark .rr-field{border-color:#5a443c;background:#2b211e;color:#fff4ee}.dark .rr-summary{border-color:#5a443c;background:#30211d}.dark .rr-muted,.dark .rr-help{color:#c3b1a8}
</style>

<div class="rr-page">
    <div class="lk-page-title mb-5"><div><span class="lk-kicker">Order support</span><h1>Return & Refund</h1><p>{{ $order->order_number }} &middot; available until {{ $deadline->format('M j, Y g:i A') }}</p></div><a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.show', $order) }}">Back to Purchase</a></div>
    @if($errors->any())<div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p class="font-semibold">Please check your request.</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="rr-card p-6">
        <div class="mb-7 flex items-center gap-2" aria-label="Return and refund steps">
            @foreach(['Select Issue','Select Reason','Request Details','Review'] as $index => $step)
                @if($index > 0)<div class="rr-line"></div>@endif
                <div class="rr-step {{ $index === 0 ? 'is-active' : '' }}" data-step-indicator="{{ $index + 1 }}"><span>{{ $index + 1 }}</span><b class="hidden sm:block">{{ $step }}</b></div>
            @endforeach
        </div>
        <form id="returnRefundForm" data-upload-form method="POST" enctype="multipart/form-data" action="{{ route('buyer.orders.return-refund', $order) }}">
            @csrf
            <section class="rr-panel is-active" data-step="1"><h2 class="text-xl font-bold text-stone-900 dark:text-white">What happened to your order?</h2><p class="rr-muted mt-1 text-sm">Choose the issue that best describes your request.</p><div class="mt-5 grid gap-3 sm:grid-cols-2">@foreach($issues as $key => $issue)<label class="rr-option"><input type="radio" name="issue_category" value="{{ $key }}" data-issue @checked(old('issue_category') === $key)><span><strong class="block">{{ $issue['label'] }}</strong><small class="rr-muted mt-1 block">Suggested solution: {{ $issue['solution'] }}</small></span></label>@endforeach</div><div class="mt-6 flex justify-end"><button type="button" class="lk-btn lk-btn-red" data-next>Continue</button></div></section>
            <section class="rr-panel" data-step="2"><h2 class="text-xl font-bold text-stone-900 dark:text-white">Why are you requesting help?</h2><p class="rr-muted mt-1 text-sm">Select one reason for the issue you chose.</p><div class="mt-5 grid gap-3">@foreach($issues as $key => $issue)<div data-reasons="{{ $key }}" hidden class="grid gap-3">@foreach($issue['reasons'] as $reason)<label class="rr-option"><input type="radio" name="issue_reason" value="{{ $reason }}"><span>{{ $reason }}</span></label>@endforeach</div>@endforeach</div><div class="mt-6 flex justify-between"><button type="button" class="lk-btn lk-btn-light" data-back>Back</button><button type="button" class="lk-btn lk-btn-red" data-next>Continue</button></div></section>
            <section class="rr-panel" data-step="3">
                <h2 class="text-xl font-bold text-stone-900 dark:text-white">Tell us more</h2><p class="rr-muted mt-1 text-sm">Select affected items, choose cash (COD) or a saved e-wallet/bank destination, and attach evidence. The refund is sent after approval and any required return checks.</p>
                <p class="rr-muted mt-4 text-sm">Choose the delivered parcel that contains the items you are reporting. Each request is linked to one physical parcel and its tracking code.</p>
                <div class="mt-4 grid gap-4">
                    @forelse($returnParcels as $parcel)
                        @php
                            $shipment = $parcel['shipment'];
                        @endphp
                        <section class="rounded-2xl border border-[#eadbce] p-4 dark:border-[#5a443c]" data-parcel-card>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl bg-[#fff7f2] p-3 dark:bg-[#30211d]">
                                <input class="mt-1" type="radio" name="shipment_id" value="{{ $shipment->id }}" data-parcel-radio required @checked((string) old('shipment_id') === (string) $shipment->id) @disabled($parcel['active'])>
                                <span><strong class="block" data-parcel-label>Parcel {{ $parcel['number'] }} of {{ $parcel['total'] }}</strong><small class="rr-muted">Tracking {{ $shipment->tracking_number }}</small></span>
                            </label>
                            @if($parcel['active'])
                                <p class="rr-muted mt-3 text-sm">A return/refund request for this parcel is already under review.</p>
                            @else
                                <div class="mt-3 grid gap-3">
                                    @foreach($parcel['items'] as $option)
                                        @php
                                            $item = $option['item'];
                                            $itemKey = $shipment->id.'-'.$item->id;
                                            $unitRefund = (float) $item->line_total / max((int) $item->quantity, 1);
                                        @endphp
                                        <label class="rr-item">
                                            <input type="checkbox" name="item_ids[]" value="{{ $item->id }}" data-item-checkbox data-item-id="{{ $item->id }}" data-item-key="{{ $itemKey }}" data-unit-refund="{{ number_format($unitRefund, 2, '.', '') }}" data-parcel-id="{{ $shipment->id }}" @checked((string) old('shipment_id') === (string) $shipment->id && old('item_ids') && in_array((string) $item->id, array_map('strval', (array) old('item_ids')), true))>
                                            <x-shared.product-thumbnail :item="$item" size="64"/>
                                            <span class="min-w-0 flex-1"><strong class="block truncate">{{ $item->product_name }}</strong><small class="rr-muted block">{{ $item->variant_description ?: 'Standard' }} &middot; Parcel qty {{ $shipment->order_item_id ? $shipment->parcel_quantity : $item->quantity }} &middot; {{ $option['available'] }} returnable</small></span>
                                            <span class="flex items-center gap-2"><span class="text-xs rr-muted">Qty</span><input class="rr-field w-20 py-2 text-center" type="number" name="quantities[{{ $item->id }}]" value="{{ old('quantities.'.$item->id, 1) }}" min="1" max="{{ $option['available'] }}" data-quantity data-for-key="{{ $itemKey }}" disabled></span>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @empty
                        <div class="rounded-xl bg-stone-50 p-4 text-sm text-stone-600">There are no delivered parcel quantities left to request.</div>
                    @endforelse
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="description">Describe the issue</label><textarea class="rr-field" id="description" name="description" rows="5" minlength="10" maxlength="2000" required aria-describedby="descriptionCount descriptionValidation" placeholder="Share details that can help the Seller review your request.">{{ old('description') }}</textarea><small class="rr-help" id="descriptionCount">0 / 2000</small><small class="rr-help text-red-700" id="descriptionValidation" aria-live="polite"></small></div><div class="space-y-4"><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="images">Photos <span class="rr-muted font-normal">(required, up to 5)</span></label><input class="rr-field" id="images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required aria-describedby="imageCount imageValidation"><div class="rr-preview mt-2" id="imagePreview"></div><small class="rr-help" id="imageCount" aria-live="polite">0 / 5 images selected</small><small class="rr-help">JPG, PNG, or WEBP. Maximum 5 MB each.</small><small class="rr-help text-red-700" id="imageValidation" aria-live="polite"></small></div><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="video">Video <span class="rr-muted font-normal">(optional)</span></label><input class="rr-field" id="video" name="video" type="file" accept="video/mp4,video/quicktime,video/webm"><small class="rr-help" id="videoName">MP4, MOV, or WEBM. Maximum 200 MB.</small></div></div></div>
                <fieldset class="mt-5 rounded-xl border border-[#eadbce] bg-[#fff7f2] p-4 dark:border-[#5a443c] dark:bg-[#30211d]"><legend class="px-1 font-semibold text-stone-900 dark:text-white">Refund method</legend><div class="grid gap-3 sm:grid-cols-2">
                    @if($refundDestinationsReady && count($refundDestinations))
                        <label class="rr-option flex items-start gap-2 rounded-lg border border-[#d9b6a8] bg-white/70 p-3 text-sm dark:bg-black/10"><input class="mt-1" type="radio" name="refund_method" value="SAVED_DESTINATION" required @checked(old('refund_method') === 'SAVED_DESTINATION')><span><strong class="block">Saved e-wallet or bank</strong><small class="rr-help">The Seller sends the approved refund to your selected saved account after required return checks.</small></span></label>
                    @else
                        <div class="rounded-lg border border-[#eadbce] p-3 text-sm dark:border-[#5a443c]"><strong class="block">Saved e-wallet or bank</strong><small class="rr-help">Add a GCash, Maya, or bank destination in <a class="underline" href="{{ route('buyer.account', ['tab' => 'wallet']) }}">Account → Wallet</a> before selecting this method.</small></div>
                    @endif
                    <label class="rr-option flex items-start gap-2 rounded-lg border border-[#eadbce] p-3 text-sm dark:border-[#5a443c]"><input class="mt-1" type="radio" name="refund_method" value="CASH" required @checked(old('refund_method', 'CASH') === 'CASH' || !($refundDestinationsReady && count($refundDestinations)))><span><strong class="block">Cash refund (COD)</strong><small class="rr-help">The Seller pays the approved cash amount after receiving and inspecting the return.</small></span></label>
                </div>
                @if($refundDestinationsReady && count($refundDestinations))
                    @php($defaultRefundDestination = collect($refundDestinations)->firstWhere('is_default', true) ?? $refundDestinations[0])
                    <label class="mt-4 block text-sm font-semibold" for="refund_destination_id">Saved refund destination</label>
                    <select class="rr-field mt-1" id="refund_destination_id" name="refund_destination_id" data-refund-destination @disabled(old('refund_method', 'CASH') !== 'SAVED_DESTINATION')>
                        @foreach($refundDestinations as $destination)
                            @php($destinationLabel = $destination['payout_method'].($destination['payout_bank_name'] ? ' · '.$destination['payout_bank_name'] : '').' · '.$destination['payout_account_name'].' · ending '.substr($destination['payout_account_number'], -4))
                            <option value="{{ $destination['id'] }}" data-destination-label="{{ $destinationLabel }}" @selected((string) old('refund_destination_id', $defaultRefundDestination['id']) === (string) $destination['id'])>{{ $destinationLabel }}</option>
                        @endforeach
                    </select>
                @endif
                <p class="rr-help mt-3">A saved destination is used only for this refund. The Seller must send the approved amount after any required return receipt and inspection; you can confirm it after the funds arrive.</p><label class="mt-4 flex items-start gap-2 text-sm"><input class="mt-1" type="checkbox" name="confirm_refund_method" value="1" required><span>I confirm my selected refund method and understand that approval and payment are not immediate.</span></label></fieldset>
                <div class="mt-5 grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="buyer_email">Email for updates</label><input class="rr-field" id="buyer_email" name="buyer_email" type="email" required maxlength="255" value="{{ old('buyer_email', auth()->user()->email) }}"></div><div class="rr-summary grid gap-2 p-4 text-sm"><div class="flex items-center justify-between gap-3"><span>Refundable Price</span><strong class="text-red-900 dark:text-red-200" id="refundPrice">PHP 0.00</strong></div><div class="flex items-center justify-between gap-3"><span class="font-semibold">Total Refund</span><strong class="text-lg text-red-900 dark:text-red-200" id="refundTotal">PHP 0.00</strong></div></div></div>
                <div class="mt-6 flex justify-between"><button type="button" class="lk-btn lk-btn-light" data-back>Back</button><button type="button" class="lk-btn lk-btn-red" data-next>Review Request</button></div>
            </section>
            <section class="rr-panel" data-step="4"><h2 class="text-xl font-bold text-stone-900 dark:text-white">Review your request</h2><p class="rr-muted mt-1 text-sm">The Seller will review your request and complete payment after approval and any required return checks.</p><dl class="rr-summary mt-5 grid gap-3 p-4 text-sm sm:grid-cols-2"><div><dt class="rr-muted">Issue</dt><dd class="font-semibold" id="reviewIssue">-</dd></div><div><dt class="rr-muted">Reason</dt><dd class="font-semibold" id="reviewReason">-</dd></div><div><dt class="rr-muted">Refund method</dt><dd class="font-semibold" id="reviewRefundMethod">Cash refund (COD)</dd></div><div class="sm:col-span-2"><dt class="rr-muted">Saved destination</dt><dd class="font-semibold" id="reviewRefundDestination">Not applicable</dd></div><div><dt class="rr-muted">Payment status</dt><dd class="font-semibold" id="reviewRefundTiming">Pending Seller approval, return receipt, and inspection before cash payment.</dd></div><div><dt class="rr-muted">Total requested refund</dt><dd class="font-semibold" id="reviewAmount">PHP 0.00</dd></div><div><dt class="rr-muted">Email</dt><dd class="font-semibold break-all" id="reviewEmail">-</dd></div><div class="sm:col-span-2"><dt class="rr-muted">Affected items</dt><dd class="font-semibold" id="reviewItems">-</dd></div><div class="sm:col-span-2"><dt class="rr-muted">Description</dt><dd class="whitespace-pre-wrap" id="reviewDescription">-</dd></div><div class="sm:col-span-2"><dt class="rr-muted">Evidence</dt><dd class="font-semibold" id="reviewEvidence">No files attached</dd></div></dl><label class="mt-5 flex items-start gap-2 text-sm"><input type="checkbox" required class="mt-1" name="confirm_request" value="1"><span>I confirm that the information and evidence I provided are accurate and understand that approval and payment are not immediate.</span></label><div class="mt-6 flex justify-between"><button type="button" class="lk-btn lk-btn-light" data-back>Back</button><button type="submit" class="lk-btn lk-btn-red">Submit Request</button></div></section>
        </form>
    </section>
</div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const f = document.getElementById('returnRefundForm');
    if (!f) return;

    const panels = [...f.querySelectorAll('[data-step]')];
    const indicators = [...document.querySelectorAll('[data-step-indicator]')];
    const submitButton = f.querySelector('button[type="submit"]');
    const money = (n) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(n);
    let step = 1;

    const show = (nextStep) => {
        panels.forEach((panel) => panel.classList.toggle('is-active', Number(panel.dataset.step) === nextStep));
        indicators.forEach((indicator) => indicator.classList.toggle('is-active', Number(indicator.dataset.stepIndicator) <= nextStep));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const issue = () => f.querySelector('[name="issue_category"]:checked');
    const reason = () => f.querySelector('[name="issue_reason"]:checked');
    const refundMethod = () => f.querySelector('[name="refund_method"]:checked')?.value || 'CASH';
    const refundDestinationSelect = f.querySelector('[data-refund-destination]');
    const updateRefundMethodReview = () => {
        const destinationSelected = refundMethod() === 'SAVED_DESTINATION';
        if (refundDestinationSelect) {
            refundDestinationSelect.disabled = !destinationSelected;
            refundDestinationSelect.required = destinationSelected;
        }
        document.getElementById('reviewRefundMethod').textContent = destinationSelected ? 'Saved e-wallet or bank account' : 'Cash refund (COD)';
        document.getElementById('reviewRefundDestination').textContent = destinationSelected
            ? refundDestinationSelect?.selectedOptions[0]?.dataset.destinationLabel || 'Choose a saved destination'
            : 'Not applicable';
        document.getElementById('reviewRefundTiming').textContent = destinationSelected
            ? 'Seller sends the refund after approval and required return checks; you confirm after funds arrive.'
            : 'Pending Seller approval, return receipt, and inspection before cash payment.';
    };
    const amount = () => [...f.querySelectorAll('[data-item-checkbox]:checked')]
        .reduce((total, checkbox) => total + Number(checkbox.dataset.unitRefund) * Number(f.querySelector(`[data-for-key="${checkbox.dataset.itemKey}"]`)?.value || 0), 0);
    const refresh = () => {
        const value = money(amount());
        ['refundPrice', 'refundTotal', 'reviewAmount'].forEach((id) => {
            const element = document.getElementById(id);
            if (element) element.textContent = value;
        });
    };
    const review = () => {
        document.getElementById('reviewIssue').textContent = issue()?.closest('label')?.querySelector('strong')?.textContent || '-';
        document.getElementById('reviewReason').textContent = reason()?.value || '-';
        updateRefundMethodReview();
        document.getElementById('reviewEmail').textContent = document.getElementById('buyer_email').value;
        document.getElementById('reviewDescription').textContent = document.getElementById('description').value;
        document.getElementById('reviewItems').textContent = [...f.querySelectorAll('[data-item-checkbox]:checked')]
            .map((checkbox) => `${checkbox.closest('.rr-item').querySelector('strong').textContent} (${checkbox.closest('[data-parcel-card]').querySelector('[data-parcel-label]').textContent.trim()})`)
            .join(', ') || '-';
        document.getElementById('reviewEvidence').textContent = [...f.querySelector('#images').files]
            .map((file) => file.name)
            .concat(f.querySelector('#video').files[0]?.name || [])
            .join(', ') || 'No files attached';
        refresh();
    };
    const imagesInput = f.querySelector('#images');
    const descriptionInput = f.querySelector('#description');
    const imageValidation = document.getElementById('imageValidation');
    const descriptionValidation = document.getElementById('descriptionValidation');
    const imageCount = document.getElementById('imageCount');
    const uploadError = () => {
        const files = [...imagesInput.files];
        const descriptionLength = descriptionInput.value.trim().length;
        imageValidation.textContent = files.length === 0
            ? 'Please provide at least one evidence image.'
            : (files.length > 5 ? 'You can upload up to 5 images only.' : '');
        descriptionValidation.textContent = descriptionLength < 10
            ? 'Please provide a description with at least 10 characters.'
            : '';
        if (files.length === 0) return imageValidation.textContent;
        if (files.length > 5) return imageValidation.textContent;
        if (files.some((file) => file.size > 5 * 1024 * 1024)) {
            imageValidation.textContent = 'Each image must not exceed 5 MB.';
            return imageValidation.textContent;
        }
        if (files.some((file) => file.type && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
            imageValidation.textContent = 'Please upload JPEG, PNG, or WebP images only.';
            return imageValidation.textContent;
        }
        if (descriptionLength < 10) return descriptionValidation.textContent;
        const video = f.querySelector('#video').files[0];
        if (video && video.size > 200 * 1024 * 1024) return 'The proof video must be 200 MB or smaller.';
        return '';
    };
    const renderImagePreview = () => {
        const preview = document.getElementById('imagePreview');
        const files = [...imagesInput.files];
        preview.replaceChildren();
        imageCount.textContent = `${files.length} / 5 images selected`;
        files.forEach((file, index) => {
            const tile = document.createElement('span');
            tile.className = 'relative inline-flex flex-col gap-1';
            const image = document.createElement('img');
            image.src = URL.createObjectURL(file);
            image.alt = file.name;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'text-xs font-semibold text-red-800 underline';
            remove.textContent = `Remove ${file.name}`;
            remove.addEventListener('click', () => {
                const transfer = new DataTransfer();
                files.forEach((selectedFile, selectedIndex) => {
                    if (selectedIndex !== index) transfer.items.add(selectedFile);
                });
                imagesInput.files = transfer.files;
                imageValidation.textContent = '';
                renderImagePreview();
            });
            tile.append(image, remove);
            preview.append(tile);
        });
        uploadError();
    };
    const focusInvalidField = () => {
        const invalid = [...f.elements].find((field) => !field.disabled && !field.checkValidity());
        if (!invalid) return false;
        const panel = invalid.closest('[data-step]');
        if (panel) {
            step = Number(panel.dataset.step);
            show(step);
        }
        window.setTimeout(() => {
            invalid.focus({ preventScroll: true });
            invalid.reportValidity();
        }, 0);
        return true;
    };

    f.querySelectorAll('[data-issue]').forEach((input) => input.addEventListener('change', () => {
        f.querySelectorAll('[data-reasons]').forEach((group) => group.hidden = group.dataset.reasons !== input.value);
        f.querySelectorAll('[data-reasons] input').forEach((reasonInput) => reasonInput.checked = false);
    }));
    const syncParcelControls = () => {
        const selectedParcelId = f.querySelector('[data-parcel-radio]:checked')?.value;
        f.querySelectorAll('[data-item-checkbox]').forEach((checkbox) => {
            const enabled = checkbox.dataset.parcelId === selectedParcelId;
            if (!enabled) checkbox.checked = false;
            checkbox.disabled = !enabled;
            const quantity = f.querySelector(`[data-for-key="${checkbox.dataset.itemKey}"]`);
            quantity.disabled = !enabled || !checkbox.checked;
        });
        refresh();
    };
    f.querySelectorAll('[data-parcel-radio]').forEach((radio) => radio.addEventListener('change', syncParcelControls));
    f.querySelectorAll('[data-item-checkbox]').forEach((checkbox) => checkbox.addEventListener('change', () => {
        const parcelRadio = f.querySelector(`[data-parcel-radio][value="${checkbox.dataset.parcelId}"]`);
        if (checkbox.checked && parcelRadio) parcelRadio.checked = true;
        syncParcelControls();
        const quantity = f.querySelector(`[data-for-key="${checkbox.dataset.itemKey}"]`);
        quantity.disabled = !checkbox.checked;
        refresh();
    }));
    syncParcelControls();
    f.querySelectorAll('[data-quantity]').forEach((quantity) => quantity.addEventListener('input', refresh));
    f.querySelectorAll('[name="refund_method"]').forEach((input) => input.addEventListener('change', updateRefundMethodReview));
    refundDestinationSelect?.addEventListener('change', updateRefundMethodReview);
    updateRefundMethodReview();
    f.querySelectorAll('[data-next]').forEach((button) => button.addEventListener('click', () => {
        if (step === 1 && !issue()) {
            alert('Choose an issue first.');
            return;
        }
        if (step === 2 && !reason()) {
            alert('Choose a reason first.');
            return;
        }
        if (step === 3) {
            const mediaError = uploadError();
            if (mediaError) {
                alert(mediaError);
                return;
            }
            if (!f.querySelector('[data-parcel-radio]:checked') || !f.querySelector('[data-item-checkbox]:checked') || !f.querySelector('[name="confirm_refund_method"]').checked || (refundMethod() === 'SAVED_DESTINATION' && !refundDestinationSelect?.value)) {
                alert('Choose a parcel, select affected items and a saved refund destination if applicable, then confirm the refund method.');
                return;
            }
        }
        if (step < 4) {
            step += 1;
            if (step === 4) review();
            show(step);
        }
    }));
    f.querySelectorAll('[data-back]').forEach((button) => button.addEventListener('click', () => {
        if (step > 1) {
            step -= 1;
            show(step);
        }
    }));
    descriptionInput.addEventListener('input', (event) => {
        document.getElementById('descriptionCount').textContent = `${event.target.value.length} / 2000`;
        uploadError();
    });
    imagesInput.addEventListener('change', renderImagePreview);
    f.querySelector('#video').addEventListener('change', (event) => {
        document.getElementById('videoName').textContent = event.target.files[0]?.name || 'MP4, MOV, or WEBM. Maximum 200 MB.';
    });
    submitButton?.addEventListener('click', (event) => {
        const error = uploadError();
        if (error) {
            event.preventDefault();
            alert(error);
            return;
        }
        if (!f.checkValidity()) {
            event.preventDefault();
            focusInvalidField();
        }
    });
    f.addEventListener('submit', (event) => {
        const error = uploadError();
        if (error) {
            event.preventDefault();
            alert(error);
            return;
        }
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting…';
            submitButton.setAttribute('aria-busy', 'true');
        }
    });
    syncParcelControls();
});
</script>
@endpush
@endsection
