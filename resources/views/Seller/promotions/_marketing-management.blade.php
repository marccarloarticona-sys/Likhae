@unless($promotionStorageReady)
    <div class="sl-compliance-feedback is-warning" role="status">Product promotions are temporarily unavailable while the product promotions database migration is pending.</div>
@else
    @php
        $isBuyXGetY = ($section ?? '') === 'buy-x-get-y';
        $variantOptions = collect($variants ?? []);
        $rewardProducts = $variantOptions->unique('product_id')->sortBy(fn ($variant) => $variant->product?->name)->values();
    @endphp

    <section class="sl-card">
        <div class="sl-table-toolbar"><div><h3>{{ $isBuyXGetY ? 'Buy X Get Y campaigns' : 'Bundle Deals' }}</h3><p>{{ $isBuyXGetY ? 'Choose a qualifying variation and a reward. Rewards may be free or discounted.' : 'Set a bundle price, quantity, schedule, and redemption limits. Group options to let buyers mix and match variations.' }}</p></div></div>
        <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Campaign</th><th>Offer</th><th>Items</th><th>Schedule &amp; limits</th><th>Status</th><th>Action</th></tr></thead><tbody>
            @if($promotions->isEmpty())
                <tr><td colspan="6">No {{ $isBuyXGetY ? 'Buy X Get Y' : 'bundle' }} campaigns yet.</td></tr>
            @else
                @foreach($promotions as $promotion)
                    @php
                        $promotionState = $promotion->state;
                        $promotionStateLabel = str($promotionState)->replace('_', ' ')->title();
                        $promotionStateClass = match ($promotionState) {
                            'ACTIVE' => 'is-success',
                            'SCHEDULED' => 'is-info',
                            'SOLD_OUT', 'EXPIRED' => 'is-warning',
                            default => 'is-neutral',
                        };
                    @endphp
                    <tr>
                        <td><strong>{{ $promotion->name }}</strong><small>{{ $promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE ? 'Bundle Deal' : 'Buy X Get Y' }}</small></td>
                        <td>@if($promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE)PHP {{ number_format((float) $promotion->bundle_price, 2) }} per set @else Buy {{ (int) $promotion->buy_quantity }}, get {{ (int) $promotion->reward_quantity }} at {{ number_format((float) ($promotion->reward_discount_percent ?? 100), 0) }}% off @endif</td>
                        <td>
                            @if($promotion->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE)
                                @foreach($promotion->components->groupBy(fn ($component) => filled($component->choice_group) ? 'choice:'.$component->choice_group : 'variant:'.$component->id) as $groupKey => $options)
                                    <small>@if($options->first()->choice_group)Buyer chooses {{ $options->first()->quantity }} from {{ $options->count() }} options ({{ $options->first()->choice_group }})@else{{ $options->first()->quantity }} &times; {{ $options->first()->productVariant?->product?->name ?? 'Unavailable item' }} — {{ $options->first()->productVariant?->sku ?? 'variation unavailable' }}@endif</small>
                                @endforeach
                            @else
                                <small>Buy: {{ $promotion->buyVariant?->product?->name ?? 'Unavailable item' }} — {{ $promotion->buyVariant?->sku ?? 'variation unavailable' }}</small>
                                <small>Reward: {{ $promotion->rewardProduct?->name ?? $promotion->rewardVariant?->product?->name ?? 'Unavailable item' }}{{ $promotion->rewardVariant ? ' — '.$promotion->rewardVariant->sku : ' — buyer selects a variation' }}</small>
                            @endif
                        </td>
                        <td>{{ $promotion->starts_at?->format('M d, Y H:i') ?? 'Starts now' }} – {{ $promotion->ends_at?->format('M d, Y H:i') ?? 'No end date' }}<small>{{ $promotion->max_redemptions !== null ? max(0, (int) $promotion->max_redemptions - (int) $promotion->redemptions_used).' redemptions left' : 'No total limit' }} · {{ $promotion->max_per_buyer ? $promotion->max_per_buyer.' per buyer' : 'No buyer limit' }}</small></td>
                        <td><span class="sl-status {{ $promotionStateClass }}">{{ $promotionStateLabel }}</span></td>
                        <td><form method="POST" action="{{ route('seller.promotions.toggle', $promotion) }}" data-confirm-title="{{ $promotion->is_active ? 'Pause promotion?' : 'Activate promotion?' }}" data-confirm-message="{{ $promotion->is_active ? 'This promotion will stop applying to new checkouts.' : 'This promotion will apply to qualifying checkouts during its schedule.' }}" data-confirm-label="{{ $promotion->is_active ? 'Pause promotion' : 'Activate promotion' }}">@csrf @method('PATCH')<button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit" @disabled(in_array($promotionState, ['EXPIRED', 'SOLD_OUT'], true))>{{ $promotion->is_active ? 'Pause' : 'Activate' }}</button></form></td>
                    </tr>
                @endforeach
            @endif
        </tbody></table></div>
        @if(method_exists($promotions, 'links')){{ $promotions->links() }}@endif
    </section>

    @if(! $isBuyXGetY)
        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>Create a Bundle Deal</h3><p>Buyers add the bundle to cart in one step. A shared choice group lets them mix variations; leave it blank for a required item.</p></div></div>
            @if($variantOptions->isEmpty())<p class="sl-empty-state">Publish an active product variation before creating a bundle.</p>@else
                <form method="POST" action="{{ route('seller.promotions.store') }}" class="sl-form-section">@csrf
                    <input type="hidden" name="promotion_type" value="BUNDLE">
                    <div class="sl-form-grid">
                        <label class="sl-field"><span>Campaign name</span><input name="name" maxlength="150" required value="{{ old('name') }}"></label>
                        <label class="sl-field"><span>Bundle price (PHP)</span><input name="bundle_price" type="number" min="0.01" step="0.01" required value="{{ old('bundle_price') }}"><small>Must be below the lowest possible regular set total. Each paid line stays at PHP 0.01 or more.</small></label>
                    </div>
                    <div class="sl-field"><span>Bundle components</span><p class="sl-field-help">Rows with the same optional choice group are alternatives buyers can combine. All rows in one group need the same quantity.</p>
                        <div class="space-y-3" data-bundle-components data-next-index="1">
                            @for($index = 0; $index < 1; $index++)
                                <div class="sl-form-grid" data-bundle-component>
                                    <label class="sl-field"><span>Seller variation</span><select name="components[{{ $index }}][product_variant_id]" required><option value="">Choose a variation</option>@foreach($variantOptions as $variant)@php $conflicts = collect($promotionConflicts['variants'][$variant->id] ?? []); @endphp<option value="{{ $variant->id }}" @selected(old("components.$index.product_variant_id") == $variant->id)>{{ $variant->product->name }} — {{ $variant->description ?: $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }}){{ $conflicts->isNotEmpty() ? ' · '.$conflicts->count().' conflicting offer(s)' : '' }}</option>@endforeach</select></label>
                                    <label class="sl-field"><span>Quantity per set</span><input name="components[{{ $index }}][quantity]" type="number" min="1" max="99" required value="{{ old("components.$index.quantity", 1) }}"></label>
                                    <label class="sl-field"><span>Choice group (optional)</span><input name="components[{{ $index }}][choice_group]" maxlength="60" pattern="[A-Za-z0-9_-]+" value="{{ old("components.$index.choice_group") }}" placeholder="e.g. shirt"><small>Use the same word for options buyers may mix and match.</small></label>
                                    <button class="sl-btn sl-btn-ghost sl-btn-sm self-end" type="button" data-remove-bundle-component disabled>Remove option</button>
                                </div>
                            @endfor
                        </div>
                        <button class="sl-btn sl-btn-ghost sl-btn-sm" type="button" data-add-bundle-component>Add a variation option</button>
                    </div>
                    <div class="sl-form-grid">
                        <label class="sl-field"><span>Starts at</span><input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}"><small>Leave blank to start now.</small></label>
                        <label class="sl-field"><span>Ends at</span><input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}"></label>
                        <label class="sl-field"><span>Maximum redemptions</span><input name="max_redemptions" type="number" min="1" value="{{ old('max_redemptions') }}" placeholder="No limit"></label>
                        <label class="sl-field"><span>Maximum per buyer</span><input name="max_per_buyer" type="number" min="1" value="{{ old('max_per_buyer') }}" placeholder="No limit"></label>
                    </div>
                    <button class="sl-btn sl-btn-primary" type="submit">Save Bundle Deal</button>
                </form>
            @endif
        </section>
    @else
        <section class="sl-card">
            <div class="sl-table-toolbar"><div><h3>Create a Buy X Get Y Offer</h3><p>Choose what buyers need to purchase, then configure the reward and redemption limits.</p></div></div>
            @if($variantOptions->isEmpty())<p class="sl-empty-state">Publish active product variations before creating an offer.</p>@else
                <form method="POST" action="{{ route('seller.promotions.store') }}" class="sl-form-section" data-buy-get-form>@csrf
                    <input type="hidden" name="promotion_type" value="BUY_X_GET_Y">
                    <div class="sl-form-grid">
                        <label class="sl-field"><span>Campaign name</span><input name="name" maxlength="150" required value="{{ old('name') }}"></label>
                        <label class="sl-field"><span>Qualifying variation</span><select name="buy_variant_id" required><option value="">Choose what the buyer must buy</option>@foreach($variantOptions as $variant)<option value="{{ $variant->id }}" @selected(old('buy_variant_id') == $variant->id)>{{ $variant->product->name }} — {{ $variant->description ?: $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }})</option>@endforeach</select></label>
                        <label class="sl-field"><span>Buy quantity</span><input name="buy_quantity" type="number" min="1" max="99" value="{{ old('buy_quantity', 1) }}" required></label>
                        <label class="sl-field"><span>Reward product</span><select name="reward_product_id" data-reward-product required><option value="">Choose the reward product</option>@foreach($rewardProducts as $variant)<option value="{{ $variant->product_id }}" @selected(old('reward_product_id') == $variant->product_id)>{{ $variant->product->name }}</option>@endforeach</select></label>
                        <label class="sl-field"><span>Reward variation</span><select name="reward_variant_id" data-reward-variant><option value="">Buyer chooses a variation</option>@foreach($variantOptions as $variant)<option value="{{ $variant->id }}" data-product-id="{{ $variant->product_id }}" @selected(old('reward_variant_id') == $variant->id)>{{ $variant->product->name }} — {{ $variant->description ?: $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }})</option>@endforeach</select><small>Choose a fixed variation or let the buyer select one in their cart.</small></label>
                        <label class="sl-field"><span>Reward quantity</span><input name="reward_quantity" type="number" min="1" max="99" value="{{ old('reward_quantity', 1) }}" required></label>
                        <label class="sl-field"><span>Reward discount (%)</span><input name="reward_discount_percent" type="number" min="0.01" max="100" step="0.01" value="{{ old('reward_discount_percent', 100) }}" required><small>100% is free. Lower values give that percentage off. Normal product prices remain at least PHP 0.01.</small></label>
                    </div>
                    <div class="sl-form-grid">
                        <label class="sl-field"><span>Starts at</span><input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}"><small>Leave blank to start now.</small></label>
                        <label class="sl-field"><span>Ends at</span><input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}"></label>
                        <label class="sl-field"><span>Maximum redemptions</span><input name="max_redemptions" type="number" min="1" value="{{ old('max_redemptions') }}" placeholder="No limit"></label>
                        <label class="sl-field"><span>Maximum per buyer</span><input name="max_per_buyer" type="number" min="1" value="{{ old('max_per_buyer') }}" placeholder="No limit"></label>
                    </div>
                    <button class="sl-btn sl-btn-primary" type="submit">Save Buy X Get Y Offer</button>
                </form>
            @endif
        </section>
    @endif
