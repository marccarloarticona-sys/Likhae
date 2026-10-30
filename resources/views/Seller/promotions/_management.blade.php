@unless($promotionStorageReady)
    <div class="sl-compliance-feedback is-warning" role="status">Product promotions are temporarily unavailable while the product promotions database migration is pending.</div>
@else
    <section class="sl-card">
        <div class="sl-table-toolbar"><div><h3>{{ $section === 'buy-x-get-y' ? 'Buy X Get Y history' : 'Your Bundle Deals' }}</h3><p>{{ $section === 'buy-x-get-y' ? 'Existing offers are retained for records and do not apply to new checkouts.' : 'Bundle prices use the current product and variant prices at checkout.' }}</p></div></div>
        @if($section === 'buy-x-get-y')<div class="sl-compliance-feedback is-warning" role="note">Buy X Get Y creation is disabled because it makes the reward item free. Existing offers remain available here for history and can be paused.</div>@endif
        <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Promotion</th><th>Offer</th><th>Products</th><th>Schedule</th><th>Status</th><th>Action</th></tr></thead><tbody>
            @if($promotions->isEmpty())
                <tr><td colspan="6">No promotions yet. Create a bundle deal below.</td></tr>
            @else
            @foreach($promotions as $promotion)
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
                                <small>{{ $component->quantity }} &times; {{ $component->productVariant?->product?->name ?? 'Unavailable product' }} ({{ $component->productVariant?->sku ?? 'variant unavailable' }})</small>
                            @endforeach
                        @else
                            <small>Buy: {{ $promotion->buyVariant?->product?->name ?? 'Unavailable product' }} ({{ $promotion->buyVariant?->sku ?? 'variant unavailable' }})</small>
                            <small>Reward: {{ $promotion->rewardVariant?->product?->name ?? 'Unavailable product' }} ({{ $promotion->rewardVariant?->sku ?? 'variant unavailable' }})</small>
                        @endif
                    </td>
                    <td>{{ $promotion->starts_at?->format('M d, Y H:i') ?? 'Now' }} &ndash; {{ $promotion->ends_at?->format('M d, Y H:i') ?? 'No end' }}</td>
                    <td><span class="sl-status {{ $promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUY_X_GET_Y ? 'is-warning' : ($promotion->is_active ? 'is-success' : 'is-neutral') }}">{{ $promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUY_X_GET_Y ? 'Disabled · free item rule' : ($promotion->is_active ? 'Active' : 'Paused') }}</span></td>
                    <td><form method="POST" action="{{ route('seller.promotions.toggle', $promotion) }}" data-confirm-title="{{ $promotion->is_active ? 'Pause promotion?' : 'Activate promotion?' }}" data-confirm-message="{{ $promotion->is_active ? 'This promotion will stop applying to new checkouts.' : 'This promotion will apply to qualifying new checkouts during its schedule.' }}" data-confirm-label="{{ $promotion->is_active ? 'Pause promotion' : 'Activate promotion' }}">@csrf @method('PATCH')<button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">{{ $promotion->is_active ? 'Pause' : 'Activate' }}</button></form></td>
                </tr>
            @endforeach
            @endif
        </tbody></table></div>
        {{ $promotions->links() }}
    </section>

    @if($section === 'bundle-deals')
    <div class="sl-form-grid">
        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>Create a Bundle Deal</h3><p>Choose the items and quantities, then set one price for the complete bundle. Buyers must purchase every listed component.</p></div></div>
            <form method="POST" action="{{ route('seller.promotions.store') }}" class="sl-form-section">@csrf
                <input type="hidden" name="promotion_type" value="BUNDLE">
                <label class="sl-field"><span>Promotion name</span><input name="name" maxlength="150" required value="{{ old('name') }}"></label>
                <label class="sl-field"><span>Bundle price (PHP)</span><input name="bundle_price" type="number" min="0.01" step="0.01" required value="{{ old('bundle_price') }}"><small>Must be below the current total price of one bundle set.</small></label>
                <div class="sl-field"><span>Bundle components</span>
                    <div data-bundle-components data-next-index="2">
                        @for($index = 0; $index < 2; $index++)
                            <div class="sl-form-grid" data-bundle-component>
                                <label class="sl-field"><span>Product variant</span><small>Variants with ⚠ already have an active or scheduled offer.</small><select name="components[{{ $index }}][product_variant_id]" required><option value="">Choose a variant</option>
                                    @foreach($variants as $variant)
                                        @php
                                            $variantConflicts = collect($promotionConflicts['variants'][$variant->id] ?? []);
                                        @endphp
                                        <option value="{{ $variant->id }}" title="{{ $variantConflicts->pluck('label')->implode('; ') }}" @selected(old("components.$index.product_variant_id") == $variant->id)>{{ $variant->product->name }} &mdash; {{ $variant->description ?: $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }}){{ $variantConflicts->isNotEmpty() ? ' · ⚠ '.$variantConflicts->count().' existing offer(s)' : '' }}</option>
                                    @endforeach
                                </select></label>
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

        <section class="sl-card sl-compliance-feedback is-warning" role="note">
            <h3>Buy X Get Y offers are unavailable</h3>
            <p>They make the reward item free. Use a bundle deal and set a price that leaves every item at least PHP 0.01.</p>
        </section>
    </div>
    @endif
@endunless

<template data-bundle-component-template>
    <div class="sl-form-grid" data-bundle-component>
        <label class="sl-field"><span>Product variant</span><small>Variants with ⚠ already have an active or scheduled offer.</small><select name="components[__INDEX__][product_variant_id]" required><option value="">Choose a variant</option>
            @foreach($variants as $variant)
                @php
                    $variantConflicts = collect($promotionConflicts['variants'][$variant->id] ?? []);
                @endphp
                <option value="{{ $variant->id }}" title="{{ $variantConflicts->pluck('label')->implode('; ') }}">{{ $variant->product->name }} &mdash; {{ $variant->description ?: $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }}){{ $variantConflicts->isNotEmpty() ? ' · ⚠ '.$variantConflicts->count().' existing offer(s)' : '' }}</option>
            @endforeach
        </select></label>
        <label class="sl-field"><span>Quantity</span><input name="components[__INDEX__][quantity]" type="number" min="1" max="99" value="1" required></label>
    </div>
</template>
