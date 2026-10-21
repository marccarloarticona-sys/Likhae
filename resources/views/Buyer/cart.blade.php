@extends('layouts.buyer')

@section('title', 'Shopping Cart')
@section('active', 'cart')

@section('content')
@php
    $cartItems = collect($cartItems ?? []);

    $normalizedCartItems = $cartItems->map(function ($item) {
        $availability = data_get($item, 'cart_availability', [
            'available' => false,
            'restricted' => true,
            'message' => 'Unavailable',
        ]);
        $restricted = (bool) data_get($availability, 'restricted', false);
        $variant = data_get($item, 'productVariant');
        $relatedProduct = data_get($variant, 'product');
        $product = $restricted ? null : $relatedProduct;
        $productImages = collect(data_get($product, 'images', []));
        $imagePath = data_get($restricted ? null : $variant, 'productImage.file_path')
            ?? data_get($productImages->firstWhere('is_primary', true), 'file_path')
            ?? data_get($productImages->first(), 'file_path');
        $imageUrl = filled($imagePath)
            ? (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')
                ? $imagePath
                : \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath))
            : asset('images/product-placeholder.svg');

        $categoryValue = $restricted ? '' : (data_get($product, 'category.name')
            ?? data_get($item, 'category.name')
            ?? data_get($item, 'category')
            ?? 'Local Product');

        $sellerValue = $restricted
            ? 'Unavailable shop'
            : (data_get($product, 'sellerProfile.business_name') ?? 'LIKHAE Seller');
        $sellerId = data_get($relatedProduct, 'seller_profile_id');
        $sellerShopUrl = \Illuminate\Support\Facades\Route::has('buyer.shop')
            && is_numeric($sellerId)
            && filled(data_get($product, 'sellerProfile.business_name'))
                ? route('buyer.shop', ['seller' => $sellerId])
                : null;

        return [
            'id' => data_get($item, 'id')
                ?? data_get($product, 'id'),

            'slug' => $restricted ? null : (data_get($product, 'slug') ?? data_get($item, 'slug')),

            'name' => $restricted ? 'Unavailable product' : (data_get($product, 'name')
                ?? data_get($item, 'name')
                ?? 'Product Name'),

            'category' => is_scalar($categoryValue)
                ? (string) $categoryValue
                : 'Local Product',

            'variant' => $restricted ? 'Unavailable' : (data_get($variant, 'description') ?? 'Standard'),

            'seller' => is_scalar($sellerValue)
                ? (string) $sellerValue
                : 'LIKHAE Seller',

            'seller_id' => is_numeric($sellerId)
                ? (int) $sellerId
                : 'unknown-'.(string) (data_get($item, 'id') ?? data_get($product, 'id')),

            'seller_shop_url' => $sellerShopUrl,
            'available' => (bool) data_get($availability, 'available', false),
            'restricted' => $restricted,
            'purchase_limit_exceeded' => (bool) data_get($item, 'purchase_limit_exceeded', false),
            'availability_message' => (string) data_get($availability, 'message', 'Unavailable'),

            'price' => max(
                0,
                $restricted ? 0 : (float) (
                    data_get($variant, 'final_price')
                    ?? 0
                )
            ),

            'old_price' => max(
                0,
                $restricted ? 0 : (float) (
                    data_get($variant, 'price')
                    ?? data_get($item, 'old_price')
                    ?? data_get($product, 'old_price')
                    ?? 0
                )
            ),

            'quantity' => max(
                1,
                (int) data_get($item, 'quantity', 1)
            ),

            'stock' => max(
                0,
                $restricted ? 0 : (int) (
                    data_get($variant, 'stock')
                    ?? 0
                )
            ),

            'image' => $imageUrl,
        ];
    });

    $cartGroups = $normalizedCartItems->groupBy('seller_id');

    $hasItems = $normalizedCartItems->isNotEmpty();
    $defaultSelectedItems = $normalizedCartItems->filter(fn ($item) => $item['available']);
    $hasUnavailableSelection = $defaultSelectedItems->contains(
        fn ($item) => $item['stock'] < $item['quantity'] || $item['purchase_limit_exceeded']
    );
    $availableVouchers = collect($availableVouchers ?? []);
    $sellerAvailableVouchers = $availableVouchers->except('platform');
    $platformVouchers = collect($availableVouchers->get('platform', []));

    $subtotal = $defaultSelectedItems->sum(
        fn ($item) => $item['price'] * $item['quantity']
    );
    $shipping = 0;
    $discount = 0;
    $total = $subtotal + $shipping - $discount;
    $hasAvailableItems = $defaultSelectedItems->isNotEmpty();

    $buyer = auth()->user();

    $buyerName = data_get($buyer, 'name', 'Buyer');
    $buyerPhone = data_get($buyer, 'contact_number', '');

    $addressRecipient = data_get(
        $defaultAddress ?? null,
        'recipient_name',
        $buyerName
    );

    $addressPhone = data_get(
        $defaultAddress ?? null,
        'contact_number',
        $buyerPhone
    );

    $addressLine = $defaultAddress?->formatted()
        ?: collect([
            data_get($buyer, 'house_number'),
            data_get($buyer, 'street'),
            data_get($buyer, 'barangay'),
            data_get($buyer, 'municipality'),
            data_get($buyer, 'province'),
            data_get($buyer, 'postal_code'),
        ])->filter()->implode(', ');

    if ($addressLine === '') {
        $addressLine = 'No delivery address saved yet.';
    }

    $hasProductDetailsRoute =
        \Illuminate\Support\Facades\Route::has('buyer.product-details');
@endphp

<div
    class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8"
    data-cart-page
    data-shipping="{{ $shipping }}"
    data-shipping-estimate-available="0"
    data-preview-url="{{ route('buyer.cart.preview') }}"
    data-applied-discount="{{ $discount }}"
