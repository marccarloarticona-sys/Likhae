<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Voucher;
use Illuminate\Validation\ValidationException;

class VoucherDiscountCalculator
{
    public static function normalizeType(mixed $type): string
    {
        return match (strtoupper(trim((string) $type))) {
            'PERCENT', 'PERCENTAGE', 'PCT' => 'PERCENT',
            'FIXED', 'FIXED_AMOUNT', 'AMOUNT' => 'FIXED',
            default => throw ValidationException::withMessages([
                'voucher_code' => 'This voucher has an unsupported discount type.',
            ]),
        };
    }

    public function calculate(Voucher $voucher, float $eligibleSubtotal): float
    {
        if ($voucher->requiresReview()) {
            throw ValidationException::withMessages([
                'voucher_code' => 'This voucher is under review and cannot be used. Please choose another voucher.',
            ]);
        }

        $eligibleSubtotal = round(max(0.0, $eligibleSubtotal), 2);
        if ($eligibleSubtotal <= 0) {
            return 0.0;
        }

        $value = max(0.0, (float) $voucher->discount_value);
        $discount = match (self::normalizeType($voucher->getRawOriginal('discount_type') ?? $voucher->discount_type)) {
            'PERCENT' => $eligibleSubtotal * ($value / 100),
            'FIXED' => $value,
        };

        $maximum = $voucher->maximum_discount_amount;
        if ($maximum !== null) {
            $discount = min($discount, max(0.0, (float) $maximum));
        }

        return round(min($discount, $eligibleSubtotal), 2);
    }
}
