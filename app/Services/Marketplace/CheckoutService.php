<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
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
use App\Support\AddressCoordinateValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function availableVouchers(Collection $items, ?User $buyer = null): Collection
    {
        return $items
            ->groupBy(fn (CartItem $item) => (int) $item->productVariant->product->seller_profile_id)
            ->map(function (Collection $sellerItems, int $sellerId) use ($buyer): Collection {
                return Voucher::query()
                    ->where('seller_profile_id', $sellerId)
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->withCount([
                        'sellerOrders',
                        'sellerOrders as buyer_uses_count' => fn ($query) => $buyer
                            ? $query->whereHas('order', fn ($order) => $order->where('buyer_user_id', $buyer->id))
                            : $query->whereRaw('1 = 0'),
                    ])
                    ->get()
                    ->filter(fn (Voucher $voucher): bool => ($voucher->usage_limit === null || $voucher->seller_orders_count < $voucher->usage_limit)
                        && ($voucher->per_user_limit === null || $voucher->buyer_uses_count < $voucher->per_user_limit)
                    )
                    ->values();
            })
            ->filter(fn (Collection $vouchers): bool => $vouchers->isNotEmpty());
    }

    public function availableLogisticsProviders(): Collection
    {
        return LogisticsCenter::query()
            ->where('status', 'ACTIVE')
            ->whereHas('serviceAreas', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('locations'))
            ->orderBy('business_name')
            ->get(['id', 'code', 'business_name', 'shipping_fee']);
    }

    public function preview(
        Collection $items,
        array|string $voucherCodes = [],
        ?User $buyer = null,
        ?LogisticsCenter $logisticsProvider = null,
    ): array
    {
        $singleVoucherCode = is_string($voucherCodes) ? mb_strtoupper(trim($voucherCodes)) : '';
        $legacyVoucherCodes = is_array($voucherCodes) ? $voucherCodes : [];
        $groups = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $shippingTotal = 0.0;
        $shippingFee = $logisticsProvider ? (float) $logisticsProvider->shipping_fee : 0.0;

        foreach ($items as $item) {
            /** @var CartItem $item */
            $item->loadMissing(['productVariant.product.sellerProfile', 'productVariant.optionValues.option']);
            $variant = $item->productVariant;
            $product = $variant->product;
            $seller = $product->sellerProfile;
            $sellerId = (int) $seller->id;
            $lineTotal = $variant->final_price * (int) $item->quantity;
            $subtotal += $lineTotal;

            $groups[$sellerId] ??= [
                'seller' => $seller,
                'items' => collect(),
                'item_subtotal' => 0.0,
                'voucher' => null,
                'voucher_discount' => 0.0,
                'shipping_fee' => $shippingFee,
                'grand_total' => 0.0,
            ];

            $groups[$sellerId]['items']->push($item);
            $groups[$sellerId]['item_subtotal'] += $lineTotal;
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
                $group['grand_total'] = max(0.0, (float) $group['item_subtotal'] - $discount + (float) $group['shipping_fee']);
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
            $group['grand_total'] = max(0.0, (float) $group['item_subtotal'] - $discount + (float) $group['shipping_fee']);
            $discountTotal += $discount;
            $shippingTotal += (float) $group['shipping_fee'];
        }
        unset($group);

        return [
            'groups' => collect($groups),
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'shipping_total' => round($shippingTotal, 2),
            'grand_total' => round($subtotal - $discountTotal + $shippingTotal, 2),
        ];
    }

    public function placeOrder(
        User $buyer,
        Address $address,
        Collection $items,
        string $paymentMethod,
        array|string $voucherCodes = [],
        ?int $logisticsCenterId = null,
    ): Order
    {
        AddressCoordinateValidator::assertValid([
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ]);

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'Select at least one cart item before checkout.',
            ]);
        }

        $logisticsProvider = $logisticsCenterId === null
            ? null
            : LogisticsCenter::query()
                ->whereKey($logisticsCenterId)
                ->where('status', 'ACTIVE')
                ->first();

        if ($logisticsCenterId !== null && ! $logisticsProvider) {
            throw ValidationException::withMessages([
                'logistics_center_id' => 'Select an active logistics provider.',
            ]);
        }

        foreach ($items as $item) {
            $configuredMethod = strtolower((string) ($item->productVariant?->payment_method ?? 'cod_online'));
            $allowed = match ($configuredMethod) {
                'cod' => ['COD'],
                'online' => ['ONLINE'],
                default => ['COD', 'ONLINE'],
            };

            if (! in_array($paymentMethod, $allowed, true)) {
                throw ValidationException::withMessages([
                    'payment_method' => 'The selected payment method is not available for one or more products in this order.',
                ]);
            }
        }

        $areaLocation = $this->serviceAreaLocationForAddress($address, $logisticsProvider);
        if (! $areaLocation) {
            throw ValidationException::withMessages([
                $logisticsProvider ? 'logistics_center_id' : 'address_id' => $logisticsProvider
                    ? 'The selected logistics provider does not serve this delivery address.'
                    : 'Delivery is not currently available to this address.',
            ]);
        }

        return DB::transaction(function () use ($buyer, $address, $items, $paymentMethod, $voucherCodes, $logisticsProvider): Order {
            $variantIds = $items->pluck('product_variant_id')->unique()->values();
            $lockedVariants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedProducts = Product::query()
                ->whereIn('id', $lockedVariants->pluck('product_id')->filter()->unique()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSellers = SellerProfile::query()
                ->whereIn('id', $lockedProducts->pluck('seller_profile_id')->filter()->unique()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSellerUsers = User::query()
                ->whereIn('id', $lockedSellers->pluck('user_id')->filter()->unique()->values())
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

                if (! $variant->is_active || $variant->stock < $item->quantity) {
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

            $preview = $this->preview($items, $voucherCodes, $buyer, $logisticsProvider);

            $order = Order::query()->create([
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
                'method' => $paymentMethod,
                'provider' => $paymentMethod === 'ONLINE' ? 'MANUAL_ONLINE' : 'COD',
                'provider_reference' => null,
                'amount' => $order->grand_total,
                'status' => 'PENDING',
                'initiated_at' => now(),
            ]);

            foreach ($preview['groups'] as $sellerId => $group) {
                $sellerOrder = SellerOrder::query()->create([
                    'seller_order_number' => $this->uniqueNumber('seller_orders', 'seller_order_number', 'SO'),
                    'order_id' => $order->id,
                    'seller_profile_id' => $sellerId,
                    'voucher_id' => $group['voucher']?->id,
                    'status' => 'PLACED',
                    'item_subtotal' => $group['item_subtotal'],
                    'voucher_discount' => $group['voucher_discount'],
                    'shipping_fee' => $group['shipping_fee'],
                    'grand_total' => $group['grand_total'],
                ]);

                foreach ($group['items'] as $item) {
                    /** @var CartItem $item */
                    $item->loadMissing(['productVariant.product', 'productVariant.optionValues.option']);
                    $variant = $lockedVariants->get($item->product_variant_id);
                    $product = $item->productVariant->product;
                    $lineTotal = $variant->final_price * (int) $item->quantity;
                    $discountAmount = $variant->discount_amount * (int) $item->quantity;

                    $sellerOrder->items()->create([
                        'product_id' => $product->id,
                        'product_variant_id' => $item->productVariant->id,
                        'product_name' => $product->name,
                        'sku' => $item->productVariant->sku,
                        'variant_description' => $item->productVariant->description,
                        'unit_price' => $variant->final_price,
                        'quantity' => $item->quantity,
                        'discount_amount' => $discountAmount,
                        'line_total' => $lineTotal,
                    ]);

                    $variant->decrement('stock', (int) $item->quantity);
                }

                $this->createShipmentForSellerOrder($sellerOrder, $address, $logisticsProvider);
            }

            $cartId = $items->first()?->cart_id;
            CartItem::query()->whereIn('id', $items->pluck('id'))->delete();

            if ($cartId && ! CartItem::query()->where('cart_id', $cartId)->exists()) {
                Cart::query()
                    ->whereKey($cartId)
                    ->update([
                        'status' => Cart::STATUS_CONVERTED,
                        'converted_at' => now(),
                    ]);
            }

            return $order->load(['sellerOrders.items', 'address', 'payments']);
        });
    }

    private function createShipmentForSellerOrder(
        SellerOrder $sellerOrder,
        Address $address,
        ?LogisticsCenter $logisticsProvider = null,
    ): Shipment
    {
        $areaLocation = $this->serviceAreaLocationForAddress($address, $logisticsProvider);

        if (! $areaLocation) {
            throw ValidationException::withMessages([
                $logisticsProvider ? 'logistics_center_id' : 'address_id' => $logisticsProvider
                    ? 'The selected logistics provider does not serve this delivery address.'
                    : 'Delivery is not currently available to this address.',
            ]);
        }

        $shipment = Shipment::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => $this->uniqueNumber('shipments', 'tracking_number', 'TRK'),
            'logistics_center_id' => $logisticsProvider?->id ?? $areaLocation->serviceArea?->logistics_center_id,
            'service_area_id' => $areaLocation->service_area_id,
            'destination_province_code' => $address->province_code,
            'destination_province_name' => $address->province_name,
            'destination_municipality_code' => $address->municipality_code,
            'destination_municipality_name' => $address->municipality_name,
            'destination_barangay_code' => $address->barangay_code,
            'destination_barangay_name' => $address->barangay_name,
            'current_status' => 'PLACED',
        ]);

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'status' => 'PLACED',
            'actor_user_id' => $sellerOrder->order?->buyer_user_id,
            'logistics_center_id' => $shipment->logistics_center_id,
            'rider_assignment_id' => null,
            'notes' => 'Order placed by buyer.',
            'occurred_at' => now(),
        ]);

        return $shipment;
    }

    private function serviceAreaLocationForAddress(Address $address, ?LogisticsCenter $logisticsProvider = null): ?ServiceAreaLocation
    {
        return ServiceAreaLocation::query()
            ->where('province_code', $address->province_code)
            ->where('municipality_code', $address->municipality_code)
            ->where('barangay_code', $address->barangay_code)
            ->whereHas('serviceArea', function ($query) use ($logisticsProvider): void {
                $query->where('is_active', true)
                    ->when($logisticsProvider, fn ($providerQuery) => $providerQuery->where('logistics_center_id', $logisticsProvider->id));
            })
            ->with('serviceArea')
            ->first();
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
                'sellerOrders',
                'sellerOrders as buyer_uses_count' => fn ($query) => $buyer
                    ? $query->whereHas('order', fn ($order) => $order->where('buyer_user_id', $buyer->id))
                    : $query->whereRaw('1 = 0'),
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
        $candidates = Voucher::query()
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])
            ->with('sellerProfile')
            ->withCount([
                'sellerOrders',
                'sellerOrders as buyer_uses_count' => fn ($query) => $buyer
                    ? $query->whereHas('order', fn ($order) => $order->where('buyer_user_id', $buyer->id))
                    : $query->whereRaw('1 = 0'),
            ])
            ->get();

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'voucher_code' => 'We could not find that voucher code. Check it and try again.',
            ]);
        }

        $cartCandidates = $candidates
            ->filter(fn (Voucher $voucher): bool => array_key_exists((int) $voucher->seller_profile_id, $groups))
            ->values();

        if ($cartCandidates->isEmpty()) {
            $shopNames = $candidates->pluck('sellerProfile.business_name')->filter()->unique()->values();
            $message = $shopNames->count() === 1
                ? 'This voucher belongs to '.$shopNames->first().' and does not apply to a shop in this checkout.'
                : 'This voucher does not apply to any shop in this checkout.';

            throw ValidationException::withMessages(['voucher_code' => $message]);
        }

        if ($cartCandidates->count() > 1) {
            throw ValidationException::withMessages([
                'voucher_code' => 'This code matches more than one shop in your cart. Ask the sellers for a unique code.',
            ]);
        }

        $voucher = $cartCandidates->first();
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
