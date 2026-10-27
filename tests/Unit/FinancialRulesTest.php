<?php

namespace Tests\Unit;

use App\Models\Buyer\OrderItem;
use App\Models\Seller\SellerOrder;
use App\Support\FinancialRules;
use App\Support\OrderItemParcelAmount;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    public function test_per_parcel_snapshot_gives_each_order_item_its_full_shipping_fee(): void
    {
        $firstItem = new OrderItem([
            'id' => 1,
            'line_total' => '1169.10',
            'quantity' => 1,
            'protection_fee' => '0.00',
        ]);
        $secondItem = new OrderItem([
            'id' => 2,
            'line_total' => '81061.00',
            'quantity' => 1,
            'protection_fee' => '0.00',
        ]);
        $sellerOrder = new SellerOrder([
            'shipping_fee' => '100.00',
            'voucher_discount' => '0.00',
            'reward_points_discount' => '0.00',
            'shipping_voucher_discount' => '0.00',
            'protection_total' => '0.00',
            'shipping_rate_snapshot' => [
                'pricing_basis' => 'PER_PARCEL',
                'parcel_count' => 2,
                'per_parcel_shipping_fee' => '50.00',
                'total_shipping_fee' => '100.00',
            ],
        ]);
        $sellerOrder->setRelation('items', new EloquentCollection([$firstItem, $secondItem]));

        $firstParcel = OrderItemParcelAmount::forItem($sellerOrder, $firstItem);
        $secondParcel = OrderItemParcelAmount::forItem($sellerOrder, $secondItem);

        $this->assertEquals(50.0, $firstParcel['shipping_fee']);
        $this->assertEquals(50.0, $secondParcel['shipping_fee']);

        $firstAllocation = FinancialRules::allocation(
            [['original_unit_price' => '1169.10', 'charged_unit_price' => '1169.10', 'quantity' => 1]],
            shippingFee: $firstParcel['shipping_fee'],
        );
        $this->assertSame('30.00', $firstAllocation['rider_allocation']);
        $this->assertSame('20.00', $firstAllocation['logistics_allocation']);
    }

    public function test_legacy_order_without_per_parcel_snapshot_keeps_its_saved_fee_proration(): void
    {
        $firstItem = new OrderItem([
            'id' => 1,
            'line_total' => '1169.10',
            'quantity' => 1,
            'protection_fee' => '0.00',
        ]);
        $secondItem = new OrderItem([
            'id' => 2,
            'line_total' => '81061.00',
            'quantity' => 1,
            'protection_fee' => '0.00',
        ]);
        $sellerOrder = new SellerOrder([
            'shipping_fee' => '50.00',
            'voucher_discount' => '0.00',
            'reward_points_discount' => '0.00',
            'shipping_voucher_discount' => '0.00',
            'protection_total' => '0.00',
        ]);
        $sellerOrder->setRelation('items', new EloquentCollection([$firstItem, $secondItem]));

        $this->assertSame(0.71, OrderItemParcelAmount::forItem($sellerOrder, $firstItem)['shipping_fee']);
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

    public function test_largest_remainder_allocation_preserves_every_centavo(): void
    {
        $this->assertSame([1, 1, 0], FinancialRules::allocateCents(2, [1, 1, 1]));
        $this->assertSame([3334, 3333, 3333], FinancialRules::allocateCents(10000, [1, 1, 1]));
    }

    public function test_platform_voucher_does_not_reduce_seller_entitlement_and_additional_care_stays_separate(): void
    {
        $allocation = FinancialRules::allocation(
            [['original_unit_price' => '1465840.00', 'charged_unit_price' => '1465840.00', 'quantity' => 1]],
            '146584.00',
            '50.00',
            '60.0000',
            '0.00',
            [
                'seller_voucher_discount' => '0.00',
                'platform_voucher_discount' => '146584.00',
                'seller_promotion_discount' => '0.00',
                'reward_points_discount' => '0.00',
            ],
            '0.1000',
            '320.00',
        );

        $this->assertSame('1465840.00', $allocation['original_product_amount']);
        $this->assertSame('1319256.00', $allocation['product_amount']);
        $this->assertSame('146584.00', $allocation['platform_commission']);
        $this->assertSame('1319256.00', $allocation['seller_payable']);
        $this->assertSame('50.00', $allocation['shipping_fee']);
        $this->assertSame('320.00', $allocation['additional_care_amount']);
        $this->assertSame('370.00', $allocation['delivery_earnings_amount']);
        $this->assertSame(
            '1319626.00',
            FinancialRules::amount(
                FinancialRules::cents($allocation['product_amount'])
                + FinancialRules::cents($allocation['shipping_fee'])
                + FinancialRules::cents($allocation['additional_care_amount']),
            ),
        );
    }

    public function test_seller_funded_discount_reduces_seller_entitlement_once(): void
    {
        $allocation = FinancialRules::allocation(
            [['original_unit_price' => '100.00', 'charged_unit_price' => '100.00', 'quantity' => 1]],
            '20.00',
            '0.00',
            '0.0000',
            '0.00',
            [
                'seller_voucher_discount' => '20.00',
                'platform_voucher_discount' => '0.00',
                'seller_promotion_discount' => '0.00',
                'reward_points_discount' => '0.00',
            ],
            '0.1000',
        );

        $this->assertSame('80.00', $allocation['product_amount']);
        $this->assertSame('10.00', $allocation['platform_commission']);
        $this->assertSame('70.00', $allocation['seller_payable']);
        $this->assertFalse($allocation['discount_funding_unresolved']);
    }

    public function test_partial_refund_slices_preserve_every_centavo_across_quantities(): void
    {
        $first = OrderItemParcelAmount::refundSliceCents(1000, 3, 0, 1);
        $second = OrderItemParcelAmount::refundSliceCents(1000, 3, 1, 1);
        $last = OrderItemParcelAmount::refundSliceCents(1000, 3, 2, 1);

        $this->assertSame([333, 333, 334], [$first, $second, $last]);
        $this->assertSame(1000, $first + $second + $last);
        $this->assertSame(0, OrderItemParcelAmount::refundSliceCents(1000, 3, 3, 1));
    }
}
