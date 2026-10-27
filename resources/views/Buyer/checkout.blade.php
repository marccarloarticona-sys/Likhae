@extends('layouts.buyer.app')
@section('title', 'Checkout')
@section('active', 'cart')

@section('content')
@php
    $cartItems = collect($cartItems ?? []);
    $addresses = collect($addresses ?? []);
    $logisticsProviders = collect($logisticsProviders ?? []);
    $defaultAddress = $addresses->firstWhere('is_default', true) ?? $addresses->first();
    $defaultLogisticsProvider = $logisticsProviders->firstWhere('id', $selectedProviderId ?? null) ?? $logisticsProviders->first();
    $automaticRouting = (bool) ($automaticRouting ?? false);
    $deliveryAvailability = (array) ($deliveryAvailability ?? []);
    $deliveryAvailabilityKey = $automaticRouting
        ? (string) ($selectedAddressId ?? $defaultAddress?->id ?? '')
        : (string) ($selectedAddressId ?? $defaultAddress?->id ?? '').':'.(string) ($selectedProviderId ?? $defaultLogisticsProvider?->id ?? '');
    $selectedDeliveryAvailability = $deliveryAvailability[$deliveryAvailabilityKey] ?? ['available' => false, 'requires_review' => false, 'message' => 'Delivery is not available for this address yet. Choose another saved address or update its delivery details.'];
    $groups = collect(data_get($preview ?? [], 'groups', []));
    $availableVouchers = collect($availableVouchers ?? []);
    $sellerAvailableVouchers = $availableVouchers->except(['platform', 'shipping']);
    $platformVouchers = collect($availableVouchers->get('platform', []));
    $shippingVouchers = collect($availableVouchers->get('shipping', []));
    $sellerVoucherCodes = (array) ($sellerVoucherCodes ?? []);
    $platformVoucherCode = (string) ($platformVoucherCode ?? '');
    $shippingVoucherCode = (string) ($shippingVoucherCode ?? '');
    $rewardPointsBalance = (int) data_get($preview ?? [], 'available_reward_points', 0);
    $maxRedeemablePoints = (int) data_get($preview ?? [], 'maximum_redeemable_points', 0);
    $selectedRewardPoints = (int) ($selectedRewardPoints ?? 0);
    $protectionEligibleItemCount = $groups
        ->flatMap(fn ($group) => collect(data_get($group, 'items', [])))
        ->filter(fn ($item) => (bool) data_get($item, 'protection_quote.eligible', false))
        ->count();
    $hasUnavailableStock = (bool) ($hasUnavailableStock ?? $cartItems->contains(function ($item): bool {
        $variant = data_get($item, 'productVariant');

        return ! $variant
            || ! (bool) data_get($variant, 'is_active', false)
            || (int) data_get($variant, 'stock', 0) < (int) data_get($item, 'quantity', 0);
    }));
    $imageUrl = static function ($product, $variant = null): string {
        $assignedPath = data_get($variant, 'productImage.file_path');
        $image = collect(data_get($product, 'images', []))->firstWhere('is_primary', true) ?? collect(data_get($product, 'images', []))->first();
        $path = $assignedPath ?? data_get($image, 'file_path');
        if (! filled($path)) return asset('images/product-placeholder.svg');
        return \Illuminate\Support\Str::startsWith($path, ['http://', 'https://']) ? $path : \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    };
@endphp

