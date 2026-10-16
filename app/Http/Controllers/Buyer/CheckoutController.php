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
use Illuminate\Support\Str;
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

        if ($this->containsVacationSeller($items)) {
            $request->session()->forget(['checkout_cart_item_ids', 'checkout_voucher_code', 'checkout_voucher_codes']);

            return redirect()->route('buyer.cart')->with('buyer_notice', 'A seller in your selected items is on vacation and is not accepting purchases. Remove those items to continue.');
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

        $addresses = $request->user()->addresses()->orderByDesc('is_default')->latest()->get();
        $automaticRouting = $this->checkoutService->automaticRoutingReady();
        $previewAddress = $addresses->firstWhere('is_default', true) ?? $addresses->first();
        $selectedProtectionIds = array_map(
            'intval',
            (array) old('protection_item_ids', $request->session()->get('checkout_protection_item_ids', [])),
        );
        $sellerNotes = old('seller_notes', $request->session()->get('checkout_seller_notes', []));
        $sellerNotes = is_array($sellerNotes) ? $sellerNotes : [];
        $request->session()->forget('checkout_seller_notes');
        $previewError = null;
        $checkoutBlocked = false;

        try {
            $preview = $this->checkoutService->preview(
                $items,
                $voucherCode,
                $request->user(),
                null,
                $automaticRouting ? $previewAddress : null,
                $selectedProtectionIds,
            );
        } catch (ValidationException $exception) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_protection_item_ids']);

            // Do not redirect back to this same GET action when the preview
            // fails. A persistent routing/protection error would otherwise
            // create an infinite /buyer/checkout redirect loop. Render the
            // page with a neutral preview so the buyer can see the reason and
            // correct the address or cart selection.
            $previewError = collect($exception->errors())->flatten()->filter()->first()
                ?: 'Checkout could not be prepared. Review your address and cart items before trying again.';
            $voucherCode = '';
            $selectedProtectionIds = [];
            $checkoutBlocked = array_key_exists('cart', $exception->errors());
            $preview = [
                'groups' => collect(),
                'subtotal' => 0.0,
                'discount_total' => 0.0,
                'shipping_total' => 0.0,
                'protection_total' => 0.0,
                'grand_total' => 0.0,
            ];

            if (! $checkoutBlocked) {
                try {
                    $preview = $this->checkoutService->preview(
                        $items,
                        '',
                        $request->user(),
                        null,
                        null,
                        [],
                    );
                } catch (ValidationException $fallbackException) {
                    $checkoutBlocked = true;
                    $previewError = collect($fallbackException->errors())->flatten()->filter()->first()
                        ?: 'Checkout could not be prepared. Return to your cart and review the selected items.';
                }
            }
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
            'addresses' => $addresses,
            'availableVouchers' => $this->checkoutService->availableVouchers($items, $request->user()),
            'logisticsProviders' => $this->checkoutService->availableLogisticsProviders(),
            'preview' => $preview,
            'voucherCode' => $voucherCode,
            'hasUnavailableStock' => $hasUnavailableStock,
            'automaticRouting' => $automaticRouting,
            'selectedProtectionIds' => $selectedProtectionIds,
            'sellerNotes' => $sellerNotes,
            'previewError' => $previewError,
            'checkoutBlocked' => $checkoutBlocked,
            'checkoutAttemptKey' => old('checkout_attempt_key', (string) Str::uuid()),
        ]);
    }

    public function applyVoucher(Request $request): RedirectResponse
    {
        $noteData = $request->validate([
            'seller_notes' => ['nullable', 'array'],
            'seller_notes.*' => ['nullable', 'string', 'max:500'],
        ]);
        $selectedIds = array_map('intval', (array) $request->session()->get('checkout_cart_item_ids', []));
        $items = $this->cartService->selectedItems($request->user(), $selectedIds);
        $sellerNotes = $this->checkoutService->normalizeSellerNotes($items, (array) ($noteData['seller_notes'] ?? []));
        $request->session()->put('checkout_seller_notes', $sellerNotes);

        if ($request->boolean('clear_voucher')) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);

            return back()->with('buyer_notice', 'Voucher removed.');
        }

        $data = $request->validate([
            'voucher_code' => ['required', 'string', 'max:80'],
        ]);
        $code = mb_strtoupper(trim($data['voucher_code']));

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
            'protection_item_ids' => ['nullable', 'array'],
            'protection_item_ids.*' => ['integer'],
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
        if ($this->containsVacationSeller($selectedItems)) {
            throw ValidationException::withMessages([
                'cart_item_ids' => 'A seller in your selected items is on vacation and is not accepting purchases. Remove those items to continue.',
            ]);
        }

        $protectionIds = collect((array) ($data['protection_item_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->intersect($ownedIds)
            ->values()
            ->all();
        $request->session()->put('checkout_protection_item_ids', $protectionIds);
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
        $request->session()->forget('checkout_seller_notes');

        return redirect()->route('buyer.checkout');
    }

    public function store(Request $request): RedirectResponse
    {
        $automaticRouting = $this->checkoutService->automaticRoutingReady();
        $logisticsRules = $automaticRouting
            ? ['nullable', 'integer']
            : ['required', 'integer', Rule::exists('logistics_centers', 'id')->where('status', 'ACTIVE')];
        $data = $request->validate([
            'checkout_attempt_key' => ['required', 'uuid'],
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer', 'distinct'],
            'address_id' => ['required', 'integer'],
            'logistics_center_id' => $logisticsRules,
            'payment_method' => ['required', Rule::in(['COD'])],
            'voucher_code' => ['nullable', 'string', 'max:80'],
            'voucher_codes' => ['nullable', 'array'],
            'voucher_codes.*' => ['nullable', 'string', 'max:80'],
            'protection_item_ids' => ['nullable', 'array'],
            'protection_item_ids.*' => ['integer'],
            'seller_notes' => ['nullable', 'array'],
            'seller_notes.*' => ['nullable', 'string', 'max:500'],
        ], [
            'address_id.required' => 'Select a delivery address.',
            'address_id.exists' => 'The selected delivery address is no longer available.',
            'logistics_center_id.required' => 'Select a logistics provider.',
            'logistics_center_id.exists' => 'The selected logistics provider is no longer active.',
            'payment_method.required' => 'Select a payment method.',
            'payment_method.in' => 'Cash on Delivery is the only payment method available for new orders.',
        ]);

        $selectedIds = array_values(array_unique(array_map('intval', (array) ($data['cart_item_ids'] ?? []))));
        $selectedItems = $this->cartService->selectedItems($request->user(), $selectedIds);
        if ($this->containsVacationSeller($selectedItems)) {
            throw ValidationException::withMessages([
                'cart_item_ids' => 'A seller in your selected items is on vacation and is not accepting purchases. Remove those items to continue.',
            ]);
        }

        $sessionIds = array_values(array_unique(array_map('intval', (array) $request->session()->get('checkout_cart_item_ids', []))));
        sort($selectedIds);
        sort($sessionIds);
        if ($sessionIds !== [] && $selectedIds !== $sessionIds) {
            throw ValidationException::withMessages([
                'cart' => 'Your checkout selection changed. Return to your cart and choose your items again.',
            ]);
        }
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
                (int) $data['address_id'],
                $selectedIds,
                $data['payment_method'],
                $voucherCode,
                isset($data['logistics_center_id']) ? (int) $data['logistics_center_id'] : null,
                array_map('intval', (array) ($data['protection_item_ids'] ?? [])),
                (array) ($data['seller_notes'] ?? []),
                $data['checkout_attempt_key'],
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('voucher_code', $exception->errors())) {
                $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes']);
            }

            throw $exception;
        }

        $request->session()->forget(['checkout_cart_item_ids', 'checkout_voucher_code', 'checkout_voucher_codes', 'checkout_protection_item_ids', 'checkout_seller_notes']);

        return redirect()
            ->route('buyer.orders.success', ['order' => $order->order_number])
            ->with('buyer_notice', 'Order placed successfully.');
    }

    private function containsVacationSeller(\Illuminate\Support\Collection $items): bool
    {
        return $items->contains(fn ($item): bool => (bool) $item->productVariant?->product?->sellerProfile?->vacation_mode);
    }
}
