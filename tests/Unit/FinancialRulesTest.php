<?php

namespace Tests\Unit;

use App\Support\FinancialRules;
use PHPUnit\Framework\TestCase;

class FinancialRulesTest extends TestCase
{
    public function test_one_shipment_uses_fixed_shipping_and_explicit_allocations(): void
    {
        $allocation = FinancialRules::allocation([
            ['original_unit_price' => '100.00', 'charged_unit_price' => '100.00', 'quantity' => 2],
        ]);

        $this->assertSame('200.00', $allocation['original_product_amount']);
        $this->assertSame('200.00', $allocation['product_amount']);
        $this->assertSame('20.00', $allocation['platform_commission']);
        $this->assertSame('50.00', $allocation['shipping_fee']);
        $this->assertSame('20.00', $allocation['logistics_allocation']);
        $this->assertSame('30.00', $allocation['rider_allocation']);
        $this->assertSame('180.00', $allocation['seller_payable']);
        $this->assertFalse($allocation['discount_funding_unresolved']);
        $this->assertSame(
            0,
            FinancialRules::cents($allocation['seller_payable'])
                + FinancialRules::cents($allocation['platform_commission'])
                + FinancialRules::cents($allocation['logistics_allocation'])
                + FinancialRules::cents($allocation['rider_allocation'])
                - (FinancialRules::cents($allocation['product_amount']) + FinancialRules::cents($allocation['shipping_fee'])),
        );
    }

    public function test_shipping_is_not_multiplied_by_item_quantity(): void
    {
        $allocation = FinancialRules::allocation([
            ['original_unit_price' => '10.00', 'charged_unit_price' => '10.00', 'quantity' => 10],
        ]);

        $this->assertSame('100.00', $allocation['original_product_amount']);
        $this->assertSame('50.00', $allocation['shipping_fee']);
        $this->assertSame('10.00', $allocation['platform_commission']);
    }

    public function test_basic_fifty_peso_case_balances_to_one_hundred_peso_cod(): void
    {
        $allocation = FinancialRules::allocation([
            ['original_unit_price' => '50.00', 'charged_unit_price' => '50.00', 'quantity' => 1],
        ]);

        $this->assertSame('50.00', $allocation['product_amount']);
        $this->assertSame('50.00', $allocation['shipping_fee']);
        $this->assertSame('5.00', $allocation['platform_commission']);
        $this->assertSame('45.00', $allocation['seller_payable']);
        $this->assertSame('20.00', $allocation['logistics_allocation']);
        $this->assertSame('30.00', $allocation['rider_allocation']);
        $this->assertSame(
            0,
            FinancialRules::cents($allocation['seller_payable'])
                + FinancialRules::cents($allocation['platform_commission'])
                + FinancialRules::cents($allocation['logistics_allocation'])
                + FinancialRules::cents($allocation['rider_allocation'])
                - FinancialRules::cents('100.00'),
        );
    }

    public function test_any_discount_keeps_seller_settlement_pending(): void
    {
        $allocation = FinancialRules::allocation([
            ['original_unit_price' => '100.00', 'charged_unit_price' => '90.00', 'quantity' => 1],
        ], '5.00');

        $this->assertSame('100.00', $allocation['original_product_amount']);
        $this->assertSame('85.00', $allocation['product_amount']);
        $this->assertSame('15.00', $allocation['discount_amount']);
        $this->assertSame('10.00', $allocation['platform_commission']);
        $this->assertNull($allocation['seller_payable']);
        $this->assertTrue($allocation['discount_funding_unresolved']);
    }
}