>
    {{-- Page heading --}}
    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-red-800">
                Your Selection
            </span>

            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                Shopping Cart
            </h1>

            <p class="mt-1 text-sm text-stone-500">
                Review your items and complete your order on this page.
            </p>
        </div>

        <a
            href="{{ route('buyer.products') }}"
            class="inline-flex w-fit items-center justify-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-xs font-semibold text-stone-700 transition hover:border-stone-400 hover:bg-stone-50 focus:outline-none focus:ring-4 focus:ring-stone-200"
        >
            <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="m15 18-6-6 6-6"/>
            </svg>

            Continue Shopping
        </a>
    </header>

    {{-- Empty cart --}}
    <section
        class="{{ $hasItems ? 'hidden' : '' }} rounded-2xl border border-stone-200 bg-white px-6 py-16 text-center shadow-sm"
        data-cart-empty
    >
        <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-50 text-red-800">
            <svg
                width="36"
                height="36"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M6 6h15l-2 8H8z"/>
                <path d="M6 6 5 3H2"/>
                <circle cx="9" cy="20" r="1"/>
                <circle cx="18" cy="20" r="1"/>
            </svg>
        </div>

        <h2 class="mt-5 text-lg font-semibold text-stone-900">
            Your cart is empty
        </h2>

        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-stone-500">
            Browse the marketplace and add products you would like to purchase.
        </p>

        <a
            href="{{ route('buyer.products') }}"
            class="mt-6 inline-flex items-center justify-center rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950 focus:outline-none focus:ring-4 focus:ring-red-100"
        >
            Browse Products
        </a>
    </section>

    {{-- Cart and checkout --}}
    <div
        class="{{ $hasItems ? '' : 'hidden' }} grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]"
        data-cart-checkout
    >
        <div class="min-w-0 space-y-5">
            {{-- Cart items --}}
            <section class="space-y-4" aria-label="Shopping cart items">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-stone-200 bg-white px-4 py-4 shadow-sm sm:px-5">
                    <label class="flex cursor-pointer items-center gap-3">
                        <input
                            type="checkbox"
                            class="h-4 w-4 rounded border-stone-300 text-red-900 focus:ring-red-800"
                            data-select-all
                            aria-label="Select all cart items"
                            checked
                        >

                        <span class="text-sm font-semibold text-stone-900">
                            Cart Items
                        </span>

                        <span class="text-xs text-stone-400" data-cart-count>
                            {{ $normalizedCartItems->count() }}
                            {{ $normalizedCartItems->count() === 1 ? 'item' : 'items' }}
                        </span>
                    </label>

                    <button
                        type="button"
                        class="text-xs font-semibold text-red-600 transition hover:text-red-700"
                        data-remove-selected
                    >
                        Remove selected
                    </button>
                </div>

                <div class="space-y-4" data-cart-items>
                    @foreach ($cartGroups as $sellerId => $sellerItems)
                        @php
                            $sellerName = $sellerItems->first()['seller'] ?? 'LIKHAE Seller';
                            $storeSubtotal = $sellerItems->filter(fn ($item) => $item['available'])->sum(fn ($item) => $item['price'] * $item['quantity']);
                            $sellerShopUrl = $sellerItems->first()['seller_shop_url'] ?? null;
                        @endphp
                        <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm" data-seller-group data-seller-id="{{ $sellerId }}">
                            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 bg-stone-50 px-4 py-4 sm:px-5">
                                <label class="flex min-w-0 cursor-pointer items-center gap-3">
                                    <input type="checkbox" class="h-4 w-4 shrink-0 rounded border-stone-300 text-red-900 focus:ring-red-800" data-seller-select @checked($sellerItems->contains(fn ($item) => $item['available'])) aria-label="Select all items from {{ $sellerName }}">
                                    <span class="min-w-0 text-sm font-bold uppercase tracking-wide text-stone-900">{{ $sellerName }}</span>
                                </label>
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-xs text-stone-500" data-seller-count>{{ $sellerItems->count() }} {{ $sellerItems->count() === 1 ? 'item' : 'items' }}</span>
                                    @if ($sellerShopUrl)
                                        <a href="{{ $sellerShopUrl }}" class="text-xs font-semibold text-red-800 hover:text-red-950">View Shop</a>
                                    @endif
                                </div>
                            </header>
                            <div class="divide-y divide-stone-100" data-seller-items>
                    @foreach ($sellerItems as $item)
                        @php
                            $lineTotal = $item['price'] * $item['quantity'];

                            $detailsUrl = !$item['restricted'] && $hasProductDetailsRoute &&
                                filled($item['slug'] ?? $item['id'])
                                    ? route('buyer.product-details', [
                                        'slug' => $item['slug'] ?? $item['id'],
                                    ])
                                    : '#';
                        @endphp

                        <article
                            class="p-4 sm:p-5"
                            data-cart-item
                            data-item-id="{{ $item['id'] ?? '' }}"
                            data-seller-id="{{ $item['seller_id'] }}"
                            data-item-price="{{ $item['price'] }}"
                            data-item-stock="{{ $item['stock'] }}"
                            data-item-available="{{ $item['available'] ? '1' : '0' }}"
                            data-item-restricted="{{ $item['restricted'] ? '1' : '0' }}"
                            data-availability-message="{{ $item['availability_message'] }}"
                            data-update-url="{{ route('buyer.cart.update', ['item' => $item['id']]) }}"
                            data-remove-url="{{ route('buyer.cart.remove', ['item' => $item['id']]) }}"
                        >
                            <div class="flex items-start gap-3 sm:gap-4">
                                <input
                                    type="checkbox"
                                    class="mt-9 h-4 w-4 shrink-0 rounded border-stone-300 text-red-900 focus:ring-red-800"
                                    data-cart-select
                                    aria-label="Select {{ $item['name'] }}"
                                    @checked($item['available'])
                                    @disabled(!$item['available'])
                                >

                                <a
                                    href="{{ $detailsUrl }}"
                                    class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-stone-200 bg-stone-50 sm:h-28 sm:w-28"
                                    aria-label="View {{ $item['name'] }}"
                                    @if (!$hasProductDetailsRoute || $item['restricted']) onclick="return false;" @endif
                                >
                                    <img
                                        src="{{ $item['image'] }}"
                                        alt="{{ $item['name'] }}"
                                        width="112"
                                        height="112"
                                        class="h-full w-full object-cover"
                                        loading="lazy"
                                        decoding="async"
                                        onerror="this.onerror=null;this.src='{{ asset('images/product-placeholder.svg') }}';"
                                    >
                                </a>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <span class="text-[10px] font-semibold uppercase tracking-wide text-red-800">
                                                {{ $item['category'] }}
                                            </span>

                                            <a
                                                href="{{ $detailsUrl }}"
                                                class="mt-1 block"
                                                @if (!$hasProductDetailsRoute || $item['restricted']) onclick="return false;" @endif
                                            >
                                                <h2 class="line-clamp-2 text-sm font-semibold leading-5 text-stone-900 transition hover:text-red-800 sm:text-base">
                                                    {{ $item['name'] }}
                                                </h2>
                                            </a>

                                            <p class="mt-1 text-[11px] text-stone-400">
                                                Variant: {{ $item['variant'] }}
                                            </p>
                                        </div>

                                        <button
                                            type="button"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-stone-400 transition hover:bg-red-50 hover:text-red-600"
                                            data-remove-item
                                            aria-label="Remove {{ $item['name'] }}"
                                        >
                                            <svg
                                                width="17"
                                                height="17"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.8"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                aria-hidden="true"
                                            >
                                                <path d="M4 7h16"/>
                                                <path d="M9 7V4h6v3"/>
                                                <path d="m6 7 1 14h10l1-14"/>
                                                <path d="M10 11v6M14 11v6"/>
                                            </svg>
                                        </button>
                                    </div>

                                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                        <div>
                                            <div class="flex items-center gap-2">
                                                @if ($item['restricted'])
                                                    <strong class="text-sm font-semibold text-red-700">Unavailable</strong>
                                                @else
                                                <strong class="text-base font-semibold text-red-800" data-current-price>
                                                    ₱{{ number_format($item['price'], 2) }}
                                                </strong>

                                                @if ($item['old_price'] > 0)
                                                    <del class="text-xs text-stone-400 {{ $item['old_price'] > $item['price'] ? '' : 'hidden' }}" data-original-price>
                                                        ₱{{ number_format($item['old_price'], 2) }}
                                                    </del>
                                                @endif
                                                @endif
                                            </div>

                                            <p class="mt-1 text-[10px] {{ !$item['available'] || $item['stock'] < $item['quantity'] || $item['purchase_limit_exceeded'] ? 'font-semibold text-red-700' : 'text-stone-400' }}" data-stock-availability>
                                                @if (!$item['available'])
                                                    {{ $item['availability_message'] }}
                                                @else
                                                    {{ $item['stock'] < $item['quantity'] ? 'Only '.$item['stock'].' available.' : $item['stock'].' pieces available' }}
                                                    @if ($item['purchase_limit_exceeded'])
                                                        Cart quantity exceeds this product's purchase limit.
                                                    @endif
                                                @endif
                                            </p>
                                        </div>

                                        <div class="flex items-center justify-between gap-4 sm:justify-end">
                                            <div class="inline-flex h-9 items-center overflow-hidden rounded-lg border border-stone-300 bg-white">
                                                <button
                                                    type="button"
                                                    class="flex h-full w-9 items-center justify-center text-stone-600 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                                                    data-quantity-minus
                                                    aria-label="Decrease quantity"
                                                    @disabled(!$item['available'])
                                                >
                                                    <svg
                                                        width="14"
                                                        height="14"
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="2"
                                                        stroke-linecap="round"
                                                        aria-hidden="true"
                                                    >
                                                        <path d="M5 12h14"/>
                                                    </svg>
                                                </button>

                                                <input
                                                    type="number"
                                                    value="{{ $item['quantity'] }}"
                                                    min="1"
                                                    max="{{ $item['stock'] }}"
                                                    class="h-full w-11 border-x border-y-0 border-stone-300 p-0 text-center text-xs font-semibold text-stone-800 outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                                    data-cart-quantity
                                                    aria-label="Quantity for {{ $item['name'] }}"
                                                    @disabled(!$item['available'])
                                                >

                                                <button
                                                    type="button"
                                                    class="flex h-full w-9 items-center justify-center text-stone-600 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                                                    data-quantity-plus
                                                    aria-label="Increase quantity"
                                                    @disabled(!$item['available'])
                                                >
                                                    <svg
                                                        width="14"
                                                        height="14"
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="2"
                                                        stroke-linecap="round"
                                                        aria-hidden="true"
                                                    >
                                                        <path d="M12 5v14M5 12h14"/>
                                                    </svg>
                                                </button>
                                            </div>

                                            <strong
                                                class="min-w-24 text-right text-sm font-semibold text-stone-900"
                                                data-line-total
                                            >
                                                {{ $item['restricted'] ? 'Unavailable' : '₱'.number_format($lineTotal, 2) }}
                                            </strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                            </div>
                            <footer class="flex items-center justify-between gap-4 border-t border-stone-100 bg-white px-4 py-4 sm:px-5">
                                <span class="text-xs font-semibold text-stone-600">Store subtotal</span>
                                <strong class="text-sm font-bold text-stone-900" data-seller-subtotal>{{ '₱'.number_format($storeSubtotal, 2) }}</strong>
                            </footer>
                        </section>
                    @endforeach
                </div>
            </section>

        </div>

        {{-- Order summary --}}
        <aside class="xl:sticky xl:top-24">
            <section class="rounded-2xl border border-stone-200 bg-white shadow-sm">
                <div class="border-b border-stone-100 px-5 py-4">
                    <h2 class="text-base font-semibold text-stone-900">
                        Order Summary
                    </h2>

                    <p class="mt-1 text-xs text-stone-500">
                        Shipping and eligible shop discounts are confirmed at checkout.
                    </p>
                </div>

                <div class="p-5">
                    <div class="space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-4">
                        <div>
                            <h3 class="text-xs font-semibold text-stone-800">Voucher</h3>
                            <p class="mt-1 text-[11px] text-stone-500">Enter a seller voucher code. Choose a LIKHAE voucher from the picker.</p>
                        </div>
                        <label for="cart-voucher-code" class="block text-[11px] font-semibold text-stone-700">Voucher code</label>
                        <div class="flex gap-2">
                            <input id="cart-voucher-code" data-voucher-code value="{{ old('voucher_code') }}" maxlength="80" autocomplete="off" class="min-w-0 flex-1 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs uppercase" placeholder="Enter your voucher">
                            <button type="button" data-apply-voucher class="rounded-lg bg-red-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-red-950">Apply Voucher</button>
                            <button type="button" data-clear-voucher class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-600">Clear</button>
                        </div>
                        <p class="hidden text-xs font-semibold text-stone-600" data-voucher-feedback role="status" aria-live="polite"></p>
                        @error('voucher_code')<p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
                        @if($sellerAvailableVouchers->isNotEmpty())
                            <div class="space-y-2 border-t border-stone-200 pt-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-stone-500">Available seller vouchers</p>
                                @foreach($sellerAvailableVouchers as $sellerId => $vouchers)
                                    @php
                                        $sellerName = (string) $sellerId === 'platform'
                                            ? 'LIKHAE Platform'
                                            : (data_get($cartItems->first(fn ($cartItem) => (int) data_get($cartItem, 'productVariant.product.seller_profile_id') === (int) $sellerId), 'productVariant.product.sellerProfile.business_name') ?? 'Shop');
                                    @endphp
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-[10px] text-stone-500">{{ $sellerName }}:</span>
                                        @foreach($vouchers as $voucher)
                                            <button type="button" data-use-voucher data-voucher-code="{{ $voucher->code }}" class="rounded-full border border-red-200 bg-white px-2.5 py-1 text-[10px] font-semibold text-red-800 hover:bg-red-50" aria-label="Use {{ $voucher->code }} at {{ $sellerName }}">
                                                {{ $voucher->code }} · {{ $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : 'PHP '.number_format((float) $voucher->discount_value, 2) }} off &middot; Min PHP {{ number_format((float) $voucher->minimum_order_amount, 2) }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-[10px] text-stone-400">No seller vouchers are available for these items. You can still enter a code from a shop.</p>
                        @endif
                        <button type="button" data-open-platform-vouchers aria-haspopup="dialog" aria-controls="cart-platform-voucher-dialog" @disabled($platformVouchers->isEmpty()) class="flex w-full items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50/60 p-3 text-left transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60">
                            <span class="flex items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-900 text-[10px] font-black tracking-wide text-white">LK</span>
                                <span><strong class="block text-xs text-stone-900">LIKHAE Voucher</strong><small class="mt-0.5 block text-[10px] text-stone-500">{{ $platformVouchers->isEmpty() ? 'No platform vouchers available' : 'Choose from '.$platformVouchers->count().' available '.\Illuminate\Support\Str::plural('voucher', $platformVouchers->count()) }}</small></span>
                            </span>
                            <span class="rounded-lg bg-red-900 px-3 py-2 text-[10px] font-bold text-white">{{ $platformVouchers->isEmpty() ? 'Unavailable' : 'Browse' }}</span>
                        </button>
                        <div data-platform-voucher-applied class="hidden items-center justify-between gap-3 border-t border-stone-200 pt-3 text-[11px]">
                            <span class="font-semibold text-emerald-800">LIKHAE voucher applied: <span data-platform-voucher-applied-code></span></span>
                            <button type="button" data-remove-platform-voucher class="font-semibold text-red-800 underline">Remove</button>
                        </div>
                    </div>

                    <div class="mt-5 space-y-3 border-t border-stone-100 pt-5 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Selected items</span>
                            <span class="font-medium text-stone-700" data-selected-count>
                                {{ $defaultSelectedItems->count() }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Selected subtotal</span>
                            <span class="font-medium text-stone-700" data-summary-subtotal>
                                ₱{{ number_format($subtotal, 2) }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Shipping</span>
                            <span class="font-medium text-stone-700" data-summary-shipping>Shipping calculated at checkout.</span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Voucher discount</span>
                            <span class="font-medium text-stone-700" data-summary-discount>−₱{{ number_format($discount, 2) }}</span>
                        </div>

                        <div class="flex items-center justify-between gap-4 hidden" data-summary-saved-row>
                            <span class="text-stone-500">You save</span>
                            <span class="font-medium text-stone-700" data-summary-saved>₱{{ number_format($discount, 2) }}</span>
                        </div>

                        <div class="flex items-center justify-between gap-4 border-t border-stone-200 pt-3 font-semibold">
                            <span class="text-stone-900">Estimated total</span>
                            <span class="text-stone-900" data-summary-total>₱{{ number_format($total, 2) }}</span>
                        </div>

                    </div>

                    <button
                        type="button"
                        class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-red-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-red-950 disabled:cursor-not-allowed disabled:bg-stone-300"
                        data-place-order
                        @disabled(!$hasAvailableItems || $hasUnavailableSelection)
                    >
                        Checkout

                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="m9 18 6-6-6-6"/>
                        </svg>
                    </button>

                    <p
                        class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-center text-[11px] font-semibold leading-4 text-amber-800 {{ $hasUnavailableSelection ? '' : 'hidden' }}"
                        data-stock-warning
                        role="alert"
                    >
                        Remove unavailable items or reduce quantities to fit stock and purchase limits before checkout.
                    </p>

                    <p class="mt-3 text-center text-[10px] leading-4 text-stone-400">
                        Review your delivery and payment details before placing the order.
                    </p>
                </div>
            </section>
        </aside>
    </div>
    <dialog id="cart-platform-voucher-dialog" class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto rounded-2xl border border-stone-200 bg-white p-0 shadow-2xl backdrop:bg-stone-950/60" aria-labelledby="cart-platform-voucher-title">
        <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-stone-200 bg-white p-5 sm:p-6">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-red-800">LIKHAE Platform</span>
                <h2 id="cart-platform-voucher-title" class="mt-1 text-xl font-bold text-stone-900">Choose a platform voucher</h2>
                <p class="mt-1 text-xs text-stone-500">Select one LIKHAE voucher to apply across eligible items in your cart.</p>
            </div>
            <button type="button" data-close-platform-vouchers class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-stone-200 text-xl leading-none text-stone-600 hover:bg-stone-50" aria-label="Close voucher picker">&times;</button>
        </div>
        <div class="p-5 sm:p-6">
            @if($platformVouchers->isEmpty())
                <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 p-8 text-center">
                    <strong class="block text-sm text-stone-800">No LIKHAE vouchers are available for this cart.</strong>
                    <p class="mt-1 text-xs text-stone-500">You can still enter an eligible seller voucher above.</p>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach($platformVouchers as $voucher)
                        @php
                            $voucherDiscountLabel = $voucher->discount_type === 'PERCENT'
                                ? number_format((float) $voucher->discount_value, 0).'% off'
                                : 'PHP '.number_format((float) $voucher->discount_value, 2).' off';
                        @endphp
                        <article class="relative overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
                            <div class="h-1.5 bg-gradient-to-r from-red-950 via-red-700 to-amber-400"></div>
                            <div class="p-4 sm:p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div><span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-[9px] font-bold uppercase tracking-wide text-red-900">LIKHAE Platform</span><h3 class="mt-3 text-base font-bold text-stone-900">{{ $voucher->name ?: 'LIKHAE Voucher' }}</h3></div>
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
                                    <button type="button" data-platform-voucher-code="{{ $voucher->code }}" class="rounded-lg bg-red-900 px-3 py-2 text-[11px] font-bold text-white transition hover:bg-red-950">Use voucher</button>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </dialog>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-cart-page]');

    if (!page) {
        return;
    }

    const currency = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
    });

    const selectAll = page.querySelector('[data-select-all]');
    const cartItemsContainer = page.querySelector('[data-cart-items]');
    const emptyState = page.querySelector('[data-cart-empty]');
    const checkoutArea = page.querySelector('[data-cart-checkout]');
    const cartCount = page.querySelector('[data-cart-count]');
    const selectedCountElement = page.querySelector('[data-selected-count]');
    const subtotalElement = page.querySelector('[data-summary-subtotal]');
    const shippingElement = page.querySelector('[data-summary-shipping]');
    const discountElement = page.querySelector('[data-summary-discount]');
    const totalElement = page.querySelector('[data-summary-total]');
    const savedRow = page.querySelector('[data-summary-saved-row]');
    const savedElement = page.querySelector('[data-summary-saved]');
    const placeOrderButton = page.querySelector('[data-place-order]');
    const stockWarning = page.querySelector('[data-stock-warning]');
    const voucherInput = page.querySelector('[data-voucher-code]');
    const voucherFeedback = page.querySelector('[data-voucher-feedback]');
    const applyVoucherButton = page.querySelector('[data-apply-voucher]');
    const platformVoucherDialog = document.getElementById('cart-platform-voucher-dialog');
    const platformVoucherButton = page.querySelector('[data-open-platform-vouchers]');
    const appliedPlatformVoucher = page.querySelector('[data-platform-voucher-applied]');
    const appliedPlatformVoucherCode = page.querySelector('[data-platform-voucher-applied-code]');
    let previewSubtotal = null;
    let previewDiscount = 0;
    let previewShipping = null;
    let previewPending = false;
    let previewError = false;
    let previewRequestId = 0;
    let previewAbortController = null;
    let validatedVoucherCode = '';
    let validatedVoucherSource = '';
    const pendingQuantityItems = new Set();
    const quantityStates = new WeakMap();

    const getItems = () => {
        return Array.from(
            page.querySelectorAll('[data-cart-item]')
        );
    };

    const getSellerGroups = () => Array.from(
        page.querySelectorAll('[data-seller-group]')
    );

    const getSellerItems = (group) => Array.from(
        group.querySelectorAll('[data-cart-item]')
    );

    const updateSellerGroup = (group) => {
        const items = getSellerItems(group);
        const selectableItems = items.filter((item) => item.dataset.itemAvailable === '1');
        const selectedItems = selectableItems.filter((item) =>
            item.querySelector('[data-cart-select]')?.checked
        );
        const selector = group.querySelector('[data-seller-select]');
        const count = group.querySelector('[data-seller-count]');
        const subtotalElement = group.querySelector('[data-seller-subtotal]');
        const subtotal = selectedItems.reduce((sum, item) => {
            const price = Number(item.dataset.itemPrice) || 0;
            const quantity = item.querySelector('[data-cart-quantity]');

            return sum + price * (quantity ? clampQuantity(quantity) : 1);
        }, 0);

        if (count) {
            count.textContent = `${items.length} ${items.length === 1 ? 'item' : 'items'}`;
        }

        if (subtotalElement) {
            subtotalElement.textContent = currency.format(subtotal);
        }

        if (selector) {
            selector.checked = selectableItems.length > 0 && selectedItems.length === selectableItems.length;
            selector.indeterminate = selectedItems.length > 0 && selectedItems.length < selectableItems.length;
        }
    };

    const removeEmptySellerGroups = () => {
        getSellerGroups().forEach((group) => {
            if (getSellerItems(group).length === 0) {
                group.remove();
            }
        });
    };

    const clampQuantity = (input) => {
        const minimum = Number(input.min) || 1;
        const raw = Number(input.value);
        const current = Number.isFinite(raw) ? Math.floor(raw) : minimum;
        const quantity = Math.min(999, Math.max(minimum, current));

        // Preserve an over-stock quantity so the buyer sees the real cart
        // state and Checkout remains blocked until it is corrected.
        input.value = quantity;

        return Number(input.value);
    };

    const updateQuantityButtons = (item) => {
        const input = item.querySelector('[data-cart-quantity]');
        const minusButton = item.querySelector('[data-quantity-minus]');
        const plusButton = item.querySelector('[data-quantity-plus]');

        if (!input) {
            return;
        }

        if (item.dataset.itemAvailable !== '1') {
            input.disabled = true;
            if (minusButton) minusButton.disabled = true;
            if (plusButton) plusButton.disabled = true;
            return;
        }

        const quantity = clampQuantity(input);
        const minimum = Number(input.min) || 1;
        const maximum = Math.max(0, Number(input.max) || 0);

        if (minusButton) {
            minusButton.disabled = quantity <= minimum;
        }

        if (plusButton) {
            plusButton.disabled = maximum === 0 || quantity >= maximum;
        }
    };

    const updateLineTotal = (item) => {
        const price = Number(item.dataset.itemPrice) || 0;
        const quantityInput = item.querySelector(
            '[data-cart-quantity]'
        );
        const lineTotal = item.querySelector(
            '[data-line-total]'
        );

        if (!quantityInput || !lineTotal) {
            return;
        }

        const quantity = clampQuantity(quantityInput);

        lineTotal.textContent = item.dataset.itemRestricted === '1'
            ? 'Unavailable'
            : currency.format(price * quantity);

        const stock = Math.max(0, Number(item.dataset.itemStock) || 0);
        const availability = item.querySelector('[data-stock-availability]');
        if (availability) {
            const unavailable = item.dataset.itemAvailable !== '1' || stock < quantity;
            if (item.dataset.itemAvailable !== '1') {
                availability.textContent = item.dataset.availabilityMessage || 'Unavailable';
            } else {
            availability.textContent = unavailable
                ? `Only ${stock} available.`
                : `${stock} pieces available`;
            }
            availability.classList.toggle('font-semibold', unavailable);
            availability.classList.toggle('text-red-700', unavailable);
            availability.classList.toggle('text-stone-400', !unavailable);
        }

        updateQuantityButtons(item);
    };

    const applyServerItemPrices = (serverItems) => {
        (Array.isArray(serverItems) ? serverItems : []).forEach((serverItem) => {
            const item = getItems().find((candidate) => candidate.dataset.itemId === String(serverItem.id));
            if (!item || item.dataset.itemRestricted === '1') return;

            const price = Math.max(0, Number(serverItem.price) || 0);
            const originalPrice = Math.max(0, Number(serverItem.original_price) || 0);
            const stock = Math.max(0, Number(serverItem.stock) || 0);
            item.dataset.itemPrice = String(price);
            item.dataset.itemStock = String(stock);

            const currentPriceElement = item.querySelector('[data-current-price]');
            if (currentPriceElement) currentPriceElement.textContent = currency.format(price);

            const originalPriceElement = item.querySelector('[data-original-price]');
            if (originalPriceElement) {
                originalPriceElement.textContent = currency.format(originalPrice);
                originalPriceElement.classList.toggle('hidden', originalPrice <= price);
            }

            const quantityInput = item.querySelector('[data-cart-quantity]');
            if (quantityInput) quantityInput.max = String(stock);
            updateLineTotal(item);
        });
    };

    const updateSummary = () => {
        removeEmptySellerGroups();
        getSellerGroups().forEach(updateSellerGroup);

        const items = getItems();
        const selectedItems = items.filter((item) => {
            return item.querySelector(
                '[data-cart-select]'
            )?.checked && item.dataset.itemAvailable === '1';
        });

        let localSubtotal = 0;
        let hasUnavailableStock = false;

        selectedItems.forEach((item) => {
            const price = Number(item.dataset.itemPrice) || 0;
            const quantityInput = item.querySelector(
                '[data-cart-quantity]'
            );
            const quantity = quantityInput
                ? clampQuantity(quantityInput)
                : 1;
            const stock = Math.max(0, Number(item.dataset.itemStock) || 0);

            if (stock < quantity) {
                hasUnavailableStock = true;
            }

            localSubtotal += price * quantity;
        });

        const subtotal = previewSubtotal === null ? localSubtotal : previewSubtotal;

        if (cartCount) {
            cartCount.textContent =
                `${items.length} ${items.length === 1 ? 'item' : 'items'}`;
        }

        if (selectedCountElement) {
            selectedCountElement.textContent = `${selectedItems.length}`;
        }

        if (subtotalElement) {
            subtotalElement.textContent =
                currency.format(subtotal);
        }

        const shipping = selectedItems.length > 0 && previewShipping !== null ? Math.max(0, previewShipping) : null;
        const discount = selectedItems.length > 0 ? Math.min(subtotal, Math.max(0, previewDiscount)) : 0;
        const total = Math.max(0, subtotal + (shipping ?? 0) - discount);

        if (shippingElement) {
            shippingElement.textContent = shipping === null
                ? 'Shipping calculated at checkout.'
                : currency.format(shipping);
        }

        if (discountElement) {
            discountElement.textContent = `−${currency.format(discount)}`;
        }

        if (totalElement) {
            totalElement.textContent = currency.format(total);
        }

        if (savedRow && savedElement) {
            savedRow.classList.add('hidden');
            savedElement.textContent = currency.format(discount);
        }

        if (placeOrderButton) {
            placeOrderButton.disabled =
                selectedItems.length === 0 || hasUnavailableStock || previewPending || previewError || pendingQuantityItems.size > 0;
        }

        if (stockWarning) {
            stockWarning.classList.toggle('hidden', !hasUnavailableStock && !previewError);
        }

        if (selectAll) {
            selectAll.checked =
                items.some((item) => item.dataset.itemAvailable === '1') &&
                selectedItems.length === items.filter((item) => item.dataset.itemAvailable === '1').length;

            selectAll.indeterminate =
                selectedItems.length > 0 &&
                selectedItems.length < items.filter((item) => item.dataset.itemAvailable === '1').length;
        }

        const cartIsEmpty = items.length === 0;

        emptyState?.classList.toggle(
            'hidden',
            !cartIsEmpty
        );

        checkoutArea?.classList.toggle(
            'hidden',
            cartIsEmpty
        );
    };

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const setVoucherFeedback = (message, kind = 'neutral') => {
        if (!voucherFeedback) return;
        voucherFeedback.textContent = message || '';
        voucherFeedback.classList.toggle('hidden', !message);
        voucherFeedback.classList.toggle('text-red-700', kind === 'error');
        voucherFeedback.classList.toggle('text-green-700', kind === 'success');
        voucherFeedback.classList.toggle('text-stone-600', kind === 'neutral');
    };
    const renderAppliedVoucher = () => {
        const isPlatformVoucher = Boolean(validatedVoucherCode) && validatedVoucherSource === 'platform';
        appliedPlatformVoucher?.classList.toggle('hidden', !isPlatformVoucher);
        appliedPlatformVoucher?.classList.toggle('flex', isPlatformVoucher);
        if (appliedPlatformVoucherCode) appliedPlatformVoucherCode.textContent = isPlatformVoucher ? validatedVoucherCode : '';
    };
    const requestCartPreview = async (voucherCode = validatedVoucherCode, applyingVoucher = false, voucherSource = validatedVoucherSource || 'seller') => {
        const requestId = ++previewRequestId;
        previewAbortController?.abort();
        previewAbortController = new AbortController();
        const code = String(voucherCode || '').trim().toUpperCase();
        const selectedIds = getItems()
            .filter((item) => item.dataset.itemAvailable === '1' && item.querySelector('[data-cart-select]')?.checked)
            .map((item) => item.dataset.itemId)
            .filter(Boolean);

        previewSubtotal = null;
        previewDiscount = 0;
        previewShipping = null;
        previewPending = true;
        previewError = false;
        if (applyVoucherButton) applyVoucherButton.disabled = true;
        if (code) setVoucherFeedback('Updating voucher preview…');
        updateSummary();

        if (selectedIds.length === 0) {
            previewPending = false;
            previewSubtotal = 0;
            validatedVoucherCode = '';
            validatedVoucherSource = '';
            previewError = false;
            if (code) setVoucherFeedback('Select eligible cart items before applying a voucher.', 'error');
            renderAppliedVoucher();
            updateSummary();
            if (applyVoucherButton) applyVoucherButton.disabled = false;
            return;
        }

        const body = new URLSearchParams({_token: csrfToken});
        body.set(voucherSource === 'platform' && code ? 'platform_voucher_code' : 'voucher_code', code);
        selectedIds.forEach((id) => body.append('cart_item_ids[]', id));

        try {
            const response = await fetch(page.dataset.previewUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body,
                signal: previewAbortController.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (requestId !== previewRequestId) return;

            if (!response.ok || !payload.success) {
                previewError = !response.ok || Boolean(payload.message);
                if (response.ok) applyServerItemPrices(payload.items);
                previewSubtotal = response.ok && Number.isFinite(Number(payload.subtotal))
                    ? Math.max(0, Number(payload.subtotal))
                    : null;
                previewDiscount = 0;
                previewShipping = response.ok && payload.shipping_estimate_available && payload.shipping_total !== null
                    ? Math.max(0, Number(payload.shipping_total) || 0)
                    : null;
                validatedVoucherCode = '';
                validatedVoucherSource = '';
                const message = payload.voucher_message || payload.message || 'Cart totals could not be refreshed. Try again.';
                if (code && !applyingVoucher) {
                    setVoucherFeedback(`Voucher removed from preview because the selected items no longer qualify. ${message}`, 'error');
                } else {
                    setVoucherFeedback(message, 'error');
                }
                return;
            }

            previewSubtotal = Math.max(0, Number(payload.subtotal) || 0);
            previewError = false;
            applyServerItemPrices(payload.items);
            previewDiscount = Math.min(previewSubtotal, Math.max(0, Number(payload.discount_total) || 0));
            previewShipping = payload.shipping_estimate_available && payload.shipping_total !== null
                ? Math.max(0, Number(payload.shipping_total) || 0)
                : null;
            validatedVoucherCode = code && payload.voucher_applied ? code : '';
            validatedVoucherSource = validatedVoucherCode ? voucherSource : '';
            if (code) {
                setVoucherFeedback(payload.voucher_message || 'Voucher applied to eligible selected items.', 'success');
            } else if (!applyingVoucher) {
                setVoucherFeedback('', 'neutral');
            }
        } catch (error) {
            if (error?.name !== 'AbortError' && requestId === previewRequestId) {
                previewSubtotal = null;
                previewDiscount = 0;
                previewShipping = null;
                previewError = true;
                validatedVoucherCode = '';
                validatedVoucherSource = '';
                setVoucherFeedback('Cart totals could not be refreshed. Check your connection and try again.', 'error');
            }
        } finally {
            if (requestId === previewRequestId) {
                previewPending = false;
                if (applyVoucherButton) applyVoucherButton.disabled = false;
                renderAppliedVoucher();
                updateSummary();
            }
        }
    };
    const invalidateCartPreview = () => {
        previewSubtotal = null;
        previewDiscount = 0;
        previewShipping = null;
        previewError = false;
        previewAbortController?.abort();
    };
    const persistQuantity = (item) => {
        const input = item?.querySelector('[data-cart-quantity]');
        if (!item?.dataset.updateUrl || !input) return Promise.resolve(false);
        let state = quantityStates.get(item);
        if (!state) {
            state = {saved: Number(input.value) || 1, desired: Number(input.value) || 1, promise: null};
            quantityStates.set(item, state);
        }
        state.desired = clampQuantity(input);
        if (state.promise) return state.promise;

        pendingQuantityItems.add(item);
        updateSummary();
        state.promise = (async () => {
            let succeeded = true;
            while (state.saved !== state.desired) {
                const requestedQuantity = state.desired;
                const body = new URLSearchParams({_token: csrfToken, _method: 'PATCH', quantity: String(requestedQuantity)});
                try {
                    const response = await fetch(item.dataset.updateUrl, {
                        method: 'POST',
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        body,
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) {
                        const errorMessage = payload?.errors ? Object.values(payload.errors).flat()[0] : payload?.message;
                        window.lkBuyerToast?.(errorMessage || 'Could not save cart quantity.');
                        if (state.desired === requestedQuantity) {
                            input.value = String(state.saved);
                            updateLineTotal(item);
                            succeeded = false;
                            break;
                        }
                        continue;
                    }
                    state.saved = Number(payload.quantity) || requestedQuantity;
                } catch (error) {
                    window.lkBuyerToast?.('Could not save cart quantity.');
                    if (state.desired === requestedQuantity) {
                        input.value = String(state.saved);
                        updateLineTotal(item);
                        succeeded = false;
                        break;
                    }
                }
            }
            pendingQuantityItems.delete(item);
            state.promise = null;
            updateSummary();
            return succeeded;
        })();
        return state.promise;
    };
    const persistRemove = async (item) => {
        if (!item?.dataset.removeUrl) return false;
        const quantityState = quantityStates.get(item);
        if (quantityState?.promise) await quantityState.promise;
        const body = new URLSearchParams({_token: csrfToken, _method: 'DELETE'});
        try {
            const response = await fetch(item.dataset.removeUrl, {method: 'POST', headers: {'Accept': 'text/html'}, body});
            if (!response.ok) throw new Error('Remove request failed.');
            return true;
        } catch (error) {
            window.lkBuyerToast?.('Could not remove cart item. The cart will refresh.');
            window.location.reload();
            return false;
        }
    };

    page.addEventListener('click', (event) => {
        const minusButton = event.target.closest(
            '[data-quantity-minus]'
        );

        const plusButton = event.target.closest(
            '[data-quantity-plus]'
        );

        const removeButton = event.target.closest(
            '[data-remove-item]'
        );

        const removeSelectedButton = event.target.closest(
            '[data-remove-selected]'
        );

        if (minusButton) {
            const item = minusButton.closest(
                '[data-cart-item]'
            );

            const input = item?.querySelector(
                '[data-cart-quantity]'
            );

            if (input) {
                input.value = Math.max(
                    Number(input.min) || 1,
                    Number(input.value || 1) - 1
                );

                updateLineTotal(item);
                invalidateCartPreview();
                updateSummary();
                persistQuantity(item).then(() => requestCartPreview(validatedVoucherCode));
            }
        }

        if (plusButton) {
            const item = plusButton.closest(
                '[data-cart-item]'
            );

            const input = item?.querySelector(
                '[data-cart-quantity]'
            );

            if (input) {
                input.value = Math.min(
                    Math.max(0, Number(input.max) || 0),
                    Number(input.value || 1) + 1
                );

                updateLineTotal(item);
                invalidateCartPreview();
                updateSummary();
                persistQuantity(item).then(() => requestCartPreview(validatedVoucherCode));
            }
        }

        if (removeButton) {
            const item = removeButton.closest(
                '[data-cart-item]'
            );

            persistRemove(item);
            item?.remove();
            invalidateCartPreview();
            updateSummary();
            requestCartPreview(validatedVoucherCode);
        }

        if (removeSelectedButton) {
            getItems().forEach((item) => {
                const checkbox = item.querySelector(
                    '[data-cart-select]'
                );

                if (checkbox?.checked) {
                    persistRemove(item);
                    item.remove();
                }
            });

            invalidateCartPreview();
            updateSummary();
            requestCartPreview(validatedVoucherCode);
        }
    });

    page.addEventListener('change', (event) => {
        if (event.target.matches('[data-cart-quantity]')) {
            const item = event.target.closest(
                '[data-cart-item]'
            );

            if (item) {
                updateLineTotal(item);
                invalidateCartPreview();
                updateSummary();
                persistQuantity(item).then(() => requestCartPreview(validatedVoucherCode));
            }
        }

        if (event.target.matches('[data-seller-select]')) {
            const group = event.target.closest('[data-seller-group]');
            group?.querySelectorAll('[data-cart-select]').forEach((checkbox) => {
                if (!checkbox.disabled) checkbox.checked = event.target.checked;
            });
            updateSummary();
            requestCartPreview(validatedVoucherCode);
            return;
        }

        if (event.target.matches('[data-cart-select]')) {
            updateSummary();
            requestCartPreview(validatedVoucherCode);
        }

        if (event.target.matches('[data-select-all]')) {
            getItems().forEach((item) => {
                const checkbox = item.querySelector(
                    '[data-cart-select]'
                );

                if (checkbox && !checkbox.disabled) {
                    checkbox.checked = event.target.checked;
                }
            });

            updateSummary();
            requestCartPreview(validatedVoucherCode);
        }
    });

    placeOrderButton?.addEventListener('click', () => {
        const selectedItems = getItems().filter((item) =>
            item.dataset.itemAvailable === '1' && item.querySelector('[data-cart-select]')?.checked
        );
        if (selectedItems.length === 0) {
            window.lkBuyerToast?.('Select at least one cart item.');
            return;
        }

        placeOrderButton.disabled = true;
        placeOrderButton.textContent = 'Opening Checkout...';

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = @json(route('buyer.checkout.post', [], false));

        const fields = {
            _token: document.querySelector('meta[name="csrf-token"]')?.content || '',
        };

        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        selectedItems.forEach((item) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cart_item_ids[]';
            input.value = item.dataset.itemId || '';
            form.appendChild(input);
        });

        const voucherCode = validatedVoucherCode;
        if (voucherCode) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = validatedVoucherSource === 'platform' ? 'platform_voucher_code' : 'voucher_code';
            input.value = voucherCode;
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    });

    page.querySelectorAll('[data-use-voucher]').forEach((button) => {
        button.addEventListener('click', () => {
            if (voucherInput) {
                if (validatedVoucherCode) {
                    validatedVoucherCode = '';
                    validatedVoucherSource = '';
                    requestCartPreview('');
                }
                voucherInput.value = button.dataset.voucherCode || '';
                voucherInput.focus();
            }
        });
    });

    applyVoucherButton?.addEventListener('click', () => {
        const code = voucherInput?.value.trim() || '';
        if (!code) {
            setVoucherFeedback('Enter a voucher code.', 'error');
            voucherInput?.focus();
            return;
        }
        requestCartPreview(code, true, 'seller');
    });

    voucherInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applyVoucherButton?.click();
        }
    });

    voucherInput?.addEventListener('input', () => {
        if (validatedVoucherCode && voucherInput.value.trim().toUpperCase() !== validatedVoucherCode) {
            validatedVoucherCode = '';
            validatedVoucherSource = '';
            setVoucherFeedback('Apply the new code to preview its discount.');
            requestCartPreview('');
        }
    });

    page.querySelector('[data-clear-voucher]')?.addEventListener('click', () => {
        if (voucherInput) {
            voucherInput.value = '';
            voucherInput.focus();
        }
        validatedVoucherCode = '';
        validatedVoucherSource = '';
        setVoucherFeedback('Voucher removed from preview.');
        requestCartPreview('');
    });

    platformVoucherButton?.addEventListener('click', () => platformVoucherDialog?.showModal());
    platformVoucherDialog?.querySelector('[data-close-platform-vouchers]')?.addEventListener('click', () => platformVoucherDialog.close());
    platformVoucherDialog?.addEventListener('click', (event) => {
        if (event.target === platformVoucherDialog) platformVoucherDialog.close();
    });
    platformVoucherDialog?.querySelectorAll('[data-platform-voucher-code]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (voucherInput) voucherInput.value = '';
            await requestCartPreview(button.dataset.platformVoucherCode || '', true, 'platform');
            platformVoucherDialog.close();
        });
    });
    page.querySelector('[data-remove-platform-voucher]')?.addEventListener('click', () => {
        validatedVoucherCode = '';
        validatedVoucherSource = '';
        setVoucherFeedback('Voucher removed from preview.');
        requestCartPreview('');
    });

    getItems().forEach((item) => {
        updateLineTotal(item);
    });

    updateSummary();
    renderAppliedVoucher();
    requestCartPreview('');
});
</script>
@endsection
