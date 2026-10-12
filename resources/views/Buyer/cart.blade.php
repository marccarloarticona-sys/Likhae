@extends('layouts.buyer')

@section('title', 'Shopping Cart')
@section('active', 'cart')

@section('content')
@php
    $cartItems = collect($cartItems ?? []);

    $normalizedCartItems = $cartItems->map(function ($item) {
        $variant = data_get($item, 'productVariant');
        $product = data_get($variant, 'product');
        $productImages = collect(data_get($product, 'images', []));
        $imagePath = data_get($variant, 'productImage.file_path')
            ?? data_get($productImages->firstWhere('is_primary', true), 'file_path')
            ?? data_get($productImages->first(), 'file_path');
        $imageUrl = filled($imagePath)
            ? (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')
                ? $imagePath
                : \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath))
            : asset('images/product-placeholder.svg');

        $categoryValue = data_get($product, 'category.name')
            ?? data_get($item, 'category.name')
            ?? data_get($item, 'category')
            ?? 'Local Product';

        $sellerValue = data_get($product, 'sellerProfile.business_name')
            ?? 'LIKHAE Seller';

        return [
            'id' => data_get($item, 'id')
                ?? data_get($product, 'id'),

            'slug' => data_get($product, 'slug')
                ?? data_get($item, 'slug'),

            'name' => data_get($product, 'name')
                ?? data_get($item, 'name')
                ?? 'Product Name',

            'category' => is_scalar($categoryValue)
                ? (string) $categoryValue
                : 'Local Product',

            'variant' => data_get($variant, 'description')
                ?? 'Standard',

            'seller' => is_scalar($sellerValue)
                ? (string) $sellerValue
                : 'LIKHAE Seller',

            'price' => max(
                0,
                (float) (
                    data_get($variant, 'final_price')
                    ?? 0
                )
            ),

            'old_price' => max(
                0,
                (float) (
                    data_get($item, 'old_price')
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
                (int) (
                    data_get($variant, 'stock')
                    ?? 0
                )
            ),

            'image' => $imageUrl,
        ];
    });

    $hasItems = $normalizedCartItems->isNotEmpty();
    $hasUnavailableSelection = $normalizedCartItems->contains(
        fn ($item) => $item['stock'] < $item['quantity']
    );
    $availableVouchers = collect($availableVouchers ?? []);

    $subtotal = $normalizedCartItems->sum(
        fn ($item) => $item['price'] * $item['quantity']
    );

    $shipping = 0;
    $discount = 0;
    $total = $subtotal;

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
            <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-4 py-4 sm:px-5">
                    <label class="flex cursor-pointer items-center gap-3">
                        <input
                            type="checkbox"
                            class="h-4 w-4 rounded border-stone-300 text-red-900 focus:ring-red-800"
                            data-select-all
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

                <div class="divide-y divide-stone-100" data-cart-items>
                    @foreach ($normalizedCartItems as $item)
                        @php
                            $lineTotal = $item['price'] * $item['quantity'];

                            $detailsUrl = $hasProductDetailsRoute &&
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
                            data-item-price="{{ $item['price'] }}"
                            data-item-stock="{{ $item['stock'] }}"
                            data-update-url="{{ route('buyer.cart.update', ['item' => $item['id']]) }}"
                            data-remove-url="{{ route('buyer.cart.remove', ['item' => $item['id']]) }}"
                        >
                            <div class="flex items-start gap-3 sm:gap-4">
                                <input
                                    type="checkbox"
                                    class="mt-9 h-4 w-4 shrink-0 rounded border-stone-300 text-red-900 focus:ring-red-800"
                                    data-cart-select
                                    aria-label="Select {{ $item['name'] }}"
                                    checked
                                >

                                <a
                                    href="{{ $detailsUrl }}"
                                    class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-stone-200 bg-stone-50 sm:h-28 sm:w-28"
                                    aria-label="View {{ $item['name'] }}"
                                    @if (!$hasProductDetailsRoute) onclick="return false;" @endif
                                >
                                    <img
                                        src="{{ $item['image'] }}"
                                        alt="{{ $item['name'] }}"
                                        class="h-full w-full object-cover"
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
                                                @if (!$hasProductDetailsRoute) onclick="return false;" @endif
                                            >
                                                <h2 class="line-clamp-2 text-sm font-semibold leading-5 text-stone-900 transition hover:text-red-800 sm:text-base">
                                                    {{ $item['name'] }}
                                                </h2>
                                            </a>

                                            <p class="mt-1 truncate text-xs text-stone-500">
                                                {{ $item['seller'] }}
                                            </p>

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
                                                <strong class="text-base font-semibold text-red-800">
                                                    ₱{{ number_format($item['price'], 2) }}
                                                </strong>

                                                @if ($item['old_price'] > $item['price'])
                                                    <del class="text-xs text-stone-400">
                                                        ₱{{ number_format($item['old_price'], 2) }}
                                                    </del>
                                                @endif
                                            </div>

                                            <p class="mt-1 text-[10px] text-stone-400">
                                                {{ $item['stock'] }} pieces available
                                            </p>
                                        </div>

                                        <div class="flex items-center justify-between gap-4 sm:justify-end">
                                            <div class="inline-flex h-9 items-center overflow-hidden rounded-lg border border-stone-300 bg-white">
                                                <button
                                                    type="button"
                                                    class="flex h-full w-9 items-center justify-center text-stone-600 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                                                    data-quantity-minus
                                                    aria-label="Decrease quantity"
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
                                                >

                                                <button
                                                    type="button"
                                                    class="flex h-full w-9 items-center justify-center text-stone-600 transition hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-40"
                                                    data-quantity-plus
                                                    aria-label="Increase quantity"
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
                                                ₱{{ number_format($lineTotal, 2) }}
                                            </strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </article>
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
                        Final amount for the selected products.
                    </p>
                </div>

                <div class="p-5">
                    <div class="space-y-3 rounded-xl border border-stone-200 bg-stone-50 p-4">
                        <div>
                            <h3 class="text-xs font-semibold text-stone-800">Voucher</h3>
                            <p class="mt-1 text-[11px] text-stone-500">Enter one code. Checkout will match it to the eligible shop.</p>
                        </div>
                        <label for="cart-voucher-code" class="block text-[11px] font-semibold text-stone-700">Voucher code</label>
                        <div class="flex gap-2">
                            <input id="cart-voucher-code" data-voucher-code value="{{ old('voucher_code') }}" maxlength="80" autocomplete="off" class="min-w-0 flex-1 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs uppercase" placeholder="Enter your voucher">
                            <button type="button" data-clear-voucher class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-600">Clear</button>
                        </div>
                        @error('voucher_code')<p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
                        @if($availableVouchers->isNotEmpty())
                            <div class="space-y-2 border-t border-stone-200 pt-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-stone-500">Available for your cart</p>
                                @foreach($availableVouchers as $sellerId => $vouchers)
                                    @php
                                        $sellerName = data_get($cartItems->first(fn ($cartItem) => (int) data_get($cartItem, 'productVariant.product.seller_profile_id') === (int) $sellerId), 'productVariant.product.sellerProfile.business_name') ?? 'Shop';
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
                            <p class="text-[10px] text-stone-400">No suggested vouchers for these items. You can still enter a code from a shop.</p>
                        @endif
                    </div>

                    <div class="mt-5 space-y-3 border-t border-stone-100 pt-5 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Selected items</span>
                            <span class="font-medium text-stone-700" data-selected-count>
                                {{ $normalizedCartItems->count() }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Subtotal</span>
                            <span class="font-medium text-stone-700" data-summary-subtotal>
                                ₱{{ number_format($subtotal, 2) }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Shipping</span>
                            <span class="font-medium text-stone-700" data-summary-shipping>
                                ₱{{ number_format($shipping, 2) }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-stone-500">Discount</span>
                            <span class="font-medium text-red-700" data-summary-discount>
                                −₱{{ number_format($discount, 2) }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-5 flex items-end justify-between gap-4 border-t border-stone-200 pt-5">
                        <span class="text-sm font-semibold text-stone-900">
                            Total
                        </span>

                        <strong class="text-xl font-semibold text-red-800" data-summary-total>
                            ₱{{ number_format($total, 2) }}
                        </strong>
                    </div>

                    <button
                        type="button"
                        class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-red-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-red-950 disabled:cursor-not-allowed disabled:bg-stone-300"
                        data-place-order
                        @disabled(!$hasItems)
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
                        Remove unavailable items or reduce their quantity before checkout.
                    </p>

                    <p class="mt-3 text-center text-[10px] leading-4 text-stone-400">
                        Review your delivery and payment details before placing the order.
                    </p>
                </div>
            </section>
        </aside>
    </div>
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
    const placeOrderButton = page.querySelector('[data-place-order]');
    const stockWarning = page.querySelector('[data-stock-warning]');

    const getItems = () => {
        return Array.from(
            page.querySelectorAll('[data-cart-item]')
        );
    };

    const clampQuantity = (input) => {
        const minimum = Number(input.min) || 1;
        const maximum = Number(input.max) || 99;
        const current = Number(input.value) || minimum;

        input.value = Math.min(
            maximum,
            Math.max(minimum, current)
        );

        return Number(input.value);
    };

    const updateQuantityButtons = (item) => {
        const input = item.querySelector('[data-cart-quantity]');
        const minusButton = item.querySelector('[data-quantity-minus]');
        const plusButton = item.querySelector('[data-quantity-plus]');

        if (!input) {
            return;
        }

        const quantity = clampQuantity(input);
        const minimum = Number(input.min) || 1;
        const maximum = Number(input.max) || 99;

        if (minusButton) {
            minusButton.disabled = quantity <= minimum;
        }

        if (plusButton) {
            plusButton.disabled = quantity >= maximum;
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

        lineTotal.textContent = currency.format(
            price * quantity
        );

        updateQuantityButtons(item);
    };

    const updateSummary = () => {
        const items = getItems();
        const selectedItems = items.filter((item) => {
            return item.querySelector(
                '[data-cart-select]'
            )?.checked;
        });

        let subtotal = 0;
        let selectedQuantity = 0;
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

            subtotal += price * quantity;
            selectedQuantity += quantity;
        });

        const baseShipping =
            Number(page.dataset.shipping) || 0;

        const shipping =
            selectedItems.length > 0 ? baseShipping : 0;

        const appliedDiscount =
            Number(page.dataset.appliedDiscount) || 0;

        const discount =
            selectedItems.length > 0
                ? Math.min(appliedDiscount, subtotal)
                : 0;

        const total = Math.max(
            0,
            subtotal + shipping - discount
        );

        if (cartCount) {
            cartCount.textContent =
                `${items.length} ${items.length === 1 ? 'item' : 'items'}`;
        }

        if (selectedCountElement) {
            selectedCountElement.textContent =
                selectedQuantity;
        }

        if (subtotalElement) {
            subtotalElement.textContent =
                currency.format(subtotal);
        }

        if (shippingElement) {
            shippingElement.textContent =
                currency.format(shipping);
        }

        if (discountElement) {
            discountElement.textContent =
                `−${currency.format(discount)}`;
        }

        if (totalElement) {
            totalElement.textContent =
                currency.format(total);
        }

        if (placeOrderButton) {
            placeOrderButton.disabled =
                selectedItems.length === 0 || hasUnavailableStock;
        }

        if (stockWarning) {
            stockWarning.classList.toggle('hidden', !hasUnavailableStock);
        }

        if (selectAll) {
            selectAll.checked =
                items.length > 0 &&
                selectedItems.length === items.length;

            selectAll.indeterminate =
                selectedItems.length > 0 &&
                selectedItems.length < items.length;
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
    const persistQuantity = (item) => {
        const input = item?.querySelector('[data-cart-quantity]');
        if (!item?.dataset.updateUrl || !input) return;
        const body = new URLSearchParams({_token: csrfToken, _method: 'PATCH', quantity: input.value});
        fetch(item.dataset.updateUrl, {method: 'POST', headers: {'Accept': 'text/html'}, body}).catch(() => window.lkBuyerToast?.('Could not save cart quantity.'));
    };
    const persistRemove = (item) => {
        if (!item?.dataset.removeUrl) return;
        const body = new URLSearchParams({_token: csrfToken, _method: 'DELETE'});
        fetch(item.dataset.removeUrl, {method: 'POST', headers: {'Accept': 'text/html'}, body}).catch(() => window.lkBuyerToast?.('Could not remove cart item.'));
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
                updateSummary();
                persistQuantity(item);
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
                    Number(input.max) || 99,
                    Number(input.value || 1) + 1
                );

                updateLineTotal(item);
                updateSummary();
                persistQuantity(item);
            }
        }

        if (removeButton) {
            const item = removeButton.closest(
                '[data-cart-item]'
            );

            persistRemove(item);
            item?.remove();
            updateSummary();
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

            updateSummary();
        }
    });

    page.addEventListener('change', (event) => {
        if (event.target.matches('[data-cart-quantity]')) {
            const item = event.target.closest(
                '[data-cart-item]'
            );

            if (item) {
                updateLineTotal(item);
                updateSummary();
                persistQuantity(item);
            }
        }

        if (event.target.matches('[data-cart-select]')) {
            updateSummary();
        }

        if (event.target.matches('[data-select-all]')) {
            getItems().forEach((item) => {
                const checkbox = item.querySelector(
                    '[data-cart-select]'
                );

                if (checkbox) {
                    checkbox.checked = event.target.checked;
                }
            });

            updateSummary();
        }
    });

    placeOrderButton?.addEventListener('click', () => {
        const selectedItems = getItems().filter((item) =>
            item.querySelector('[data-cart-select]')?.checked
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

        const voucherCode = page.querySelector('[data-voucher-code]')?.value.trim();
        if (voucherCode) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'voucher_code';
            input.value = voucherCode;
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
    });

    page.querySelectorAll('[data-use-voucher]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = page.querySelector('input[data-voucher-code]');
            if (input) {
                input.value = button.dataset.voucherCode || '';
                input.focus();
            }
        });
    });

    page.querySelector('[data-clear-voucher]')?.addEventListener('click', () => {
        const input = page.querySelector('input[data-voucher-code]');
        if (input) {
            input.value = '';
            input.focus();
        }
    });

    getItems().forEach((item) => {
        updateLineTotal(item);
    });

    updateSummary();
});
</script>
@endsection
