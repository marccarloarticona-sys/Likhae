@extends('layouts.seller.app')

@section('title', 'Promotions')
@section('active', 'marketing')
@section('subtitle', 'Bundle offers and Buy X Get Y campaigns.')

@section('content')
<div class="sl-page">
    @if(session('status'))<div class="sl-compliance-feedback" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-compliance-feedback is-warning" role="alert">{{ $errors->first() }}</div>@endif

    <div class="sl-page-toolbar">
        <div><a href="{{ route('seller.marketing') }}" class="sl-editor-back">&larr; Back to Marketing</a><h2>Product Promotions</h2><p>Set discounts that apply when buyers place the required products in their cart.</p></div>
        <a href="{{ route('seller.marketing') }}" class="sl-btn sl-btn-ghost">Voucher Center</a>
    </div>

    <section class="sl-card">
        <div class="sl-table-toolbar"><div><h3>Your Promotions</h3><p>Discounts are recalculated against current stock and prices at checkout.</p></div></div>
        <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Promotion</th><th>Offer</th><th>Products</th><th>Schedule</th><th>Status</th><th>Action</th></tr></thead><tbody>
            @forelse($promotions as $promotion)
                <tr>
                    <td><strong>{{ $promotion->name }}</strong><small>{{ $promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE ? 'Bundle deal' : 'Buy X Get Y' }}</small></td>
                    <td>
                        @if($promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE)
                            Bundle price: PHP {{ number_format((float) $promotion->bundle_price, 2) }}
                        @else
                            Buy {{ $promotion->buy_quantity }}, get {{ $promotion->reward_quantity }} free
                        @endif
                    </td>
                    <td>
                        @if($promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE)
                            @foreach($promotion->components as $component)
                                <small>{{ $component->quantity }} × {{ $component->productVariant?->product?->name ?? 'Unavailable product' }} ({{ $component->productVariant?->sku ?? 'variant unavailable' }})</small>
                            @endforeach
                        @else
                            <small>Buy: {{ $promotion->buyVariant?->product?->name ?? 'Unavailable product' }} ({{ $promotion->buyVariant?->sku ?? 'variant unavailable' }})</small>
                            <small>Reward: {{ $promotion->rewardVariant?->product?->name ?? 'Unavailable product' }} ({{ $promotion->rewardVariant?->sku ?? 'variant unavailable' }})</small>
                        @endif
                    </td>
                    <td>{{ $promotion->starts_at?->format('M d, Y H:i') ?? 'Now' }} – {{ $promotion->ends_at?->format('M d, Y H:i') ?? 'No end' }}</td>
                    <td><span class="sl-status {{ $promotion->is_active ? 'is-success' : 'is-neutral' }}">{{ $promotion->is_active ? 'Active' : 'Paused' }}</span></td>
                    <td><form method="POST" action="{{ route('seller.promotions.toggle', $promotion) }}" data-confirm-title="{{ $promotion->is_active ? 'Pause promotion?' : 'Activate promotion?' }}" data-confirm-message="{{ $promotion->is_active ? 'This promotion will stop applying to new checkouts.' : 'This promotion will apply to qualifying new checkouts during its schedule.' }}" data-confirm-label="{{ $promotion->is_active ? 'Pause promotion' : 'Activate promotion' }}">@csrf @method('PATCH')<button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">{{ $promotion->is_active ? 'Pause' : 'Activate' }}</button></form></td>
                </tr>
            @empty<tr><td colspan="6">No promotions yet. Create a bundle or Buy X Get Y offer below.</td></tr>@endforelse
        </tbody></table></div>
        {{ $promotions->links() }}
    </section>

    <div class="sl-form-grid">
        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>Create a Bundle Deal</h3><p>Buyers must add every listed variant and quantity. Each bundle set receives the bundle price.</p></div></div>
            <form method="POST" action="{{ route('seller.promotions.store') }}" class="sl-form-section">@csrf
                <input type="hidden" name="promotion_type" value="BUNDLE">
                <label class="sl-field"><span>Promotion name</span><input name="name" maxlength="150" required value="{{ old('name') }}"></label>
                <label class="sl-field"><span>Bundle price (PHP)</span><input name="bundle_price" type="number" min="0.01" step="0.01" required value="{{ old('bundle_price') }}"><small>Must be below the current total price of one bundle set.</small></label>
                <div class="sl-field"><span>Bundle components</span>
                    <div data-bundle-components data-next-index="2">
                        @for($index = 0; $index < 2; $index++)
                            <div class="sl-form-grid" data-bundle-component>
                                <label class="sl-field"><span>Product variant</span><select name="components[{{ $index }}][product_variant_id]" required><option value="">Choose a variant</option>@foreach($variants as $variant)<option value="{{ $variant->id }}" @selected(old("components.$index.product_variant_id") == $variant->id)>{{ $variant->product->name }} — {{ $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }})</option>@endforeach</select></label>
                                <label class="sl-field"><span>Quantity</span><input name="components[{{ $index }}][quantity]" type="number" min="1" max="99" value="{{ old("components.$index.quantity", 1) }}" required></label>
                            </div>
                        @endfor
                    </div>
                    <button class="sl-btn sl-btn-ghost sl-btn-sm" type="button" data-add-bundle-component>Add a component</button>
                </div>
                <div class="sl-form-grid">
                    <label class="sl-field"><span>Starts at</span><input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}"></label>
                    <label class="sl-field"><span>Ends at</span><input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}"></label>
                </div>
                <button class="sl-btn sl-btn-primary" type="submit">Save Bundle Deal</button>
            </form>
        </section>

        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>Create a Buy X Get Y Offer</h3><p>The buyer adds the reward variant to the cart. Its qualifying quantity is free at checkout.</p></div></div>
            <form method="POST" action="{{ route('seller.promotions.store') }}" class="sl-form-section">@csrf
                <input type="hidden" name="promotion_type" value="BUY_X_GET_Y">
                <label class="sl-field"><span>Promotion name</span><input name="name" maxlength="150" required value="{{ old('name') }}"></label>
                <div class="sl-form-grid">
                    <label class="sl-field"><span>Buy variant</span><select name="buy_variant_id" required><option value="">Choose a variant</option>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->product->name }} — {{ $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }})</option>@endforeach</select></label>
                    <label class="sl-field"><span>Buy quantity</span><input name="buy_quantity" type="number" min="1" max="99" value="1" required></label>
                    <label class="sl-field"><span>Reward variant</span><select name="reward_variant_id" required><option value="">Choose a variant</option>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->product->name }} — {{ $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }})</option>@endforeach</select></label>
                    <label class="sl-field"><span>Reward quantity</span><input name="reward_quantity" type="number" min="1" max="99" value="1" required></label>
                </div>
                <div class="sl-form-grid">
                    <label class="sl-field"><span>Starts at</span><input name="starts_at" type="datetime-local"></label>
                    <label class="sl-field"><span>Ends at</span><input name="ends_at" type="datetime-local"></label>
                </div>
                <button class="sl-btn sl-btn-primary" type="submit">Save Buy X Get Y Offer</button>
            </form>
        </section>
    </div>
</div>

<template data-bundle-component-template>
    <div class="sl-form-grid" data-bundle-component>
        <label class="sl-field"><span>Product variant</span><select name="components[__INDEX__][product_variant_id]" required><option value="">Choose a variant</option>@foreach($variants as $variant)<option value="{{ $variant->id }}">{{ $variant->product->name }} — {{ $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }})</option>@endforeach</select></label>
        <label class="sl-field"><span>Quantity</span><input name="components[__INDEX__][quantity]" type="number" min="1" max="99" value="1" required></label>
    </div>
</template>
<script>
    document.querySelector('[data-add-bundle-component]')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        const container = document.querySelector('[data-bundle-components]');
        if (!container || container.querySelectorAll('[data-bundle-component]').length >= 10) return;
        const template = document.querySelector('[data-bundle-component-template]');
        const index = Number(container.dataset.nextIndex || 2);
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replaceAll('__INDEX__', String(index)); });
        container.appendChild(fragment);
        container.dataset.nextIndex = String(index + 1);
    });
</script>
@endsection
