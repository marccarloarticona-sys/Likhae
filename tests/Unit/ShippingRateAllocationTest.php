<?php

namespace Tests\Unit;

use App\Support\FinancialRules;
use PHPUnit\Framework\TestCase;

class ShippingRateAllocationTest extends TestCase
{
    private const CURRENT_WEIGHTS = ['PICKUP' => 8, 'HUB_TO_HUB' => 7, 'DELIVERY' => 15];

    public function test_fifty_peso_shipping_splits_into_thirty_rider_and_twenty_logistics(): void
    {
        $this->assertSame(['rider_cents' => 3000, 'logistics_cents' => 2000], FinancialRules::shippingAllocation('50.00', '60.0000'));
    }

    public function test_fifty_shipping_plus_two_hundred_eighty_additional_care_splits_on_the_combined_pool(): void
    {
        $allocation = FinancialRules::allocation(
            [['original_unit_price' => '1000.00', 'charged_unit_price' => '1000.00', 'quantity' => 1]],
            shippingFee: '50.00',
            riderPoolPercentage: '60.0000',
            additionalCareFee: '280.00',
        );

        $this->assertSame('50.00', $allocation['shipping_fee']);
        $this->assertSame('280.00', $allocation['additional_care_amount']);
        $this->assertSame('330.00', $allocation['delivery_earnings_amount']);
        $this->assertSame('132.00', $allocation['logistics_allocation']);
        $this->assertSame('198.00', $allocation['rider_allocation']);
    }

    public function test_fifty_shipping_plus_three_hundred_twenty_additional_care_splits_exactly(): void
    {
        $allocation = FinancialRules::allocation(
            [['original_unit_price' => '1000.00', 'charged_unit_price' => '1000.00', 'quantity' => 1]],
            shippingFee: '50.00',
            riderPoolPercentage: '60.0000',
            additionalCareFee: '320.00',
        );

        $this->assertSame('370.00', $allocation['delivery_earnings_amount']);
        $this->assertSame('148.00', $allocation['logistics_allocation']);
        $this->assertSame('222.00', $allocation['rider_allocation']);
    }

    public function test_platform_shipping_discount_does_not_reduce_delivery_earnings_entitlement(): void
    {
        $allocation = FinancialRules::allocation(
            [['original_unit_price' => '1000.00', 'charged_unit_price' => '1000.00', 'quantity' => 1]],
            shippingFee: '50.00',
            riderPoolPercentage: '60.0000',
            shippingVoucherDiscount: '20.00',
            additionalCareFee: '280.00',
        );

        $this->assertSame('330.00', $allocation['delivery_earnings_amount']);
        $this->assertSame('132.00', $allocation['logistics_allocation']);
        $this->assertSame('198.00', $allocation['rider_allocation']);
    }

    public function test_sixty_peso_shipping_splits_into_thirty_six_rider_and_twenty_four_logistics(): void
    {
        $this->assertSame(['rider_cents' => 3600, 'logistics_cents' => 2400], FinancialRules::shippingAllocation('60.00', '60.0000'));
    }

    public function test_completed_pickup_and_delivery_normalize_over_twenty_three_active_weight_points(): void
    {
        $distribution = FinancialRules::riderLegDistribution(3000, [
            'PICKUP' => [41],
            'DELIVERY' => [43],
        ], self::CURRENT_WEIGHTS);

        $this->assertSame(['PICKUP' => 1043, 'HUB_TO_HUB' => 0, 'DELIVERY' => 1957], $distribution['leg_cents']);
        $this->assertSame(3000, $distribution['allocated_cents']);
        $this->assertSame(0, $distribution['unresolved_cents']);
    }

    public function test_no_hub_earning_is_created_without_a_completed_authoritative_hub_assignment(): void
    {
        $distribution = FinancialRules::riderLegDistribution(3000, [
            'PICKUP' => [41],
            'DELIVERY' => [43],
        ], self::CURRENT_WEIGHTS);

        $this->assertNotContains('HUB_TO_HUB', array_column($distribution['participants'], 'participant_role'));
        $this->assertSame(0, $distribution['leg_cents']['HUB_TO_HUB']);
    }

