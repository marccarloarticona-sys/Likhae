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
            $request->session()->forget(['checkout_cart_item_ids', 'checkout_reward_points']);
            $this->storeVoucherSelection($request, ['seller' => [], 'platform' => '', 'shipping' => '']);

            return redirect()->route('buyer.cart')->with('buyer_notice', 'A seller in your selected items is on vacation and is not accepting purchases. Remove those items to continue.');
        }

        $availableVouchers = $this->checkoutService->availableVouchers($items, $request->user());
        $voucherSelection = $this->currentVoucherSelection($request, $items);

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
                $voucherSelection,
                $request->user(),
                null,
                $previewAddress,
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
                $errors = $exception->errors();
                foreach (array_keys($errors) as $field) {
                    if ($field === 'platform_voucher_code') {
                        $voucherSelection['platform'] = '';
                    } elseif ($field === 'shipping_voucher_code') {
                        $voucherSelection['shipping'] = '';
                    } elseif (str_starts_with($field, 'seller_voucher_codes.')) {
                        unset($voucherSelection['seller'][(int) substr($field, strlen('seller_voucher_codes.'))]);
                    } elseif ($field === 'voucher_code' || $field === 'voucher_codes') {
                        $voucherSelection = ['seller' => [], 'platform' => '', 'shipping' => ''];
                    }
                }
                $this->storeVoucherSelection($request, $voucherSelection);
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
                        ['seller' => [], 'platform' => '', 'shipping' => ''],
                        $request->user(),
                        null,
                        $previewAddress,
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

        return view('buyer.checkout', [
            'cartItems' => $items,
            'addresses' => $addresses,
            'availableVouchers' => $availableVouchers,
            'logisticsProviders' => $logisticsProviders,
            'preview' => $preview,
            'sellerVoucherCodes' => $voucherSelection['seller'],
            'platformVoucherCode' => $voucherSelection['platform'],
            'shippingVoucherCode' => $voucherSelection['shipping'],
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

        $data = $request->validate([
            'seller_voucher_codes' => ['nullable', 'array'],
            'seller_voucher_codes.*' => ['nullable', 'string', 'max:80'],
            'platform_voucher_code' => ['nullable', 'string', 'max:80'],
            'shipping_voucher_code' => ['nullable', 'string', 'max:80'],
            'voucher_code' => ['nullable', 'string', 'max:80'],
            'remove_seller_voucher' => ['nullable', 'integer'],
            'remove_platform_voucher' => ['nullable', 'boolean'],
            'remove_shipping_voucher' => ['nullable', 'boolean'],
            'clear_voucher' => ['nullable', 'boolean'],
        ]);

        if ($items->isEmpty()) {
            $this->storeVoucherSelection($request, ['seller' => [], 'platform' => '', 'shipping' => '']);
            $request->session()->forget('checkout_reward_points');

            throw ValidationException::withMessages([
                'voucher_code' => 'Your checkout selection expired. Return to your cart and choose your items again.',
            ]);
        }

        $selection = $this->currentVoucherSelection($request, $items);
        if ($request->boolean('clear_voucher')) {
            $selection = ['seller' => [], 'platform' => '', 'shipping' => ''];
        } else {
            if ($request->filled('remove_seller_voucher')) {
                unset($selection['seller'][(int) $data['remove_seller_voucher']]);
            }
            if ($request->boolean('remove_platform_voucher')) {
                $selection['platform'] = '';
            }
            if ($request->boolean('remove_shipping_voucher')) {
                $selection['shipping'] = '';
            }
            foreach ((array) ($data['seller_voucher_codes'] ?? []) as $sellerId => $code) {
                $sellerId = (int) $sellerId;
                $code = mb_strtoupper(trim((string) $code));
                if ($code === '') {
                    unset($selection['seller'][$sellerId]);
                } else {
                    $selection['seller'][$sellerId] = $code;
                }
            }
            if (filled($data['platform_voucher_code'] ?? null)) {
                $selection['platform'] = mb_strtoupper(trim((string) $data['platform_voucher_code']));
            }
            if (filled($data['shipping_voucher_code'] ?? null)) {
                $selection['shipping'] = mb_strtoupper(trim((string) $data['shipping_voucher_code']));
            }
            if (filled($data['voucher_code'] ?? null)) {
                $code = mb_strtoupper(trim((string) $data['voucher_code']));
                $available = $this->checkoutService->availableVouchers($items, $request->user())->except(['platform', 'shipping']);
                $matches = $available->flatMap(fn ($vouchers, $sellerId) => collect($vouchers)
                    ->filter(fn (Voucher $voucher): bool => mb_strtoupper((string) $voucher->code) === $code)
                    ->map(fn (Voucher $voucher) => (int) $sellerId));
                if ($matches->count() !== 1) {
                    throw ValidationException::withMessages([
                        'voucher_code' => $matches->isEmpty()
                            ? 'That Seller voucher is not available for the selected items.'
                            : 'This code is available at more than one selected shop. Choose it from the correct Seller section.',
                    ]);
                }
                $selection['seller'][$matches->first()] = $code;
            }
        }

        ksort($selection['seller']);
        $this->checkoutService->preview($items, $selection, $request->user(), rewardPointsToRedeem: $requestedRewardPoints);
        $this->storeVoucherSelection($request, $selection);

        $notice = $request->boolean('remove_platform_voucher')
            ? 'Platform voucher removed.'
            : ($request->boolean('remove_shipping_voucher') ? 'Shipping voucher removed.'
                : ($request->filled('remove_seller_voucher') ? 'Seller voucher removed.' : 'Voucher selection updated.'));

        return back()->with('buyer_notice', $notice);
    }

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cart_item_ids' => ['required', 'array', 'min:1'],
            'cart_item_ids.*' => ['required', 'integer'],
            'voucher_code' => ['nullable', 'string', 'max:80'],
            'platform_voucher_code' => ['nullable', 'string', 'max:80'],
            'seller_voucher_codes' => ['nullable', 'array'],
            'seller_voucher_codes.*' => ['nullable', 'string', 'max:80'],
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
        $sellerVoucherCodes = collect((array) ($data['seller_voucher_codes'] ?? []))
            ->mapWithKeys(fn ($value, $key) => [(int) $key => mb_strtoupper(trim((string) $value))])
            ->filter()
            ->all();
        $sellerVoucherCodes = array_replace($legacyVoucherCodes, $sellerVoucherCodes);
        $platformVoucherCode = mb_strtoupper(trim((string) ($data['platform_voucher_code'] ?? '')));

        $selectedItems = $this->cartService->selectedItems($request->user(), $ownedIds);
        $request->session()->forget(['checkout_seller_voucher_codes', 'checkout_platform_voucher_code', 'checkout_shipping_voucher_code']);
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
        $typedSellerVoucherCode = mb_strtoupper(trim((string) ($data['voucher_code'] ?? '')));
        if ($typedSellerVoucherCode !== '') {
            $platformCodes = collect($this->checkoutService->availableVouchers($selectedItems, $request->user())->get('platform', []))
                ->pluck('code')
                ->map(fn ($code): string => mb_strtoupper((string) $code));
            if ($platformCodes->contains($typedSellerVoucherCode)) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'Choose LIKHAE vouchers from the picker in Order Summary.',
                ]);
            }

            $sellerVoucherMatches = $this->checkoutService->availableVouchers($selectedItems, $request->user())
                ->except(['platform', 'shipping'])
                ->flatMap(fn ($vouchers, $sellerId) => collect($vouchers)
                    ->filter(fn (Voucher $voucher): bool => mb_strtoupper((string) $voucher->code) === $typedSellerVoucherCode)
                    ->map(fn (Voucher $voucher): int => (int) $sellerId));
            if ($sellerVoucherMatches->count() !== 1) {
                throw ValidationException::withMessages([
                    'voucher_code' => $sellerVoucherMatches->isEmpty()
                        ? 'That Seller voucher is not available for the selected items.'
                        : 'This code is available at more than one selected shop. Choose it from the correct Seller section.',
                ]);
            }
            $sellerVoucherCodes[$sellerVoucherMatches->first()] = $typedSellerVoucherCode;
        }

        ksort($sellerVoucherCodes);
        $voucherSelection = [
            'seller' => $sellerVoucherCodes,
            'platform' => $platformVoucherCode,
            'shipping' => '',
        ];
        $this->checkoutService->preview($selectedItems, $voucherSelection, $request->user());
        $this->storeVoucherSelection($request, $voucherSelection);

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
        $voucherSelection = $this->currentVoucherSelection($request, $selectedItems);
        $rewardPointsToRedeem = $request->boolean('use_reward_points')
            ? (int) ($data['reward_points'] ?? 0)
            : 0;

        $priceSnapshots = (array) $request->session()->get('checkout_price_snapshots', []);
        $expectedPriceSnapshot = $priceSnapshots[$data['checkout_attempt_key']] ?? null;

        try {
            $order = $this->checkoutService->placeOrder(
                $request->user(),
                (int) $data['address_id'],
                $selectedIds,
                $data['payment_method'],
                $voucherSelection,
                isset($data['logistics_center_id']) ? (int) $data['logistics_center_id'] : null,
                array_map('intval', (array) ($data['protection_item_ids'] ?? [])),
                (array) ($data['seller_notes'] ?? []),
                $data['checkout_attempt_key'],
                is_array($expectedPriceSnapshot) ? $expectedPriceSnapshot : null,
                $rewardPointsToRedeem,
            );
        } catch (ValidationException $exception) {
            foreach (array_keys($exception->errors()) as $field) {
                if ($field === 'platform_voucher_code') {
                    $voucherSelection['platform'] = '';
                } elseif ($field === 'shipping_voucher_code') {
                    $voucherSelection['shipping'] = '';
                } elseif (str_starts_with($field, 'seller_voucher_codes.')) {
                    unset($voucherSelection['seller'][(int) substr($field, strlen('seller_voucher_codes.'))]);
                } elseif ($field === 'voucher_code') {
                    $voucherSelection = ['seller' => [], 'platform' => '', 'shipping' => ''];
                }
            }
            if (array_key_exists('platform_voucher_code', $exception->errors())
                || array_key_exists('shipping_voucher_code', $exception->errors())
                || collect(array_keys($exception->errors()))->contains(fn ($field): bool => str_starts_with($field, 'seller_voucher_codes.'))
                || array_key_exists('voucher_code', $exception->errors())) {
                $this->storeVoucherSelection($request, $voucherSelection);
            }
            if (array_key_exists('price_changed', $exception->errors())) {
                unset($priceSnapshots[$data['checkout_attempt_key']]);
                $request->session()->put('checkout_price_snapshots', $priceSnapshots);
                $request->session()->flash('checkout_price_change_notice', 'A product price changed while you were reviewing checkout. The updated prices and total are shown below; place the order again to confirm them.');
            }

            throw $exception;
        }

        $request->session()->forget(['checkout_cart_item_ids', 'checkout_protection_item_ids', 'checkout_reward_points', 'checkout_seller_notes', 'checkout_price_snapshots']);
        $this->storeVoucherSelection($request, ['seller' => [], 'platform' => '', 'shipping' => '']);

        return redirect()
            ->route('buyer.orders.success', ['order' => $order->order_number])
            ->with('buyer_notice', 'Order placed successfully.');
    }

    private function containsVacationSeller(\Illuminate\Support\Collection $items): bool
    {
        return $items->contains(fn ($item): bool => (bool) $item->productVariant?->product?->sellerProfile?->vacation_mode);
    }

    /** @return array{seller:array<int,string>,platform:string,shipping:string} */
    private function currentVoucherSelection(Request $request, \Illuminate\Support\Collection $items): array
    {
        $session = $request->session();
        $hasNewSelection = $session->has('checkout_seller_voucher_codes')
            || $session->has('checkout_platform_voucher_code')
            || $session->has('checkout_shipping_voucher_code');
        $selection = [
            'seller' => (array) $session->get('checkout_seller_voucher_codes', []),
            'platform' => (string) $session->get('checkout_platform_voucher_code', ''),
            'shipping' => (string) $session->get('checkout_shipping_voucher_code', ''),
        ];

        if (! $hasNewSelection) {
            $legacySellerCodes = (array) $session->get('checkout_voucher_codes', []);
            foreach ($legacySellerCodes as $sellerId => $code) {
                $selection['seller'][(int) $sellerId] = mb_strtoupper(trim((string) $code));
            }

            $legacyCode = mb_strtoupper(trim((string) $session->get('checkout_voucher_code', '')));
            if ($legacyCode !== '') {
                $source = (string) $session->get('checkout_voucher_source', 'seller');
                if ($source === 'platform' && Schema::hasColumn('vouchers', 'platform_code_key')) {
                    $voucher = Voucher::query()
                        ->whereNull('seller_profile_id')
                        ->whereRaw('UPPER(code) = ?', [$legacyCode])
                        ->first();
                    if ($voucher && strtoupper((string) ($voucher->benefit_scope ?? 'PRODUCT')) === 'SHIPPING') {
                        $selection['shipping'] = $legacyCode;
                    } else {
                        $selection['platform'] = $legacyCode;
                    }
                } else {
                    $sellerIds = $items->map(fn ($item): int => (int) ($item->productVariant?->product?->seller_profile_id ?? 0))
                        ->filter()
                        ->unique()
                        ->values();
                    $voucher = Voucher::query()
                        ->whereIn('seller_profile_id', $sellerIds)
                        ->whereRaw('UPPER(code) = ?', [$legacyCode])
                        ->first();
                    $sellerId = (int) ($voucher?->seller_profile_id ?? ($sellerIds->count() === 1 ? $sellerIds->first() : 0));
                    if ($sellerId > 0) {
                        $selection['seller'][$sellerId] = $legacyCode;
                    }
                }
            }
        }

        $selection['seller'] = collect($selection['seller'])
            ->mapWithKeys(fn ($code, $sellerId): array => [(int) $sellerId => mb_strtoupper(trim((string) $code))])
            ->filter()
            ->all();
        $selection['platform'] = mb_strtoupper(trim((string) $selection['platform']));
        $selection['shipping'] = mb_strtoupper(trim((string) $selection['shipping']));
        ksort($selection['seller']);
        $this->storeVoucherSelection($request, $selection);

        return $selection;
    }

    /** @param array{seller?:array<int|string,string>,platform?:string,shipping?:string} $selection */
    private function storeVoucherSelection(Request $request, array $selection): void
    {
        $sellerCodes = collect((array) ($selection['seller'] ?? []))
            ->mapWithKeys(fn ($code, $sellerId): array => [(int) $sellerId => mb_strtoupper(trim((string) $code))])
            ->filter()
            ->all();
        ksort($sellerCodes);
        $request->session()->put('checkout_seller_voucher_codes', $sellerCodes);
        $request->session()->put('checkout_platform_voucher_code', mb_strtoupper(trim((string) ($selection['platform'] ?? ''))));
        $request->session()->put('checkout_shipping_voucher_code', mb_strtoupper(trim((string) ($selection['shipping'] ?? ''))));
        $request->session()->forget(['checkout_voucher_code', 'checkout_voucher_codes', 'checkout_voucher_source']);
    }
}
