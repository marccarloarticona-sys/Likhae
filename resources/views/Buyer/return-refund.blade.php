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
                <h2 class="text-xl font-bold text-stone-900 dark:text-white">Tell us more</h2><p class="rr-muted mt-1 text-sm">Select affected items and attach evidence. New COD refunds are cash-only and are paid only after approval, return custody, and inspection.</p>
                <div class="mt-5 grid gap-3">@foreach($order->sellerOrders->flatMap->items as $item)@php($unitRefund=(float)$item->line_total/max((int)$item->quantity,1))<label class="rr-item"><input type="checkbox" name="item_ids[]" value="{{ $item->id }}" data-item-checkbox data-item-id="{{ $item->id }}" data-unit-refund="{{ number_format($unitRefund,2,'.','') }}"><x-shared.product-thumbnail :item="$item" size="64"/><span class="min-w-0 flex-1"><strong class="block truncate">{{ $item->product_name }}</strong><small class="rr-muted block">{{ $item->variant_description ?: 'Standard' }} &middot; Purchased qty {{ $item->quantity }}</small></span><span class="flex items-center gap-2"><span class="text-xs rr-muted">Qty</span><input class="rr-field w-20 py-2 text-center" type="number" name="quantities[{{ $item->id }}]" value="1" min="1" max="{{ $item->quantity }}" data-quantity data-for-item="{{ $item->id }}" disabled></span></label>@endforeach</div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="description">Describe the issue</label><textarea class="rr-field" id="description" name="description" rows="5" maxlength="2000" required placeholder="Share details that can help the Seller review your request.">{{ old('description') }}</textarea><small class="rr-help" id="descriptionCount">0 / 2000</small></div><div class="space-y-4"><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="images">Photos <span class="rr-muted font-normal">(up to 6)</span></label><input class="rr-field" id="images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><div class="rr-preview mt-2" id="imagePreview"></div><small class="rr-help">JPG, PNG, or WEBP. Maximum 8 MB each.</small></div><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="video">Video <span class="rr-muted font-normal">(optional)</span></label><input class="rr-field" id="video" name="video" type="file" accept="video/mp4,video/quicktime,video/webm"><small class="rr-help" id="videoName">MP4, MOV, or WEBM. Maximum 200 MB.</small></div></div></div>
                <fieldset class="mt-5 rounded-xl border border-[#eadbce] bg-[#fff7f2] p-4 dark:border-[#5a443c] dark:bg-[#30211d]"><legend class="px-1 font-semibold text-stone-900 dark:text-white">Refund method</legend><div class="grid gap-3 sm:grid-cols-2"><label class="flex items-start gap-2 rounded-lg border border-[#d9b6a8] bg-white/70 p-3 text-sm dark:bg-black/10"><input class="mt-1" type="radio" checked disabled aria-label="Cash refund method, active"><span><strong class="block">Cash / Current Refund Method</strong><small class="rr-help">New COD refunds are recorded as a cash obligation.</small></span></label><label class="flex items-start gap-2 rounded-lg border border-[#eadbce] p-3 text-sm opacity-65 dark:border-[#5a443c]"><input class="mt-1" type="radio" disabled aria-label="GCash refund method, coming soon"><span><strong class="block">GCash</strong><small class="rr-help">Coming Soon</small></span></label></div><p class="rr-help mt-3">Approval does not mean payment; the return must be received and inspected first, and an authorized cash payer must be configured.</p><label class="mt-4 flex items-start gap-2 text-sm"><input class="mt-1" type="checkbox" name="confirm_cash" value="1" required><span>I understand that an approved cash refund is not paid immediately.</span></label></fieldset>
                <div class="mt-5 grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-semibold text-stone-800 dark:text-white" for="buyer_email">Email for updates</label><input class="rr-field" id="buyer_email" name="buyer_email" type="email" required maxlength="255" value="{{ old('buyer_email', auth()->user()->email) }}"></div><div class="rr-summary grid gap-2 p-4 text-sm"><div class="flex items-center justify-between gap-3"><span>Refundable Price</span><strong class="text-red-900 dark:text-red-200" id="refundPrice">PHP 0.00</strong></div><div class="flex items-center justify-between gap-3"><span class="font-semibold">Total Refund</span><strong class="text-lg text-red-900 dark:text-red-200" id="refundTotal">PHP 0.00</strong></div></div></div>
                <div class="mt-6 flex justify-between"><button type="button" class="lk-btn lk-btn-light" data-back>Back</button><button type="button" class="lk-btn lk-btn-red" data-next>Review Request</button></div>
            </section>
            <section class="rr-panel" data-step="4"><h2 class="text-xl font-bold text-stone-900 dark:text-white">Review your request</h2><p class="rr-muted mt-1 text-sm">The Seller will review your request. If approved, a cash obligation will be created after return receipt and inspection.</p><dl class="rr-summary mt-5 grid gap-3 p-4 text-sm sm:grid-cols-2"><div><dt class="rr-muted">Issue</dt><dd class="font-semibold" id="reviewIssue">-</dd></div><div><dt class="rr-muted">Reason</dt><dd class="font-semibold" id="reviewReason">-</dd></div><div><dt class="rr-muted">Refund method</dt><dd class="font-semibold">Cash</dd></div><div><dt class="rr-muted">Payment status</dt><dd class="font-semibold">Pending approval, return receipt, inspection, and authorized payer</dd></div><div><dt class="rr-muted">Total requested refund</dt><dd class="font-semibold" id="reviewAmount">PHP 0.00</dd></div><div><dt class="rr-muted">Email</dt><dd class="font-semibold break-all" id="reviewEmail">-</dd></div><div class="sm:col-span-2"><dt class="rr-muted">Affected items</dt><dd class="font-semibold" id="reviewItems">-</dd></div><div class="sm:col-span-2"><dt class="rr-muted">Description</dt><dd class="whitespace-pre-wrap" id="reviewDescription">-</dd></div><div class="sm:col-span-2"><dt class="rr-muted">Evidence</dt><dd class="font-semibold" id="reviewEvidence">No files attached</dd></div></dl><label class="mt-5 flex items-start gap-2 text-sm"><input type="checkbox" required class="mt-1" name="confirm_request" value="1"><span>I confirm that the information and evidence I provided are accurate and understand that approval and payment are not immediate.</span></label><div class="mt-6 flex justify-between"><button type="button" class="lk-btn lk-btn-light" data-back>Back</button><button type="submit" class="lk-btn lk-btn-red">Submit Request</button></div></section>
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
    const amount = () => [...f.querySelectorAll('[data-item-checkbox]:checked')]
        .reduce((total, checkbox) => total + Number(checkbox.dataset.unitRefund) * Number(f.querySelector(`[data-for-item="${checkbox.dataset.itemId}"]`).value || 0), 0);
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
        document.getElementById('reviewEmail').textContent = document.getElementById('buyer_email').value;
        document.getElementById('reviewDescription').textContent = document.getElementById('description').value;
        document.getElementById('reviewItems').textContent = [...f.querySelectorAll('[data-item-checkbox]:checked')]
            .map((checkbox) => checkbox.closest('.rr-item').querySelector('strong').textContent)
            .join(', ') || '-';
        document.getElementById('reviewEvidence').textContent = [...f.querySelector('#images').files]
            .map((file) => file.name)
            .concat(f.querySelector('#video').files[0]?.name || [])
            .join(', ') || 'No files attached';
        refresh();
    };
    const uploadError = () => {
        const oversizedImage = [...f.querySelector('#images').files].find((file) => file.size > 8 * 1024 * 1024);
        if (oversizedImage) return 'Each proof image must be 8 MB or smaller.';
        const video = f.querySelector('#video').files[0];
        if (video && video.size > 200 * 1024 * 1024) return 'The proof video must be 200 MB or smaller.';
        return '';
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
    f.querySelectorAll('[data-item-checkbox]').forEach((checkbox) => checkbox.addEventListener('change', () => {
        const quantity = f.querySelector(`[data-for-item="${checkbox.dataset.itemId}"]`);
        quantity.disabled = !checkbox.checked;
        refresh();
    }));
    f.querySelectorAll('[data-quantity]').forEach((quantity) => quantity.addEventListener('input', refresh));
    f.querySelectorAll('[data-next]').forEach((button) => button.addEventListener('click', () => {
        if (step === 1 && !issue()) {
            alert('Choose an issue first.');
            return;
        }
        if (step === 2 && !reason()) {
            alert('Choose a reason first.');
            return;
        }
        if (step === 3 && (!f.querySelector('[data-item-checkbox]:checked')
            || !f.querySelector('[name="confirm_cash"]').checked)) {
            alert('Select affected items and confirm the cash-refund policy.');
            return;
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
    f.querySelector('#description').addEventListener('input', (event) => {
        document.getElementById('descriptionCount').textContent = `${event.target.value.length} / 2000`;
    });
    f.querySelector('#images').addEventListener('change', (event) => {
        const preview = document.getElementById('imagePreview');
        preview.innerHTML = '';
        [...event.target.files].slice(0, 6).forEach((file) => {
            const image = document.createElement('img');
            image.src = URL.createObjectURL(file);
            image.alt = file.name;
            preview.append(image);
        });
    });
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
    refresh();
});
</script>
@endpush
@endsection
