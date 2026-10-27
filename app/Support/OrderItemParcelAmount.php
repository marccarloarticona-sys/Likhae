<?php

namespace App\Support;

use App\Models\Buyer\OrderItem;
use App\Models\Seller\SellerOrder;

final class OrderItemParcelAmount
{
    public static function refundSliceCents(
        int $linePaidCents,
        int $quantityTotal,
        int $alreadyClaimedQuantity,
        int $requestedQuantity,
    ): int {
        $quantityTotal = max(1, $quantityTotal);
        $alreadyClaimedQuantity = min($quantityTotal, max(0, $alreadyClaimedQuantity));
        $requestedQuantity = min(max(0, $requestedQuantity), $quantityTotal - $alreadyClaimedQuantity);
        $afterQuantity = $alreadyClaimedQuantity + $requestedQuantity;
        $beforeCents = intdiv(max(0, $linePaidCents) * $alreadyClaimedQuantity, $quantityTotal);
        $afterCents = intdiv(max(0, $linePaidCents) * $afterQuantity, $quantityTotal);

        return $afterCents - $beforeCents;
    }

    public static function buyerProductRefundSliceCents(
        SellerOrder $sellerOrder,
        OrderItem $item,
        int $alreadyClaimedQuantity,
        int $requestedQuantity,
    ): int {
        $amounts = self::forItem($sellerOrder, $item);
        $paidCents = max(0, $amounts['product_amount_cents'] - $amounts['discount_amount_cents']);

        return self::refundSliceCents(
            $paidCents,
            (int) $item->quantity,
            $alreadyClaimedQuantity,
            $requestedQuantity,
        );
    }

