<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Support\AddressCoordinateValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CheckoutService $checkoutService,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $selectedIds = array_map('intval', (array) $request->session()->get('checkout_cart_item_ids', []));
        $items = $this->cartService->selectedItems($request->user(), $selectedIds);

        if ($items->isEmpty()) {
            return redirect()->route('buyer.cart')->with('buyer_notice', 'Your cart is empty.');
        }

        $voucherCode = trim((string) $request->session()->get('checkout_voucher_code', ''));
        $legacyVoucherCodes = collect((array) $request->session()->get('checkout_voucher_codes', []))
            ->map(fn ($code) => mb_strtoupper(trim((string) $code)))
            ->filter()
            ->values();

        if ($voucherCode === '' && $legacyVoucherCodes->count() === 1) {
            $voucherCode = $legacyVoucherCodes->first();
            $request->session()->put('checkout_voucher_code', $voucherCode);
            $request->session()->forget('checkout_voucher_codes');
        } elseif ($legacyVoucherCodes->count() > 1) {
            $request->session()->forget('checkout_voucher_codes');
            $request->session()->flash('buyer_notice', 'Checkout now accepts one voucher code. Choose one code to continue.');
        }

        try {
            $preview = $this->checkoutService->preview($items, $voucherCode, $request->user());
        } catch (ValidationException $exception) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);

            return redirect()
                ->route('buyer.checkout')
                ->withErrors($exception->errors())
                ->withInput(['voucher_code' => $voucherCode]);
        }

        // Stock can change after an item is added to the cart. Keep the
        // checkout action disabled for an item that is no longer available;
        // placeOrder() still performs the authoritative locked stock check.
        $hasUnavailableStock = $items->contains(function ($item): bool {
            $variant = $item->productVariant;

            return ! $variant
                || ! $variant->is_active
                || (int) $variant->stock < (int) $item->quantity;
        });

        return view('Buyer.checkout', [
            'cartItems' => $items,
            'addresses' => $request->user()->addresses()->orderByDesc('is_default')->latest()->get(),
            'availableVouchers' => $this->checkoutService->availableVouchers($items, $request->user()),
            'logisticsProviders' => $this->checkoutService->availableLogisticsProviders(),
            'preview' => $preview,
            'voucherCode' => $voucherCode,
            'hasUnavailableStock' => $hasUnavailableStock,
        ]);
    }

    public function applyVoucher(Request $request): RedirectResponse
    {
        if ($request->boolean('clear_voucher')) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);

            return back()->with('buyer_notice', 'Voucher removed.');
        }

        $data = $request->validate([
            'voucher_code' => ['required', 'string', 'max:80'],
        ]);
        $code = mb_strtoupper(trim($data['voucher_code']));
        $selectedIds = array_map('intval', (array) $request->session()->get('checkout_cart_item_ids', []));
        $items = $this->cartService->selectedItems($request->user(), $selectedIds);

        if ($items->isEmpty()) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);

            throw ValidationException::withMessages([
                'voucher_code' => 'Your checkout selection expired. Return to your cart and choose your items again.',
            ]);
        }

        try {
            $this->checkoutService->preview($items, $code, $request->user());
        } catch (ValidationException $exception) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);
            throw $exception;
        }

        $request->session()->put('checkout_voucher_code', $code);
        $request->session()->forget('checkout_voucher_codes');

        return back()->with('buyer_notice', 'Voucher applied to the eligible shop.');
    }

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer'],
            'voucher_code' => ['nullable', 'string', 'max:80'],
            'voucher_codes' => ['nullable', 'array'],
            'voucher_codes.*' => ['nullable', 'string', 'max:80'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['cart_item_ids'])));
        $ownedIds = $this->cartService->selectedItems($request->user(), $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless(count($ownedIds) === count($ids), 403);

        $legacyVoucherCodes = collect((array) $request->input('voucher_codes', []))
            ->mapWithKeys(fn ($value, $key) => [(int) $key => mb_strtoupper(trim((string) $value))])
            ->filter()
            ->all();

        $voucherCode = mb_strtoupper(trim((string) ($data['voucher_code'] ?? '')));
        if ($voucherCode === '' && count($legacyVoucherCodes) > 1) {
            throw ValidationException::withMessages([
                'voucher_code' => 'Enter one voucher code for checkout.',
            ]);
        }

        $selectedItems = $this->cartService->selectedItems($request->user(), $ownedIds);
        if ($voucherCode !== '') {
            $request->session()->put('checkout_voucher_code', $voucherCode);
            $request->session()->forget('checkout_voucher_codes');
        } elseif ($legacyVoucherCodes !== []) {
            $this->checkoutService->preview($selectedItems, $legacyVoucherCodes, $request->user());
            $request->session()->put('checkout_voucher_codes', $legacyVoucherCodes);
            $request->session()->forget('checkout_voucher_code');
        } else {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);
        }

        $request->session()->put('checkout_cart_item_ids', $ownedIds);

        return redirect()->route('buyer.checkout');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where('user_id', $request->user()->id)],
            'logistics_center_id' => ['required', 'integer', Rule::exists('logistics_centers', 'id')->where('status', 'ACTIVE')],
            'payment_method' => ['required', Rule::in(['COD', 'ONLINE'])],
            'voucher_code' => ['nullable', 'string', 'max:80'],
            'voucher_codes' => ['nullable', 'array'],
            'voucher_codes.*' => ['nullable', 'string', 'max:80'],
        ], [
            'address_id.required' => 'Select a delivery address.',
            'address_id.exists' => 'The selected delivery address is no longer available.',
            'logistics_center_id.required' => 'Select a logistics provider.',
            'logistics_center_id.exists' => 'The selected logistics provider is no longer active.',
            'payment_method.required' => 'Select a payment method.',
            'payment_method.in' => 'Select a supported payment method.',
        ]);

        $selectedIds = array_map('intval', (array) $request->session()->get('checkout_cart_item_ids', []));
        if ($selectedIds === []) {
            throw ValidationException::withMessages([
                'cart' => 'Your checkout selection expired. Return to your cart or use Buy Now again.',
            ]);
        }

        $items = $this->cartService->selectedItems($request->user(), $selectedIds);
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'The selected product is no longer available in your cart. Choose the product again.',
            ]);
        }
        $address = Address::query()->where('user_id', $request->user()->id)->findOrFail((int) $data['address_id']);
        AddressCoordinateValidator::assertValid([
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ]);
        $legacyVoucherCodes = collect((array) $request->input('voucher_codes', []))
            ->mapWithKeys(fn ($value, $key) => [(int) $key => mb_strtoupper(trim((string) $value))])
            ->filter()
            ->all();
        $voucherCode = mb_strtoupper(trim((string) ($data['voucher_code'] ?? '')));

        if ($voucherCode === '' && count($legacyVoucherCodes) > 1) {
            throw ValidationException::withMessages([
                'voucher_code' => 'Enter one voucher code for checkout.',
            ]);
        }

        $voucherCode = $voucherCode !== ''
            ? $voucherCode
            : (count($legacyVoucherCodes) === 1 ? (string) reset($legacyVoucherCodes) : '');

        try {
            $order = $this->checkoutService->placeOrder(
                $request->user(),
                $address,
                $items,
                $data['payment_method'],
                $voucherCode,
                (int) $data['logistics_center_id'],
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('voucher_code', $exception->errors())) {
                $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);
            }

            throw $exception;
        }

        $request->session()->forget(['checkout_cart_item_ids', 'checkout_voucher_code', 'checkout_voucher_codes']);

        return redirect()
            ->route('buyer.orders.success', ['order' => $order->order_number])
            ->with('buyer_notice', 'Order placed successfully.');
    }
}