<div class="lk-page-narrow">
    <div class="lk-page-title"><div><span class="lk-kicker">Secure Checkout</span><h1>Review and place your order</h1><p>Confirm your delivery address and payment method.</p></div><a class="lk-btn lk-btn-light" href="{{ route('buyer.cart') }}">Back to Cart</a></div>

    @if($cartItems->isEmpty())
        <x-buyer.empty-state title="Your cart is empty" message="Add a product before checking out." />
    @elseif($addresses->isEmpty())
        <section class="mt-5 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-stone-900">A delivery address is required</h2><p class="mt-2 text-sm text-stone-500">Save an address before placing this order.</p><a class="lk-btn lk-btn-red mt-5" href="{{ route('buyer.account.addresses') }}">Add delivery address</a></section>
    @else
        @if(filled($previewError ?? null))
            <section class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-900 shadow-sm" role="alert" aria-live="assertive">
                <h2 class="text-sm font-bold">Checkout needs attention</h2>
                <p class="mt-1 text-xs leading-5">{{ $previewError }}</p>
                @if($checkoutBlocked ?? false)
                    <p class="mt-2 text-xs font-semibold">Remove the unavailable item from your cart, then return to checkout.</p>
                    <a class="mt-2 inline-flex text-xs font-semibold underline" href="{{ route('buyer.cart') }}">Review cart</a>
                @endif
            </section>
        @endif

        @if(filled($priceChangeNotice ?? null))
            <section class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-900 shadow-sm" role="status" aria-live="polite">
                <h2 class="text-sm font-bold">Updated product prices</h2>
                <p class="mt-1 text-xs leading-5">{{ $priceChangeNotice }}</p>
            </section>
        @endif

        @if($errors->any() && !$errors->has('voucher_code') && !$errors->has('platform_voucher_code') && !$errors->has('shipping_voucher_code') && !collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'seller_voucher_codes.')))
            <section id="checkout-validation-errors" class="mt-5 rounded-2xl border border-red-300 bg-red-50 p-5 text-red-900 shadow-sm" role="alert" aria-live="assertive" tabindex="-1">
                <h2 class="text-sm font-bold">Your order could not be placed</h2>
                <p class="mt-1 text-xs">Please fix the following before trying again:</p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-xs font-semibold">
                    @foreach(collect($errors->all())->unique() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($hasUnavailableStock)
            <section class="mt-5 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm" role="alert">
                <p class="font-semibold">One or more selected products no longer have enough stock.</p>
                <p class="mt-1 text-xs">Return to your cart, remove the unavailable item or adjust its quantity, then try again.</p>
                <a class="mt-3 inline-flex text-xs font-semibold underline" href="{{ route('buyer.cart') }}">Return to cart</a>
            </section>
        @endif

        <form method="POST" action="{{ route('buyer.order.store') }}" class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]" data-checkout-form data-confirm-title="Place this order?" data-confirm-message="Review the items, delivery address, and total before placing your order." data-confirm-label="Place order">
            @csrf
            <input type="hidden" name="checkout_attempt_key" value="{{ $checkoutAttemptKey }}">
            @foreach($cartItems as $cartItem)
                <input type="hidden" name="cart_item_ids[]" value="{{ $cartItem->id }}">
            @endforeach
            <div class="space-y-5">
                <section class="rounded-2xl border border-stone-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4"><div><h2 class="text-base font-bold text-stone-900">Delivery Address</h2><p class="mt-1 text-xs text-stone-500">Your order will be delivered to this address.</p></div><a href="{{ route('buyer.account', ['tab' => 'addresses']) }}" class="shrink-0 text-xs font-semibold text-red-800">Change</a></div>
                    @error('address_id')<p class="mx-5 mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800">{{ $message }} <a class="underline" href="{{ route('buyer.account.addresses') }}">Open Account Addresses</a></p>@enderror
                    @if($errors->has('latitude') || $errors->has('longitude'))<p class="mx-5 mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800">{{ $errors->first('latitude') ?: $errors->first('longitude') }} <a class="underline" href="{{ route('buyer.account.addresses') }}">Confirm the address pin</a></p>@endif
                    <div class="grid gap-3 p-5">@foreach($addresses as $address)<label class="cursor-pointer"><input required type="radio" name="address_id" value="{{ $address->id }}" class="peer sr-only" @checked((string) old('address_id', $selectedAddressId ?? $defaultAddress?->id) === (string) $address->id)><span class="flex gap-3 rounded-xl border border-stone-200 p-4 transition peer-checked:border-red-800 peer-checked:bg-red-50/60"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-800">⌖</span><span class="min-w-0"><span class="flex flex-wrap items-center gap-2"><strong class="text-sm text-stone-900">{{ $address->recipient_name }}</strong><span class="text-xs text-stone-500">{{ $address->contact_number }}</span>@if($address->is_default)<span class="rounded-full bg-red-100 px-2 py-0.5 text-[9px] font-semibold uppercase text-red-900">Default</span>@endif</span><span class="mt-2 block text-xs leading-5 text-stone-600">{{ $address->formatted() }}</span><span class="mt-2 block text-[11px] font-semibold {{ filled($address->latitude) && filled($address->longitude) ? 'text-emerald-700' : 'text-amber-700' }}">{{ filled($address->latitude) && filled($address->longitude) ? 'Exact pin confirmed' : 'Confirm exact pin in Account before ordering' }}</span></span></span></label>@endforeach
                    <p data-delivery-availability role="status" aria-live="polite" class="rounded-lg border p-3 text-xs font-semibold {{ !data_get($selectedDeliveryAvailability, 'available') ? 'border-red-200 bg-red-50 text-red-800' : (data_get($selectedDeliveryAvailability, 'requires_review') ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">{{ data_get($selectedDeliveryAvailability, 'message') ?: (data_get($selectedDeliveryAvailability, 'available') ? 'Delivery is available to this address.' : 'Delivery is not available for this address yet. Choose another saved address or update its delivery details.') }}</p></div>
                </section>

                <section class="rounded-2xl border border-stone-200 bg-white shadow-sm">
                    <div class="border-b border-stone-100 px-5 py-4">
                        <h2 class="text-base font-bold text-stone-900">Delivery</h2>
                        <p class="mt-1 text-xs text-stone-500">{{ $automaticRouting ? 'Delivery fees are calculated per seller parcel for your selected address.' : 'Choose an available delivery provider.' }}</p>
                    </div>
                    <div class="grid gap-3 p-5">
                        @if($automaticRouting)
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                                <strong class="block">Delivery fee: &#8369;{{ number_format((float) config('likhae.standard_shipping_fee', 50), 2) }} per seller parcel</strong>
                                <span class="mt-1 block text-xs leading-5">Each shop ships as a separate parcel. Delivery availability is checked against the selected address.</span>
                            </div>
                        @else
                        @forelse($logisticsProviders as $provider)
                            <label class="cursor-pointer">
                                <input required type="radio" name="logistics_center_id" value="{{ $provider->id }}" data-shipping-fee="{{ number_format((float) $provider->shipping_fee, 2, '.', '') }}" class="peer sr-only" @checked((string) old('logistics_center_id', $selectedProviderId ?? $defaultLogisticsProvider?->id) === (string) $provider->id)>
                                <span class="flex items-center gap-3 rounded-xl border border-stone-200 p-4 transition peer-checked:border-red-800 peer-checked:bg-red-50">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-xs font-bold text-red-800">LP</span>
                                    <span class="min-w-0 flex-1">
                                        <strong class="block text-sm text-stone-900">{{ $provider->business_name }}</strong>
                                        <small class="mt-1 block text-xs text-stone-500">Delivery fee: &#8369;{{ number_format((float) $provider->shipping_fee, 2) }}</small>
                                    </span>
                                    <span class="text-xs font-semibold text-stone-500">Select</span>
                                </span>
                            </label>
                        @empty
                            <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">No active logistics provider is available for checkout right now.</p>
                        @endforelse
                        @endif
                        @error('logistics_center_id')<p class="text-xs font-semibold text-red-700">{{ $message }}</p>@enderror
                        @if(!$automaticRouting)<p class="text-[11px] leading-5 text-stone-500">The delivery fee is &#8369;{{ number_format((float) config('likhae.standard_shipping_fee', 50), 2) }} for each seller parcel.</p>@endif
                    </div>
                </section>

                @foreach($groups as $sellerId => $group)
                    @php
                        $sellerSubtotal = (float) data_get($group, 'item_subtotal', 0);
                        $sellerPromotionDiscount = (float) data_get($group, 'promotion_discount_total', 0);
                        $sellerDiscount = (float) data_get($group, 'voucher_discount', 0);
                        $sellerSellerVoucherDiscount = (float) data_get($group, 'seller_voucher_discount', 0);
                        $sellerPlatformVoucherDiscount = (float) data_get($group, 'platform_voucher_discount', 0);
                        $sellerShippingVoucherDiscount = (float) data_get($group, 'shipping_voucher_discount', 0);
                        $sellerRewardPoints = (int) data_get($group, 'reward_points_redeemed', 0);
                        $sellerRewardDiscount = (float) data_get($group, 'reward_points_discount', 0);
                        $sellerShipping = (float) data_get($group, 'shipping_fee', 0);
                        $sellerProtection = (float) data_get($group, 'protection_total', 0);
                        $sellerTotal = (float) data_get($group, 'grand_total', 0);
                    @endphp
                    <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-seller-card data-seller-id="{{ $sellerId }}" data-seller-subtotal="{{ number_format($sellerSubtotal, 2, '.', '') }}" data-seller-promotion-discount="{{ number_format($sellerPromotionDiscount, 2, '.', '') }}" data-seller-discount="{{ number_format($sellerDiscount, 2, '.', '') }}" data-seller-reward-discount="{{ number_format($sellerRewardDiscount, 2, '.', '') }}" data-seller-reward-points="{{ $sellerRewardPoints }}" data-seller-shipping-fee="{{ number_format($sellerShipping, 2, '.', '') }}" data-seller-shipping-discount="{{ number_format($sellerShippingVoucherDiscount, 2, '.', '') }}">
                        <header class="border-b border-stone-100 bg-stone-50 px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold uppercase tracking-wide text-stone-900">{{ data_get($group, 'seller.business_name', 'LIKHAE Seller') }}</h2>
                                <span class="rounded-full bg-white px-3 py-1 text-[10px] font-semibold text-stone-600">{{ collect(data_get($group, 'items', []))->count() }} {{ collect(data_get($group, 'items', []))->count() === 1 ? 'product' : 'products' }} · one seller shipment</span>
                            </div>
                        </header>
                        <div class="space-y-3 p-4 sm:p-5" aria-label="{{ data_get($group, 'seller.business_name', 'Seller') }} products">
                            @foreach(collect(data_get($group, 'items', [])) as $item)
                                @php
                                    $variant = $item->productVariant;
                                    $product = $variant->product;
                                    $protectionQuote = (array) ($item->protection_quote ?? []);
                                    $unitPrice = (float) $variant->final_price;
                                    $promotionDiscount = (float) ($item->getAttribute('checkout_promotion_discount_amount') ?? 0);
                                    $lineTotal = max(0, $unitPrice * (int) $item->quantity - $promotionDiscount);
                                @endphp
                                <article class="rounded-xl border border-stone-200 p-3 sm:p-4">
                                    <div class="flex gap-3">
                                        <img class="h-16 w-16 shrink-0 rounded-lg border border-stone-100 bg-stone-50 object-cover" src="{{ $imageUrl($product, $variant) }}" alt="{{ $product->name }}" width="64" height="64" decoding="async">
                                        <div class="min-w-0 flex-1">
                                            <strong class="block text-sm font-semibold text-stone-900">{{ $product->name }}</strong>
                                            <span class="mt-1 block text-xs text-stone-500">{{ $variant->description ?: 'Standard' }}</span>
                                            <span class="mt-2 block text-xs text-stone-600">&#8369;{{ number_format($unitPrice, 2) }} × {{ $item->quantity }}</span>
                                            @foreach((array) ($item->getAttribute('checkout_promotion_snapshot') ?? []) as $promotionSnapshot)
                                                <span class="mt-1 block text-xs font-semibold text-emerald-700">{{ data_get($promotionSnapshot, 'name', 'Seller promotion') }}: −&#8369;{{ number_format((float) data_get($promotionSnapshot, 'discount_amount', 0), 2) }}</span>
                                            @endforeach
                                        </div>
                                        <div class="shrink-0 text-right">
                                            <span class="block text-[10px] uppercase tracking-wide text-stone-400">Line total</span>
                                            <strong class="mt-1 block text-sm font-bold text-stone-900">&#8369;{{ number_format($lineTotal, 2) }}</strong>
                                        </div>
                                    </div>
                                    @if($protectionQuote['eligible'] ?? false)
                                        @php
                                            $careFee = (float) ($protectionQuote['fee'] ?? 0);
                                            $careUnitFee = (float) ($protectionQuote['unit_fee'] ?? 0);
                                            $careIsPerUnit = ($protectionQuote['scope'] ?? null) === 'PER_UNIT';
                                        @endphp
                                        <label class="mt-3 flex cursor-pointer items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-950">
                                            <input type="checkbox" name="protection_item_ids[]" value="{{ $item->id }}" data-protection-checkbox data-seller-id="{{ $sellerId }}" data-protection-fee="{{ number_format($careFee, 2, '.', '') }}" class="mt-0.5" @checked($protectionQuote['selected'] ?? false)>
                                            <span>
                                                <strong class="block">Add Product Protection <span class="font-semibold">+&#8369;{{ number_format($careFee, 2) }}</span></strong>
                                                <span class="mt-1 block">Optional protection for this item during delivery.</span>
                                                @if($careIsPerUnit)
                                                    <span class="mt-1 block text-[11px]">&#8369;{{ number_format($careUnitFee, 2) }} per unit &times; {{ $item->quantity }}</span>
                                                @endif
                                            </span>
                                        </label>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                        <div class="space-y-2 border-t border-stone-100 px-5 py-4 text-xs">
                            <div class="flex justify-between gap-3 text-stone-500"><span>{{ data_get($group, 'seller.business_name', 'Shop') }} subtotal</span><strong class="text-stone-700">&#8369;{{ number_format($sellerSubtotal, 2) }}</strong></div>
                            @if($sellerPromotionDiscount > 0)
                                <div class="flex flex-wrap justify-between gap-2 text-emerald-700"><span>Seller promotion</span><strong>−&#8369;{{ number_format($sellerPromotionDiscount, 2) }}</strong></div>
                            @endif
                            @if(data_get($group, 'seller_voucher'))
                                <div class="flex flex-wrap justify-between gap-2 text-emerald-700"><span>Seller Voucher {{ data_get($group, 'seller_voucher.code') }}</span><strong>-&#8369;{{ number_format($sellerSellerVoucherDiscount, 2) }}</strong></div>
                            @endif
                            @if($sellerPlatformVoucherDiscount > 0)
                                <div class="flex flex-wrap justify-between gap-2 text-emerald-700"><span>Platform Voucher {{ $platformVoucherCode }}</span><strong>-&#8369;{{ number_format($sellerPlatformVoucherDiscount, 2) }}</strong></div>
                            @endif
                            @if($sellerShippingVoucherDiscount > 0)
                                <div class="flex flex-wrap justify-between gap-2 text-emerald-700"><span>Shipping Voucher {{ $shippingVoucherCode }}</span><strong>-&#8369;{{ number_format($sellerShippingVoucherDiscount, 2) }}</strong></div>
                            @endif
                            <div data-seller-reward-row class="flex justify-between gap-3 text-red-800" @if($sellerRewardPoints === 0) hidden @endif><span>Reward points (<span data-seller-reward-points>{{ number_format($sellerRewardPoints) }}</span>)</span><strong data-seller-reward-discount>-&#8369;{{ number_format($sellerRewardDiscount, 2) }}</strong></div>
                            <div class="flex justify-between gap-3 text-stone-500"><span>Shipping</span><strong data-seller-shipping-total>&#8369;{{ number_format($sellerShipping, 2) }}</strong></div>
                            <div class="flex justify-between gap-3 text-amber-800"><span>Additional Care</span><strong data-seller-protection-total>&#8369;{{ number_format($sellerProtection, 2) }}</strong></div>
                            <div class="flex justify-between gap-3 border-t border-stone-100 pt-2 font-bold text-stone-900"><span>Seller total</span><strong data-seller-total>&#8369;{{ number_format($sellerTotal, 2) }}</strong></div>
                        </div>
                        @php
                            $sellerNoteInput = old('seller_notes.'.$sellerId, $sellerNotes[$sellerId] ?? '');
                        @endphp
                        <div class="mt-4 border-t border-stone-100 pt-4">
                            <label for="seller-note-{{ $sellerId }}" class="block text-xs font-semibold text-stone-700">Note for {{ data_get($group, 'seller.business_name', 'Seller') }} <span class="font-normal text-stone-500">(optional)</span></label>
                            <textarea id="seller-note-{{ $sellerId }}" name="seller_notes[{{ $sellerId }}]" rows="3" maxlength="200" data-seller-note class="mt-2 block w-full rounded-xl border border-stone-300 px-3 py-2.5 text-sm text-stone-800 outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100" placeholder="Add instructions for this seller...">{{ $sellerNoteInput }}</textarea>
                            <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-[11px] text-stone-500"><p id="seller-note-help-{{ $sellerId }}">Order instructions only. Do not include passwords, card details, or other sensitive information.</p><span data-seller-note-count>{{ mb_strlen((string) $sellerNoteInput) }} / 200</span></div>
                        </div>
                    </section>
                @endforeach

                <section class="rounded-2xl border border-stone-200 bg-white shadow-sm"><div class="border-b border-stone-100 px-5 py-4"><h2 class="text-base font-bold text-stone-900">Payment Method</h2><p class="mt-1 text-xs text-stone-500">Choose how you want to pay for this order.</p></div><div class="grid gap-3 p-5 sm:grid-cols-2"><label class="block cursor-pointer"><input required type="radio" name="payment_method" value="COD" class="peer sr-only" checked><span class="flex h-full items-start gap-3 rounded-xl border border-red-800 bg-red-50 p-4"><span class="text-red-800">₱</span><span><strong class="block text-xs">Cash on Delivery (COD)</strong><small class="text-[10px] text-stone-500">Pay when your order arrives.</small></span></span></label><label class="block cursor-not-allowed opacity-60"><input type="radio" disabled class="peer sr-only"><span class="flex h-full items-start gap-3 rounded-xl border border-stone-200 bg-stone-50 p-4"><span class="text-stone-500">◎</span><span><strong class="block text-xs text-stone-700">E-Wallet</strong><small class="text-[10px] text-stone-500">Coming Soon</small></span></span></label></div>@error('payment_method')<p class="px-5 pb-5 text-xs font-semibold text-red-700">{{ $message }}</p>@enderror</section>
            </div>

             <aside class="h-fit rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-bold text-stone-900">Order Summary</h2>
                <p class="mt-1 text-xs text-stone-500">Shipping and eligible shop discounts are confirmed at checkout.</p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-500">Selected items</dt><dd>{{ collect($cartItems)->count() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-500">Selected subtotal</dt><dd>&#8369;{{ number_format((float) data_get($preview, 'subtotal', 0), 2) }}</dd></div>
                    <div class="space-y-3 rounded-xl border border-red-200 bg-red-50/60 p-3">
                        <label for="checkout-reward-points-toggle" class="flex cursor-pointer items-start gap-2.5">
                            <input id="checkout-reward-points-toggle" type="checkbox" name="use_reward_points" value="1" data-reward-points-toggle class="mt-0.5 rounded border-stone-300 text-red-900 focus:ring-red-200" @checked($selectedRewardPoints > 0) @disabled($maxRedeemablePoints < 100)>
                            <span>
                                <strong class="block text-xs font-semibold text-stone-900">Use LIKHAE reward points</strong>
                                <span class="mt-1 block text-[11px] text-stone-600">Balance: {{ number_format($rewardPointsBalance) }} points &middot; 100 points = &#8369;1 off</span>
                            </span>
                        </label>
                        <label for="checkout-reward-points" class="grid gap-1 text-[11px] font-semibold text-stone-700">
                            Points to redeem
                            <input id="checkout-reward-points" name="reward_points" type="number" min="100" step="100" max="{{ $maxRedeemablePoints }}" value="{{ $selectedRewardPoints > 0 ? $selectedRewardPoints : ($maxRedeemablePoints >= 100 ? 100 : '') }}" data-reward-points-input @disabled($maxRedeemablePoints < 100 || $selectedRewardPoints === 0) class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-normal text-stone-800 disabled:bg-stone-100">
                        </label>
                        <p data-reward-points-availability class="text-[10px] leading-4 text-stone-500">@if($maxRedeemablePoints >= 100) Redeem up to {{ number_format($maxRedeemablePoints) }} points on this order. @else You need at least 100 points and at least &#8369;1 in eligible products to redeem. @endif</p>
                        @error('reward_points')<p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-3">
                        <div>
                            <h3 class="text-xs font-semibold text-stone-800">Seller Voucher</h3>
                            <p class="mt-1 text-[11px] text-stone-500">Apply one eligible Seller voucher to each shop in this checkout.</p>
                        </div>
                        <label for="checkout-voucher-code" class="block text-[11px] font-semibold text-stone-700">Voucher code</label>
                        <div class="flex gap-2">
                            <input id="checkout-voucher-code" name="voucher_code" value="{{ old('voucher_code') }}" maxlength="80" autocomplete="off" autocapitalize="characters" class="min-w-0 flex-1 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs uppercase" placeholder="Enter your voucher">
                            <button type="submit" name="apply_seller_voucher" value="1" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="rounded-lg bg-red-900 px-3 py-2 text-xs font-semibold text-white">Apply Voucher</button>
                        </div>
                        @error('voucher_code')<p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
                        @error('seller_voucher_codes.*')<p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
                        @if($sellerVoucherCodes !== [])
                            <div class="space-y-2 border-t border-stone-200 pt-3">
                                @foreach($sellerVoucherCodes as $sellerId => $code)
                                    <div class="flex items-center justify-between gap-2 text-[10px]">
                                        <span class="font-semibold text-emerald-800">{{ data_get($groups->get($sellerId), 'seller.business_name', 'Shop') }}: {{ $code }}</span>
                                        <button type="submit" name="remove_seller_voucher" value="{{ $sellerId }}" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="font-semibold text-red-800 underline">Remove</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-[10px] text-stone-500">Each Seller voucher applies only to eligible products from that shop.</p>
                        @if($sellerAvailableVouchers->isNotEmpty())
                            <div class="space-y-2 border-t border-stone-200 pt-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-stone-500">Available seller vouchers</p>
                                @foreach($sellerAvailableVouchers as $sellerId => $vouchers)
                                    @php $voucherShop = data_get($groups->get($sellerId), 'seller.business_name', 'Shop'); @endphp
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-[10px] text-stone-500">{{ $voucherShop }}:</span>
                                        @foreach($vouchers as $voucher)
                                            <button type="submit" name="seller_voucher_codes[{{ $sellerId }}]" value="{{ $voucher->code }}" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="rounded-full border border-red-200 bg-white px-2.5 py-1 text-[10px] font-semibold text-red-800 hover:bg-red-50" aria-label="Use {{ $voucher->code }} at {{ $voucherShop }}">{{ $voucher->code }} &middot; {{ $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : 'PHP '.number_format((float) $voucher->discount_value, 2) }} off</button>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="rounded-xl border border-red-200 bg-red-50/60 p-3">
                        <button type="button" data-open-platform-vouchers aria-haspopup="dialog" aria-controls="checkout-platform-voucher-dialog" class="flex w-full items-center justify-between gap-3 text-left">
                            <span class="flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-900 text-xs font-black tracking-wide text-white">LK</span>
                                <span>
                                    <strong class="block text-sm text-stone-900">Platform Voucher</strong>
                                    <small class="mt-0.5 block text-[11px] text-stone-500">{{ $platformVouchers->isEmpty() ? 'No platform vouchers available' : 'Choose from '.$platformVouchers->count().' available '.\Illuminate\Support\Str::plural('voucher', $platformVouchers->count()) }}</small>
                                </span>
                            </span>
                            <span class="rounded-lg bg-red-900 px-3 py-2 text-[11px] font-bold text-white">{{ $platformVouchers->isEmpty() ? 'Unavailable' : 'Browse' }}</span>
                        </button>
                        @if(filled($platformVoucherCode))
                            <div class="mt-3 flex items-center justify-between gap-3 border-t border-red-200 pt-3 text-xs">
                                <span class="font-semibold text-emerald-800">Applied: {{ $platformVoucherCode }}</span>
                                <button type="submit" name="remove_platform_voucher" value="1" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="font-semibold text-red-800 underline">Remove</button>
                            </div>
                        @endif
                    </div>
                    @error('platform_voucher_code')<p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
                    <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-3">
                        <button type="button" data-open-shipping-vouchers aria-haspopup="dialog" aria-controls="checkout-shipping-voucher-dialog" class="flex w-full items-center justify-between gap-3 text-left">
                            <span class="flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-700 text-xs font-black tracking-wide text-white">SHIP</span>
                                <span><strong class="block text-sm text-stone-900">Shipping Voucher</strong><small class="mt-0.5 block text-[11px] text-stone-500">{{ $shippingVouchers->isEmpty() ? 'No shipping vouchers available' : 'Choose from '.$shippingVouchers->count().' available '.\Illuminate\Support\Str::plural('voucher', $shippingVouchers->count()) }}</small></span>
                            </span>
                            <span class="rounded-lg bg-amber-700 px-3 py-2 text-[11px] font-bold text-white">{{ $shippingVouchers->isEmpty() ? 'Unavailable' : 'Browse' }}</span>
                        </button>
                        @if(filled($shippingVoucherCode))
                            <div class="mt-3 flex items-center justify-between gap-3 border-t border-amber-200 pt-3 text-xs">
                                <span class="font-semibold text-emerald-800">Applied: {{ $shippingVoucherCode }}</span>
                                <button type="submit" name="remove_shipping_voucher" value="1" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="font-semibold text-red-800 underline">Remove</button>
                            </div>
                        @endif
                    </div>
                    @error('shipping_voucher_code')<p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
                    <div class="flex justify-between"><dt class="text-stone-500">Shipping</dt><dd id="checkout-shipping-total">&#8369;{{ number_format((float) data_get($preview, 'shipping_total', 0), 2) }}</dd></div>
                    <div class="flex justify-between text-emerald-800"><dt>Seller Voucher discount</dt><dd>-&#8369;{{ number_format($groups->sum(fn ($group) => (float) data_get($group, 'seller_voucher_discount', 0)), 2) }}</dd></div>
                    <div class="flex justify-between text-emerald-800"><dt>Platform Voucher discount</dt><dd>-&#8369;{{ number_format((float) data_get($preview, 'platform_voucher_discount', 0), 2) }}</dd></div>
                    <div class="flex justify-between text-emerald-800"><dt>Shipping Voucher discount</dt><dd>-&#8369;{{ number_format((float) data_get($preview, 'shipping_voucher_discount', 0), 2) }}</dd></div>
                    <div class="flex justify-between border-t border-stone-100 pt-2 font-semibold text-stone-800"><dt>Total discount</dt><dd id="checkout-total-discount">-&#8369;{{ number_format((float) data_get($preview, 'total_discount', 0), 2) }}</dd></div>
                    <div id="checkout-reward-points-summary" class="flex justify-between text-red-800" @if($selectedRewardPoints === 0) hidden @endif><dt>Reward points (<span data-reward-points-summary-count>{{ number_format($selectedRewardPoints) }}</span>)</dt><dd id="checkout-reward-points-discount">-&#8369;{{ number_format((float) data_get($preview, 'reward_points_discount', 0), 2) }}</dd></div>
                    <div class="flex items-center justify-between gap-3 text-amber-800">
                        <dt><label for="checkout-protection-master" class="flex cursor-pointer items-center gap-2"><input id="checkout-protection-master" type="checkbox" data-protection-master aria-label="Apply Additional Care to all eligible items" @disabled($protectionEligibleItemCount === 0)><span><span>Apply Additional Care</span><small class="mt-1 block text-[10px] text-stone-500">{{ $protectionEligibleItemCount > 0 ? 'All eligible items' : 'Not available for these items' }}</small></span></label></dt>
                        <dd id="checkout-protection-total">&#8369;{{ number_format((float) data_get($preview, 'protection_total', 0), 2) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-stone-200 pt-3 text-base font-bold"><dt>Estimated total</dt><dd id="checkout-grand-total">&#8369;{{ number_format((float) data_get($preview, 'grand_total', 0), 2) }}</dd></div>
                </dl>
                @error('cart')<p class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800">{{ $message }}</p>@enderror
                <button class="lk-btn lk-btn-red lk-btn-full mt-5" type="submit" data-place-order-submit @disabled((!$automaticRouting && $logisticsProviders->isEmpty()) || $hasUnavailableStock || $checkoutBlocked || !data_get($selectedDeliveryAvailability, 'available'))>{{ (!$automaticRouting && $logisticsProviders->isEmpty()) || !data_get($selectedDeliveryAvailability, 'available') ? 'Delivery unavailable' : ($checkoutBlocked || $hasUnavailableStock ? 'Review checkout' : 'Place Order') }}</button>
                <p class="mt-3 text-center text-[10px] leading-4 text-stone-400">Review your delivery and payment details before placing the order.</p>
            </aside>

            <dialog id="checkout-platform-voucher-dialog" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border border-stone-200 bg-white p-0 shadow-2xl backdrop:bg-stone-950/60" aria-labelledby="checkout-platform-voucher-title">
                <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-stone-200 bg-white p-5 sm:p-6">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-red-800">LIKHAE Platform</span>
                        <h2 id="checkout-platform-voucher-title" class="mt-1 text-xl font-bold text-stone-900">Choose a platform voucher</h2>
                        <p class="mt-1 text-xs text-stone-500">Select one LIKHAE voucher to apply across eligible items in this order.</p>
                    </div>
                    <button type="button" data-close-platform-vouchers class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-stone-200 text-xl leading-none text-stone-600 hover:bg-stone-50" aria-label="Close voucher picker">&times;</button>
                </div>
                <div class="p-5 sm:p-6">
                    @error('platform_voucher_code')<p class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800" role="alert">{{ $message }}</p>@enderror
                    @if($platformVouchers->isEmpty())
                        <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 p-8 text-center">
                            <strong class="block text-sm text-stone-800">No LIKHAE vouchers are available right now.</strong>
                            <p class="mt-1 text-xs text-stone-500">You can still enter a seller voucher in the voucher field.</p>
                        </div>
                    @else
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach($platformVouchers as $voucher)
                                @php
                                    $voucherIsApplied = mb_strtoupper((string) $voucher->code) === mb_strtoupper((string) $platformVoucherCode);
                                    $voucherDiscountLabel = $voucher->discount_type === 'PERCENT'
                                        ? number_format((float) $voucher->discount_value, 0).'% off'
                                        : 'PHP '.number_format((float) $voucher->discount_value, 2).' off';
                                @endphp
                                <article class="relative overflow-hidden rounded-2xl border {{ $voucherIsApplied ? 'border-emerald-300 bg-emerald-50/50' : 'border-stone-200 bg-white' }} shadow-sm">
                                    <div class="h-1.5 bg-gradient-to-r from-red-950 via-red-700 to-amber-400"></div>
                                    <div class="p-4 sm:p-5">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-[9px] font-bold uppercase tracking-wide text-red-900">LIKHAE Platform</span>
                                                <h3 class="mt-3 text-base font-bold text-stone-900">{{ $voucher->name ?: 'LIKHAE Voucher' }}</h3>
                                            </div>
                                            <span class="rounded-xl bg-red-950 px-3 py-2 text-lg font-black text-white">{{ $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : 'PHP '.number_format((float) $voucher->discount_value, 0) }}</span>
                                        </div>
                                        <p class="mt-3 text-sm font-semibold text-red-900">{{ $voucherDiscountLabel }}</p>
                                        <div class="mt-3 flex flex-wrap gap-2 text-[10px] text-stone-600">
                                            <span class="rounded-full bg-stone-100 px-2.5 py-1">Min. spend &#8369;{{ number_format((float) $voucher->minimum_order_amount, 2) }}</span>
                                            @if($voucher->maximum_discount_amount !== null)<span class="rounded-full bg-stone-100 px-2.5 py-1">Up to &#8369;{{ number_format((float) $voucher->maximum_discount_amount, 2) }} off</span>@endif
                                            @if($voucher->ends_at)<span class="rounded-full bg-stone-100 px-2.5 py-1">Ends {{ $voucher->ends_at->format('M j, Y') }}</span>@endif
                                        </div>
                                        <div class="mt-4 flex items-center justify-between gap-3 border-t border-dashed border-stone-300 pt-3">
                                            <span class="font-mono text-xs font-bold tracking-wide text-stone-800">{{ $voucher->code }}</span>
                                            @if($voucherIsApplied)
                                                <span class="rounded-lg bg-emerald-700 px-3 py-2 text-[11px] font-bold text-white">Applied</span>
                                            @else
                                                <button type="submit" name="platform_voucher_code" value="{{ $voucher->code }}" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="rounded-lg bg-red-900 px-3 py-2 text-[11px] font-bold text-white transition hover:bg-red-950">Use voucher</button>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </dialog>

            <dialog id="checkout-shipping-voucher-dialog" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border border-stone-200 bg-white p-0 shadow-2xl backdrop:bg-stone-950/60" aria-labelledby="checkout-shipping-voucher-title">
                <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-stone-200 bg-white p-5 sm:p-6">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800">LIKHAE Shipping</span>
                        <h2 id="checkout-shipping-voucher-title" class="mt-1 text-xl font-bold text-stone-900">Choose a shipping voucher</h2>
                        <p class="mt-1 text-xs text-stone-500">One shipping voucher can reduce eligible shipping fees for this order.</p>
                    </div>
                    <button type="button" data-close-shipping-vouchers class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-stone-200 text-xl leading-none text-stone-600 hover:bg-stone-50" aria-label="Close shipping voucher picker">&times;</button>
                </div>
                <div class="p-5 sm:p-6">
                    @error('shipping_voucher_code')<p class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800" role="alert">{{ $message }}</p>@enderror
                    @if($shippingVouchers->isEmpty())
                        <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 p-8 text-center">
                            <strong class="block text-sm text-stone-800">No shipping vouchers are available right now.</strong>
                            <p class="mt-1 text-xs text-stone-500">Shipping fees will be shown in your order total.</p>
                        </div>
                    @else
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach($shippingVouchers as $voucher)
                                @php
                                    $voucherIsApplied = mb_strtoupper((string) $voucher->code) === mb_strtoupper((string) $shippingVoucherCode);
                                    $voucherDiscountLabel = $voucher->discount_type === 'PERCENT'
                                        ? number_format((float) $voucher->discount_value, 0).'% off shipping'
                                        : 'PHP '.number_format((float) $voucher->discount_value, 2).' off shipping';
                                @endphp
                                <article class="relative overflow-hidden rounded-2xl border {{ $voucherIsApplied ? 'border-emerald-300 bg-emerald-50/50' : 'border-stone-200 bg-white' }} shadow-sm">
                                    <div class="h-1.5 bg-gradient-to-r from-amber-900 via-amber-700 to-yellow-400"></div>
                                    <div class="p-4 sm:p-5">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-[9px] font-bold uppercase tracking-wide text-amber-900">Shipping benefit</span>
                                                <h3 class="mt-3 text-base font-bold text-stone-900">{{ $voucher->name ?: 'Shipping Voucher' }}</h3>
                                            </div>
                                            <span class="rounded-xl bg-amber-900 px-3 py-2 text-lg font-black text-white">{{ $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : 'PHP '.number_format((float) $voucher->discount_value, 0) }}</span>
                                        </div>
                                        <p class="mt-3 text-sm font-semibold text-amber-900">{{ $voucherDiscountLabel }}</p>
                                        <div class="mt-3 flex flex-wrap gap-2 text-[10px] text-stone-600">
                                            <span class="rounded-full bg-stone-100 px-2.5 py-1">Min. spend &#8369;{{ number_format((float) $voucher->minimum_order_amount, 2) }}</span>
                                            @if($voucher->maximum_discount_amount !== null)<span class="rounded-full bg-stone-100 px-2.5 py-1">Up to &#8369;{{ number_format((float) $voucher->maximum_discount_amount, 2) }} off</span>@endif
                                            @if($voucher->ends_at)<span class="rounded-full bg-stone-100 px-2.5 py-1">Ends {{ $voucher->ends_at->format('M j, Y') }}</span>@endif
                                        </div>
                                        <div class="mt-4 flex items-center justify-between gap-3 border-t border-dashed border-stone-300 pt-3">
                                            <span class="font-mono text-xs font-bold tracking-wide text-stone-800">{{ $voucher->code }}</span>
                                            @if($voucherIsApplied)
                                                <span class="rounded-lg bg-emerald-700 px-3 py-2 text-[11px] font-bold text-white">Applied</span>
                                            @else
                                                <button type="submit" name="shipping_voucher_code" value="{{ $voucher->code }}" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="rounded-lg bg-amber-800 px-3 py-2 text-[11px] font-bold text-white transition hover:bg-amber-950">Use voucher</button>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </dialog>
        </form>
    @endif
