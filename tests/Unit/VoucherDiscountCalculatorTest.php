<?php

namespace Tests\Unit;

use App\Models\Seller\Voucher;
use App\Services\Marketplace\VoucherDiscountCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VoucherDiscountCalculatorTest extends TestCase
{
    public function test_percentage_and_fixed_discounts_keep_their_types(): void
    {
        $calculator = new VoucherDiscountCalculator();

        $percentage = new Voucher(['discount_type' => 'PERCENT', 'discount_value' => 10]);
        $fixed = new Voucher(['discount_type' => 'FIXED', 'discount_value' => 10]);

        $this->assertSame(50.0, $calculator->calculate($percentage, 500));
        $this->assertSame(100.0, $calculator->calculate($percentage, 1000));
        $this->assertSame(10.0, $calculator->calculate($fixed, 500));
    }

    public function test_percentage_cap_and_subtotal_cap_are_applied(): void
    {
        $calculator = new VoucherDiscountCalculator();
        $capped = new Voucher([
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'maximum_discount_amount' => 50,
        ]);
        $tooLargeFixed = new Voucher(['discount_type' => 'FIXED', 'discount_value' => 999]);

        $this->assertSame(50.0, $calculator->calculate($capped, 1000));
        $this->assertSame(5.0, $calculator->calculate($tooLargeFixed, 5));
    }

    public function test_discount_type_is_never_inferred_from_its_numeric_value(): void
    {
        $calculator = new VoucherDiscountCalculator();
        $unknownType = new Voucher(['discount_type' => 'UNKNOWN', 'discount_value' => 10]);

        $this->expectException(ValidationException::class);
        $calculator->calculate($unknownType, 500);
    }
}
