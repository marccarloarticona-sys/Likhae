<?php

namespace App\Support;

/**
 * Decimal-safe policy calculations for new Step 6/7 financial records.
 * Amounts are carried as integer cents and returned as two-decimal strings.
 */
final class FinancialRules
{
    public const COD_METHOD = 'COD';
    public const COMMISSION_RATE = 0.10;
    public const SHIPPING_FEE_CENTS = 5000;
    public const LOGISTICS_ALLOCATION_CENTS = 2000;
    public const RIDER_ALLOCATION_CENTS = 3000;

    private function __construct() {}

    public static function cents(string|int|float|null $amount): int
    {
        return (int) round((float) ($amount ?? 0) * 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function commissionCents(int $originalProductCents): int
    {
        return (int) round($originalProductCents * self::COMMISSION_RATE, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param list<array{original_unit_price:string|int|float,charged_unit_price:string|int|float,quantity:int}> $items
     * @return array<string, string|bool>
     */
    public static function allocation(array $items, string|int|float $voucherDiscount = 0, string|int|float $shippingFee = 50): array
    {
        $original = 0;
        $charged = 0;

        foreach ($items as $item) {
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $original += self::cents($item['original_unit_price'] ?? 0) * $quantity;
            $charged += self::cents($item['charged_unit_price'] ?? 0) * $quantity;
        }

        $voucher = self::cents($voucherDiscount);
        $discount = max(0, $original - $charged) + $voucher;
        $commission = self::commissionCents($original);
        $shipping = self::cents($shippingFee);
        $discountFundingUnresolved = $discount > 0;

        return [
            'original_product_amount' => self::amount($original),
            'product_amount' => self::amount(max(0, $charged - $voucher)),
            'discount_amount' => self::amount($discount),
            'shipping_fee' => self::amount($shipping),
            'platform_commission' => self::amount($commission),
            'seller_payable' => $discountFundingUnresolved
                ? null
                : self::amount(max(0, $charged - $commission)),
            'logistics_allocation' => self::amount(self::LOGISTICS_ALLOCATION_CENTS),
            'rider_allocation' => self::amount(self::RIDER_ALLOCATION_CENTS),
            'discount_funding_unresolved' => $discountFundingUnresolved,
        ];
    }
}