</div>

@if($cartItems->isNotEmpty() && $addresses->isNotEmpty())
    <script>
        (() => {
            const platformDialog = document.getElementById('checkout-platform-voucher-dialog');
            const shippingDialog = document.getElementById('checkout-shipping-voucher-dialog');
            const dialogFor = (trigger) => trigger.matches('[data-open-platform-vouchers]')
                ? platformDialog
                : shippingDialog;

            const openDialog = (dialog) => {
                if (!dialog || dialog.open) return;
                if (typeof dialog.showModal === 'function') {
                    try {
                        dialog.showModal();
                        return;
                    } catch (error) {
                        // Keep the picker usable if native modal mode is unavailable.
                    }
                }
                dialog.setAttribute('open', '');
            };

            const closeDialog = (dialog) => {
                if (!dialog) return;
                if (dialog.open && typeof dialog.close === 'function') {
                    try {
                        dialog.close();
                        return;
                    } catch (error) {
                        // Fall back to closing a non-modal dialog.
                    }
                }
                dialog.removeAttribute('open');
            };

            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;
                const trigger = event.target.closest('[data-open-platform-vouchers], [data-open-shipping-vouchers]');
                if (trigger) {
                    event.preventDefault();
                    openDialog(dialogFor(trigger));
                    return;
                }

                const closeButton = event.target.closest('[data-close-platform-vouchers], [data-close-shipping-vouchers]');
                if (closeButton) {
                    closeDialog(closeButton.closest('dialog'));
                    return;
                }

                if (event.target === platformDialog || event.target === shippingDialog) {
                    closeDialog(event.target);
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                [platformDialog, shippingDialog].forEach((dialog) => {
                    if (!dialog?.open) return;
                    try {
                        if (dialog.matches(':modal')) return;
                    } catch (error) {
                        // Older browsers may not recognize the :modal selector.
                    }
                    closeDialog(dialog);
                });
            });

            @if($errors->has('platform_voucher_code'))
                openDialog(platformDialog);
            @endif
            @if($errors->has('shipping_voucher_code'))
                openDialog(shippingDialog);
            @endif
        })();
    </script>
