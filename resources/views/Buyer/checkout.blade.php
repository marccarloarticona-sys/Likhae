@extends('layouts.buyer')
@section('title', 'Checkout')
@section('active', 'cart')

@section('content')
@php
    $cartItems = collect($cartItems ?? []);
    $addresses = collect($addresses ?? []);
    $logisticsProviders = collect($logisticsProviders ?? []);
    $defaultAddress = $addresses->firstWhere('is_default', true) ?? $addresses->first();
    $defaultLogisticsProvider = $logisticsProviders->first();
    $automaticRouting = (bool) ($automaticRouting ?? false);
    $groups = collect(data_get($preview ?? [], 'groups', []));
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
            </section>
        @endif

        @if($errors->any() && !$errors->has('voucher_code'))
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
            <div class="space-y-5">
                <section class="rounded-2xl border border-stone-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4"><div><h2 class="text-base font-bold text-stone-900">Delivery Address</h2><p class="mt-1 text-xs text-stone-500">Your order will be delivered to this address.</p></div><a href="{{ route('buyer.account', ['tab' => 'addresses']) }}" class="shrink-0 text-xs font-semibold text-red-800">Change</a></div>
                    @error('address_id')<p class="mx-5 mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800">{{ $message }} <a class="underline" href="{{ route('buyer.account.addresses') }}">Open Account Addresses</a></p>@enderror
                    @if($errors->has('latitude') || $errors->has('longitude'))<p class="mx-5 mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800">{{ $errors->first('latitude') ?: $errors->first('longitude') }} <a class="underline" href="{{ route('buyer.account.addresses') }}">Confirm the address pin</a></p>@endif
                    <div class="grid gap-3 p-5">@foreach($addresses as $address)<label class="cursor-pointer"><input required type="radio" name="address_id" value="{{ $address->id }}" class="peer sr-only" @checked((string) old('address_id', $defaultAddress?->id) === (string) $address->id)><span class="flex gap-3 rounded-xl border border-stone-200 p-4 transition peer-checked:border-red-800 peer-checked:bg-red-50/60"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-800">⌖</span><span class="min-w-0"><span class="flex flex-wrap items-center gap-2"><strong class="text-sm text-stone-900">{{ $address->recipient_name }}</strong><span class="text-xs text-stone-500">{{ $address->contact_number }}</span>@if($address->is_default)<span class="rounded-full bg-red-100 px-2 py-0.5 text-[9px] font-semibold uppercase text-red-900">Default</span>@endif</span><span class="mt-2 block text-xs leading-5 text-stone-600">{{ $address->formatted() }}</span><span class="mt-2 block text-[11px] font-semibold {{ filled($address->latitude) && filled($address->longitude) ? 'text-emerald-700' : 'text-amber-700' }}">{{ filled($address->latitude) && filled($address->longitude) ? 'Exact pin confirmed' : 'Confirm exact pin in Account before ordering' }}</span></span></span></label>@endforeach</div>
                </section>

                <section class="rounded-2xl border border-stone-200 bg-white shadow-sm">
                    <div class="border-b border-stone-100 px-5 py-4">
                        <h2 class="text-base font-bold text-stone-900">Logistics / Delivery Method</h2>
                        <p class="mt-1 text-xs text-stone-500">{{ $automaticRouting ? 'LIKHAE will route each seller parcel from its pickup municipality to the buyer municipality using canonical PSGC coverage.' : 'Choose an available delivery provider for your parcel.' }}</p>
                    </div>
                    <div class="grid gap-3 p-5">
                        @if($automaticRouting)
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                                <strong class="block">Automatic PSGC routing enabled</strong>
                                <span class="mt-1 block text-xs leading-5">Each seller receives its own shipment route. {{ config('likhae.allow_ambiguous_routing', false) ? 'A temporary demo fallback selects a covered active hub when more than one hub matches the municipality.' : 'A missing, invalid, or ambiguous hub or barangay coverage will safely stop checkout.' }}</span>
                            </div>
                        @else
                        @forelse($logisticsProviders as $provider)
                            <label class="cursor-pointer">
                                <input required type="radio" name="logistics_center_id" value="{{ $provider->id }}" data-shipping-fee="{{ number_format((float) $provider->shipping_fee, 2, '.', '') }}" class="peer sr-only" @checked((string) old('logistics_center_id', $defaultLogisticsProvider?->id) === (string) $provider->id)>
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
                        <p class="text-[11px] leading-5 text-stone-500">{{ $automaticRouting ? 'The displayed fee uses the default address for preview; the submitted canonical address is authoritative.' : 'The final delivery fee is verified from the selected provider when the order is placed.' }}</p>
                    </div>
                </section>

                <section class="rounded-2xl border border-stone-200 bg-white shadow-sm" aria-labelledby="checkout-voucher-heading">
                    <div class="border-b border-stone-100 px-5 py-4">
                        <h2 id="checkout-voucher-heading" class="text-base font-bold text-stone-900">Shop voucher</h2>
                        <p class="mt-1 text-xs text-stone-500">Enter one code. We'll identify the shop and apply it only to eligible items.</p>
                    </div>
                    <div class="grid gap-3 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        <label for="checkout-voucher-code" class="block text-xs font-semibold text-stone-700">
                            Enter your voucher
                            <input id="checkout-voucher-code" name="voucher_code" value="{{ old('voucher_code', $voucherCode ?? '') }}" maxlength="80" autocomplete="off" autocapitalize="characters" class="mt-2 block w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm uppercase outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100" placeholder="e.g. SHOP-SAVE10" aria-describedby="checkout-voucher-help">
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="lk-btn lk-btn-red">Apply code</button>
                            @if(filled(old('voucher_code', $voucherCode ?? '')))
                                <button type="submit" name="clear_voucher" value="1" formmethod="POST" formaction="{{ route('buyer.checkout.voucher') }}" formnovalidate class="lk-btn lk-btn-light">Remove</button>
                            @endif
                        </div>
                    </div>
                    <p id="checkout-voucher-help" class="px-5 pb-4 text-[11px] text-stone-500">A seller voucher discounts only that shop’s eligible items. Your order totals update after you apply it.</p>
                    @error('voucher_code')<p class="mx-5 mb-5 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800" role="alert">{{ $message }}</p>@enderror
                    @if(collect($availableVouchers ?? [])->isNotEmpty())
                        <div class="space-y-2 border-t border-stone-100 px-5 py-4">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-stone-500">Available for this checkout</p>
                            @foreach($availableVouchers as $sellerId => $vouchers)
                                @php($voucherShop = data_get($groups->get($sellerId), 'seller.business_name', 'Shop'))
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[10px] text-stone-500">{{ $voucherShop }}:</span>
                                    @foreach($vouchers as $voucher)
                                        <button type="button" data-checkout-voucher-suggestion data-voucher-code="{{ $voucher->code }}" class="rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-[10px] font-semibold text-red-800 hover:bg-red-100" aria-label="Use {{ $voucher->code }} at {{ $voucherShop }}">
                                            {{ $voucher->code }} &middot; {{ $voucher->discount_type === 'PERCENT' ? number_format((float) $voucher->discount_value, 0).'%' : 'PHP '.number_format((float) $voucher->discount_value, 2) }} off &middot; Min PHP {{ number_format((float) $voucher->minimum_order_amount, 2) }}
                                        </button>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                @foreach($groups as $sellerId => $group)
                    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                        <h2 class="text-base font-bold text-stone-900">{{ data_get($group, 'seller.business_name', 'LIKHAE Seller') }}</h2>
                        <div class="mt-4 space-y-3">
                            @foreach(collect(data_get($group, 'items', [])) as $item)
                                @php($variant = $item->productVariant)
                                @php($product = $variant->product)
                                @php($protectionQuote = (array) ($item->protection_quote ?? []))
                                @if($protectionQuote['eligible'] ?? false)
                                    <label class="mb-2 flex cursor-pointer items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/70 p-2 text-[11px] text-amber-950"><input type="checkbox" name="protection_item_ids[]" value="{{ $item->id }}" data-protection-checkbox data-protection-fee="{{ number_format((float) ($protectionQuote['fee'] ?? 0), 2, '.', '') }}" class="mt-0.5" @checked($protectionQuote['selected'] ?? false)><span><strong class="block">Add Additional Care / Fragile Protection</strong><span>PHP {{ number_format((float) ($protectionQuote['fee'] ?? 0), 2) }} · {{ str((string) ($protectionQuote['scope'] ?? ''))->headline() }}</span></span></label>
                                @endif
                                <div class="flex gap-3 text-sm"><img class="h-14 w-14 rounded-lg object-cover" src="{{ $imageUrl($product, $variant) }}" alt="{{ $product->name }}"><div class="min-w-0 flex-1"><strong class="line-clamp-2 text-stone-800">{{ $product->name }}</strong><span class="block text-xs text-stone-500">{{ $variant->description ?: 'Standard' }} · Qty {{ $item->quantity }}</span></div><strong>&#8369;{{ number_format((float) $variant->final_price * $item->quantity, 2) }}</strong></div>
                            @endforeach
                        </div>
                        <div class="mt-4 space-y-2 border-t border-stone-100 pt-3 text-xs">
                            <div class="flex justify-between gap-3 text-stone-500"><span>{{ data_get($group, 'seller.business_name', 'Shop') }} subtotal</span><strong class="text-stone-700">&#8369;{{ number_format((float) data_get($group, 'item_subtotal', 0), 2) }}</strong></div>
                            @if(data_get($group, 'voucher'))
                                <div class="flex flex-wrap justify-between gap-2 text-emerald-700"><span>Voucher {{ data_get($group, 'voucher.code') }} &middot; {{ data_get($group, 'seller.business_name', 'Shop') }}</span><strong>-&#8369;{{ number_format((float) data_get($group, 'voucher_discount', 0), 2) }}</strong></div>
                            @endif
                            @if((float) data_get($group, 'protection_total', 0) > 0)<div class="flex justify-between gap-3 text-amber-800"><span>Additional Care</span><strong>&#8369;{{ number_format((float) data_get($group, 'protection_total', 0), 2) }}</strong></div>@endif
                        </div>
                    </section>
                @endforeach

                <section class="rounded-2xl border border-stone-200 bg-white shadow-sm"><div class="border-b border-stone-100 px-5 py-4"><h2 class="text-base font-bold text-stone-900">Payment Method</h2><p class="mt-1 text-xs text-stone-500">New orders use Cash on Delivery. The final-mile delivery rider collects the full amount shown below.</p></div><div class="p-5"><label class="block cursor-pointer"><input type="radio" name="payment_method" value="COD" class="peer sr-only" checked><span class="flex items-start gap-3 rounded-xl border border-red-800 bg-red-50 p-4"><span class="text-red-800">₱</span><span><strong class="block text-xs">Cash on Delivery (COD)</strong><small class="text-[10px] text-stone-500">Pay the product amount plus shipping when your order arrives.</small></span></span></label></div>@error('payment_method')<p class="px-5 pb-5 text-xs font-semibold text-red-700">{{ $message }}</p>@enderror</section>
            </div>

             <aside class="h-fit rounded-2xl border border-stone-200 bg-white p-5 shadow-sm"><h2 class="text-base font-bold text-stone-900">Order summary</h2><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><dt class="text-stone-500">Subtotal</dt><dd>&#8369;{{ number_format((float) data_get($preview, 'subtotal', 0), 2) }}</dd></div><div class="flex justify-between"><dt class="text-stone-500">Discount</dt><dd>-&#8369;{{ number_format((float) data_get($preview, 'discount_total', 0), 2) }}</dd></div><div class="flex justify-between"><dt class="text-stone-500">Shipping</dt><dd id="checkout-shipping-total">&#8369;{{ number_format((float) data_get($preview, 'shipping_total', 0), 2) }}</dd></div><div class="flex justify-between text-amber-800"><dt>Additional Care</dt><dd id="checkout-protection-total">&#8369;{{ number_format((float) data_get($preview, 'protection_total', 0), 2) }}</dd></div><div class="flex justify-between border-t border-stone-200 pt-3 text-base font-bold"><dt>Total</dt><dd id="checkout-grand-total">&#8369;{{ number_format((float) data_get($preview, 'grand_total', 0), 2) }}</dd></div></dl>@error('cart')<p class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-800">{{ $message }}</p>@enderror<button class="lk-btn lk-btn-red lk-btn-full mt-5" type="submit" data-place-order-submit @disabled((!$automaticRouting && $logisticsProviders->isEmpty()) || $hasUnavailableStock)>{{ (!$automaticRouting && $logisticsProviders->isEmpty()) ? 'Delivery unavailable' : 'Place Order' }}</button></aside>
        </form>
    @endif