    /** @return array{product_amount: float, discount_amount: float, shipping_fee: float, shipping_discount: float, protection_amount: float, total: float, product_amount_cents: int, discount_amount_cents: int, shipping_fee_cents: int, shipping_discount_cents: int, protection_amount_cents: int, total_cents: int} */
    public static function forItem(SellerOrder $sellerOrder, OrderItem $target): array
    {
        $items = $sellerOrder->items->values();
        $targetIndex = $items->search(fn (OrderItem $item): bool => (int) $item->id === (int) $target->id);

        if ($targetIndex === false || $items->isEmpty()) {
            $productCents = FinancialRules::cents($target->line_total);
            $protectionCents = max(0, FinancialRules::cents($target->protection_fee ?? 0));
            $totalCents = max(0, $productCents + $protectionCents);

            return [
                'product_amount' => $productCents / 100,
                'discount_amount' => 0.0,
                'shipping_fee' => 0.0,
                'shipping_discount' => 0.0,
                'protection_amount' => $protectionCents / 100,
                'total' => $totalCents / 100,
                'product_amount_cents' => $productCents,
                'discount_amount_cents' => 0,
                'shipping_fee_cents' => 0,
                'shipping_discount_cents' => 0,
                'protection_amount_cents' => $protectionCents,
                'total_cents' => $totalCents,
            ];
        }

        $weights = $items->map(fn (OrderItem $item): int => max(0, FinancialRules::cents($item->line_total)))->all();
        $totalWeight = array_sum($weights);
        $shareIndex = (int) $targetIndex;
        $lastIndex = count($weights) - 1;

        $sourceAware = (int) ($sellerOrder->funding_snapshot_version ?? 0) === 1
            && (int) ($items[$shareIndex]->funding_snapshot_version ?? 0) === 1;
        if ($sourceAware) {
            $sellerVoucherCents = FinancialRules::cents($items[$shareIndex]->seller_voucher_discount ?? 0);
            $platformVoucherCents = FinancialRules::cents($items[$shareIndex]->platform_voucher_discount ?? 0);
            $rewardPointsCents = FinancialRules::cents($items[$shareIndex]->reward_points_discount ?? 0);
            $discountCents = $sellerVoucherCents + $platformVoucherCents + $rewardPointsCents;
            $discountShares = [];
            $shippingDiscountShares = [];
            $shippingDiscountShares[$shareIndex] = FinancialRules::cents($items[$shareIndex]->platform_shipping_voucher_discount ?? 0);
        } else {
            $discountCents = FinancialRules::cents($sellerOrder->voucher_discount)
                + FinancialRules::cents($sellerOrder->reward_points_discount ?? 0);
            $discountShares = self::shares($discountCents, $weights, $totalWeight);
            $shippingDiscountShares = self::shares(FinancialRules::cents($sellerOrder->shipping_voucher_discount ?? 0), $weights, $totalWeight);
            $sellerVoucherCents = 0;
            $platformVoucherCents = 0;
            $rewardPointsCents = 0;
        }
        $shippingRateSnapshot = is_array($sellerOrder->shipping_rate_snapshot)
            ? $sellerOrder->shipping_rate_snapshot
            : [];
        $perParcelShippingFee = $shippingRateSnapshot['per_parcel_shipping_fee'] ?? null;
        $usesPerParcelPricing = ($shippingRateSnapshot['pricing_basis'] ?? null) === 'PER_PARCEL'
            && is_numeric($perParcelShippingFee);
        $shippingShares = $usesPerParcelPricing
            ? []
            : self::shares(FinancialRules::cents($sellerOrder->shipping_fee), $weights, $totalWeight);

        $itemProtectionTotal = $items->sum(fn (OrderItem $item): int => max(0, FinancialRules::cents($item->protection_fee ?? 0)));
        $sellerProtectionTotal = max(0, FinancialRules::cents($sellerOrder->protection_total ?? $itemProtectionTotal / 100));
        $protectionShares = $itemProtectionTotal === $sellerProtectionTotal
            ? $items->map(fn (OrderItem $item): int => max(0, FinancialRules::cents($item->protection_fee ?? 0)))->all()
            : self::shares($sellerProtectionTotal, $weights, $totalWeight);

        $productCents = max(0, FinancialRules::cents($items[$shareIndex]->line_total));
        $discountCents = $sourceAware ? $discountCents : ($discountShares[$shareIndex] ?? 0);
        $shippingCents = $usesPerParcelPricing
            ? max(0, FinancialRules::cents($perParcelShippingFee))
            : ($shippingShares[$shareIndex] ?? 0);
        $shippingDiscountCents = $shippingDiscountShares[$shareIndex] ?? 0;
        $protectionCents = $protectionShares[$shareIndex] ?? 0;
        $totalCents = max(0, $productCents - $discountCents + $shippingCents - $shippingDiscountCents + $protectionCents);

        return [
            'product_amount' => $productCents / 100,
            'discount_amount' => $discountCents / 100,
            'seller_voucher_discount' => $sellerVoucherCents / 100,
            'platform_voucher_discount' => $platformVoucherCents / 100,
            'reward_points_discount' => $rewardPointsCents / 100,
            'shipping_fee' => $shippingCents / 100,
            'shipping_discount' => $shippingDiscountCents / 100,
            'platform_shipping_voucher_discount' => $shippingDiscountCents / 100,
            'protection_amount' => $protectionCents / 100,
            'total' => $totalCents / 100,
            'product_amount_cents' => $productCents,
            'discount_amount_cents' => $discountCents,
            'shipping_fee_cents' => $shippingCents,
            'shipping_discount_cents' => $shippingDiscountCents,
            'protection_amount_cents' => $protectionCents,
            'total_cents' => $totalCents,
        ];
    }

    /** @param array<int, int> $weights
     *  @return array<int, int>
     */
    private static function shares(int $amount, array $weights, int $totalWeight): array
    {
        $weights = array_map(fn (int $weight): int => max(0, $weight), $weights);
        $amount = max(0, $amount);
        if ($totalWeight <= 0) {
            $shares = array_fill(0, count($weights), 0);
            if ($shares !== []) {
                $shares[array_key_last($shares)] = $amount;
            }

            return $shares;
        }

        return FinancialRules::allocateCents($amount, $weights);
    }
}