@endunless

<template data-bundle-component-template>
    <div class="sl-form-grid" data-bundle-component>
        <label class="sl-field"><span>Seller variation</span><select name="components[__INDEX__][product_variant_id]" required><option value="">Choose a variation</option>@foreach($variants ?? [] as $variant)@php $conflicts = collect($promotionConflicts['variants'][$variant->id] ?? []); @endphp<option value="{{ $variant->id }}">{{ $variant->product->name }} — {{ $variant->description ?: $variant->sku }} (PHP {{ number_format((float) $variant->final_price, 2) }}){{ $conflicts->isNotEmpty() ? ' · '.$conflicts->count().' conflicting offer(s)' : '' }}</option>@endforeach</select></label>
        <label class="sl-field"><span>Quantity per set</span><input name="components[__INDEX__][quantity]" type="number" min="1" max="99" value="1" required></label>
        <label class="sl-field"><span>Choice group (optional)</span><input name="components[__INDEX__][choice_group]" maxlength="60" pattern="[A-Za-z0-9_-]+" placeholder="e.g. shirt"><small>Use the same word for options buyers may mix and match.</small></label>
        <button class="sl-btn sl-btn-ghost sl-btn-sm self-end" type="button" data-remove-bundle-component>Remove option</button>
    </div>
</template>

@if(($section ?? '') === 'buy-x-get-y')
    <script>
        document.querySelectorAll('[data-buy-get-form]').forEach((form) => {
            const product = form.querySelector('[data-reward-product]');
            const variant = form.querySelector('[data-reward-variant]');
            if (!product || !variant) return;
            const filterVariants = () => {
                const productId = product.value;
                [...variant.options].forEach((option, index) => {
                    if (index === 0) return;
                    option.hidden = !productId || option.dataset.productId !== productId;
                    option.disabled = option.hidden;
                });
                const selected = variant.selectedOptions[0];
                if (selected?.hidden) variant.value = '';
                variant.disabled = !productId;
            };
            product.addEventListener('change', () => { variant.value = ''; filterVariants(); });
            filterVariants();
        });
    </script>
@endif
