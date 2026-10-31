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
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Finance\CodCollectionService;
use App\Services\Finance\RegionShippingRateService;
use App\Services\Finance\ShippingRatePolicyService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\PsgcLogisticsRoutingService;
use App\Services\Fulfillment\ShipmentTransferService;
use App\Services\Protection\FragileProtectionRuleService;
use App\Support\AddressCoordinateValidator;
use App\Support\FinancialRules;
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
        private readonly PurchaseLimitService $purchaseLimits,
        private readonly ShippingRatePolicyService $shippingRates,
        private readonly RegionShippingRateService $regionShippingRates,
        private readonly BuyerProductAvailabilityService $productAvailability,
        private readonly RewardPointsService $rewardPoints,
        private readonly VoucherDiscountCalculator $voucherDiscounts,
    ) {}

    public function automaticRoutingReady(): bool
    {
        return $this->schema->automaticRoutingReady();
    }

    public function currentShippingFee(): string
    {
        return $this->shippingRates->currentPolicy()['shipping_fee'];
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
                $cartProductIds = $sellerItems
                    ->map(fn (CartItem $item): int => (int) $item->productVariant->product_id)
                    ->unique()
                    ->all();

                return Voucher::query()
                    ->where('seller_profile_id', $sellerId)
                    ->when(Schema::hasColumn('vouchers', 'platform_code_key'), fn ($query) => $query->whereNull('platform_code_key'))
                    ->when(Schema::hasColumn('vouchers', 'benefit_scope'), fn ($query) => $query->where('benefit_scope', 'PRODUCT'))
                    ->when(Schema::hasColumn('vouchers', 'visibility'), fn ($query) => $query->where('visibility', 'PUBLIC'))
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->whereHas('buyerClaims', fn ($query) => $query->where('buyer_user_id', $buyer?->id ?? 0))
                    ->whereHas('sellerProfile', fn ($query) => $query
                        ->where('status', 'ACTIVE')
                        ->whereHas('user', fn ($user) => $user
                            ->where('account_type', User::TYPE_SELLER)
                            ->where('status', User::STATUS_ACTIVE)))
                    ->withCount([
                        'sellerOrders' => fn ($query) => $query->where('status', '<>', 'CANCELLED'),
                        'sellerOrders as buyer_uses_count' => fn ($query) => $query->where('status', '<>', 'CANCELLED')->when(
                            $buyer,
                            fn ($uses) => $uses->whereHas('order', fn ($order) => $order->where('buyer_user_id', $buyer->id)),
                            fn ($uses) => $uses->whereRaw('1 = 0'),
                        ),
                    ])
                    ->with('eligibleProducts:id')
                    ->get()
                    ->filter(fn (Voucher $voucher): bool => ($voucher->usage_limit === null || $voucher->seller_orders_count < $voucher->usage_limit)
                        && ($voucher->per_user_limit === null || $voucher->buyer_uses_count < $voucher->per_user_limit)
                        && ($voucher->applies_to !== 'PRODUCTS' || $voucher->eligibleProducts->contains(fn (Product $product): bool => in_array((int) $product->id, $cartProductIds, true)))
                    )
                    ->values();
            })
            ->filter(fn (Collection $vouchers): bool => $vouchers->isNotEmpty());

        if (Schema::hasColumn('vouchers', 'platform_code_key')) {
            $platformVouchers = Voucher::query()
                ->whereNull('seller_profile_id')
                ->whereNotNull('platform_code_key')
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

            $productVouchers = $platformVouchers->filter(fn (Voucher $voucher): bool => ! Schema::hasColumn('vouchers', 'benefit_scope')
                || strtoupper((string) $voucher->benefit_scope) === 'PRODUCT')->values();
            $shippingVouchers = $platformVouchers->filter(fn (Voucher $voucher): bool => Schema::hasColumn('vouchers', 'benefit_scope')
                && strtoupper((string) $voucher->benefit_scope) === 'SHIPPING')->values();

            if ($productVouchers->isNotEmpty()) {
                $sellerVoucherGroups->put('platform', $productVouchers);
            }
            if ($shippingVouchers->isNotEmpty()) {
                $sellerVoucherGroups->put('shipping', $shippingVouchers);
            }
        }

        return $sellerVoucherGroups;
    }

    public function availableLogisticsProviders(): Collection
    {
        $globalShippingFee = $this->currentShippingFee();

        return LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('serviceAreas', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('locations'))
            ->orderBy('business_name')
            ->get(['id', 'code', 'business_name'])
            ->each(fn (LogisticsCenter $center) => $center->setAttribute('shipping_fee', $globalShippingFee));
    }

    /** @return array{available: bool, requires_review: bool, message: string|null} */
    public function deliveryAvailability(Collection $items, Address $destination, ?LogisticsCenter $provider = null): array
    {
        try {
            $requiresReview = $this->assertDeliveryAvailable($items, $destination, $provider);

            return [
                'available' => true,
                'requires_review' => $requiresReview,
                'message' => $requiresReview ? 'Delivery details may need confirmation after your order is placed.' : null,
            ];
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->filter()->first()
                ?: 'Delivery is not available for this address.';

            return [
                'available' => false,
                'requires_review' => false,
                'message' => $this->buyerFacingDeliveryError((string) $message),
            ];
        }
    }

    private function assertDeliveryAvailable(Collection $items, Address $destination, ?LogisticsCenter $provider = null): bool
    {
        try {
            AddressCoordinateValidator::assertValid([
                'latitude' => $destination->latitude,
                'longitude' => $destination->longitude,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'address_id' => $this->buyerFacingDeliveryError((string) (collect($exception->errors())->flatten()->filter()->first() ?? '')),
            ]);
        }

        if (! $this->automaticRoutingReady()) {
            if (! $provider) {
                throw ValidationException::withMessages([
                    'logistics_center_id' => 'Select an available delivery provider.',
                ]);
            }

            try {
                if (! $this->serviceAreaLocationForAddress($destination, $provider)) {
                    throw ValidationException::withMessages([
                        'address_id' => 'This delivery provider does not serve the selected address.',
                    ]);
                }
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'address_id' => $this->buyerFacingDeliveryError((string) (collect($exception->errors())->flatten()->filter()->first() ?? '')),
                ]);
            }

            return false;
        }

        $requiresReview = false;
        foreach ($items as $item) {
            $item->loadMissing('productVariant.product.sellerProfile.businessAddress');
            $seller = $item->productVariant?->product?->sellerProfile;
            if (! $seller) {
                continue;
            }

            try {
                $route = $this->routing->resolve($seller, $destination);
                $this->assertCrossHubCustodyReady($route);
            } catch (ValidationException $exception) {
                $reason = (string) (collect($exception->errors())->flatten()->filter()->first() ?? '');
                if ($this->isUnserviceableBuyerAddress($reason) || str_contains(mb_strtolower($reason), 'cross-hub fulfillment')) {
                    throw ValidationException::withMessages([
                        'address_id' => $this->buyerFacingDeliveryError($reason),
                    ]);
                }

                // Ambiguous or incomplete seller-side routing remains a
                // logistics review state. Buyer-address coverage failures
                // are blocked before order creation below.
                if (! $this->routingReviewReason($exception)) {
                    throw ValidationException::withMessages([
                        'address_id' => $this->buyerFacingDeliveryError($reason),
                    ]);
                }

                $requiresReview = true;
            }
        }

        return $requiresReview;
    }

    private function isUnserviceableBuyerAddress(string $reason): bool
    {
        $message = mb_strtolower($reason);

        return str_contains($message, 'buyer delivery')
            && (str_contains($message, 'no active logistics hub')
                || str_contains($message, 'not covered')
                || str_contains($message, 'missing a canonical')
                || str_contains($message, 'missing or does not belong'));
    }

    private function buyerFacingDeliveryError(string $reason): string
    {
        $message = mb_strtolower($reason);
        if (str_contains($message, 'latitude') || str_contains($message, 'longitude') || str_contains($message, 'pin')) {
            return 'Confirm the exact delivery pin in your saved address before placing this order.';
        }

        return 'Delivery is not available for this address yet. Choose another saved address or update its delivery details.';
    }

    public function preview(
        Collection $items,
        array|string $voucherCodes = [],
        ?User $buyer = null,
        ?LogisticsCenter $logisticsProvider = null,
        ?Address $automaticDestination = null,
        array $protectionItemIds = [],
        bool $lockVoucherUsage = false,
        int $rewardPointsToRedeem = 0,
    ): array
    {
        $singleVoucherCode = is_string($voucherCodes) ? mb_strtoupper(trim($voucherCodes)) : '';
        $structuredVoucherSelection = is_array($voucherCodes)
            && array_intersect(['seller', 'platform', 'shipping'], array_keys($voucherCodes)) !== [];
        $legacyVoucherCodes = is_array($voucherCodes) && ! $structuredVoucherSelection ? $voucherCodes : [];
        $groups = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $shippingTotal = 0.0;
        $shippingVoucherDiscountTotal = 0.0;
        $protectionTotal = 0.0;
        $selectedProtectionIds = collect($protectionItemIds)->map(fn ($id): int => (int) $id)->unique()->values();
        // Step 7: one fixed charge per seller-order/shipment, never per item unit.
        // The provider is selected separately from the charge; API checkout may
        // resolve the provider from the destination service area.
        $ratePolicy = $this->shippingRates->currentPolicy(lockForUpdate: $lockVoucherUsage);
        $shippingFee = (float) $ratePolicy['shipping_fee'];
        $destinationForPricing = $automaticDestination;
        if (! $destinationForPricing && $buyer) {
            $destinationForPricing = $buyer->addresses()
                ->orderByDesc('is_default')
                ->latest('id')
                ->first();
        }
        $shippingQuotes = [];

        $this->assertFlashSaleAllocations($items);

        foreach ($items as $item) {
            /** @var CartItem $item */
            $item->loadMissing(['productVariant.product.sellerProfile.user', 'productVariant.product.sellerProfile.businessAddress', 'productVariant.optionValues.option']);
            $variant = $item->productVariant;
            $product = $variant?->product;
            $seller = $product?->sellerProfile;
            $availability = $this->productAvailability->inspect(
                $variant,
                $product,
                $seller,
                $seller?->user,
                (int) $item->quantity,
            );
            if (! $availability['available']) {
                throw ValidationException::withMessages([
                    'cart' => $availability['message'],
                ]);
            }

            $sellerId = (int) $seller->id;
            $unitPrice = (float) $variant->final_price;
            $itemQuantity = (int) $item->quantity;
            $item->setAttribute('checkout_unit_price', $unitPrice);
            $item->setAttribute('checkout_discount_amount', (float) $variant->discount_amount);
            $item->setAttribute('checkout_flash_sale_units', $variant->active_flash_sale_price !== null ? $itemQuantity : 0);
            $item->setAttribute('checkout_promotion_discount_amount', 0.0);
            $item->setAttribute('checkout_promotion_snapshot', []);
            $lineTotal = round($unitPrice * $itemQuantity, 2);
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
            $shippingSnapshot = [
                'base_fee' => number_format($shippingFee, 2, '.', ''),
                'origin_region' => null,
                'destination_region' => null,
                'additional_region_tiers' => null,
                'additional_region_tier_fee' => null,
                'shipping_fee' => number_format($shippingFee, 2, '.', ''),
                'region_pricing_pending' => true,
            ];
            if ($destinationForPricing) {
                $shippingQuotes[$sellerId] ??= $this->regionShippingRates->quote(
                    $seller->businessAddress,
                    $destinationForPricing,
                    $ratePolicy['shipping_fee'],
                );
                $groupShippingFee = (float) $shippingQuotes[$sellerId]['shipping_fee'];
                $shippingSnapshot = $shippingQuotes[$sellerId]['snapshot'];
            }
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
            }

            $groups[$sellerId] ??= [
                'seller' => $seller,
                'items' => collect(),
                'item_subtotal' => 0.0,
                'promotion_discount_total' => 0.0,
                'voucher' => null,
                'seller_voucher' => null,
                'seller_voucher_discount' => 0.0,
                'platform_voucher_discount' => 0.0,
                'shipping_voucher_discount' => 0.0,
                'voucher_discount' => 0.0,
                'reward_points_redeemed' => 0,
                'reward_points_discount' => 0.0,
                'shipping_fee' => $groupShippingFee,
                'shipping_rate_version_id' => $ratePolicy['version']?->id,
                'shipping_rate_snapshot' => $shippingSnapshot,
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

        $promotionDiscountTotal = $this->applySellerPromotions($groups, $lockVoucherUsage);

        $platformVoucher = null;
        $shippingVoucher = null;
        if ($singleVoucherCode !== '') {
            $platformVoucher = $this->applySingleVoucherCode($groups, $singleVoucherCode, $buyer, $lockVoucherUsage);
        } elseif ($structuredVoucherSelection) {
            $sellerSelections = collect((array) ($voucherCodes['seller'] ?? []))
                ->mapWithKeys(fn ($code, $sellerId): array => [(int) $sellerId => mb_strtoupper(trim((string) $code))])
                ->filter()
                ->all();
            foreach (array_keys($sellerSelections) as $sellerId) {
                if (! array_key_exists((int) $sellerId, $groups)) {
                    throw ValidationException::withMessages([
                        'seller_voucher_codes.'.$sellerId => 'This Seller voucher does not apply to the selected cart items.',
                    ]);
                }
            }

            foreach ($groups as $sellerId => &$group) {
                $code = (string) ($sellerSelections[(int) $sellerId] ?? '');
                if ($code === '') {
                    continue;
                }
                $voucher = $this->usableVoucher(
                    (int) $sellerId,
                    $code,
                    $group['items'],
                    $buyer,
                    $lockVoucherUsage,
                    'seller_voucher_codes.'.$sellerId,
                );
                if (! $voucher) {
                    throw ValidationException::withMessages([
                        'seller_voucher_codes.'.$sellerId => 'This voucher is invalid or no longer available for this Seller.',
                    ]);
                }
                $discount = $this->discountAmount($voucher, $this->voucherEligibleSubtotal($voucher, $group['items']));
                $group['seller_voucher'] = $voucher;
                $group['seller_voucher_discount'] = $discount;
                $group['voucher'] = $voucher;
                $group['voucher_discount'] = $discount;
            }
            unset($group);

            $eligibleProductTotal = array_sum(array_map(
                fn (array $group): float => max(0.0, (float) $group['item_subtotal'] - (float) $group['promotion_discount_total'] - (float) $group['seller_voucher_discount']),
                $groups,
            ));
            $platformCode = mb_strtoupper(trim((string) ($voucherCodes['platform'] ?? '')));
            if ($platformCode !== '') {
                $platformVoucher = $this->usablePlatformVoucher($platformCode, 'PRODUCT', $eligibleProductTotal, $buyer, $lockVoucherUsage);
                $platformDiscount = $this->discountAmount($platformVoucher, $eligibleProductTotal);
                $this->allocatePlatformDiscount($groups, $platformVoucher, $platformDiscount, $eligibleProductTotal);
            }

            $shippingCode = mb_strtoupper(trim((string) ($voucherCodes['shipping'] ?? '')));
            if ($shippingCode !== '') {
                $eligibleShippingTotal = array_sum(array_map(fn (array $group): float => (float) $group['shipping_fee'], $groups));
                $shippingSpendBase = array_sum(array_map(
                    fn (array $group): float => max(0.0, (float) $group['item_subtotal'] - (float) $group['promotion_discount_total'] - (float) $group['voucher_discount']),
                    $groups,
                ));
                $shippingVoucher = $this->usablePlatformVoucher($shippingCode, 'SHIPPING', $shippingSpendBase, $buyer, $lockVoucherUsage);
                $shippingDiscount = $this->discountAmount($shippingVoucher, $eligibleShippingTotal);
                $this->allocateShippingDiscount($groups, $shippingDiscount, $eligibleShippingTotal);
            }
        } else {
            foreach ($groups as $sellerId => &$group) {
                $code = trim((string) ($legacyVoucherCodes[$sellerId] ?? ''));
                $voucher = $code !== ''
                    ? $this->usableVoucher(
                        (int) $sellerId,
                        $code,
                        $group['items'],
                        $buyer,
                        $lockVoucherUsage,
                        'voucher_codes.'.$sellerId,
                    )
                    : null;
                if ($code !== '' && $voucher === null) {
                    throw ValidationException::withMessages([
                        'voucher_codes.'.$sellerId => 'This voucher is invalid or no longer available for this seller.',
                    ]);
                }
                $discount = $voucher ? $this->discountAmount($voucher, $this->voucherEligibleSubtotal($voucher, $group['items'])) : 0.0;

                $group['voucher'] = $voucher;
                $group['seller_voucher'] = $voucher;
                $group['seller_voucher_discount'] = $discount;
                $group['voucher_discount'] = $discount;
                $group['grand_total'] = max(0.0, (float) $group['item_subtotal'] - (float) $group['promotion_discount_total'] - $discount + (float) $group['shipping_fee'] + (float) $group['protection_total']);
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

        $eligibleSubtotalCents = array_sum(array_map(
            fn (array $group): int => max(0, FinancialRules::cents($group['item_subtotal']) - FinancialRules::cents($group['promotion_discount_total']) - FinancialRules::cents($group['voucher_discount'])),
            $groups,
        ));
        if ($rewardPointsToRedeem > 0 && ! $buyer) {
            throw ValidationException::withMessages([
                'reward_points' => 'Sign in to redeem reward points.',
            ]);
        }
        if ($buyer) {
            $this->rewardPoints->assertRedemptionIsValid($buyer, $rewardPointsToRedeem, $eligibleSubtotalCents);
        } elseif ($rewardPointsToRedeem !== 0) {
            throw ValidationException::withMessages([
                'reward_points' => 'Reward points cannot be redeemed for this checkout.',
            ]);
        }

        $this->allocateRewardPoints($groups, $rewardPointsToRedeem);

        foreach ($groups as &$group) {
            $discount = (float) $group['voucher_discount'];
            $rewardDiscount = (float) $group['reward_points_discount'];
            $shippingVoucherDiscount = min((float) $group['shipping_fee'], max(0.0, (float) $group['shipping_voucher_discount']));
            $group['shipping_voucher_discount'] = $shippingVoucherDiscount;
            $group['grand_total'] = max(0.0, (float) $group['item_subtotal'] - (float) $group['promotion_discount_total'] - $discount - $rewardDiscount + (float) $group['shipping_fee'] - $shippingVoucherDiscount + (float) $group['protection_total']);
            $discountTotal += $discount;
            $shippingTotal += (float) $group['shipping_fee'];
            $shippingVoucherDiscountTotal += $shippingVoucherDiscount;
        }
        unset($group);

        $rewardPointsDiscount = round($rewardPointsToRedeem / RewardPointsService::POINTS_PER_PESO, 2);
        $maximumRedeemablePoints = $buyer
            ? $this->rewardPoints->maximumRedeemablePoints($buyer, $eligibleSubtotalCents)
            : 0;
        $platformVoucherDiscountTotal = round(array_sum(array_map(fn (array $group): float => (float) $group['platform_voucher_discount'], $groups)), 2);
        $totalDiscount = round($discountTotal + $promotionDiscountTotal + $shippingVoucherDiscountTotal + $rewardPointsDiscount, 2);

        return [
            'groups' => collect($groups),
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'promotion_discount_total' => round($promotionDiscountTotal, 2),
            'platform_voucher' => $platformVoucher,
            'platform_voucher_discount' => $platformVoucherDiscountTotal,
            'shipping_voucher' => $shippingVoucher,
            'shipping_voucher_discount' => round($shippingVoucherDiscountTotal, 2),
            'total_discount' => $totalDiscount,
            'reward_points_redeemed' => $rewardPointsToRedeem,
            'reward_points_discount' => $rewardPointsDiscount,
            'available_reward_points' => $buyer ? $this->rewardPoints->balance($buyer) : 0,
            'maximum_redeemable_points' => $maximumRedeemablePoints,
            'shipping_total' => round($shippingTotal, 2),
            'protection_total' => round($protectionTotal, 2),
            'grand_total' => max(0.0, round($subtotal - $promotionDiscountTotal - $discountTotal - $rewardPointsDiscount + $shippingTotal - $shippingVoucherDiscountTotal + $protectionTotal, 2)),
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
        ?array $expectedPriceSnapshot = null,
        int $rewardPointsToRedeem = 0,
        ?string $recipientName = null,
        ?string $contactNumber = null,
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
            $rewardPointsToRedeem,
            $recipientName,
            $contactNumber,
        );
        $existing = $this->resolveCheckoutAttempt($buyer, $keyHash, $requestHash);
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($buyer, $addressId, $cartItemIds, $paymentMethod, $voucherCodes, $logisticsCenterId, $protectionItemIds, $sellerNotes, $keyHash, $requestHash, $expectedPriceSnapshot, $rewardPointsToRedeem, $recipientName, $contactNumber): Order {
            $existing = $this->resolveCheckoutAttempt($buyer, $keyHash, $requestHash, true);
            if ($existing) {
                return $existing;
            }

            // Serialize point redemption across simultaneous checkouts so a
            // buyer cannot spend the same balance on two orders at once.
            $lockedBuyer = User::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();

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

                $availability = $this->productAvailability->inspect(
                    $variant,
                    $product,
                    $seller,
                    $sellerUser,
                    (int) $item->quantity,
                );
                if (! $availability['available']) {
                    throw ValidationException::withMessages([
                        'cart' => $availability['message'],
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

            // This runs against the products locked above, after the cart and
            // variant locks. The product lock serializes separate checkouts
            // for the same product while the current-read history query sees
            // any order committed by the preceding checkout.
            $requestedByProduct = $items
                ->groupBy(fn (CartItem $item): int => (int) $lockedVariants->get($item->product_variant_id)->product_id)
                ->sortKeys();
            foreach ($requestedByProduct as $productId => $productItems) {
                $product = $lockedProducts->get((int) $productId);
                if (! $product) {
                    continue;
                }

                $this->purchaseLimits->assertWithinLimit(
                    $buyer,
                    $product,
                    (int) $productItems->sum('quantity'),
                    attribute: 'cart',
                    lockHistoricalRows: true,
                );
            }

            if ($expectedPriceSnapshot !== null) {
                $priceChanges = [];
                foreach ($items as $item) {
                    $expectedPrice = $expectedPriceSnapshot[(string) $item->id] ?? null;
                    if ($expectedPrice === null) {
                        continue;
                    }

                    $currentPrice = (float) $item->productVariant->final_price;
                    if ((int) round((float) $expectedPrice * 100) !== (int) round($currentPrice * 100)) {
                        $priceChanges[] = $item->productVariant->product->name
                            .' changed from PHP '.number_format((float) $expectedPrice, 2)
                            .' to PHP '.number_format($currentPrice, 2).'.';
                    }
                }

                if ($priceChanges !== []) {
                    throw ValidationException::withMessages([
                        'price_changed' => 'Product prices changed while you were reviewing checkout. '.implode(' ', $priceChanges).' Review the updated totals before placing the order.',
                    ]);
                }
            }

            $this->assertDeliveryAvailable($items, $address, $logisticsProvider);

            $this->lockVoucherRows($voucherCodes);

            $preview = $this->preview(
                $items,
                $voucherCodes,
                $lockedBuyer,
                $logisticsProvider,
                $address,
                $protectionItemIds,
                lockVoucherUsage: true,
                rewardPointsToRedeem: $rewardPointsToRedeem,
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
                'discount_total' => $preview['total_discount'],
                'reward_points_redeemed' => $preview['reward_points_redeemed'],
                'reward_points_discount' => $preview['reward_points_discount'],
                'shipping_total' => $preview['shipping_total'],
                'grand_total' => $preview['grand_total'],
                'payment_status' => 'PENDING',
                'placed_at' => now(),
            ];
            if (Schema::hasColumn('orders', 'platform_voucher_id')) {
                $orderAttributes['platform_voucher_id'] = $preview['platform_voucher']?->id;
                $orderAttributes['platform_voucher_discount'] = $preview['platform_voucher_discount'];
                $orderAttributes['shipping_voucher_id'] = $preview['shipping_voucher']?->id;
                $orderAttributes['shipping_voucher_discount'] = $preview['shipping_voucher_discount'];
            }
            if (Schema::hasColumn('orders', 'protection_total')) {
                $orderAttributes['protection_total'] = $preview['protection_total'];
            }
            $order = Order::query()->create($orderAttributes);
            DB::table('checkout_attempts')->where('id', $checkoutAttemptId)->update([
                'order_id' => $order->id,
                'updated_at' => now(),
            ]);

            $orderAddressAttributes = [
                'recipient_name' => $recipientName ?? $address->recipient_name,
                'contact_number' => $contactNumber ?? $address->contact_number,
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
            ];
            foreach (['region_code', 'region_name'] as $regionColumn) {
                if (Schema::hasColumn('order_addresses', $regionColumn)) {
                    $orderAddressAttributes[$regionColumn] = $address->{$regionColumn};
                }
            }
            $order->address()->create($orderAddressAttributes);

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
                    'voucher_id' => $group['seller_voucher']?->id,
                    'status' => 'PLACED',
                    'item_subtotal' => $group['item_subtotal'],
                    'voucher_discount' => $group['voucher_discount'],
                    'reward_points_redeemed' => $group['reward_points_redeemed'],
                    'reward_points_discount' => $group['reward_points_discount'],
                    'shipping_fee' => $group['shipping_fee'],
                    'grand_total' => $group['grand_total'],
                ];
                if (Schema::hasColumn('seller_orders', 'promotion_discount_total')) {
                    $sellerOrderAttributes['promotion_discount_total'] = $group['promotion_discount_total'];
                }
                if (Schema::hasColumn('seller_orders', 'shipping_voucher_discount')) {
                    $sellerOrderAttributes['shipping_voucher_discount'] = $group['shipping_voucher_discount'];
                }
                if ($group['shipping_rate_version_id'] !== null
                    && Schema::hasColumn('seller_orders', 'shipping_rate_version_id')) {
                    $sellerOrderAttributes['shipping_rate_version_id'] = $group['shipping_rate_version_id'];
                }
                if (Schema::hasColumn('seller_orders', 'shipping_rate_snapshot')) {
                    $sellerOrderAttributes['shipping_rate_snapshot'] = $group['shipping_rate_snapshot'];
                }
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
                    $unitPrice = (float) $item->getAttribute('checkout_unit_price');
                    $unitDiscount = (float) $item->getAttribute('checkout_discount_amount');
                    $flashSaleUnits = (int) $item->getAttribute('checkout_flash_sale_units');
                    $promotionDiscount = (float) $item->getAttribute('checkout_promotion_discount_amount');
                    $lineTotal = max(0.0, round($unitPrice * (int) $item->quantity - $promotionDiscount, 2));
                    $discountAmount = round($unitDiscount * (int) $item->quantity, 2);

                    $itemAttributes = [
                        'product_id' => $product->id,
                        'product_variant_id' => $item->productVariant->id,
                        'product_name' => $product->name,
                        'sku' => $item->productVariant->sku,
                        'variant_description' => $item->productVariant->description,
                        'unit_price' => $unitPrice,
                        'quantity' => $item->quantity,
                        'discount_amount' => $discountAmount,
                        'line_total' => $lineTotal,
                    ];
                    if ($snapshotReady) {
                        $itemAttributes['original_unit_price'] = number_format((float) $variant->price, 2, '.', '');
                    }
                    if (Schema::hasColumn('order_items', 'flash_sale_quantity')) {
                        $itemAttributes['flash_sale_quantity'] = $flashSaleUnits;
                    }
                    if (Schema::hasColumn('order_items', 'promotion_discount_amount')) {
                        $itemAttributes['promotion_discount_amount'] = $promotionDiscount;
                    }
                    if (Schema::hasColumn('order_items', 'promotion_snapshot')) {
                        $snapshots = (array) $item->getAttribute('checkout_promotion_snapshot');
                        $itemAttributes['promotion_snapshot'] = $snapshots !== [] ? $snapshots : null;
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
                    if ($flashSaleUnits > 0) {
                        $variant->increment('flash_sale_sold', $flashSaleUnits);
                    }
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

    /** @param array<int, array<string, mixed>> $groups */
    private function allocateRewardPoints(array &$groups, int $rewardPoints): void
    {
        $weights = [];
        foreach ($groups as $sellerId => $group) {
            $weights[$sellerId] = max(
                0,
                FinancialRules::cents($group['item_subtotal'])
                    - FinancialRules::cents($group['promotion_discount_total'])
                    - FinancialRules::cents($group['voucher_discount']),
            );
            $groups[$sellerId]['reward_points_redeemed'] = 0;
            $groups[$sellerId]['reward_points_discount'] = 0.0;
        }

        if ($rewardPoints <= 0) {
            return;
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0 || $rewardPoints > $totalWeight) {
            throw ValidationException::withMessages([
                'reward_points' => 'The points discount cannot exceed the eligible product total after vouchers.',
            ]);
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $sellerId => $weight) {
            $numerator = $rewardPoints * $weight;
            $share = intdiv($numerator, $totalWeight);
            $shares[$sellerId] = $share;
            $allocated += $share;
            $remainders[] = [
                'seller_id' => $sellerId,
                'remainder' => $numerator % $totalWeight,
            ];
        }

        usort($remainders, fn (array $left, array $right): int =>
            ($right['remainder'] <=> $left['remainder']) ?: ((int) $left['seller_id'] <=> (int) $right['seller_id']));
        $remaining = $rewardPoints - $allocated;
        foreach ($remainders as $row) {
            if ($remaining <= 0) {
                break;
            }

            $sellerId = $row['seller_id'];
            $capacity = $weights[$sellerId] - $shares[$sellerId];
            $extra = min($capacity, $remaining);
            $shares[$sellerId] += $extra;
            $remaining -= $extra;
        }

        foreach ($shares as $sellerId => $share) {
            $groups[$sellerId]['reward_points_redeemed'] = $share;
            // At the 100:1 rate, one point represents one centavo.
            $groups[$sellerId]['reward_points_discount'] = round($share / 100, 2);
        }
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function applySellerPromotions(array &$groups, bool $lockForUpdate = false): float
    {
        if ($groups === [] || ! Schema::hasTable('seller_promotions') || ! Schema::hasTable('seller_promotion_components')) {
            return 0.0;
        }

        $query = SellerPromotion::query()
            ->whereIn('seller_profile_id', array_map('intval', array_keys($groups)))
            ->where('is_active', true)
            ->where(fn ($window) => $window->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($window) => $window->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->with(['components.productVariant.product', 'buyVariant.product', 'rewardVariant.product'])
            ->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $promotions = $query->get();
        $reservedQuantities = [];
        $totalDiscount = 0.0;

        foreach ($promotions as $promotion) {
            $sellerId = (int) $promotion->seller_profile_id;
            if (! isset($groups[$sellerId])) {
                continue;
            }
            $items = $groups[$sellerId]['items'];

            if ($promotion->promotion_type === SellerPromotion::TYPE_BUNDLE) {
                $components = $promotion->components
                    ->filter(fn ($component) => $component->productVariant !== null)
                    ->values();
                if ($components->count() < 2) {
                    continue;
                }
                $sets = $components->map(fn ($component): int => intdiv(
                    $this->availableCartQuantity($items, (int) $component->product_variant_id, $reservedQuantities),
                    max(1, (int) $component->quantity),
                ))->min() ?? 0;
                if ($sets < 1) {
                    continue;
                }

                $availableRows = [];
                foreach ($components as $component) {
                    $required = (int) $component->quantity * $sets;
                    $availableRows = array_merge($availableRows, $this->reserveVariantQuantity(
                        $items,
                        (int) $component->product_variant_id,
                        $required,
                        $reservedQuantities,
                        reserve: false,
                    ));
                }
                $componentValue = array_sum(array_map(fn (array $row): float => $row['unit_price'] * $row['quantity'], $availableRows));
                $bundleValue = (float) $promotion->bundle_price * $sets;
                $discount = round(min($componentValue, max(0.0, $componentValue - $bundleValue)), 2);
                if ($discount <= 0) {
                    continue;
                }

                $allocations = [];
                foreach ($components as $component) {
                    $allocations = array_merge($allocations, $this->reserveVariantQuantity(
                        $items,
                        (int) $component->product_variant_id,
                        (int) $component->quantity * $sets,
                        $reservedQuantities,
                    ));
                }
                $this->applyPromotionDiscountToAllocations($allocations, $promotion, $discount, [
                    'bundle_sets' => $sets,
                    'bundle_price' => (float) $promotion->bundle_price,
                    'components' => $components->map(fn ($component): array => [
                        'product_variant_id' => (int) $component->product_variant_id,
                        'product_name' => $component->productVariant->product?->name,
                        'sku' => $component->productVariant->sku,
                        'quantity_per_bundle' => (int) $component->quantity,
                    ])->values()->all(),
                ]);
                $groups[$sellerId]['promotion_discount_total'] += $discount;
                $totalDiscount += $discount;

                continue;
            }

            if ($promotion->promotion_type !== SellerPromotion::TYPE_BUY_X_GET_Y
                || ! $promotion->buy_variant_id || ! $promotion->reward_variant_id
                || ! $promotion->buyVariant || ! $promotion->rewardVariant) {
                continue;
            }

            $buyVariantId = (int) $promotion->buy_variant_id;
            $rewardVariantId = (int) $promotion->reward_variant_id;
            $buyQuantity = max(1, (int) $promotion->buy_quantity);
            $rewardQuantity = max(1, (int) $promotion->reward_quantity);
            $buyAvailable = $this->availableCartQuantity($items, $buyVariantId, $reservedQuantities);
            $rewardAvailable = $this->availableCartQuantity($items, $rewardVariantId, $reservedQuantities);

            if ($buyVariantId === $rewardVariantId) {
                $sets = intdiv($buyAvailable, $buyQuantity + $rewardQuantity);
            } else {
                $sets = min(intdiv($buyAvailable, $buyQuantity), intdiv($rewardAvailable, $rewardQuantity));
            }
            if ($sets < 1) {
                continue;
            }

            $buyAllocations = $this->reserveVariantQuantity(
                $items,
                $buyVariantId,
                $buyQuantity * $sets,
                $reservedQuantities,
            );
            $rewardAllocations = $this->reserveVariantQuantity(
                $items,
                $rewardVariantId,
                $rewardQuantity * $sets,
                $reservedQuantities,
            );
            if (array_sum(array_column($buyAllocations, 'quantity')) !== $buyQuantity * $sets
                || array_sum(array_column($rewardAllocations, 'quantity')) !== $rewardQuantity * $sets) {
                continue;
            }

            $discount = round(array_sum(array_map(fn (array $row): float => $row['unit_price'] * $row['quantity'], $rewardAllocations)), 2);
            if ($discount <= 0) {
                continue;
            }

            $this->applyPromotionDiscountToAllocations($rewardAllocations, $promotion, $discount, [
                'buy_variant_id' => $buyVariantId,
                'buy_product_name' => $promotion->buyVariant->product?->name,
                'buy_sku' => $promotion->buyVariant->sku,
                'buy_quantity' => $buyQuantity * $sets,
                'reward_variant_id' => $rewardVariantId,
                'reward_product_name' => $promotion->rewardVariant->product?->name,
                'reward_sku' => $promotion->rewardVariant->sku,
                'reward_quantity' => $rewardQuantity * $sets,
            ]);
            $groups[$sellerId]['promotion_discount_total'] += $discount;
            $totalDiscount += $discount;
        }

        return round($totalDiscount, 2);
    }

    /** @param array<int, int> $reservedQuantities */
    private function availableCartQuantity(Collection $items, int $variantId, array $reservedQuantities): int
    {
        return (int) $items
            ->filter(fn (CartItem $item): bool => (int) $item->product_variant_id === $variantId)
            ->sum(fn (CartItem $item): int => max(0, (int) $item->quantity - ($reservedQuantities[(int) $item->id] ?? 0)));
    }

    /**
     * @param array<int, int> $reservedQuantities
     * @return array<int, array{item: CartItem, quantity: int, unit_price: float}>
     */
    private function reserveVariantQuantity(
        Collection $items,
        int $variantId,
        int $quantity,
        array &$reservedQuantities,
        bool $reserve = true,
    ): array {
        $remaining = $quantity;
        $allocations = [];
        foreach ($items->sortBy('id') as $item) {
            if ((int) $item->product_variant_id !== $variantId || $remaining <= 0) {
                continue;
            }
            $itemId = (int) $item->id;
            $available = max(0, (int) $item->quantity - ($reservedQuantities[$itemId] ?? 0));
            $take = min($remaining, $available);
            if ($take <= 0) {
                continue;
            }
            $unitPrice = (float) $item->getAttribute('checkout_unit_price');
            $allocations[] = ['item' => $item, 'quantity' => $take, 'unit_price' => $unitPrice];
            if ($reserve) {
                $reservedQuantities[$itemId] = ($reservedQuantities[$itemId] ?? 0) + $take;
            }
            $remaining -= $take;
        }

        return $allocations;
    }

    /** @param array<int, array{item: CartItem, quantity: int, unit_price: float}> $allocations */
    private function applyPromotionDiscountToAllocations(array $allocations, SellerPromotion $promotion, float $discount, array $snapshot): void
    {
        $baseCents = array_map(fn (array $row): int => FinancialRules::cents($row['unit_price'] * $row['quantity']), $allocations);
        $totalBaseCents = array_sum($baseCents);
        $discountCents = min($totalBaseCents, FinancialRules::cents($discount));
        if ($totalBaseCents <= 0 || $discountCents <= 0) {
            return;
        }

        $allocatedCents = 0;
        $lastIndex = array_key_last($allocations);
        foreach ($allocations as $index => $row) {
            $shareCents = $index === $lastIndex
                ? $discountCents - $allocatedCents
                : intdiv($discountCents * $baseCents[$index], $totalBaseCents);
            $shareCents = min($baseCents[$index], max(0, $shareCents));
            $allocatedCents += $shareCents;
            if ($shareCents <= 0) {
                continue;
            }

            $item = $row['item'];
            $item->setAttribute(
                'checkout_promotion_discount_amount',
                round((float) $item->getAttribute('checkout_promotion_discount_amount') + ($shareCents / 100), 2),
            );
            $snapshots = (array) $item->getAttribute('checkout_promotion_snapshot');
            $snapshots[] = array_merge([
                'promotion_id' => (int) $promotion->id,
                'promotion_type' => $promotion->promotion_type,
                'name' => $promotion->name,
                'discount_amount' => number_format($shareCents / 100, 2, '.', ''),
                'discounted_quantity' => (int) $row['quantity'],
            ], $snapshot);
            $item->setAttribute('checkout_promotion_snapshot', $snapshots);
        }
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
        int $rewardPointsToRedeem,
        ?string $recipientName,
        ?string $contactNumber,
    ): string {
        sort($cartItemIds);
        $normalizeVoucherPayload = function (mixed $value) use (&$normalizeVoucherPayload): array|string {
            if (! is_array($value)) {
                return mb_strtoupper(trim((string) $value));
            }
            $normalized = [];
            foreach ($value as $key => $child) {
                $normalized[$key] = $normalizeVoucherPayload($child);
            }
            ksort($normalized);

            return $normalized;
        };
        $voucherPayload = $normalizeVoucherPayload($voucherCodes);
        $protectionPayload = array_values(array_unique(array_map('intval', $protectionItemIds)));
        sort($protectionPayload);
        $notePayload = [];
        foreach ($sellerNotes as $sellerId => $note) {
            $notePayload[(string) $sellerId] = trim((string) ($note ?? ''));
        }
        ksort($notePayload);

        $requestPayload = [
            'buyer_id' => $buyerId,
            'cart_item_ids' => $cartItemIds,
            'address_id' => $addressId,
            'payment_method' => strtoupper(trim($paymentMethod)),
            'voucher_codes' => $voucherPayload,
            'logistics_center_id' => $logisticsCenterId,
            'protection_item_ids' => $protectionPayload,
            'seller_notes' => $notePayload,
            'reward_points_to_redeem' => $rewardPointsToRedeem,
        ];

        // Keep the legacy web-checkout hash byte-for-byte compatible when the
        // optional mobile overrides are absent. Mobile requests include these
        // fields so different recipient details cannot reuse the same attempt.
        if ($recipientName !== null) {
            $requestPayload['recipient_name'] = trim($recipientName);
        }
        if ($contactNumber !== null) {
            $requestPayload['contact_number'] = trim($contactNumber);
        }

        return hash('sha256', json_encode(
            $requestPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
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
            if (mb_strlen($trimmed) > 200) {
                throw ValidationException::withMessages([
                    'seller_notes.'.$sellerId => 'Seller notes may not exceed 200 characters.',
                ]);
            }

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

    private function usableVoucher(
        int $sellerId,
        string $code,
        Collection $items,
        ?User $buyer = null,
        bool $lockVoucherUsage = false,
        string $errorKey = 'voucher_code',
    ): ?Voucher
    {
        $voucher = Voucher::query()
            ->where('seller_profile_id', $sellerId)
            ->where('code', mb_strtoupper($code))
            ->when(Schema::hasColumn('vouchers', 'platform_code_key'), fn ($query) => $query->whereNull('platform_code_key'))
            ->when(Schema::hasColumn('vouchers', 'benefit_scope'), fn ($query) => $query->where('benefit_scope', 'PRODUCT'))
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->when(
                Schema::hasColumn('vouchers', 'visibility') && $buyer !== null,
                fn ($query) => $query->where(fn ($access) => $access
                    ->where('visibility', 'CODE_ONLY')
                    ->orWhereHas('buyerClaims', fn ($claims) => $claims->where('buyer_user_id', $buyer->id))),
                fn ($query) => $query->whereHas('buyerClaims', fn ($claims) => $claims->where('buyer_user_id', $buyer?->id ?? 0)),
            )
            ->whereHas('sellerProfile', fn ($query) => $query
                ->where('status', 'ACTIVE')
                ->whereHas('user', fn ($user) => $user
                    ->where('account_type', User::TYPE_SELLER)
                    ->where('status', User::STATUS_ACTIVE)))
            ->with('eligibleProducts:id')
            ->withCount([
                'sellerOrders' => fn ($query) => $query->where('status', '<>', 'CANCELLED'),
                'sellerOrders as buyer_uses_count' => fn ($query) => $query->where('status', '<>', 'CANCELLED')->when(
                    $buyer,
                    fn ($uses) => $uses->whereHas('order', fn ($order) => $order->where('buyer_user_id', $buyer->id)),
                    fn ($uses) => $uses->whereRaw('1 = 0'),
                ),
            ])
            ->when($lockVoucherUsage, fn ($query) => $query->lockForUpdate())
            ->first();
        if ($voucher && $lockVoucherUsage) {
            $usage = VoucherRedemptionCounter::lockedUsageFor((int) $voucher->id, $buyer?->id);
            $voucher->setAttribute('seller_orders_count', $usage['redemptions']);
            $voucher->setAttribute('buyer_uses_count', $usage['buyer_redemptions']);
        }
        if (! $voucher
            || ($voucher->usage_limit !== null && $voucher->seller_orders_count >= $voucher->usage_limit)
            || ($buyer && $voucher->per_user_limit !== null && $voucher->buyer_uses_count >= $voucher->per_user_limit)) {
            return null;
        }

        $eligibleSubtotal = $this->voucherEligibleSubtotal($voucher, $items);
        if ($eligibleSubtotal <= 0) {
            throw ValidationException::withMessages([
                $errorKey => 'This voucher does not apply to the selected cart items.',
            ]);
        }
        if ($eligibleSubtotal < (float) $voucher->minimum_order_amount) {
            throw ValidationException::withMessages([
                $errorKey => 'Add PHP '.number_format((float) $voucher->minimum_order_amount - $eligibleSubtotal, 2).' more in eligible products to use this voucher.',
            ]);
        }

        return $voucher;
    }

    private function voucherEligibleSubtotal(Voucher $voucher, Collection $items): float
    {
        if ($voucher->applies_to !== 'PRODUCTS') {
            return round((float) $items->sum(fn (CartItem $item): float => $this->checkoutItemSubtotalAfterPromotions($item)), 2);
        }

        $productIds = $voucher->relationLoaded('eligibleProducts')
            ? $voucher->eligibleProducts->modelKeys()
            : $voucher->eligibleProducts()->pluck('products.id')->all();
        $eligibleIds = array_fill_keys(array_map('intval', $productIds), true);

        return round((float) $items->sum(function (CartItem $item) use ($eligibleIds): float {
            $item->loadMissing('productVariant');
            $variant = $item->productVariant;
            if (! $variant || ! isset($eligibleIds[(int) $variant->product_id])) {
                return 0.0;
            }

            return $this->checkoutItemSubtotalAfterPromotions($item);
        }), 2);
    }

    private function checkoutItemSubtotalAfterPromotions(CartItem $item): float
    {
        $item->loadMissing('productVariant');
        $unitPrice = $item->getAttribute('checkout_unit_price');
        $unitPrice = $unitPrice === null ? (float) ($item->productVariant?->final_price ?? 0) : (float) $unitPrice;
        $lineSubtotal = $unitPrice * (int) $item->quantity;
        $promotionDiscount = (float) ($item->getAttribute('checkout_promotion_discount_amount') ?? 0);

        return round(max(0.0, $lineSubtotal - $promotionDiscount), 2);
    }

    private function assertFlashSaleAllocations(Collection $items): void
    {
        $items->groupBy('product_variant_id')->each(function (Collection $variantItems): void {
            /** @var CartItem|null $firstItem */
            $firstItem = $variantItems->first();
            if (! $firstItem) {
                return;
            }

            $firstItem->loadMissing('productVariant.product');
            $variant = $firstItem->productVariant;
            $flashPrice = $variant?->active_flash_sale_price;
            if (! $variant || $flashPrice === null) {
                return;
            }

            $requested = (int) $variantItems->sum('quantity');
            $remaining = (int) $variant->flash_sale_remaining;
            if ($requested > $remaining) {
                $productName = $variant->product?->name ?? 'This product';
                throw ValidationException::withMessages([
                    'cart' => "Only {$remaining} unit(s) remain at the flash-sale price for {$productName}. Reduce the quantity and refresh checkout. The product remains available at its regular price after the allocation is exhausted.",
                ]);
            }
        });
    }

    private function usablePlatformVoucher(string $code, string $scope, float $eligibleSubtotal, ?User $buyer, bool $lockVoucherUsage): Voucher
    {
        $field = $scope === 'SHIPPING' ? 'shipping_voucher_code' : 'platform_voucher_code';
        if (! Schema::hasColumn('vouchers', 'platform_code_key')) {
            throw ValidationException::withMessages([$field => 'Platform vouchers are temporarily unavailable.']);
        }

        $query = Voucher::query()
            ->whereNull('seller_profile_id')
            ->where('platform_code_key', mb_strtoupper($code))
            ->when(Schema::hasColumn('vouchers', 'benefit_scope'), fn ($voucherQuery) => $voucherQuery->where('benefit_scope', $scope));
        if ($lockVoucherUsage) {
            $query->lockForUpdate();
        }
        $voucher = $query->first();
        if (! $voucher) {
            throw ValidationException::withMessages([$field => 'Choose an available LIKHAE voucher for this category.']);
        }

        $usage = $lockVoucherUsage
            ? VoucherRedemptionCounter::lockedUsageFor((int) $voucher->id, $buyer?->id)
            : null;
        $redemptions = $usage['redemptions'] ?? VoucherRedemptionCounter::count((int) $voucher->id);
        $buyerRedemptions = $usage['buyer_redemptions'] ?? ($buyer
            ? VoucherRedemptionCounter::count((int) $voucher->id, (int) $buyer->id)
            : 0);

        if (! $voucher->is_active || ($voucher->starts_at && $voucher->starts_at->isFuture())) {
            throw ValidationException::withMessages([$field => 'This voucher is not active yet.']);
        }
        if ($voucher->ends_at && $voucher->ends_at->isPast()) {
            throw ValidationException::withMessages([$field => 'This voucher has expired.']);
        }
        if ($voucher->usage_limit !== null && $redemptions >= $voucher->usage_limit) {
            throw ValidationException::withMessages([$field => 'This voucher has reached its redemption limit.']);
        }
        if ($buyer && $voucher->per_user_limit !== null && $buyerRedemptions >= $voucher->per_user_limit) {
            throw ValidationException::withMessages([$field => 'You have already used this voucher the maximum number of times.']);
        }
        if ($eligibleSubtotal < (float) $voucher->minimum_order_amount) {
            throw ValidationException::withMessages([$field => 'The eligible amount does not meet this voucher’s minimum spend.']);
        }

        return $voucher;
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function applySingleVoucherCode(array &$groups, string $code, ?User $buyer, bool $lockVoucherUsage = false): ?Voucher
    {
        $query = Voucher::query()
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->with('sellerProfile.user');
        if ($lockVoucherUsage) {
            $query->lockForUpdate();
        }
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
            if (Schema::hasColumn('vouchers', 'benefit_scope') && $voucher->benefit_scope !== 'PRODUCT') {
                throw ValidationException::withMessages([
                    'voucher_code' => 'Choose shipping vouchers from the Shipping Voucher picker.',
                ]);
            }
            $subtotal = array_sum(array_map(fn (array $group): float => max(0.0, (float) $group['item_subtotal'] - (float) $group['promotion_discount_total']), $groups));
            $usage = $lockVoucherUsage
                ? VoucherRedemptionCounter::lockedUsageFor((int) $voucher->id, $buyer?->id)
                : null;
            $redemptions = $usage['redemptions'] ?? VoucherRedemptionCounter::count((int) $voucher->id);
            $buyerRedemptions = $usage['buyer_redemptions'] ?? ($buyer
                ? VoucherRedemptionCounter::count((int) $voucher->id, (int) $buyer->id)
                : 0);

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

            return $voucher;
        }

        if (Schema::hasColumn('vouchers', 'benefit_scope') && $voucher->benefit_scope !== 'PRODUCT') {
            throw ValidationException::withMessages([
                'voucher_code' => 'Seller vouchers can only discount eligible products.',
            ]);
        }

        $requiresClaim = ! Schema::hasColumn('vouchers', 'visibility') || $voucher->visibility !== 'CODE_ONLY';
        if ($voucher->platform_code_key !== null
            || ! $buyer
            || ($requiresClaim && ! $voucher->buyerClaims()->where('buyer_user_id', $buyer->id)->exists())) {
            throw ValidationException::withMessages([
                'voucher_code' => 'Collect this Seller voucher in Rewards before using it.',
            ]);
        }

        if (! $voucher->sellerProfile
            || $voucher->sellerProfile->status !== 'ACTIVE'
            || ! $voucher->sellerProfile->user
            || ! $voucher->sellerProfile->user->isActive()
            || ! $voucher->sellerProfile->user->isAccountType(User::TYPE_SELLER)) {
            throw ValidationException::withMessages([
                'voucher_code' => 'This Seller is not currently eligible for orders.',
            ]);
        }

        $sellerId = (int) $voucher->seller_profile_id;
        $group = &$groups[$sellerId];

        if ($lockVoucherUsage) {
            $usage = VoucherRedemptionCounter::lockedUsageFor((int) $voucher->id, $buyer?->id);
            $voucher->setAttribute('seller_orders_count', $usage['redemptions']);
            $voucher->setAttribute('buyer_uses_count', $usage['buyer_redemptions']);
        }

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

        $eligibleSubtotal = $this->voucherEligibleSubtotal($voucher, $group['items']);
        if ($eligibleSubtotal <= 0) {
            throw ValidationException::withMessages([
                'voucher_code' => 'This voucher does not apply to the selected cart items.',
            ]);
        }
        if ($eligibleSubtotal < (float) $voucher->minimum_order_amount) {
            $remaining = (float) $voucher->minimum_order_amount - $eligibleSubtotal;

            throw ValidationException::withMessages([
                'voucher_code' => 'Add PHP '.number_format($remaining, 2).' more from '.$voucher->sellerProfile?->business_name.' to use this voucher.',
            ]);
        }

        $discount = $this->discountAmount($voucher, $eligibleSubtotal);
        $group['seller_voucher'] = $voucher;
        $group['seller_voucher_discount'] = $discount;
        $group['voucher'] = $voucher;
        $group['voucher_discount'] = $discount;

        return null;
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function allocatePlatformDiscount(array &$groups, Voucher $voucher, float $discount, float $subtotal): void
    {
        $allocated = 0.0;
        $lastSellerId = array_key_last($groups);

        foreach ($groups as $sellerId => &$group) {
            $eligibleGroupSubtotal = max(0.0, (float) $group['item_subtotal'] - (float) $group['promotion_discount_total'] - (float) $group['seller_voucher_discount']);
            $share = (int) $sellerId === (int) $lastSellerId
                ? round($discount - $allocated, 2)
                : round($subtotal > 0 ? $discount * ($eligibleGroupSubtotal / $subtotal) : 0.0, 2);
            $share = min($eligibleGroupSubtotal, max(0.0, $share));
            $group['platform_voucher_discount'] = $share;
            $group['voucher_discount'] = (float) $group['seller_voucher_discount'] + $share;
            $group['voucher'] = $group['seller_voucher'] ?? $voucher;
            $allocated += $share;
        }
        unset($group);
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function allocateShippingDiscount(array &$groups, float $discount, float $shippingSubtotal): void
    {
        $allocated = 0.0;
        $lastSellerId = array_key_last($groups);
        foreach ($groups as $sellerId => &$group) {
            $fee = max(0.0, (float) $group['shipping_fee']);
            $share = (int) $sellerId === (int) $lastSellerId
                ? round($discount - $allocated, 2)
                : round($shippingSubtotal > 0 ? $discount * ($fee / $shippingSubtotal) : 0.0, 2);
            $share = min($fee, max(0.0, $share));
            $group['shipping_voucher_discount'] = $share;
            $allocated += $share;
        }
        unset($group);
    }

    private function lockVoucherRows(array|string $voucherCodes): void
    {
        $flattenCodes = function (mixed $value) use (&$flattenCodes): array {
            if (! is_array($value)) {
                return [(string) $value];
            }

            $codes = [];
            foreach ($value as $child) {
                $codes = [...$codes, ...$flattenCodes($child)];
            }

            return $codes;
        };
        $codes = collect($flattenCodes($voucherCodes))
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
        return $this->voucherDiscounts->calculate($voucher, $subtotal);
    }

    private function uniqueNumber(string $table, string $column, string $prefix): string
    {
        do {
            $value = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (DB::table($table)->where($column, $value)->exists());

        return $value;
    }
}