</div>

@if($cartItems->isNotEmpty() && $addresses->isNotEmpty())
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const shipping = document.getElementById('checkout-shipping-total');
            const protection = document.getElementById('checkout-protection-total');
            const total = document.getElementById('checkout-grand-total');
            const subtotal = Number(@json((float) data_get($preview, 'subtotal', 0)));
            const discount = Number(@json((float) data_get($preview, 'discount_total', 0)));
            const initialProtection = Number(@json((float) data_get($preview, 'protection_total', 0)));
            const parcelCount = Number(@json(max($groups->count(), 1)));
            const money = (value) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

            const updateTotals = (input) => {
                const fee = Number(input?.dataset.shippingFee || 0) * parcelCount;
                if (shipping) shipping.textContent = money(fee);
                const protectionFee = [...document.querySelectorAll('[data-protection-checkbox]:checked')]
                    .reduce((sum, checkbox) => sum + Number(checkbox.dataset.protectionFee || 0), 0);
                if (protection) protection.textContent = money(protectionFee);
                if (total) total.textContent = money(Math.max(0, subtotal - discount + fee + protectionFee));
            };

            document.querySelectorAll('[data-protection-checkbox]').forEach((input) => {
                input.addEventListener('change', () => updateTotals(document.querySelector('input[name="logistics_center_id"]:checked')));
            });

            document.querySelectorAll('input[name="logistics_center_id"]').forEach((input) => {
                input.addEventListener('change', () => updateTotals(input));
                if (input.checked) updateTotals(input);
            });
            if (!document.querySelector('input[name="logistics_center_id"]:checked') && protection) {
                protection.textContent = money(initialProtection);
            }

            const checkoutForm = document.querySelector('[data-checkout-form]');
            const placeOrderButton = checkoutForm?.querySelector('[data-place-order-submit]');
            const voucherInput = document.getElementById('checkout-voucher-code');
            document.querySelectorAll('[data-checkout-voucher-suggestion]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!voucherInput) return;
                    voucherInput.value = button.dataset.voucherCode || '';
                    voucherInput.focus();
                });
            });
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
