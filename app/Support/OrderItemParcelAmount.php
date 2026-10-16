<?php

namespace App\Support;

use App\Models\Buyer\OrderItem;
use App\Models\Seller\SellerOrder;

final class OrderItemParcelAmount
{
    /** @return array{product_amount: float, discount_amount: float, shipping_fee: float, protection_amount: float, total: float} */
    public static function forItem(SellerOrder $sellerOrder, OrderItem $target): array
    {
        $items = $sellerOrder->items->values();
        $targetIndex = $items->search(fn (OrderItem $item): bool => (int) $item->id === (int) $target->id);

        if ($targetIndex === false || $items->isEmpty()) {
            return [
                'product_amount' => (float) $target->line_total,
                'discount_amount' => 0.0,
                'shipping_fee' => 0.0,
                'protection_amount' => (float) ($target->protection_fee ?? 0),
                'total' => (float) $target->line_total + (float) ($target->protection_fee ?? 0),
            ];
        }

        $weights = $items->map(fn (OrderItem $item): int => max(0, FinancialRules::cents($item->line_total)))->all();
        $totalWeight = array_sum($weights);
        $shareIndex = (int) $targetIndex;
        $lastIndex = count($weights) - 1;

        $discountShares = self::shares(FinancialRules::cents($sellerOrder->voucher_discount), $weights, $totalWeight);
        $shippingShares = self::shares(FinancialRules::cents($sellerOrder->shipping_fee), $weights, $totalWeight);

        $itemProtectionTotal = $items->sum(fn (OrderItem $item): int => max(0, FinancialRules::cents($item->protection_fee ?? 0)));
        $sellerProtectionTotal = max(0, FinancialRules::cents($sellerOrder->protection_total ?? $itemProtectionTotal / 100));
        $protectionShares = $itemProtectionTotal === $sellerProtectionTotal
            ? $items->map(fn (OrderItem $item): int => max(0, FinancialRules::cents($item->protection_fee ?? 0)))->all()
            : self::shares($sellerProtectionTotal, $weights, $totalWeight);

        $productCents = max(0, FinancialRules::cents($items[$shareIndex]->line_total));
        $discountCents = $discountShares[$shareIndex] ?? 0;
        $shippingCents = $shippingShares[$shareIndex] ?? 0;
        $protectionCents = $protectionShares[$shareIndex] ?? 0;

        return [
            'product_amount' => $productCents / 100,
            'discount_amount' => $discountCents / 100,
            'shipping_fee' => $shippingCents / 100,
            'protection_amount' => $protectionCents / 100,
            'total' => max(0, $productCents - $discountCents + $shippingCents + $protectionCents) / 100,
        ];
    }

    /** @param array<int, int> $weights
     *  @return array<int, int>
     */
    private static function shares(int $amount, array $weights, int $totalWeight): array
    {
        $shares = [];
        $allocated = 0;
        $lastIndex = count($weights) - 1;

        foreach ($weights as $index => $weight) {
            $share = $index === $lastIndex
                ? $amount - $allocated
                : ($totalWeight > 0 ? (int) round($amount * ($weight / $totalWeight)) : 0);
            $shares[$index] = max(0, $share);
            $allocated += $shares[$index];
        }

        return $shares;
    }
}
