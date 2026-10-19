<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Seller\Voucher;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Support\AddressCoordinateValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
            $request->session()->forget(['checkout_cart_item_ids', 'checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source', 'checkout_reward_points']);

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
        $defaultAddress = $addresses->firstWhere('is_default', true) ?? $addresses->first();
        $selectedAddress = $addresses->firstWhere('id', (int) old('address_id', $request->query('address_id'))) ?? $defaultAddress;
        $previewAddress = $selectedAddress;
        $selectedProviderId = (int) old('logistics_center_id', $request->query('logistics_center_id', 0));
        $selectedProtectionIds = array_map(
            'intval',
            (array) old('protection_item_ids', $request->session()->get('checkout_protection_item_ids', [])),
        );
        $useRewardPoints = (bool) old('use_reward_points', (int) $request->session()->get('checkout_reward_points', 0) > 0);
        $selectedRewardPoints = $useRewardPoints
            ? (int) old('reward_points', $request->session()->get('checkout_reward_points', 0))
            : 0;
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
                rewardPointsToRedeem: $selectedRewardPoints,
            );
        } catch (ValidationException $exception) {
            $rewardPointsInvalid = array_key_exists('reward_points', $exception->errors());
            if ($rewardPointsInvalid) {
                $selectedRewardPoints = 0;
                $request->session()->put('checkout_reward_points', 0);
            }
            if (! $rewardPointsInvalid) {
                $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source', 'checkout_protection_item_ids']);
            }

            // Do not redirect back to this same GET action when the preview
            // fails. A persistent routing/protection error would otherwise
            // create an infinite /buyer/checkout redirect loop. Render the
            // page with a neutral preview so the buyer can see the reason and
            // correct the address or cart selection.
            $previewError = collect($exception->errors())->flatten()->filter()->first()
                ?: 'Checkout could not be prepared. Review your address and cart items before trying again.';
            if (array_intersect(['address_id', 'latitude', 'longitude', 'logistics_center_id'], array_keys($exception->errors())) !== []) {
                $previewError = 'Delivery is not available for this address yet. Choose another saved address or update its delivery details.';
            }
            if (! $rewardPointsInvalid) {
                $voucherCode = '';
                $selectedProtectionIds = [];
            }
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
                        $voucherCode,
                        $request->user(),
                        null,
                        null,
                        $selectedProtectionIds,
                        rewardPointsToRedeem: 0,
                    );
                } catch (ValidationException $fallbackException) {
                    $checkoutBlocked = true;
                    $previewError = collect($fallbackException->errors())->flatten()->filter()->first()
                        ?: 'Checkout could not be prepared. Return to your cart and review the selected items.';
                }
            }
        }

        $availableVouchers = $this->checkoutService->availableVouchers($items, $request->user());
        $sessionVoucherSource = (string) $request->session()->get('checkout_voucher_source', '');
        $sessionVoucherCode = mb_strtoupper(trim((string) $request->session()->get('checkout_voucher_code', '')));
        $voucherSource = $voucherCode !== ''
            && $sessionVoucherCode === mb_strtoupper($voucherCode)
            && in_array($sessionVoucherSource, ['seller', 'platform'], true)
                ? $sessionVoucherSource
                : ($this->isPlatformVoucherCode($voucherCode) ? 'platform' : 'seller');
        if ($voucherCode !== '') {
            $request->session()->put('checkout_voucher_source', $voucherSource);
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

        $checkoutAttemptKey = (string) old('checkout_attempt_key', Str::uuid());
        $priceSnapshot = $items->mapWithKeys(fn ($item): array => [
            (string) $item->id => number_format((float) $item->productVariant?->final_price, 2, '.', ''),
        ])->all();
        $priceSnapshots = (array) $request->session()->get('checkout_price_snapshots', []);
        $priceSnapshots[$checkoutAttemptKey] = $priceSnapshot;
        $request->session()->put('checkout_price_snapshots', $priceSnapshots);

        $logisticsProviders = $this->checkoutService->availableLogisticsProviders();
        $selectedProvider = $logisticsProviders->firstWhere('id', $selectedProviderId) ?? $logisticsProviders->first();
        $deliveryAvailability = [];
        foreach ($addresses as $address) {
            if ($automaticRouting) {
                $deliveryAvailability[(string) $address->id] = $this->checkoutService->deliveryAvailability($items, $address);
                continue;
            }

            foreach ($logisticsProviders as $provider) {
                $deliveryAvailability[$address->id.':'.$provider->id] = $this->checkoutService->deliveryAvailability($items, $address, $provider);
            }
        }

        return view('Buyer.checkout', [
            'cartItems' => $items,
            'addresses' => $addresses,
            'availableVouchers' => $availableVouchers,
            'logisticsProviders' => $logisticsProviders,
            'preview' => $preview,
            'voucherCode' => $voucherCode,
            'voucherSource' => $voucherSource,
            'hasUnavailableStock' => $hasUnavailableStock,
            'automaticRouting' => $automaticRouting,
            'selectedProtectionIds' => $selectedProtectionIds,
            'selectedRewardPoints' => $selectedRewardPoints,
            'sellerNotes' => $sellerNotes,
            'previewError' => $previewError,
            'checkoutBlocked' => $checkoutBlocked,
            'checkoutAttemptKey' => $checkoutAttemptKey,
            'selectedAddressId' => $selectedAddress?->id,
            'selectedProviderId' => $selectedProvider?->id,
            'deliveryAvailability' => $deliveryAvailability,
            'priceChangeNotice' => $request->session()->pull('checkout_price_change_notice'),
        ]);
    }

    public function applyVoucher(Request $request): RedirectResponse
    {
        $noteData = $request->validate([
            'seller_notes' => ['nullable', 'array'],
            'seller_notes.*' => ['nullable', 'string', 'max:200'],
            'use_reward_points' => ['nullable', 'boolean'],
            'reward_points' => ['nullable', 'integer', 'min:0'],
        ]);
        $requestedRewardPoints = $request->boolean('use_reward_points')
            ? (int) ($noteData['reward_points'] ?? 0)
            : 0;
        $request->session()->put('checkout_reward_points', $requestedRewardPoints);
        $selectedIds = array_map('intval', (array) $request->session()->get('checkout_cart_item_ids', []));
        $items = $this->cartService->selectedItems($request->user(), $selectedIds);
        $sellerNotes = $this->checkoutService->normalizeSellerNotes($items, (array) ($noteData['seller_notes'] ?? []));
        $request->session()->put('checkout_seller_notes', $sellerNotes);

        if ($request->boolean('clear_voucher')) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);

            return back()->with('buyer_notice', 'Voucher removed.');
        }

        $platformSelection = $request->filled('platform_voucher_code');
        $data = $request->validate($platformSelection
            ? ['platform_voucher_code' => ['required', 'string', 'max:80']]
            : ['voucher_code' => ['required', 'string', 'max:80']]);
        $code = mb_strtoupper(trim((string) ($platformSelection ? $data['platform_voucher_code'] : $data['voucher_code'])));
        $errorField = $platformSelection ? 'platform_voucher_code' : 'voucher_code';

        if ($platformSelection && ! $this->isPlatformVoucherCode($code)) {
            throw ValidationException::withMessages([
                'platform_voucher_code' => 'That LIKHAE voucher is no longer available. Choose another voucher.',
            ]);
        }
        if (! $platformSelection && $this->isPlatformVoucherCode($code)) {
            throw ValidationException::withMessages([
                'voucher_code' => 'Choose LIKHAE vouchers from the picker in Order Summary.',
            ]);
        }

        if ($items->isEmpty()) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source', 'checkout_reward_points']);

            throw ValidationException::withMessages([
                $errorField => 'Your checkout selection expired. Return to your cart and choose your items again.',
            ]);
        }

        try {
            $this->checkoutService->preview($items, $code, $request->user(), rewardPointsToRedeem: $requestedRewardPoints);
        } catch (ValidationException $exception) {
            if (! array_key_exists('reward_points', $exception->errors())) {
                $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);
            }
            $errors = $exception->errors();
            if ($errorField !== 'voucher_code' && array_key_exists('voucher_code', $errors)) {
                throw ValidationException::withMessages([$errorField => $errors['voucher_code']]);
            }
            throw $exception;
        }

        $request->session()->put('checkout_voucher_code', $code);
        $request->session()->put('checkout_voucher_source', $platformSelection ? 'platform' : 'seller');
        $request->session()->forget('checkout_voucher_codes');

        return back()->with('buyer_notice', $platformSelection ? 'LIKHAE voucher applied to your order.' : 'Seller voucher applied to the eligible shop.');
    }

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer'],
            'voucher_code' => ['nullable', 'string', 'max:80'],
            'platform_voucher_code' => ['nullable', 'string', 'max:80'],
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

        $platformSelection = filled($data['platform_voucher_code'] ?? null);
        if ($platformSelection && filled($data['voucher_code'] ?? null)) {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);
            throw ValidationException::withMessages([
                'voucher_code' => 'Choose one seller voucher or one LIKHAE voucher.',
            ]);
        }

        $voucherCode = mb_strtoupper(trim((string) ($platformSelection
            ? $data['platform_voucher_code']
            : ($data['voucher_code'] ?? ''))));
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
            $platformCodes = collect($this->checkoutService->availableVouchers($selectedItems, $request->user())->get('platform', []))
                ->pluck('code')
                ->map(fn ($code): string => mb_strtoupper((string) $code));
            $isPlatformCode = $platformCodes->contains($voucherCode);
            if ($platformSelection && ! $isPlatformCode) {
                $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);
                throw ValidationException::withMessages([
                    'platform_voucher_code' => 'That LIKHAE voucher is no longer available. Choose another voucher.',
                ]);
            }
            if (! $platformSelection && $isPlatformCode) {
                $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);
                throw ValidationException::withMessages([
                    'voucher_code' => 'Choose LIKHAE vouchers from the picker in Order Summary.',
                ]);
            }

            $request->session()->put('checkout_voucher_code', $voucherCode);
            $request->session()->put('checkout_voucher_source', $platformSelection ? 'platform' : 'seller');
            $request->session()->forget('checkout_voucher_codes');
        } elseif ($legacyVoucherCodes !== []) {
            $this->checkoutService->preview($selectedItems, $legacyVoucherCodes, $request->user());
            $request->session()->put('checkout_voucher_codes', $legacyVoucherCodes);
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_source']);
        } else {
            $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);
        }

        $request->session()->put('checkout_cart_item_ids', $ownedIds);
        $request->session()->forget('checkout_reward_points');
        $request->session()->forget('checkout_seller_notes');
        $request->session()->forget('checkout_price_snapshots');

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
            'applied_voucher_code' => ['nullable', 'string', 'max:80'],
            'voucher_codes' => ['nullable', 'array'],
            'voucher_codes.*' => ['nullable', 'string', 'max:80'],
            'protection_item_ids' => ['nullable', 'array'],
            'protection_item_ids.*' => ['integer'],
            'seller_notes' => ['nullable', 'array'],
            'seller_notes.*' => ['nullable', 'string', 'max:200'],
            'use_reward_points' => ['nullable', 'boolean'],
            'reward_points' => ['nullable', 'integer', 'min:0'],
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
        $voucherValue = array_key_exists('applied_voucher_code', $data)
            ? $data['applied_voucher_code']
            : ($data['voucher_code'] ?? '');
        $voucherCode = mb_strtoupper(trim((string) $voucherValue));

        if ($voucherCode === '' && count($legacyVoucherCodes) > 1) {
            throw ValidationException::withMessages([
                'voucher_code' => 'Enter one voucher code for checkout.',
            ]);
        }

        $voucherCode = $voucherCode !== ''
            ? $voucherCode
            : (count($legacyVoucherCodes) === 1 ? (string) reset($legacyVoucherCodes) : '');
        $rewardPointsToRedeem = $request->boolean('use_reward_points')
            ? (int) ($data['reward_points'] ?? 0)
            : 0;

        if ($voucherCode !== '' && $this->isPlatformVoucherCode($voucherCode)) {
            $sessionVoucherCode = mb_strtoupper(trim((string) $request->session()->get('checkout_voucher_code', '')));
            $platformPickerSelectedCode = $request->session()->get('checkout_voucher_source') === 'platform'
                ? $sessionVoucherCode
                : '';
            if ($platformPickerSelectedCode !== $voucherCode) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'Choose LIKHAE vouchers from the picker in Order Summary.',
                ]);
            }
        }

        $priceSnapshots = (array) $request->session()->get('checkout_price_snapshots', []);
        $expectedPriceSnapshot = $priceSnapshots[$data['checkout_attempt_key']] ?? null;

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
                is_array($expectedPriceSnapshot) ? $expectedPriceSnapshot : null,
                $rewardPointsToRedeem,
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('voucher_code', $exception->errors())) {
                $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);
            }
            if (array_key_exists('price_changed', $exception->errors())) {
                unset($priceSnapshots[$data['checkout_attempt_key']]);
                $request->session()->put('checkout_price_snapshots', $priceSnapshots);
                $request->session()->flash('checkout_price_change_notice', 'A product price changed while you were reviewing checkout. The updated prices and total are shown below; place the order again to confirm them.');
            }

            throw $exception;
        }

        $request->session()->forget(['checkout_cart_item_ids', 'checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source', 'checkout_protection_item_ids', 'checkout_reward_points', 'checkout_seller_notes', 'checkout_price_snapshots']);

        return redirect()
            ->route('buyer.orders.success', ['order' => $order->order_number])
            ->with('buyer_notice', 'Order placed successfully.');
    }

    private function containsVacationSeller(\Illuminate\Support\Collection $items): bool
    {
        return $items->contains(fn ($item): bool => (bool) $item->productVariant?->product?->sellerProfile?->vacation_mode);
    }

    private function isPlatformVoucherCode(string $code): bool
    {
        if (! Schema::hasColumn('vouchers', 'platform_code_key')) {
            return false;
        }

        return Voucher::query()
            ->whereNull('seller_profile_id')
            ->whereNotNull('platform_code_key')
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->exists();
    }
}
