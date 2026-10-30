@extends('layouts.seller.app')

@php
    $currentTab = $tab ?? request('tab','profile');
    $shopName = $storeProfile->shop_name ?: 'LIKHAE Studio';
    $shopInitials = collect(explode(' ', $shopName))->filter()->take(2)->map(fn($w)=>mb_strtoupper(mb_substr($w,0,1)))->implode('');
    $buyerPreviewLocation = collect([$storeProfile->municipality, $storeProfile->province])->filter()->implode(', ');
@endphp

@section('title','Store')
@section('active','store')
@section('subtitle','Manage your public shop identity, information, and operating settings.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Store Management</span><h2>Your Shop</h2><p>Manage the shop details and images customers see.</p></div><a href="{{ route('seller.products') }}" class="sl-btn sl-btn-ghost">Preview Catalog</a></div>
    <nav class="sl-tabs"><a href="{{ route('seller.store',['tab'=>'profile']) }}" class="{{ $currentTab==='profile'?'is-active':'' }}">Shop Profile</a><a href="{{ route('seller.store',['tab'=>'settings']) }}" class="{{ $currentTab==='settings'?'is-active':'' }}">Store Settings</a></nav>

    @if($currentTab === 'settings')
        <form class="sl-settings-layout" method="POST" action="{{ route('seller.store.update') }}">@csrf @method('PUT')
            <input type="hidden" name="business_name" value="{{ $storeProfile->shop_name }}">
            <div class="sl-settings-nav sl-card"><strong>Settings</strong><a href="#operations" class="is-active">Operations</a><a href="#orders">Order Preferences</a><a href="#visibility">Store Visibility</a></div>
            <div class="sl-settings-main">
                <section class="sl-card sl-form-section" id="operations"><header class="sl-card-head"><div><h2>Store Operations</h2><p>Set handling time and operating schedule.</p></div></header><div class="sl-form-grid"><label class="sl-field"><span>Business days</span><input name="business_days" value="{{ old('business_days',$storeProfile->business_days) }}"></label><label class="sl-field"><span>Business hours</span><input name="business_hours" value="{{ old('business_hours',$storeProfile->business_hours) }}"></label><label class="sl-field"><span>Default preparation days</span><input name="processing_days" type="number" min="1" max="30" value="{{ old('processing_days',$storeProfile->processing_days) }}"></label><label class="sl-field"><span>Order cutoff</span><input name="order_cutoff" value="{{ old('order_cutoff',$storeProfile->order_cutoff) }}" placeholder="e.g. 2:00 PM"></label></div></section>
                <section class="sl-card sl-form-section" id="orders"><header class="sl-card-head"><div><h2>Order Preferences</h2><p>Control automatic acceptance behavior.</p></div></header><div class="sl-setting-switch"><div><strong>Automatic order acceptance</strong><p>Enable automatic acceptance when stock is available.</p></div><label class="sl-switch"><input type="hidden" name="auto_accept_orders" value="0"><input name="auto_accept_orders" value="1" type="checkbox" @checked($storeProfile->auto_accept_orders)><span></span></label></div></section>
                <section class="sl-card sl-form-section" id="visibility"><header class="sl-card-head"><div><h2>Store Visibility</h2><p>Control buyer discovery and vacation state.</p></div></header><div class="sl-setting-switch"><div><strong>Store is visible</strong><p>Buyers can discover active listings.</p></div><label class="sl-switch"><input type="hidden" name="store_visibility" value="0"><input name="store_visibility" value="1" type="checkbox" @checked($storeProfile->store_visibility)><span></span></label></div><div class="sl-setting-switch"><div><strong>Vacation mode</strong><p>Temporarily pause new orders.</p></div><label class="sl-switch"><input type="hidden" name="vacation_mode" value="0"><input name="vacation_mode" value="1" type="checkbox" @checked($storeProfile->vacation_mode)><span></span></label></div></section>
                <div class="sl-sticky-actions"><a href="{{ route('seller.store',['tab'=>'settings']) }}" class="sl-btn sl-btn-ghost">Discard</a><button type="submit" class="sl-btn sl-btn-primary">Save Settings</button></div>
            </div>
        </form>
    @else
        <form class="sl-store-layout" method="POST" enctype="multipart/form-data" action="{{ route('seller.store.update') }}">@csrf @method('PUT')
            <aside class="sl-card sl-store-preview" aria-label="Buyer-facing shop preview">
                <div class="sl-store-preview-banner">
                    <x-shared.profile-image :path="$storeProfile->banner_path" role="store-banner" class="sl-store-cover" data-branding-preview="banner" :alt="$shopName.' banner'" />
                    <div class="sl-store-preview-scrim" aria-hidden="true"></div>
                </div>
                <div class="sl-store-preview-heading">
                    <div class="sl-shop-logo"><x-shared.profile-image :path="$storeProfile->avatar_path" role="seller" data-branding-preview="avatar" :alt="$shopName.' avatar'" /></div>
                    <div class="sl-store-preview-copy">
                        <span class="sl-store-preview-eyebrow">Verified LIKHAE store</span>
                        <h2>{{ $shopName }}</h2>
                        @if(filled($storeProfile->tagline))<p class="sl-store-preview-tagline">{{ $storeProfile->tagline }}</p>@endif
                        @if(filled($buyerPreviewLocation) || filled($storeProfile->joined))
                            <p class="sl-store-preview-meta">{{ $buyerPreviewLocation }}@if(filled($buyerPreviewLocation) && filled($storeProfile->joined)) · @endif @if(filled($storeProfile->joined))Joined {{ $storeProfile->joined }}@endif</p>
                        @endif
                    </div>
                </div>
                <div class="sl-store-preview-about">
                    <h3>About this store</h3>
                    <p>{{ $storeProfile->description ?: 'Complete your shop description.' }}</p>
                </div>
            </aside>
            <div class="sl-store-form">
                <section class="sl-card sl-form-section"><header class="sl-card-head"><div><h2>Shop Information</h2><p>This information is visible to buyers.</p></div></header><div class="sl-form-grid"><label class="sl-field sl-span-2"><span>Shop name <b>*</b></span><input name="business_name" value="{{ old('business_name',$storeProfile->shop_name) }}" minlength="3" maxlength="30" required aria-describedby="shop-name-help"><small id="shop-name-help">Use a unique store name between 3 and 30 characters.</small></label>
                    <div class="sl-field sl-span-2"><span>Shop logo</span><input id="store-avatar-file" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-branding-file="avatar"><small>Square crop - JPEG, PNG, or WebP - max 5 MB.</small><label>Zoom <input type="range" min="100" max="200" value="100" data-branding-zoom="avatar"></label><label>Horizontal position <input type="range" min="0" max="100" value="50" data-branding-x="avatar"></label><label>Vertical position <input type="range" min="0" max="100" value="50" data-branding-y="avatar"></label></div>
                    <div class="sl-field sl-span-2"><span>Shop cover photo</span><input id="store-banner-file" type="file" name="banner" accept="image/jpeg,image/png,image/webp" data-branding-file="banner"><small>Wide crop - JPEG, PNG, or WebP - max 5 MB</small><label>Zoom <input type="range" min="100" max="200" value="100" data-branding-zoom="banner"></label><label>Horizontal position <input type="range" min="0" max="100" value="50" data-branding-x="banner"></label><label>Vertical position <input type="range" min="0" max="100" value="50" data-branding-y="banner"></label></div>
                    <label class="sl-field sl-span-2"><span>Tagline</span><input name="tagline" value="{{ old('tagline',$storeProfile->tagline) }}"></label><label class="sl-field sl-span-2"><span>Description</span><textarea name="description" rows="5" maxlength="500" data-description-input>{{ old('description',$storeProfile->description) }}</textarea><small><span data-description-count>{{ mb_strlen(old('description',$storeProfile->description)) }}</span>/500 characters</small></label></div></section>
                <div class="sl-sticky-actions"><a href="{{ route('seller.store') }}" class="sl-btn sl-btn-ghost">Cancel</a><button type="submit" class="sl-btn sl-btn-primary">Save Shop Profile</button></div>
            </div>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const states = new Map();
    const maxBytes = 5 * 1024 * 1024;
    const descriptionInput = document.querySelector('[data-description-input]');
    const descriptionCount = document.querySelector('[data-description-count]');
    const updateDescriptionCount = () => {
        if (descriptionCount && descriptionInput) descriptionCount.textContent = String([...descriptionInput.value].length);
    };
    descriptionInput?.addEventListener('input', updateDescriptionCount);

    const drawBrandingCrop = (key, image) => {
        const ratio = key === 'avatar' ? 1 : 3;
        const width = key === 'avatar' ? 600 : 1200;
        const height = Math.round(width / ratio);
        const zoom = Number(document.querySelector('[data-branding-zoom="' + key + '"]').value) / 100;
        const scale = Math.max(width / image.width, height / image.height) * zoom;
        const drawWidth = image.width * scale;
        const drawHeight = image.height * scale;
        const x = (width - drawWidth) * Number(document.querySelector('[data-branding-x="' + key + '"]').value) / 100;
        const y = (height - drawHeight) * Number(document.querySelector('[data-branding-y="' + key + '"]').value) / 100;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.getContext('2d').drawImage(image, x, y, drawWidth, drawHeight);
        return canvas;
    };

    const updatePreview = (key) => {
        const state = states.get(key);
        const preview = document.querySelector('[data-branding-preview="' + key + '"]');
        if (!state || !preview) return;
        preview.hidden = false;
        if (!state.image.complete || !state.image.naturalWidth) return;
        preview.style.objectPosition = '50% 50%';
        preview.style.transform = 'none';
        preview.src = drawBrandingCrop(key, state.image).toDataURL('image/webp', .88);
    };

    document.querySelectorAll('[data-branding-file]').forEach((input) => input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file) return;
        if (file.size > maxBytes) {
            input.value = '';
            input.setCustomValidity('Choose an original image that is 5 MB or smaller.');
            input.reportValidity();
            return;
        }
        input.setCustomValidity('');
        const old = states.get(input.dataset.brandingFile);
        if (old?.url) URL.revokeObjectURL(old.url);
        const key = input.dataset.brandingFile;
        const state = {file, url: URL.createObjectURL(file), image: new Image()};
        states.set(key, state);
        state.image.addEventListener('load', () => {
            if (states.get(key) === state) updatePreview(key);
        }, {once: true});
        state.image.src = state.url;
    }));

    document.querySelectorAll('[data-branding-zoom], [data-branding-x], [data-branding-y]').forEach((control) =>
        control.addEventListener('input', () => updatePreview(control.dataset.brandingZoom || control.dataset.brandingX || control.dataset.brandingY))
    );

    document.querySelector('.sl-store-layout')?.addEventListener('submit', async (event) => {
        const form = event.currentTarget;
        const selected = [...states.entries()];
        if (!selected.length) return;
        event.preventDefault();

        for (const [key, state] of selected) {
            const originalInput = document.createElement('input');
            originalInput.type = 'file';
            originalInput.name = key + '_original';
            originalInput.hidden = true;
            const originalTransfer = new DataTransfer();
            originalTransfer.items.add(state.file);
            originalInput.files = originalTransfer.files;
            form.appendChild(originalInput);

            try {
                const image = await createImageBitmap(state.file);
                const canvas = drawBrandingCrop(key, image);
                image.close();
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', .88));
                if (blob && blob.size <= maxBytes) {
                    const input = document.querySelector('[data-branding-file="' + key + '"]');
                    const transfer = new DataTransfer();
                    transfer.items.add(new File([blob], key + '.webp', {type: 'image/webp'}));
                    input.files = transfer.files;
                }
            } catch (error) {
                console.warn('Image crop could not be generated; uploading the original image.', error);
            }
        }
        HTMLFormElement.prototype.submit.call(form);
    });
    updateDescriptionCount();
});
</script>
@endpush
