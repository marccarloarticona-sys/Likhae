<?php

namespace Tests\Unit;

use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Logistics\Shipment;
use App\Services\Finance\DeliveryEarningsBreakdown;
use PHPUnit\Framework\TestCase;

class DeliveryEarningsBreakdownTest extends TestCase
{
    public function test_small_amounts_match_the_saved_rider_pool_and_reconcile(): void
    {
        foreach (range(0, 200) as $cents) {
            [$rider, $origin, $destination] = \App\Support\FinancialRules::interHubDeliverySplitCents($cents);
            $this->assertSame(\App\Support\FinancialRules::shippingAllocationCents($cents, '60')['rider_cents'], $rider);
            $this->assertSame($cents, $rider + $origin + $destination);
        }
    }

    public function test_single_hub_preserves_the_saved_dynamic_allocation_and_rider_reserve(): void
    {
        $shipment = new Shipment([
            'origin_logistics_center_id' => 7,
            'destination_logistics_center_id' => 7,
            'logistics_center_id' => 7,
        ]);
        $allocation = new ShipmentFinancialAllocation([
            'shipping_fee' => '47.70',
            'protection_amount' => '5.00',
            'logistics_allocation' => '21.08',
            'rider_allocation' => '31.62',
        ]);

        $split = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, 7);

        $this->assertSame([
            'role' => 'SINGLE_HUB',
            'net_share_cents' => 2108,
            'transfer_cents' => 5270,
            'rider_reserve_cents' => 3162,
        ], $split);
    }

    public function test_inter_hub_transfer_splits_rider_sixty_origin_thirty_and_destination_ten(): void
    {
        $shipment = new Shipment([
            'origin_logistics_center_id' => 4,
            'destination_logistics_center_id' => 8,
            'logistics_center_id' => 4,
        ]);
        $allocation = new ShipmentFinancialAllocation([
            'shipping_fee' => '100.00',
            'protection_amount' => '5.00',
            'logistics_allocation' => '42.00',
            'rider_allocation' => '63.00',
        ]);

        $origin = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, 4);
        $destination = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, 8);

        $this->assertSame([
            'role' => 'ORIGIN',
            'net_share_cents' => 3150,
            'transfer_cents' => 3150,
            'rider_reserve_cents' => 0,
        ], $origin);
        $this->assertSame([
            'role' => 'DESTINATION',
            'net_share_cents' => 1050,
            'transfer_cents' => 7350,
            'rider_reserve_cents' => 6300,
        ], $destination);
        $this->assertSame(10500, $origin['transfer_cents'] + $destination['net_share_cents'] + $destination['rider_reserve_cents']);
    }

    public function test_variable_regional_and_additional_care_amounts_reconcile_to_each_centavo(): void
    {
        $shipment = new Shipment([
            'origin_logistics_center_id' => 4,
            'destination_logistics_center_id' => 8,
            'logistics_center_id' => 4,
        ]);
        $allocation = new ShipmentFinancialAllocation([
            'shipping_fee' => '142.13',
            'protection_amount' => '320.00',
            'logistics_allocation' => '184.85',
            'rider_allocation' => '277.28',
        ]);

        $origin = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, 4);
        $destination = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, 8);

        $this->assertSame(46213, $origin['transfer_cents'] + $destination['net_share_cents'] + $destination['rider_reserve_cents']);
        $this->assertSame(13864, $origin['transfer_cents']);
        $this->assertSame(4621, $destination['net_share_cents']);
        $this->assertSame(27728, $destination['rider_reserve_cents']);
    }
}