@endif

@if($cartItems->isNotEmpty() && $addresses->isNotEmpty())
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const shipping = document.getElementById('checkout-shipping-total');
            const protection = document.getElementById('checkout-protection-total');
            const protectionMaster = document.querySelector('[data-protection-master]');
            const total = document.getElementById('checkout-grand-total');
            const rewardToggle = document.querySelector('[data-reward-points-toggle]');
            const rewardInput = document.querySelector('[data-reward-points-input]');
            const rewardSummary = document.getElementById('checkout-reward-points-summary');
            const rewardDiscount = document.getElementById('checkout-reward-points-discount');
            const rewardCount = document.querySelector('[data-reward-points-summary-count]');
            const subtotal = Number(@json((float) data_get($preview, 'subtotal', 0)));
            const discount = Number(@json((float) data_get($preview, 'discount_total', 0)));
            const promotionDiscount = Number(@json((float) data_get($preview, 'promotion_discount_total', 0)));
            const shippingVoucherDiscount = Number(@json((float) data_get($preview, 'shipping_voucher_discount', 0)));
            const fixedVoucherDiscount = discount + shippingVoucherDiscount;
            const maximumRewardPoints = Number(@json($maxRedeemablePoints));
            const money = (value) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

            const selectedRewardPoints = () => {
                if (!rewardToggle?.checked || !rewardInput) return 0;
                const requested = Math.floor(Number(rewardInput.value || 0) / 100) * 100;
                return Math.max(0, Math.min(requested, maximumRewardPoints));
            };

            const allocateRewardPoints = (points) => {
                const cards = [...document.querySelectorAll('[data-seller-card]')];
                const rows = cards.map((card) => ({
                    card,
                    eligibleCents: Math.max(0, Math.round((Number(card.dataset.sellerSubtotal || 0) - Number(card.dataset.sellerPromotionDiscount || 0) - Number(card.dataset.sellerDiscount || 0)) * 100)),
                }));
                const totalWeight = rows.reduce((sum, row) => sum + row.eligibleCents, 0);
                let allocated = 0;
                rows.forEach((row) => {
                    const numerator = points * row.eligibleCents;
                    row.points = totalWeight > 0 ? Math.floor(numerator / totalWeight) : 0;
                    row.remainder = totalWeight > 0 ? numerator % totalWeight : 0;
                    allocated += row.points;
                });
                const remainders = [...rows].sort((left, right) =>
                    (right.remainder - left.remainder)
                    || (Number(left.card.dataset.sellerId) - Number(right.card.dataset.sellerId)));
                let remaining = points - allocated;
                remainders.forEach((row) => {
                    if (remaining <= 0) return;
                    const extra = Math.min(row.eligibleCents - row.points, remaining);
                    row.points += extra;
                    remaining -= extra;
                });
                return rows;
            };

            const updateTotals = (input) => {
                let shippingTotal = 0;
                let protectionTotal = 0;
                const rewardPoints = selectedRewardPoints();
                const rewardAllocations = allocateRewardPoints(rewardPoints);
                const protectionCheckboxes = [...document.querySelectorAll('[data-protection-checkbox]')];
                const selectedProtectionCount = protectionCheckboxes.filter((checkbox) => checkbox.checked).length;

                if (protectionMaster) {
                    protectionMaster.checked = protectionCheckboxes.length > 0 && selectedProtectionCount === protectionCheckboxes.length;
                    protectionMaster.indeterminate = selectedProtectionCount > 0 && selectedProtectionCount < protectionCheckboxes.length;
                }

                rewardAllocations.forEach(({ card, points: sellerRewardPoints }) => {
                    const sellerId = card.dataset.sellerId;
                    const sellerSubtotal = Number(card.dataset.sellerSubtotal || 0);
                    const sellerPromotionDiscount = Number(card.dataset.sellerPromotionDiscount || 0);
                    const sellerDiscount = Number(card.dataset.sellerDiscount || 0);
                    const sellerRewardDiscount = sellerRewardPoints / 100;
                    const sellerShipping = input
                        ? Number(input.dataset.shippingFee || 0)
                        : Number(card.dataset.sellerShippingFee || 0);
                    const sellerProtection = [...document.querySelectorAll('[data-protection-checkbox]:checked')]
                        .filter((checkbox) => checkbox.dataset.sellerId === sellerId)
                        .reduce((sum, checkbox) => sum + Number(checkbox.dataset.protectionFee || 0), 0);
                    const sellerShippingDiscount = Number(card.dataset.sellerShippingDiscount || 0);
                    const sellerTotal = Math.max(0, sellerSubtotal - sellerPromotionDiscount - sellerDiscount - sellerRewardDiscount + sellerShipping - sellerShippingDiscount + sellerProtection);

                    shippingTotal += sellerShipping;
                    protectionTotal += sellerProtection;
                    card.querySelector('[data-seller-shipping-total]')?.replaceChildren(money(sellerShipping));
                    card.querySelector('[data-seller-protection-total]')?.replaceChildren(money(sellerProtection));
                    const rewardRow = card.querySelector('[data-seller-reward-row]');
                    if (rewardRow) {
                        rewardRow.hidden = sellerRewardPoints === 0;
                        rewardRow.querySelector('[data-seller-reward-points]')?.replaceChildren(sellerRewardPoints.toLocaleString('en-PH'));
                        rewardRow.querySelector('[data-seller-reward-discount]')?.replaceChildren(`-${money(sellerRewardDiscount)}`);
                    }
                    card.querySelector('[data-seller-total]')?.replaceChildren(money(sellerTotal));
                });

                if (rewardSummary) rewardSummary.hidden = rewardPoints === 0;
                if (rewardCount) rewardCount.textContent = rewardPoints.toLocaleString('en-PH');
                if (rewardDiscount) rewardDiscount.textContent = `-${money(rewardPoints / 100)}`;
                if (shipping) shipping.textContent = money(shippingTotal);
                if (protection) protection.textContent = money(protectionTotal);
                const totalDiscount = document.getElementById('checkout-total-discount');
                if (totalDiscount) totalDiscount.textContent = `-${money(fixedVoucherDiscount + promotionDiscount + (rewardPoints / 100))}`;
                if (total) total.textContent = money(Math.max(0, subtotal - promotionDiscount - discount - (rewardPoints / 100) + shippingTotal - shippingVoucherDiscount + protectionTotal));
            };

            rewardToggle?.addEventListener('change', () => {
                if (rewardInput) {
                    rewardInput.disabled = !rewardToggle.checked;
                    if (rewardToggle.checked && Number(rewardInput.value || 0) < 100) rewardInput.value = '100';
                }
                updateTotals(document.querySelector('input[name="logistics_center_id"]:checked'));
            });
            rewardInput?.addEventListener('input', () => updateTotals(document.querySelector('input[name="logistics_center_id"]:checked')));

            document.querySelectorAll('[data-protection-checkbox]').forEach((input) => {
                input.addEventListener('change', () => updateTotals(document.querySelector('input[name="logistics_center_id"]:checked')));
            });
            protectionMaster?.addEventListener('change', () => {
                document.querySelectorAll('[data-protection-checkbox]').forEach((input) => {
                    input.checked = protectionMaster.checked;
                });
                updateTotals(document.querySelector('input[name="logistics_center_id"]:checked'));
            });
            updateTotals(document.querySelector('input[name="logistics_center_id"]:checked'));

            document.querySelectorAll('input[name="logistics_center_id"]').forEach((input) => {
                input.addEventListener('change', () => {
                    updateTotals(input);
                    updateDeliveryAvailability();
                });
                if (input.checked) updateTotals(input);
            });
            document.querySelectorAll('input[name="address_id"]').forEach((input) => input.addEventListener('change', updateDeliveryAvailability));
            document.querySelectorAll('[data-seller-note]').forEach((textarea) => {
                const counter = textarea.parentElement?.querySelector('[data-seller-note-count]');
                const updateCount = () => {
                    if (counter) counter.textContent = `${Array.from(textarea.value).length} / 200`;
                };
                textarea.addEventListener('input', updateCount);
                updateCount();
            });

            const checkoutForm = document.querySelector('[data-checkout-form]');
            const placeOrderButton = checkoutForm?.querySelector('[data-place-order-submit]');
            const deliveryStatus = document.querySelector('[data-delivery-availability]');
            const deliveryAvailability = @json($deliveryAvailability);
            const automaticRouting = @json($automaticRouting);
            const checkoutBlocked = @json((!$automaticRouting && $logisticsProviders->isEmpty()) || $hasUnavailableStock || $checkoutBlocked);
            const availabilityMessage = 'Delivery is not available for this address yet. Choose another saved address or update its delivery details.';
            const updateDeliveryAvailability = () => {
                const addressId = document.querySelector('input[name="address_id"]:checked')?.value || '';
                const providerId = document.querySelector('input[name="logistics_center_id"]:checked')?.value || '';
                const key = automaticRouting ? addressId : `${addressId}:${providerId}`;
                const availability = deliveryAvailability[key] || { available: false, requires_review: false, message: availabilityMessage };
                if (deliveryStatus) {
                    deliveryStatus.textContent = availability.message || (availability.available ? 'Delivery is available to this address.' : availabilityMessage);
                    deliveryStatus.classList.toggle('border-emerald-200', availability.available && !availability.requires_review);
                    deliveryStatus.classList.toggle('bg-emerald-50', availability.available && !availability.requires_review);
                    deliveryStatus.classList.toggle('text-emerald-800', availability.available && !availability.requires_review);
                    deliveryStatus.classList.toggle('border-amber-200', availability.available && availability.requires_review);
                    deliveryStatus.classList.toggle('bg-amber-50', availability.available && availability.requires_review);
                    deliveryStatus.classList.toggle('text-amber-900', availability.available && availability.requires_review);
                    deliveryStatus.classList.toggle('border-red-200', !availability.available);
                    deliveryStatus.classList.toggle('bg-red-50', !availability.available);
                    deliveryStatus.classList.toggle('text-red-800', !availability.available);
                }
                if (placeOrderButton && !placeOrderButton.textContent.includes('Placing order')) {
                    placeOrderButton.disabled = checkoutBlocked || !availability.available;
                    placeOrderButton.textContent = checkoutBlocked ? 'Review checkout' : (availability.available ? 'Place Order' : 'Delivery unavailable');
                }
            };
            updateDeliveryAvailability();
            checkoutForm?.addEventListener('submit', (event) => {
                if (!event.submitter?.matches('[data-place-order-submit]')) return;
                if (!placeOrderButton) return;
                placeOrderButton.disabled = true;
                placeOrderButton.textContent = 'Placing order...';
            });

            const validationSummary = document.getElementById('checkout-validation-errors');
            if (validationSummary) {
                validationSummary.focus({ preventScroll: true });
                validationSummary.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    </script>
@endif
@endsection