    public function test_same_rider_on_pickup_and_delivery_receives_the_entire_rider_pool(): void
    {
        $distribution = FinancialRules::riderLegDistribution(3000, [
            'PICKUP' => [41],
            'DELIVERY' => [41],
        ], self::CURRENT_WEIGHTS);

        $this->assertSame([1043, 1957], array_column($distribution['participants'], 'share_cents'));
        $this->assertSame(3000, array_sum(array_column($distribution['participants'], 'share_cents')));
        $this->assertSame([41], array_values(array_unique(array_column($distribution['participants'], 'rider_profile_id'))));
    }

    public function test_different_pickup_and_delivery_riders_receive_normalized_weight_shares(): void
    {
        $distribution = FinancialRules::riderLegDistribution(3000, [
            'PICKUP' => [41],
            'DELIVERY' => [43],
        ], self::CURRENT_WEIGHTS);

        $this->assertSame([
            ['rider_profile_id' => 41, 'participant_role' => 'PICKUP', 'share_cents' => 1043],
            ['rider_profile_id' => 43, 'participant_role' => 'DELIVERY', 'share_cents' => 1957],
        ], $distribution['participants']);
    }

    public function test_only_one_qualifying_completed_rider_leg_receives_the_entire_pool(): void
    {
        $distribution = FinancialRules::riderLegDistribution(3000, [
            'DELIVERY' => [43],
        ], self::CURRENT_WEIGHTS);

        $this->assertSame(3000, $distribution['leg_cents']['DELIVERY']);
        $this->assertSame(3000, $distribution['participants'][0]['share_cents']);
        $this->assertSame(0, $distribution['unresolved_cents']);
    }

    public function test_incomplete_rider_leg_has_no_eligible_participant_or_earning(): void
    {
        // The service supplies only COMPLETED assignments to this method.
        $distribution = FinancialRules::riderLegDistribution(3000, [
            'PICKUP' => [41],
            'DELIVERY' => [],
        ], self::CURRENT_WEIGHTS);

        $this->assertSame(['PICKUP'], array_values(array_unique(array_column($distribution['participants'], 'participant_role'))));
        $this->assertSame(0, $distribution['leg_cents']['DELIVERY']);
        $this->assertSame(3000, $distribution['allocated_cents']);
    }

    public function test_no_completed_rider_leg_leaves_the_full_pool_explicitly_unresolved(): void
    {
        $distribution = FinancialRules::riderLegDistribution(3000, [], self::CURRENT_WEIGHTS);

        $this->assertSame([], $distribution['participants']);
        $this->assertSame(0, $distribution['allocated_cents']);
        $this->assertSame(3000, $distribution['unresolved_cents']);
    }

    public function test_completed_rider_earnings_sum_exactly_to_the_pool_even_with_multiple_riders_per_leg(): void
    {
        $distribution = FinancialRules::riderLegDistribution(3001, [
            'PICKUP' => [41, 42],
            'DELIVERY' => [43, 44, 45],
        ], self::CURRENT_WEIGHTS);

        $this->assertSame(3001, array_sum(array_column($distribution['participants'], 'share_cents')));
        $this->assertSame(3001, $distribution['allocated_cents']);
        $this->assertSame(0, $distribution['unresolved_cents']);
    }

    public function test_rider_pool_and_logistics_share_equal_the_shipping_fee(): void
    {
        foreach (['50.00', '60.00', '75.33', '0.01'] as $fee) {
            $split = FinancialRules::shippingAllocation($fee, '60.0000');
            $this->assertSame(FinancialRules::cents($fee), $split['rider_cents'] + $split['logistics_cents']);
        }
    }

    public function test_odd_centavos_are_allocated_without_float_drift_or_leaking_out_of_the_rider_pool(): void
    {
        $shipping = FinancialRules::shippingAllocation('0.01', '60.0000');
        $distribution = FinancialRules::riderLegDistribution(
            $shipping['rider_cents'],
            ['PICKUP' => [41], 'DELIVERY' => [43]],
            self::CURRENT_WEIGHTS,
        );

        $this->assertSame(['rider_cents' => 1, 'logistics_cents' => 0], $shipping);
        $this->assertSame(1, $distribution['allocated_cents']);
        $this->assertSame(0, $distribution['unresolved_cents']);
        $this->assertSame(1, array_sum(array_column($distribution['participants'], 'share_cents')));
    }
}
