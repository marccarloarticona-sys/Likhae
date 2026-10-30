@props(['promotions' => collect(), 'guest' => false, 'heading' => 'Bundle Deals & Buy X Get Y', 'eyebrow' => 'Seller offers', 'compact' => false])

@php
    $availableOffers = collect($promotions)->filter(fn ($offer) => data_get($offer, 'state') === 'ACTIVE')->values();
@endphp

@if($availableOffers->isNotEmpty())
    <section class="mt-5 space-y-3" aria-label="{{ $heading }}">
        <div><span class="lk-kicker">{{ $eyebrow }}</span><h2 class="text-lg font-bold text-stone-900">{{ $heading }}</h2><p class="text-sm text-stone-600">Offer prices are checked against available stock and confirmed at checkout.</p></div>
        @foreach($availableOffers as $offer)
            @php
                $remainingOfferSets = $offer->buyer_redemptions_remaining
                    ?? ($offer->max_redemptions !== null ? max(0, (int) $offer->max_redemptions - (int) $offer->redemptions_used) : 99);
                $maximumOfferSets = min(99, max(1, (int) $remainingOfferSets));
                $offerCardPadding = $compact ? 'p-3 sm:p-4' : 'p-4 sm:p-5';
            @endphp
            <article class="rounded-2xl border border-[#eadccc] bg-[#fffdf9] {{ $offerCardPadding }} shadow-sm">
                @if($offer->promotion_type === \App\Models\Seller\SellerPromotion::TYPE_BUNDLE)
                    @php
                        $bundleGroups = $offer->components->groupBy(fn ($component) => filled($component->choice_group) ? 'choice:'.$component->choice_group : 'variant:'.$component->id);
                        $regularBundleTotal = (float) $bundleGroups->sum(function ($options): float {
                            $component = $options->first();
                            $availablePrices = $options->filter(fn ($option) => $option->productVariant && (int) $option->productVariant->stock >= (int) $option->quantity)
                                ->map(fn ($option) => (float) $option->productVariant->price);

                            return (float) ($availablePrices->min() ?? 0) * max(1, (int) $component->quantity);
                        });
                        $bundleSavings = max(0, $regularBundleTotal - (float) $offer->bundle_price);
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><span class="text-[10px] font-bold uppercase tracking-widest text-[#7b2c23]">Bundle deal</span><h3 class="mt-1 font-bold text-stone-900">{{ $offer->name }}</h3>
                            <p class="mt-1 text-sm text-stone-600">Get all listed items together for <strong class="text-[#561c17]">₱{{ number_format((float) $offer->bundle_price, 2) }}</strong>.</p>
                            @if($regularBundleTotal > 0)<p class="mt-1 text-xs text-stone-500">Usual total <s>₱{{ number_format($regularBundleTotal, 2) }}</s> · Save <strong class="text-[#561c17]">₱{{ number_format($bundleSavings, 2) }}</strong></p>@endif
                            <div class="mt-3 flex gap-2">
                                @foreach($bundleGroups as $options)
                                    @php
                                        $imageProduct = $options->firstWhere('productVariant.stock', '>', 0)?->productVariant?->product;
                                        $imagePath = $imageProduct?->images?->first()?->file_path;
                                        $imageUrl = $imagePath ? (str_starts_with((string) $imagePath, 'http') ? $imagePath : '/storage/'.ltrim((string) $imagePath, '/')) : null;
                                    @endphp
                                    @if($imageUrl)<img class="h-12 w-12 rounded-lg object-cover" src="{{ $imageUrl }}" alt="{{ $imageProduct?->name }}" loading="lazy">@endif
                                @endforeach
                            </div>
                            <ul class="mt-2 space-y-1 text-xs text-stone-600">
                                @foreach($bundleGroups as $options)
                                    @if($options->first()->choice_group)
                                        <li>{{ (int) $options->first()->quantity }} item(s) from {{ $options->pluck('productVariant.product.name')->filter()->unique()->join(', ') }}. Choose or mix these variations below.</li>
                                    @else
                                        @php $component = $options->first(); @endphp
                                        <li>{{ (int) $component->quantity }} × {{ $component->productVariant?->product?->name ?? 'Unavailable item' }} — {{ $component->productVariant?->description ?: $component->productVariant?->sku }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                        @if($offer->max_redemptions !== null)<span class="rounded-full bg-[#f4e6df] px-3 py-1 text-xs font-semibold text-[#561c17]">{{ max(0, (int) $offer->max_redemptions - (int) $offer->redemptions_used) }} bundle set(s) left</span>@endif
                    </div>
                    @if($guest)
                        <a class="mt-4 inline-flex rounded-xl bg-[#561c17] px-4 py-2 text-sm font-semibold text-white" href="{{ route('login') }}">Sign in to add this bundle</a>
                    @else
                        <form method="POST" action="{{ route('buyer.cart.bundle-add', ['promotion' => $offer->id]) }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf
                            @foreach($offer->components->groupBy(fn ($component) => filled($component->choice_group) ? 'choice:'.$component->choice_group : 'variant:'.$component->id) as $options)
                                @if($options->first()->choice_group)
                                    @php
                                        $choiceOptions = $options->filter(fn ($component) => $component->productVariant && (int) $component->productVariant->stock > 0);
                                    @endphp
                                    @if($choiceOptions->isNotEmpty())
                                        <label class="text-xs font-semibold text-stone-700">Choose {{ $options->first()->choice_group }} variation
                                            <select class="mt-1 block min-w-48 rounded-lg border border-stone-300 px-3 py-2" name="choices[{{ $options->first()->choice_group }}]" required>
                                                <option value="">Choose a variation</option>
                                                @foreach($choiceOptions as $component)
                                                    <option value="{{ $component->product_variant_id }}">{{ $component->productVariant->product?->name }} — {{ $component->productVariant->description ?: $component->productVariant->sku }} · ₱{{ number_format((float) $component->productVariant->final_price, 2) }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                @endif
                            @endforeach
                            <label class="text-xs font-semibold text-stone-700">Bundle sets <input class="mt-1 block w-24 rounded-lg border border-stone-300 px-3 py-2" type="number" name="sets" min="1" max="{{ $maximumOfferSets }}" value="1"></label>
                            <button class="rounded-xl bg-[#561c17] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#3e130f]" type="submit">Add Bundle to Cart</button>
                        </form>
                    @endif
                @else
                    @php
                        $rewardPercent = min(100, max(1, (float) ($offer->reward_discount_percent ?? 100)));
                        $rewardVariants = $offer->reward_variant_id
                            ? collect([$offer->rewardVariant])->filter()->values()
                            : collect($offer->rewardProduct?->variants ?? [])->where('is_active', true)->where('stock', '>', 0)->values();
                        $rewardProductName = $offer->rewardVariant?->product?->name ?? $offer->rewardProduct?->name ?? 'reward item';
                        $buyProduct = $offer->buyVariant?->product;
                        $rewardProduct = $offer->rewardVariant?->product ?? $offer->rewardProduct;
                        $buyImagePath = $buyProduct?->images?->first()?->file_path;
                        $rewardImagePath = $rewardProduct?->images?->first()?->file_path;
                        $buyImageUrl = $buyImagePath ? (str_starts_with((string) $buyImagePath, 'http') ? $buyImagePath : '/storage/'.ltrim((string) $buyImagePath, '/')) : null;
                        $rewardImageUrl = $rewardImagePath ? (str_starts_with((string) $rewardImagePath, 'http') ? $rewardImagePath : '/storage/'.ltrim((string) $rewardImagePath, '/')) : null;
                    @endphp
                    <div class="mb-3 flex items-center -space-x-3">
                        @if($buyImageUrl)<img class="h-14 w-14 rounded-xl border-2 border-white object-cover" src="{{ $buyImageUrl }}" alt="{{ $buyProduct?->name }}" loading="lazy">@endif
                        @if($rewardImageUrl)<img class="h-14 w-14 rounded-xl border-2 border-white object-cover" src="{{ $rewardImageUrl }}" alt="{{ $rewardProductName }}" loading="lazy">@endif
                    </div>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><span class="text-[10px] font-bold uppercase tracking-widest text-[#7b2c23]">Buy X Get Y</span><h3 class="mt-1 font-bold text-stone-900">{{ $offer->name }}</h3>
                            <p class="mt-1 text-sm text-stone-600">Buy {{ (int) $offer->buy_quantity }} × {{ $offer->buyVariant?->product?->name ?? 'qualifying item' }}, get {{ (int) $offer->reward_quantity }} × {{ $rewardProductName }} {{ $rewardPercent >= 100 ? 'free' : 'at '.rtrim(rtrim(number_format($rewardPercent, 2, '.', ''), '0'), '.').'% off' }}.</p>
                            @if($offer->max_per_buyer)<p class="mt-1 text-xs text-stone-500">Limit {{ (int) $offer->max_per_buyer }} redemption(s) per Buyer.</p>@endif
                        </div>
                        @if($offer->max_redemptions !== null)<span class="rounded-full bg-[#f4e6df] px-3 py-1 text-xs font-semibold text-[#561c17]">{{ max(0, (int) $offer->max_redemptions - (int) $offer->redemptions_used) }} redemption(s) left</span>@endif
                    </div>
                    @if($guest)
                        <a class="mt-4 inline-flex rounded-xl bg-[#561c17] px-4 py-2 text-sm font-semibold text-white" href="{{ route('login') }}">Sign in to claim this offer</a>
                    @else
                        <form method="POST" action="{{ route('buyer.cart.offer-claim', ['promotion' => $offer->id]) }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf
                            @if($offer->reward_variant_id)
                                <input type="hidden" name="reward_variant_id" value="{{ $offer->reward_variant_id }}">
                            @elseif($rewardVariants->count() === 1)
                                <input type="hidden" name="reward_variant_id" value="{{ $rewardVariants->first()->id }}">
                            @elseif($rewardVariants->isNotEmpty())
                                <label class="text-xs font-semibold text-stone-700">Choose reward variation
                                    <select class="mt-1 block min-w-48 rounded-lg border border-stone-300 px-3 py-2" name="reward_variant_id" required>
                                        <option value="">Choose a variation</option>
                                        @foreach($rewardVariants as $variant)<option value="{{ $variant->id }}">{{ $variant->description ?: $variant->sku }} · ₱{{ number_format((float) $variant->final_price, 2) }}</option>@endforeach
                                    </select>
                                </label>
                            @endif
                            <label class="text-xs font-semibold text-stone-700">Offer sets <input class="mt-1 block w-24 rounded-lg border border-stone-300 px-3 py-2" type="number" name="sets" min="1" max="{{ $maximumOfferSets }}" value="1"></label>
                            <button class="rounded-xl bg-[#561c17] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#3e130f]" type="submit">Add Items &amp; Claim</button>
                        </form>
                    @endif
                @endif
                @if($offer->starts_at || $offer->ends_at)<p class="mt-3 text-[11px] text-stone-500">{{ $offer->starts_at?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Available now' }}@if($offer->ends_at) – {{ $offer->ends_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}@endif</p>@endif
            </article>
        @endforeach
    </section>
@endif
