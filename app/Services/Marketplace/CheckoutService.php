<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Payment;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Finance\CodCollectionService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\PsgcLogisticsRoutingService;
use App\Services\Fulfillment\ShipmentTransferService;
use App\Services\Protection\FragileProtectionRuleService;
use App\Support\AddressCoordinateValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly LogisticsSchemaReadiness $schema,
        private readonly PsgcLogisticsRoutingService $routing,
        private readonly ShipmentTransferService $transfers,
        private readonly FragileProtectionRuleService $protection,
    ) {}

    public function automaticRoutingReady(): bool
    {
        return $this->schema->automaticRoutingReady();
    }

    public function availableVouchers(Collection $items, ?User $buyer = null): Collection
    {
        $sellerVoucherGroups = $items
            ->filter(function (CartItem $item): bool {
                $item->loadMissing('productVariant.product');

                return (int) ($item->productVariant?->product?->seller_profile_id ?? 0) > 0;
            })
            ->groupBy(fn (CartItem $item) => (int) $item->productVariant->product->seller_profile_id)
            ->map(function (Collection $sellerItems, int $sellerId) use ($buyer): Collection {
                return Voucher::query()
                    ->where('seller_profile_id', $sellerId)
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->withCount([
                        'sellerOrders' => fn ($query) => $query->where('status', '<>', 'CANCELLED'),
                        'sellerOrders as buyer_uses_count' => fn ($query) => $query->where('status', '<>', 'CANCELLED')->when(
                            $buyer,
                            fn ($uses) => $uses->whereHas('order', fn ($order) => $order->where('buyer_user_id', $buyer->id)),
                            fn ($uses) => $uses->whereRaw('1 = 0'),
                        ),
                    ])
                    ->get()
                    ->filter(fn (Voucher $voucher): bool => ($voucher->usage_limit === null || $voucher->seller_orders_count < $voucher->usage_limit)
                        && ($voucher->per_user_limit === null || $voucher->buyer_uses_count < $voucher->per_user_limit)
                    )
                    ->values();
            })
            ->filter(fn (Collection $vouchers): bool => $vouchers->isNotEmpty());

        if (Schema::hasColumn('vouchers', 'platform_code_key')) {
            $platformVouchers = Voucher::query()
                ->whereNull('seller_profile_id')
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->orderBy('name')
                ->get();
            $redemptionCounts = VoucherRedemptionCounter::countsFor($platformVouchers);
            $buyerRedemptionCounts = $buyer
                ? VoucherRedemptionCounter::countsFor($platformVouchers, (int) $buyer->id)
                : collect();
            $platformVouchers = $platformVouchers->filter(fn (Voucher $voucher): bool =>
                ($voucher->usage_limit === null || $redemptionCounts->get($voucher->id, 0) < $voucher->usage_limit)
                && (! $buyer || $voucher->per_user_limit === null || $buyerRedemptionCounts->get($voucher->id, 0) < $voucher->per_user_limit)
            )->values();

            if ($platformVouchers->isNotEmpty()) {
                $sellerVoucherGroups->put('platform', $platformVouchers);
            }
        }

        return $sellerVoucherGroups;
    }

    public function availableLogisticsProviders(): Collection
    {
        return LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('serviceAreas', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('locations'))
            ->orderBy('business_name')
            ->get(['id', 'code', 'business_name', 'shipping_fee'])
            ->each(fn (LogisticsCenter $center) => $center->setAttribute('shipping_fee', number_format((float) config('likhae.standard_shipping_fee', 50), 2, '.', '')));
    }

    public function preview(
        Collection $items,
        array|string $voucherCodes = [],
        ?User $buyer = null,
        ?LogisticsCenter $logisticsProvider = null,
        ?Address $automaticDestination = null,
        array $protectionItemIds = [],
    ): array
    {
        $singleVoucherCode = is_string($voucherCodes) ? mb_strtoupper(trim($voucherCodes)) : '';
        $legacyVoucherCodes = is_array($voucherCodes) ? $voucherCodes : [];
        $groups = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $shippingTotal = 0.0;
        $protectionTotal = 0.0;
        $selectedProtectionIds = collect($protectionItemIds)->map(fn ($id): int => (int) $id)->unique()->values();
        // Step 7: one fixed charge per seller-order/shipment, never per item unit.
        // The provider is selected separately from the charge; API checkout may
        // resolve the provider from the destination service area.
        $shippingFee = (float) config('likhae.standard_shipping_fee', 50);

        foreach ($items as $item) {
            /** @var CartItem $item */
            $item->loadMissing(['productVariant.product.sellerProfile', 'productVariant.optionValues.option']);
            $variant = $item->productVariant;
            $product = $variant?->product;
            $seller = $product?->sellerProfile;
            if (! $variant
                || ! $product
                || $product->status !== 'ACTIVE'
                || ! $seller
                || $seller->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'cart' => 'One or more selected products or shops are no longer available. Remove the unavailable item from your cart and try again.',
                ]);
            }

            $sellerId = (int) $seller->id;
            $lineTotal = $variant->final_price * (int) $item->quantity;
            $subtotal += $lineTotal;

            $protection = $this->protection->quoteForCartItem($item);
            $selectedProtection = $selectedProtectionIds->contains((int) $item->id);
            if ($selectedProtection && ! $protection) {
                throw ValidationException::withMessages([
                    'protection_item_ids' => 'Additional Care is no longer available for one of the selected products. Refresh checkout and try again.',
                ]);
            }
            $itemProtectionFee = $selectedProtection ? (float) ($protection['fee'] ?? 0) : 0.0;
            $item->setAttribute('protection_quote', [
                'eligible' => $protection !== null,
                'selected' => $selectedProtection,
                'fee' => $protection['fee'] ?? 0,
                'unit_fee' => $protection['unit_fee'] ?? 0,
                'scope' => $protection['scope'] ?? null,
                'risk_level' => $protection['risk_level'] ?? null,
                'rule_id' => $protection['rule_id'] ?? null,
                'rule_snapshot' => $protection['rule_snapshot'] ?? null,
            ]);
            $protectionTotal += $itemProtectionFee;

            $groupShippingFee = $shippingFee;
            $groupNeedsRoutingReview = false;
            $groupRoutingReviewReason = null;
            if ($this->automaticRoutingReady() && $logisticsProvider === null && $automaticDestination) {
                try {
                    $route = $this->routing->resolve($seller, $automaticDestination);
                } catch (ValidationException $exception) {
                    // Keep checkout available while preserving an explicit
                    // unrouted parcel for administrator review after purchase.
                    $reviewReason = $this->routingReviewReason($exception);
                    if (! $reviewReason) {
                        throw $exception;
                    }
                    $groupNeedsRoutingReview = true;
                    $groupRoutingReviewReason = $reviewReason;
                    $route = null;
                }
                if ($route) {
                    $this->assertCrossHubCustodyReady($route);
                }
                $groupShippingFee = (float) config('likhae.standard_shipping_fee', 50);
            }

            $groups[$sellerId] ??= [
                'seller' => $seller,
                'items' => collect(),
                'item_subtotal' => 0.0,
                'voucher' => null,
                'voucher_discount' => 0.0,
                'shipping_fee' => $groupShippingFee,
                'routing_review_required' => $groupNeedsRoutingReview,
                'routing_review_reason' => $groupRoutingReviewReason,
                'protection_total' => 0.0,
                'grand_total' => 0.0,
            ];

            if ($groupNeedsRoutingReview) {
                $groups[$sellerId]['routing_review_required'] = true;
                $groups[$sellerId]['routing_review_reason'] ??= $groupRoutingReviewReason;
            }

            $groups[$sellerId]['items']->push($item);
            $groups[$sellerId]['item_subtotal'] += $lineTotal;
            $groups[$sellerId]['protection_total'] += $itemProtectionFee;
        }

        $unknownProtectionIds = $selectedProtectionIds->diff($items->pluck('id')->map(fn ($id): int => (int) $id));
        if ($unknownProtectionIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'protection_item_ids' => 'One or more protection selections are no longer part of this checkout.',
            ]);
        }

        if ($singleVoucherCode !== '') {
            $this->applySingleVoucherCode($groups, $singleVoucherCode, $buyer);
        } else {
            foreach ($groups as $sellerId => &$group) {
                $code = trim((string) ($legacyVoucherCodes[$sellerId] ?? ''));
                $voucher = $code !== ''
                    ? $this->usableVoucher((int) $sellerId, $code, (float) $group['item_subtotal'], $buyer)
                    : null;
                if ($code !== '' && $voucher === null) {
                    throw ValidationException::withMessages([
                        'voucher_codes.'.$sellerId => 'This voucher is invalid or no longer available for this seller.',
                    ]);
                }
                $discount = $voucher ? $this->discountAmount($voucher, (float) $group['item_subtotal']) : 0.0;

                $group['voucher'] = $voucher;
                $group['voucher_discount'] = $discount;
                    $group['grand_total'] = max(0.0, (float) $group['item_subtotal'] - $discount + (float) $group['shipping_fee'] + (float) $group['protection_total']);
            }
            unset($group);

            foreach (array_keys($legacyVoucherCodes) as $sellerId) {
                if (! array_key_exists((int) $sellerId, $groups)) {
                    throw ValidationException::withMessages([
                        'voucher_codes.'.$sellerId => 'This voucher does not apply to the selected cart items.',
                    ]);
                }
            }
        }

        foreach ($groups as &$group) {
            $discount = (float) $group['voucher_discount'];
            $group['grand_total'] = max(0.0, (float) $group['item_subtotal'] - $discount + (float) $group['shipping_fee'] + (float) $group['protection_total']);
            $discountTotal += $discount;
            $shippingTotal += (float) $group['shipping_fee'];
        }
        unset($group);

        return [
            'groups' => collect($groups),
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'shipping_total' => round($shippingTotal, 2),
            'protection_total' => round($protectionTotal, 2),
            'grand_total' => round($subtotal - $discountTotal + $shippingTotal + $protectionTotal, 2),
        ];
    }

    public function placeOrder(
        User $buyer,
        Address|int $address,
        Collection|array $items,
        string $paymentMethod,
        array|string $voucherCodes = [],
        ?int $logisticsCenterId = null,
        array $protectionItemIds = [],
        array $sellerNotes = [],
        ?string $checkoutAttemptKey = null,
    ): Order
    {
        if (strtoupper($paymentMethod) !== 'COD') {
            throw ValidationException::withMessages([
                'payment_method' => 'Cash on Delivery is the only payment method available for new orders.',
            ]);
        }

        $cartItemIds = $items instanceof Collection
            ? $items->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : array_map('intval', $items);
        if ($cartItemIds === []) {
            throw ValidationException::withMessages([
                'cart' => 'Select at least one cart item before checkout.',
            ]);
        }
        $cartItemIds = array_values(array_unique($cartItemIds));
        sort($cartItemIds);
        $checkoutAttemptKey ??= (string) Str::uuid();
        if (! Str::isUuid($checkoutAttemptKey)) {
            throw ValidationException::withMessages([
                'checkout_attempt_key' => 'Refresh checkout to generate a valid checkout attempt.',
            ]);
        }
        if (! Schema::hasTable('checkout_attempts')) {
            throw ValidationException::withMessages([
                'checkout_attempt_key' => 'Checkout is temporarily unavailable until idempotency storage is installed.',
            ]);
        }

        $keyHash = hash('sha256', strtolower($checkoutAttemptKey));
        $addressId = $address instanceof Address ? (int) $address->id : $address;
        $requestHash = $this->checkoutRequestHash(
            (int) $buyer->id,
            $cartItemIds,
            (int) $addressId,
            $paymentMethod,
            $voucherCodes,
            $logisticsCenterId,
            $protectionItemIds,
            $sellerNotes,
        );
        $existing = $this->resolveCheckoutAttempt($buyer, $keyHash, $requestHash);
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($buyer, $addressId, $cartItemIds, $paymentMethod, $voucherCodes, $logisticsCenterId, $protectionItemIds, $sellerNotes, $keyHash, $requestHash): Order {
            $existing = $this->resolveCheckoutAttempt($buyer, $keyHash, $requestHash, true);
            if ($existing) {
                return $existing;
            }

            // Lock the owning active cart before its selected lines. A second
            // submission then observes the committed deletion/conversion.
            $cartIds = CartItem::query()->whereIn('id', $cartItemIds)->orderBy('cart_id')->pluck('cart_id')->unique()->values();
            $lockedCarts = Cart::query()
                ->whereIn('id', $cartIds)
                ->where('buyer_user_id', $buyer->id)
                ->where('status', Cart::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $items = CartItem::query()
                ->whereIn('id', $cartItemIds)
                ->whereIn('cart_id', $lockedCarts->pluck('id'))
                ->whereHas('cart', fn ($query) => $query
                    ->where('buyer_user_id', $buyer->id)
                    ->where('status', Cart::STATUS_ACTIVE))
                ->with(['productVariant.optionValues.option'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($cartItemIds)) {
                $existing = $this->resolveCheckoutAttempt($buyer, $keyHash, $requestHash);
                if ($existing) {
                    return $existing;
                }
                throw ValidationException::withMessages([
                    'cart' => 'One or more selected cart items are no longer in your active cart. Return to your cart and try again.',
                ]);
            }

            $address = Address::query()
                ->whereKey($addressId)
                ->where('user_id', $buyer->id)
                ->lockForUpdate()
                ->first();
            if (! $address) {
                throw ValidationException::withMessages([
                    'address_id' => 'The selected delivery address is no longer available to this buyer.',
                ]);
            }
            AddressCoordinateValidator::assertValid([
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ]);

            $logisticsProvider = $logisticsCenterId === null
                ? null
                : LogisticsCenter::query()
                    ->whereKey($logisticsCenterId)
                    ->approvedActive()
                    ->lockForUpdate()
                    ->first();
            if ($logisticsCenterId !== null && ! $logisticsProvider) {
                throw ValidationException::withMessages([
                    'logistics_center_id' => 'Select an active logistics provider.',
                ]);
            }
            if ($this->automaticRoutingReady() && $logisticsCenterId !== null) {
                throw ValidationException::withMessages([
                    'logistics_center_id' => 'Manual logistics provider selection is disabled while automatic PSGC routing is active.',
                ]);
            }
            $automaticRouting = $this->automaticRoutingReady() && $logisticsProvider === null;

            $requestedProtectionIds = collect($protectionItemIds)->map(fn ($id): int => (int) $id)->unique()->values();
            if ($requestedProtectionIds->diff($items->pluck('id')->map(fn ($id): int => (int) $id))->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'protection_item_ids' => 'Additional Care can only be selected for cart lines in this checkout.',
                ]);
            }
            $sellerNotes = $this->normalizeSellerNotes($items, $sellerNotes);

            $variantIds = $items->pluck('product_variant_id')->unique()->sort()->values();
            $lockedVariants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->with('optionValues.option')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedProducts = Product::query()
                ->whereIn('id', $lockedVariants->pluck('product_id')->filter()->unique()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSellers = SellerProfile::query()
                ->whereIn('id', $lockedProducts->pluck('seller_profile_id')->filter()->unique()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSellerUsers = User::query()
                ->whereIn('id', $lockedSellers->pluck('user_id')->filter()->unique()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $variant = $lockedVariants->get($item->product_variant_id);
                $product = $variant ? $lockedProducts->get($variant->product_id) : null;
                $seller = $product ? $lockedSellers->get($product->seller_profile_id) : null;
                $sellerUser = $seller ? $lockedSellerUsers->get($seller->user_id) : null;

                if (! $product
                    || $product->status !== 'ACTIVE'
                    || ! $seller
                    || $seller->status !== 'ACTIVE'
                    || ! $sellerUser
                    || $sellerUser->status !== User::STATUS_ACTIVE) {
                    throw ValidationException::withMessages([
                        'cart' => 'One or more selected products are no longer available for checkout. Remove the unavailable item and try again.',
                    ]);
                }

                if (! $variant || ! $variant->is_active || (int) $variant->stock < (int) $item->quantity) {
                    throw ValidationException::withMessages([
                        'cart' => 'One or more products no longer have enough stock.',
                    ]);
                }

                // Use the locked records for the totals and order snapshot. The
                // cart relation may have been loaded before another request
                // changed a price, stock, or listing status.
                $seller->setRelation('user', $sellerUser);
                $product->setRelation('sellerProfile', $seller);
                $variant->setRelation('product', $product);
                $item->setRelation('productVariant', $variant);
            }

            $this->lockVoucherRows($voucherCodes);

            $preview = $this->preview(
                $items,
                $voucherCodes,
                $buyer,
                $logisticsProvider,
                $automaticRouting ? $address : null,
                $protectionItemIds,
            );

            $checkoutAttemptId = DB::table('checkout_attempts')->insertGetId([
                'key_hash' => $keyHash,
                'buyer_user_id' => $buyer->id,
                'request_hash' => $requestHash,
                'order_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $orderAttributes = [
                'order_number' => $this->uniqueNumber('orders', 'order_number', 'LK'),
                'buyer_user_id' => $buyer->id,
                'source_cart_id' => $items->first()?->cart_id,
                'status' => 'PLACED',
                'currency' => 'PHP',
                'subtotal' => $preview['subtotal'],
                'discount_total' => $preview['discount_total'],
                'shipping_total' => $preview['shipping_total'],
                'grand_total' => $preview['grand_total'],
                'payment_status' => 'PENDING',
                'placed_at' => now(),
            ];
            if (Schema::hasColumn('orders', 'protection_total')) {
                $orderAttributes['protection_total'] = $preview['protection_total'];
            }
            $order = Order::query()->create($orderAttributes);
            DB::table('checkout_attempts')->where('id', $checkoutAttemptId)->update([
                'order_id' => $order->id,
                'updated_at' => now(),
            ]);

            $order->address()->create([
                'recipient_name' => $address->recipient_name,
                'contact_number' => $address->contact_number,
                'province_code' => $address->province_code,
                'province_name' => $address->province_name,
                'municipality_code' => $address->municipality_code,
                'municipality_name' => $address->municipality_name,
                'barangay_code' => $address->barangay_code,
                'barangay_name' => $address->barangay_name,
                'postal_code' => $address->postal_code,
                'house_number' => $address->house_number,
                'street_address' => $address->street_address,
                'landmark' => $address->landmark,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ]);

            Payment::query()->create([
                'order_id' => $order->id,
                'method' => 'COD',
                'provider' => 'COD',
                'provider_reference' => null,
                'amount' => $order->grand_total,
                'status' => 'PENDING',
                'initiated_at' => now(),
            ]);

            foreach ($preview['groups'] as $sellerId => $group) {
                $sellerOrderAttributes = [
                    'seller_order_number' => $this->uniqueNumber('seller_orders', 'seller_order_number', 'SO'),
                    'order_id' => $order->id,
                    'seller_profile_id' => $sellerId,
                    'buyer_note' => $sellerNotes[$sellerId] ?? null,
                    'voucher_id' => $group['voucher']?->id,
                    'status' => 'PLACED',
                    'item_subtotal' => $group['item_subtotal'],
                    'voucher_discount' => $group['voucher_discount'],
                    'shipping_fee' => $group['shipping_fee'],
                    'grand_total' => $group['grand_total'],
                ];
                if (Schema::hasColumn('seller_orders', 'protection_total')) {
                    $sellerOrderAttributes['protection_total'] = $group['protection_total'];
                }
                $sellerOrder = SellerOrder::query()->create($sellerOrderAttributes);

                $snapshotReady = Schema::hasColumn('order_items', 'original_unit_price');
                foreach ($group['items'] as $item) {
                    /** @var CartItem $item */
                    $item->loadMissing(['productVariant.product', 'productVariant.optionValues.option']);
                    $variant = $lockedVariants->get($item->product_variant_id);
                    $product = $item->productVariant->product;
                    $lineTotal = $variant->final_price * (int) $item->quantity;
                    $discountAmount = $variant->discount_amount * (int) $item->quantity;

                    $itemAttributes = [
                        'product_id' => $product->id,
                        'product_variant_id' => $item->productVariant->id,
                        'product_name' => $product->name,
                        'sku' => $item->productVariant->sku,
                        'variant_description' => $item->productVariant->description,
                        'unit_price' => $variant->final_price,
                        'quantity' => $item->quantity,
                        'discount_amount' => $discountAmount,
                        'line_total' => $lineTotal,
                    ];
                    if ($snapshotReady) {
                        $itemAttributes['original_unit_price'] = number_format((float) $variant->price, 2, '.', '');
                    }
                    if (Schema::hasColumn('order_items', 'protection_selected')) {
                        $quote = (array) $item->getAttribute('protection_quote');
                        $itemAttributes += [
                            'protection_selected' => (bool) ($quote['selected'] ?? false),
                            'protection_fee' => (float) ($quote['selected'] ? ($quote['fee'] ?? 0) : 0),
                            'protection_rule_id' => $quote['selected'] ? ($quote['rule_id'] ?? null) : null,
                            'protection_scope' => $quote['selected'] ? ($quote['scope'] ?? null) : null,
                            'protection_rule_snapshot' => $quote['selected'] ? ($quote['rule_snapshot'] ?? null) : null,
                            'protection_handling_flag' => (bool) ($quote['selected'] ?? false),
                        ];
                    }
                    $orderItem = $sellerOrder->items()->create($itemAttributes);

                    $shipment = $this->createShipmentForOrderItem($sellerOrder, $orderItem, $address, $logisticsProvider);
                    app(CodCollectionService::class)->initializeForShipment($shipment);

                    if (Schema::hasTable('protection_selection_audits')) {
                        $quote = (array) $item->getAttribute('protection_quote');
                        DB::table('protection_selection_audits')->insert([
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'protection_rule_id' => $quote['selected'] ? ($quote['rule_id'] ?? null) : null,
                            'buyer_user_id' => $buyer->id,
                            'selected' => (bool) ($quote['selected'] ?? false),
                            'amount' => (float) ($quote['selected'] ? ($quote['fee'] ?? 0) : 0),
                            'handling_flag' => (bool) ($quote['selected'] ?? false),
                            'metadata' => json_encode(['scope' => $quote['scope'] ?? null, 'risk_level' => $quote['risk_level'] ?? null, 'rule_snapshot' => $quote['rule_snapshot'] ?? null], JSON_THROW_ON_ERROR),
                            'selected_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $variant->decrement('stock', (int) $item->quantity);
                }

                // Recalculate every parcel after all seller-order lines exist
                // so voucher, shipping, and protection shares reconcile to
                // the final seller-order total in cents.
                $sellerOrder->load('items.shipment');
                foreach ($sellerOrder->items as $orderItem) {
                    if ($orderItem->shipment) {
                        app(CodCollectionService::class)->initializeForShipment($orderItem->shipment);
                    }
                }

            }

            $deletedCount = CartItem::query()->whereIn('id', $cartItemIds)->delete();
            if ($deletedCount !== count($cartItemIds)) {
                throw ValidationException::withMessages([
                    'cart' => 'The selected cart changed during checkout. Review your cart and try again.',
                ]);
            }

            foreach ($lockedCarts as $cart) {
                if (! CartItem::query()->where('cart_id', $cart->id)->exists()) {
                    $cart->update([
                        'status' => Cart::STATUS_CONVERTED,
                        'converted_at' => now(),
                    ]);
                }
            }

                return $order->load(['sellerOrders.items.shipment', 'sellerOrders.shipments', 'address', 'payments']);
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent request with the same key can win the unique insert.
            // Its transaction owns the result; any rows from this transaction
            // have already rolled back before this lookup runs.
            $existing = $this->resolveCheckoutAttempt($buyer, $keyHash, $requestHash);
            if ($existing) {
                return $existing;
            }

            if ($this->isTransientCheckoutLockError($exception)) {
                throw ValidationException::withMessages([
                    'cart' => 'Inventory is being updated by another checkout. Refresh your cart and try again.',
                ]);
            }

            throw $exception;
        }
    }

    private function isTransientCheckoutLockError(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
    }

    /** @param array<int> $cartItemIds */
    private function checkoutRequestHash(
        int $buyerId,
        array $cartItemIds,
        int $addressId,
        string $paymentMethod,
        array|string $voucherCodes,
        ?int $logisticsCenterId,
        array $protectionItemIds,
        array $sellerNotes,
    ): string {
        sort($cartItemIds);
        $voucherPayload = is_array($voucherCodes)
            ? array_map(fn ($code): string => mb_strtoupper(trim((string) $code)), $voucherCodes)
            : mb_strtoupper(trim($voucherCodes));
        if (is_array($voucherPayload)) {
            ksort($voucherPayload);
        }
        $protectionPayload = array_values(array_unique(array_map('intval', $protectionItemIds)));
        sort($protectionPayload);
        $notePayload = [];
        foreach ($sellerNotes as $sellerId => $note) {
            $notePayload[(string) $sellerId] = trim((string) ($note ?? ''));
        }
        ksort($notePayload);

        return hash('sha256', json_encode([
            'buyer_id' => $buyerId,
            'cart_item_ids' => $cartItemIds,
            'address_id' => $addressId,
            'payment_method' => strtoupper(trim($paymentMethod)),
            'voucher_codes' => $voucherPayload,
            'logistics_center_id' => $logisticsCenterId,
            'protection_item_ids' => $protectionPayload,
            'seller_notes' => $notePayload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function resolveCheckoutAttempt(User $buyer, string $keyHash, string $requestHash, bool $lock = false): ?Order
    {
        $query = DB::table('checkout_attempts')->where('key_hash', $keyHash);
        if ($lock) {
            $query->lockForUpdate();
        }
        $attempt = $query->first();
        if (! $attempt) {
            return null;
        }
        if ((int) $attempt->buyer_user_id !== (int) $buyer->id) {
            throw ValidationException::withMessages([
                'checkout_attempt_key' => 'This checkout attempt key belongs to another buyer and cannot be reused.',
            ]);
        }
        if (! hash_equals((string) $attempt->request_hash, $requestHash)) {
            throw ValidationException::withMessages([
                'checkout_attempt_key' => 'This checkout attempt was already used with different order details. Refresh checkout to start a new attempt.',
            ]);
        }
        if (! $attempt->order_id) {
            throw ValidationException::withMessages([
                'checkout_attempt_key' => 'This checkout attempt is still being finalized. Retry the same request shortly.',
            ]);
        }

        $order = Order::query()
            ->where('buyer_user_id', $buyer->id)
            ->with(['sellerOrders.items.shipment', 'sellerOrders.shipments', 'address', 'payments'])
            ->find($attempt->order_id);
        if (! $order) {
            throw ValidationException::withMessages([
                'checkout_attempt_key' => 'The order for this checkout attempt is unavailable. Contact marketplace support.',
            ]);
        }

        return $order;
    }

    /**
     * Keep checkout notes scoped to sellers represented in the selected items.
     *
     * @param array<int|string, mixed> $sellerNotes
     * @return array<int, string|null>
     */
    public function normalizeSellerNotes(Collection $items, array $sellerNotes): array
    {
        $sellerIds = $items
            ->map(function (CartItem $item): int {
                $item->loadMissing('productVariant.product');

                return (int) $item->productVariant?->product?->seller_profile_id;
            })
            ->filter(fn (int $sellerId): bool => $sellerId > 0)
            ->unique()
            ->values();

        $normalized = [];
        foreach ($sellerNotes as $sellerId => $note) {
            $sellerIdString = (string) $sellerId;
            if ((! is_int($sellerId) && ! ctype_digit($sellerIdString))
                || (int) $sellerId <= 0
                || ! $sellerIds->contains((int) $sellerId)) {
                throw ValidationException::withMessages([
                    'seller_notes' => 'A seller note can only be added for a seller in this checkout.',
                ]);
            }

            $trimmed = trim((string) ($note ?? ''));
            $normalized[(int) $sellerId] = $trimmed !== '' ? $trimmed : null;
        }

        return $normalized;
    }

    private function createShipmentForOrderItem(
        SellerOrder $sellerOrder,
        OrderItem $orderItem,
        Address $address,
        ?LogisticsCenter $logisticsProvider = null,
    ): Shipment
    {
        $automaticRouting = $this->automaticRoutingReady() && $logisticsProvider === null;
        $route = null;
        $areaLocation = null;
        $routingReviewReason = null;
        if ($automaticRouting) {
            $sellerOrder->loadMissing('sellerProfile.businessAddress');
            try {
                $route = $this->routing->resolve($sellerOrder->sellerProfile, $address);
            } catch (ValidationException $exception) {
                $routingReviewReason = $this->routingReviewReason($exception);
                if (! $routingReviewReason) {
                    throw $exception;
                }
            }
            if ($route) {
                $this->assertCrossHubCustodyReady($route);
                $areaLocation = $route['destination_area'];
            }
        } else {
            $areaLocation = $this->serviceAreaLocationForAddress($address, $logisticsProvider);
        }

        if (! $areaLocation && ! $routingReviewReason) {
            throw ValidationException::withMessages([
                $logisticsProvider ? 'logistics_center_id' : 'address_id' => $logisticsProvider
                    ? 'The selected logistics provider does not serve this delivery address.'
                    : 'Delivery is not currently available to this address.',
            ]);
        }

        $shipmentAttributes = [
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $orderItem->id,
            'tracking_number' => $this->uniqueNumber('shipments', 'tracking_number', 'TRK'),
            'logistics_center_id' => $route['origin']->id ?? ($logisticsProvider?->id ?? $areaLocation?->serviceArea?->logistics_center_id),
            'service_area_id' => $areaLocation?->service_area_id,
            'destination_province_code' => $address->province_code,
            'destination_province_name' => $address->province_name,
            'destination_municipality_code' => $address->municipality_code,
            'destination_municipality_name' => $address->municipality_name,
            'destination_barangay_code' => $address->barangay_code,
            'destination_barangay_name' => $address->barangay_name,
            'current_status' => 'PLACED',
        ];
        if ($route) {
            $originAddress = $route['origin']->address;
            $shipmentAttributes += [
                'origin_logistics_center_id' => $route['origin']->id,
                'destination_logistics_center_id' => $route['destination']->id,
                'origin_province_code' => $originAddress->province_code,
                'origin_province_name' => $originAddress->province_name,
                'origin_municipality_code' => $originAddress->municipality_code,
                'origin_municipality_name' => $originAddress->municipality_name,
                'origin_barangay_code' => $sellerOrder->sellerProfile->businessAddress->barangay_code,
                'origin_barangay_name' => $sellerOrder->sellerProfile->businessAddress->barangay_name,
            ];
        }

        $shipment = Shipment::query()->create($shipmentAttributes);

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'status' => 'PLACED',
            'actor_user_id' => $sellerOrder->order?->buyer_user_id,
            'logistics_center_id' => $shipment->logistics_center_id,
            'rider_assignment_id' => null,
            'notes' => $routingReviewReason
                ? 'Parcel placed. ROUTING_REVIEW_REQUIRED: '.$routingReviewReason
                : 'Parcel for order item '.$orderItem->id.' placed by buyer.',
            'occurred_at' => now(),
        ]);

        if ($route && (int) $route['origin']->id !== (int) $route['destination']->id) {
            $this->transfers->createPlannedLeg($shipment, $route['origin'], $route['destination']);
        }

        return $shipment;
    }

    /** @param array{origin: LogisticsCenter, destination: LogisticsCenter} $route */
    private function assertCrossHubCustodyReady(array $route): void
    {
        if ((int) $route['origin']->id !== (int) $route['destination']->id
            && ! $this->schema->manifestReady()) {
            throw ValidationException::withMessages([
                'address_id' => 'Cross-hub fulfillment is temporarily disabled until transfer manifests, custody scans, and approved vehicle capacities are installed.',
            ]);
        }
    }

    private function routingReviewReason(ValidationException $exception): ?string
    {
        $reason = (string) ($exception->errors()['address_id'][0] ?? '');
        $message = mb_strtolower($reason);

        return str_contains($message, 'ambiguous')
            || str_contains($message, 'no active logistics hub')
            || str_contains($message, 'barangay psgc is not covered')
            ? $reason
            : null;
    }

    private function serviceAreaLocationForAddress(Address $address, ?LogisticsCenter $logisticsProvider = null): ?ServiceAreaLocation
    {
        $locations = ServiceAreaLocation::query()
            ->where('province_code', $address->province_code)
            ->where('municipality_code', $address->municipality_code)
            ->where('barangay_code', $address->barangay_code)
            ->whereHas('serviceArea', function ($query) use ($logisticsProvider): void {
                $query->where('is_active', true)
                    ->when($logisticsProvider, fn ($providerQuery) => $providerQuery->where('logistics_center_id', $logisticsProvider->id));
            })
            ->with('serviceArea')
            ->get();

        if ($locations->count() > 1) {
            throw ValidationException::withMessages([
                'address_id' => 'Delivery routing is ambiguous for this PSGC address. Please contact logistics support before placing the order.',
            ]);
        }

        return $locations->first();
    }

    private function usableVoucher(int $sellerId, string $code, float $subtotal, ?User $buyer = null): ?Voucher
    {
        $voucher = Voucher::query()
            ->where('seller_profile_id', $sellerId)
            ->where('code', mb_strtoupper($code))
            ->where('is_active', true)
            ->where('minimum_order_amount', '<=', $subtotal)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->withCount([
                'sellerOrders' => fn ($query) => $query->where('status', '<>', 'CANCELLED'),
                'sellerOrders as buyer_uses_count' => fn ($query) => $query->where('status', '<>', 'CANCELLED')->when(
                    $buyer,
                    fn ($uses) => $uses->whereHas('order', fn ($order) => $order->where('buyer_user_id', $buyer->id)),
                    fn ($uses) => $uses->whereRaw('1 = 0'),
                ),
            ])
            ->first();
        if (! $voucher
            || ($voucher->usage_limit !== null && $voucher->seller_orders_count >= $voucher->usage_limit)
            || ($buyer && $voucher->per_user_limit !== null && $voucher->buyer_uses_count >= $voucher->per_user_limit)) {
            return null;
        }

        return $voucher;
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function applySingleVoucherCode(array &$groups, string $code, ?User $buyer): void
    {
        $query = Voucher::query()
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->with('sellerProfile');
        if (Schema::hasColumn('vouchers', 'platform_code_key')) {
            $query->withCount([
                'sellerOrders' => fn ($orderQuery) => $orderQuery->where('status', '<>', 'CANCELLED'),
                'sellerOrders as buyer_uses_count' => fn ($orderQuery) => $orderQuery->where('status', '<>', 'CANCELLED')->when(
                    $buyer,
                    fn ($uses) => $uses->whereHas('order', fn ($query) => $query->where('buyer_user_id', $buyer->id)),
                    fn ($uses) => $uses->whereRaw('1 = 0'),
                ),
            ]);
        } else {
            $query->withCount(['sellerOrders' => fn ($orderQuery) => $orderQuery->where('status', '<>', 'CANCELLED')]);
        }
        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'voucher_code' => 'We could not find that voucher code. Check it and try again.',
            ]);
        }

        $cartCandidates = $candidates
            ->filter(fn (Voucher $voucher): bool => $voucher->seller_profile_id === null
                ? Schema::hasColumn('vouchers', 'platform_code_key')
                : array_key_exists((int) $voucher->seller_profile_id, $groups))
            ->values();

        if ($cartCandidates->isEmpty()) {
            $shopNames = $candidates->pluck('sellerProfile.business_name')->filter()->unique()->values();
            $message = $shopNames->count() === 1
                ? 'This voucher belongs to '.$shopNames->first().' and does not apply to a shop in this checkout.'
                : 'This voucher does not apply to any shop in this checkout.';

            throw ValidationException::withMessages(['voucher_code' => $message]);
        }

        if ($cartCandidates->count() > 1) {
            $message = $cartCandidates->contains(fn (Voucher $candidate): bool => $candidate->seller_profile_id === null)
                ? 'This code matches both a LIKHAE platform campaign and a seller voucher. Use a unique code.'
                : 'This code matches more than one shop in your cart. Ask the sellers for a unique code.';
            throw ValidationException::withMessages([
                'voucher_code' => $message,
            ]);
        }

        $voucher = $cartCandidates->first();
        if ($voucher->seller_profile_id === null) {
            $subtotal = array_sum(array_map(fn (array $group): float => (float) $group['item_subtotal'], $groups));
            $redemptions = VoucherRedemptionCounter::count((int) $voucher->id);
            $buyerRedemptions = $buyer
                ? VoucherRedemptionCounter::count((int) $voucher->id, (int) $buyer->id)
                : 0;

            if (! $voucher->is_active || ($voucher->starts_at && $voucher->starts_at->isFuture())) {
                throw ValidationException::withMessages(['voucher_code' => 'This platform voucher is not active yet.']);
            }
            if ($voucher->ends_at && $voucher->ends_at->isPast()) {
                throw ValidationException::withMessages(['voucher_code' => 'This platform voucher has expired.']);
            }
            if ($voucher->usage_limit !== null && $redemptions >= $voucher->usage_limit) {
                throw ValidationException::withMessages(['voucher_code' => 'This platform voucher has reached its redemption limit.']);
            }
            if ($buyer && $voucher->per_user_limit !== null && $buyerRedemptions >= $voucher->per_user_limit) {
                throw ValidationException::withMessages(['voucher_code' => 'You have already used this platform voucher the maximum number of times.']);
            }
            if ($subtotal < (float) $voucher->minimum_order_amount) {
                $remaining = (float) $voucher->minimum_order_amount - $subtotal;
                throw ValidationException::withMessages([
                    'voucher_code' => 'Add PHP '.number_format($remaining, 2).' more to use this platform voucher.',
                ]);
            }

            $discount = $this->discountAmount($voucher, $subtotal);
            $this->allocatePlatformDiscount($groups, $voucher, $discount, $subtotal);

            return;
        }

        $sellerId = (int) $voucher->seller_profile_id;
        $group = &$groups[$sellerId];

        if (! $voucher->is_active || ($voucher->starts_at && $voucher->starts_at->isFuture())) {
            throw ValidationException::withMessages([
                'voucher_code' => 'This voucher is not active yet.',
            ]);
        }

        if ($voucher->ends_at && $voucher->ends_at->isPast()) {
            throw ValidationException::withMessages([
                'voucher_code' => 'This voucher has expired.',
            ]);
        }

        if ($voucher->usage_limit !== null && $voucher->seller_orders_count >= $voucher->usage_limit) {
            throw ValidationException::withMessages([
                'voucher_code' => 'This voucher has reached its redemption limit.',
            ]);
        }

        if ($buyer && $voucher->per_user_limit !== null && $voucher->buyer_uses_count >= $voucher->per_user_limit) {
            throw ValidationException::withMessages([
                'voucher_code' => 'You have already used this voucher the maximum number of times.',
            ]);
        }

        if ((float) $group['item_subtotal'] < (float) $voucher->minimum_order_amount) {
            $remaining = (float) $voucher->minimum_order_amount - (float) $group['item_subtotal'];

            throw ValidationException::withMessages([
                'voucher_code' => 'Add PHP '.number_format($remaining, 2).' more from '.$voucher->sellerProfile?->business_name.' to use this voucher.',
            ]);
        }

        $discount = $this->discountAmount($voucher, (float) $group['item_subtotal']);
        $group['voucher'] = $voucher;
        $group['voucher_discount'] = $discount;
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function allocatePlatformDiscount(array &$groups, Voucher $voucher, float $discount, float $subtotal): void
    {
        $allocated = 0.0;
        $lastSellerId = array_key_last($groups);

        foreach ($groups as $sellerId => &$group) {
            $share = (int) $sellerId === (int) $lastSellerId
                ? round($discount - $allocated, 2)
                : round($discount * ((float) $group['item_subtotal'] / $subtotal), 2);
            $share = min((float) $group['item_subtotal'], max(0.0, $share));
            $group['voucher'] = $voucher;
            $group['voucher_discount'] = $share;
            $allocated += $share;
        }
        unset($group);
    }

    private function lockVoucherRows(array|string $voucherCodes): void
    {
        $codes = collect(is_array($voucherCodes) ? $voucherCodes : [$voucherCodes])
            ->map(fn ($code): string => mb_strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        foreach ($codes as $code) {
            Voucher::query()
                ->whereRaw('UPPER(code) = ?', [$code])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
    }

    private function discountAmount(Voucher $voucher, float $subtotal): float
    {
        $discount = $voucher->discount_type === 'PERCENT'
            ? $subtotal * ((float) $voucher->discount_value / 100)
            : (float) $voucher->discount_value;

        if ($voucher->maximum_discount_amount !== null) {
            $discount = min($discount, (float) $voucher->maximum_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }

    private function uniqueNumber(string $table, string $column, string $prefix): string
    {
        do {
            $value = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (DB::table($table)->where($column, $value)->exists());

        return $value;
    }
}
