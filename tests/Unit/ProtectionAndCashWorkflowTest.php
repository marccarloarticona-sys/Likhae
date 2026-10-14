<?php

namespace Tests\Unit;

use App\Models\Protection\FragileProtectionRule;
use App\Models\Returns\CashRefundObligation;
use App\Services\Returns\CashRefundWorkflowService;
use App\Services\Protection\FragileProtectionRuleService;
use App\Support\FinancialRules;
use PHPUnit\Framework\TestCase;

class ProtectionAndCashWorkflowTest extends TestCase
{
    public function test_protection_is_optional_and_missing_scope_is_not_chargeable(): void
    {
        $rule = [
            'scope' => FragileProtectionRule::SCOPE_PER_UNIT,
            'calculation_type' => FragileProtectionRule::TYPE_PERCENTAGE,
            'percentage_rate' => 2.5,
            'flat_amount' => null,
            'minimum_fee' => null,
            'maximum_fee' => null,
            'minimum_product_price' => null,
            'maximum_product_price' => null,
        ];

        $this->assertSame(25.0, FragileProtectionRuleService::calculateFee($rule, 1000));
        $this->assertNull(FragileProtectionRuleService::calculateFee([...$rule, 'scope' => null], 1000));
    }

    public function test_protection_fee_calculation_allows_omitted_optional_bounds(): void
    {
        $this->assertSame(25.0, FragileProtectionRuleService::calculateFee([
            'scope' => FragileProtectionRule::SCOPE_PER_UNIT,
            'calculation_type' => FragileProtectionRule::TYPE_PERCENTAGE,
            'percentage_rate' => 2.5,
        ], 1000));
    }

    public function test_rules_without_a_positive_rate_or_amount_are_not_chargeable(): void
    {
        $this->assertNull(FragileProtectionRuleService::calculateFee([
            'scope' => FragileProtectionRule::SCOPE_PER_UNIT,
            'calculation_type' => FragileProtectionRule::TYPE_PERCENTAGE,
            'percentage_rate' => 0,
        ], 1000));
        $this->assertNull(FragileProtectionRuleService::calculateFee([
            'scope' => FragileProtectionRule::SCOPE_PER_LINE,
            'calculation_type' => FragileProtectionRule::TYPE_FLAT,
            'flat_amount' => 0,
        ], 1000));
        $this->assertNull(FragileProtectionRuleService::calculateFee([
            'scope' => FragileProtectionRule::SCOPE_PER_UNIT,
            'calculation_type' => FragileProtectionRule::TYPE_HYBRID,
            'percentage_rate' => 0,
            'flat_amount' => 0,
        ], 1000));
    }

    public function test_price_bands_and_per_line_scope_are_respected(): void
    {
        $rule = [
            'scope' => FragileProtectionRule::SCOPE_PER_LINE,
            'calculation_type' => FragileProtectionRule::TYPE_HYBRID,
            'percentage_rate' => 3.0,
            'flat_amount' => 50,
            'minimum_fee' => 60,
            'maximum_fee' => 90,
            'minimum_product_price' => 500,
            'maximum_product_price' => 5000,
        ];

        $this->assertSame(60.0, FragileProtectionRuleService::calculateFee($rule, 1000));
        $this->assertNull(FragileProtectionRuleService::calculateFee($rule, 200));
        $this->assertSame(90.0, FragileProtectionRuleService::calculateFee($rule, 5000));
    }

    public function test_cash_states_are_distinct_from_approval_and_online_history(): void
    {
        $this->assertTrue(CashRefundWorkflowService::isNewCashCase('CASH'));
        $this->assertFalse(CashRefundWorkflowService::isNewCashCase('GCASH_TRANSFER'));
        $this->assertSame('APPROVED', CashRefundObligation::APPROVED);
        $this->assertSame('CASH_READY', CashRefundObligation::CASH_READY);
        $this->assertSame('CASH_PAID', CashRefundObligation::CASH_PAID);
        $this->assertSame('COMPLETED', CashRefundObligation::COMPLETED);
    }

    public function test_protection_does_not_change_product_commission_base_or_shipping(): void
    {
        $allocation = FinancialRules::allocation([
            ['original_unit_price' => '1000.00', 'charged_unit_price' => '1000.00', 'quantity' => 1],
        ]);

        $this->assertSame('100.00', $allocation['platform_commission']);
        $this->assertSame('50.00', $allocation['shipping_fee']);
    }
}
