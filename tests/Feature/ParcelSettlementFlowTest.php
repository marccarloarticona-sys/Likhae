<?php

namespace Tests\Feature;

use App\Models\Admin\CommissionTransaction;
use App\Models\Buyer\Order;
use App\Models\Finance\CodCollection;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderRemittance;
use App\Models\Finance\SellerSettlement;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Finance\CodCollectionService;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\FinancialAllocationService;
use App\Services\Finance\ProductProceedsService;
use App\Services\Finance\RemittanceService;
use App\Services\Finance\SellerSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParcelSettlementFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_example_amounts_reconcile_through_three_handoffs_and_seller_acknowledgment(): void
    {
        $fixture = $this->parcelFixture();
        $collection = $this->recordCollection($fixture, '1050.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());

        $this->assertNotNull($allocation);
        $this->assertSame('1000.00', $allocation->product_amount);
        $this->assertSame('50.00', $allocation->shipping_fee);
        $this->assertSame('100.00', $allocation->platform_commission);
        $this->assertSame('900.00', $allocation->seller_payable);
        $this->assertSame('20.00', $allocation->logistics_allocation);
        $this->assertSame('30.00', $allocation->rider_allocation);

        $remittances = app(RemittanceService::class);
        $this->assertSame(CodCollection::COLLECTED, $collection->fresh()->status);
        $this->assertSame($fixture['rider']->id, (int) $collection->fresh()->rider_profile_id);
        $this->assertSame($fixture['center']->id, (int) $collection->fresh()->logistics_center_id);
        $remittance = $remittances->submit($fixture['riderUser'], [$collection->id], '1050.00');
        $this->assertSame(CodCollection::REMITTED, $collection->fresh()->status);
        $this->assertDatabaseHas('rider_remittance_items', ['cod_collection_id' => $collection->id, 'rider_remittance_id' => $remittance->id]);
        $remittances->submit($fixture['riderUser'], [$collection->id], '1050.00');
        $this->assertSame(1, RiderRemittance::query()->whereKey($remittance->id)->count());
        $remittances->verify($fixture['centerUser'], $remittance, '1050.00');
        $remittances->verify($fixture['centerUser'], $remittance->fresh(), '1050.00');
        $remittances->reconcile($fixture['centerUser'], $remittance->fresh());
        $remittances->reconcile($fixture['centerUser'], $remittance->fresh());
        $this->assertSame(RiderRemittance::RECONCILED, $remittance->fresh()->status);
        $this->assertSame(CodCollection::REMITTED, $collection->fresh()->status);

        $payouts = app(EarningPayoutService::class);
        $eligible = $payouts->eligibleRiderParticipants($fixture['rider']);
        $this->assertCount(1, $eligible);
        $riderPayout = $payouts->requestRiderPayout($fixture['riderUser'], [$eligible->first()->id])->firstOrFail();
        $this->assertSame('30.00', $riderPayout->amount);
        $recordedRiderPayout = $payouts->recordRiderPayout($fixture['centerUser'], $riderPayout, '30.00', 'Online', 'GC-RIDER-001');
        $this->assertSame('PAYOUT_PENDING_ACK', $recordedRiderPayout->status);
        $payouts->acknowledgeRiderPayout($fixture['riderUser'], $recordedRiderPayout, '30.00');
        $this->assertSame('PAID', $recordedRiderPayout->fresh()->status);
        $this->assertSame('PAID', $eligible->first()->fresh()->status);

        $productProceeds = FinancialLedgerEntry::query()->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')->firstOrFail();
        $proceedsService = app(ProductProceedsService::class);
        $submitted = $proceedsService->submitToAdmin($fixture['centerUser'], $productProceeds, '1020.00');
        $proceedsService->submitToAdmin($fixture['centerUser'], $productProceeds, '1020.00');
        $this->assertSame('SUBMITTED', $submitted->fresh()->status);
        $proceedsService->acknowledgeByAdmin($fixture['admin'], $submitted, '1020.00');
        $proceedsService->acknowledgeByAdmin($fixture['admin'], $submitted->fresh(), '1020.00');
        $this->assertSame('RECEIVED', $submitted->fresh()->status);
        $this->assertSame(1, FinanceAuditEvent::query()->where('event_type', 'ADMIN_ACKNOWLEDGED_PRODUCT_PROCEEDS')->count());

        $logisticsPool = LogisticsEarningPool::query()->where('shipment_financial_allocation_id', $allocation->id)->firstOrFail();
        $logisticsPayout = $payouts->recordLogisticsPayout($fixture['admin'], $logisticsPool, '20.00', 'Manual', 'ADMIN-LOG-001');
        $this->assertSame('PAYOUT_PENDING_ACK', $logisticsPayout->status);
        $payouts->acknowledgeLogisticsPayout($fixture['centerUser'], $logisticsPayout, '20.00');
        $this->assertSame('PAID', $logisticsPayout->fresh()->status);
        $this->assertSame('PAID', $logisticsPool->fresh()->status);

        $settlement = SellerSettlement::query()->where('shipment_financial_allocation_id', $allocation->id)->firstOrFail();
        $sellerService = app(SellerSettlementService::class);
        $sellerService->authorize($fixture['admin'], $settlement);
        $sellerService->pay($fixture['admin'], $settlement->fresh(), '900.00', 'GCash', 'GC-TRANSFER-01');
        $this->assertSame('PAYOUT_PENDING_ACK', $settlement->fresh()->payout_status);
        $this->assertSame('GC-TRANSFER-01', $settlement->fresh()->payout_external_reference);
        $this->assertFinanceThrows(fn () => $sellerService->acknowledgeBySeller($fixture['otherSellerUser'], $settlement->fresh()));
        $sellerService->acknowledgeBySeller($fixture['sellerUser'], $settlement->fresh());
        $sellerService->acknowledgeBySeller($fixture['sellerUser'], $settlement->fresh());
        $this->assertSame('PAID', $settlement->fresh()->payout_status);
        $this->assertNotNull($settlement->fresh()->seller_acknowledged_at);
        $this->assertSame(1, FinanceAuditEvent::query()->where('event_type', 'SELLER_ACKNOWLEDGED_PAYOUT')->count());

        $receipt = FinanceAuditEvent::query()->where('event_type', 'SELLER_PAYOUT_SENT_AWAITING_ACKNOWLEDGMENT')->firstOrFail()->after_state;
        $this->assertSame($fixture['shipment']->tracking_number, $receipt['parcel_reference']);
        $this->assertSame('1000.00', $receipt['product_amount']);
        $this->assertSame('100.00', $receipt['platform_fee']);
        $this->assertSame('900.00', $receipt['seller_amount']);
        $this->assertSame('30.00', $allocation->riderPool()->value('total_amount'));
        $this->assertSame('20.00', $allocation->logisticsPool()->value('total_amount'));
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'RIDER_ACKNOWLEDGED_PAYOUT']);
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'LOGISTICS_ACKNOWLEDGED_PAYOUT']);
    }

    public function test_discrepancies_block_advancement_and_require_audited_resolution(): void
    {
        $fixture = $this->parcelFixture();
        $collection = $this->recordCollection($fixture, '1050.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());
        $remittances = app(RemittanceService::class);
        $remittance = $remittances->submit($fixture['riderUser'], [$collection->id], '1050.00');
        $remittances->verify($fixture['centerUser'], $remittance, '1040.00');
        $remittances->reconcile($fixture['centerUser'], $remittance->fresh());
        $this->assertSame(RiderRemittance::DISCREPANCY, $remittance->fresh()->status);
        $riderParticipant = $allocation->riderPool()->firstOrFail()->participants()->firstOrFail();
        $this->assertFinanceThrows(fn () => app(EarningPayoutService::class)->requestRiderPayout($fixture['riderUser'], [$riderParticipant->id]));

        $entry = FinancialLedgerEntry::query()->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')->firstOrFail();
        $proceedsService = app(ProductProceedsService::class);
        $this->assertFinanceThrows(fn () => $proceedsService->submitToAdmin($fixture['centerUser'], $entry, '1020.00'));
        $remittances->resolveDiscrepancy($fixture['centerUser'], $remittance->fresh(), '1050.00', 'Recovered the missing cash at the center.');
        $this->assertSame(RiderRemittance::RECONCILED, $remittance->fresh()->status);

        $proceedsService->submitToAdmin($fixture['centerUser'], $entry->fresh(), '1020.00');
        $proceedsService->acknowledgeByAdmin($fixture['admin'], $entry->fresh(), '1010.00');
        $this->assertSame('DISCREPANCY', $entry->fresh()->status);
        $settlement = SellerSettlement::query()->where('shipment_financial_allocation_id', $allocation->id)->firstOrFail();
        $sellerService = app(SellerSettlementService::class);
        $this->assertFinanceThrows(fn () => $sellerService->authorize($fixture['admin'], $settlement));
        $this->assertFinanceThrows(fn () => $proceedsService->resolveAdminDiscrepancy($fixture['admin'], $entry->fresh(), '1019.00', 'Incorrect amount.'));
        $proceedsService->resolveAdminDiscrepancy($fixture['admin'], $entry->fresh(), '1020.00', 'Short amount recovered and counted.');
        $sellerService->authorize($fixture['admin'], $settlement->fresh());
        $this->assertSame('AUTHORIZED', $settlement->fresh()->payout_status);
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'RIDER_HANDOFF_DISCREPANCY_RESOLVED']);
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'ADMIN_RESOLVED_PRODUCT_PROCEEDS_DISCREPANCY']);
    }

    public function test_buyer_collection_discrepancy_does_not_allocate_until_admin_resolution(): void
    {
        $fixture = $this->parcelFixture();
        $collection = $this->recordCollection($fixture, '1040.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $this->assertNull(app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh()));

        $remittances = app(RemittanceService::class);
        $remittance = $remittances->submit($fixture['riderUser'], [$collection->id], '1040.00');
        $remittances->verify($fixture['centerUser'], $remittance, '1040.00');
        $remittances->reconcile($fixture['centerUser'], $remittance->fresh());
        $this->assertSame(CodCollection::REMITTED_DISCREPANCY, $collection->fresh()->status);

        $proceeds = app(ProductProceedsService::class);
        $this->assertFinanceThrows(fn () => $proceeds->resolveBuyerCollectionDiscrepancy($fixture['sellerUser'], $collection, 'Not authorized.'));
        $proceeds->resolveBuyerCollectionDiscrepancy($fixture['admin'], $collection->fresh(), 'Buyer shortfall was recovered and added to the reconciled COD amount.');
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());
        $this->assertNotNull($allocation);
        $this->assertSame('1000.00', $allocation->product_amount);
        $this->assertSame('REMITTED', $collection->fresh()->status);
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'ADMIN_RESOLVED_BUYER_COD_DISCREPANCY']);
    }

    public function test_discounted_product_uses_original_commission_base_and_preserves_funding_review(): void
    {
        $fixture = $this->parcelFixture();
        $fixture['sellerOrder']->update(['voucher_discount' => '100.00', 'reward_points_discount' => '50.00', 'grand_total' => '900.00']);
        $fixture['order']->update(['grand_total' => '900.00']);
        $collection = $this->recordCollection($fixture, '900.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());

        $this->assertNotNull($allocation);
        $this->assertSame('1000.00', $allocation->original_product_amount);
        $this->assertSame('850.00', $allocation->product_amount);
        $this->assertSame('150.00', $allocation->discount_amount);
        $this->assertSame('100.00', $allocation->platform_commission);
        $this->assertNull($allocation->seller_payable);
        $this->assertSame('REVIEW_REQUIRED', SellerSettlement::query()->where('shipment_financial_allocation_id', $allocation->id)->value('payout_status'));
        $this->assertSame('COLLECTED', $collection->fresh()->status);
    }

    public function test_payout_failure_is_preserved_and_retry_requires_a_new_acknowledged_payout(): void
    {
        $fixture = $this->parcelFixture();
        $collection = $this->recordCollection($fixture, '1050.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());
        $remittances = app(RemittanceService::class);
        $remittance = $remittances->submit($fixture['riderUser'], [$collection->id], '1050.00');
        $remittances->verify($fixture['centerUser'], $remittance, '1050.00');
        $remittances->reconcile($fixture['centerUser'], $remittance->fresh());
        $entry = FinancialLedgerEntry::query()->where('shipment_financial_allocation_id', $allocation->id)->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')->firstOrFail();
        app(ProductProceedsService::class)->submitToAdmin($fixture['centerUser'], $entry, '1020.00');
        app(ProductProceedsService::class)->acknowledgeByAdmin($fixture['admin'], $entry->fresh(), '1020.00');

        $settlement = SellerSettlement::query()->where('shipment_financial_allocation_id', $allocation->id)->firstOrFail();
        $service = app(SellerSettlementService::class);
        $service->authorize($fixture['admin'], $settlement);
        $service->pay($fixture['admin'], $settlement->fresh(), '900.00', 'Bank transfer', 'BANK-FAILED-01');
        $service->failPayout($fixture['admin'], $settlement->fresh(), 'Receiving account rejected the transfer.');
        $this->assertSame('FAILED', $settlement->fresh()->payout_status);
        $this->assertSame('Receiving account rejected the transfer.', $settlement->fresh()->payout_failure_reason);
        $this->assertFinanceThrows(fn () => $service->acknowledgeBySeller($fixture['sellerUser'], $settlement->fresh()));

        $service->authorize($fixture['admin'], $settlement->fresh(), 'Seller corrected the receiving account.');
        $service->pay($fixture['admin'], $settlement->fresh(), '900.00', 'Bank transfer', 'BANK-RETRY-02');
        $service->acknowledgeBySeller($fixture['sellerUser'], $settlement->fresh());
        $this->assertSame('PAID', $settlement->fresh()->payout_status);
        $this->assertSame('BANK-RETRY-02', $settlement->fresh()->payout_external_reference);
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'SELLER_PAYOUT_FAILED']);
    }

    public function test_multiple_item_parcels_get_independent_allocations_and_shipping_split(): void
    {
        $fixture = $this->parcelFixture();
        $sellerOrder = $fixture['sellerOrder'];
        $firstItem = $fixture['item'];
        $firstItem->update(['original_unit_price' => '600.00', 'unit_price' => '600.00', 'line_total' => '600.00']);
        $secondItem = $sellerOrder->items()->create([
            'product_name' => 'Second parcel item', 'sku' => 'SECOND', 'original_unit_price' => '400.00',
            'unit_price' => '400.00', 'quantity' => 1, 'discount_amount' => 0, 'line_total' => '400.00',
        ]);
        $sellerOrder->update(['item_subtotal' => '1000.00', 'shipping_fee' => '50.00', 'grand_total' => '1050.00']);
        $first = $fixture['shipment'];
        $second = Shipment::create([
            'seller_order_id' => $sellerOrder->id, 'order_item_id' => $secondItem->id,
            'tracking_number' => 'PARCEL-SECOND-001', 'logistics_center_id' => $fixture['center']->id,
            'destination_province_code' => '0403400000', 'destination_province_name' => 'Laguna',
            'destination_municipality_code' => '0403424000', 'destination_municipality_name' => 'San Pablo',
            'destination_barangay_code' => '0403424001', 'destination_barangay_name' => 'Bagong Bayan',
            'current_status' => 'DELIVERED',
        ]);
        $first->update(['current_status' => 'DELIVERED']);
        $firstCollection = CodCollection::create([
            'shipment_id' => $first->id, 'seller_order_id' => $sellerOrder->id, 'order_id' => $fixture['order']->id,
            'rider_profile_id' => $fixture['rider']->id, 'logistics_center_id' => $fixture['center']->id,
            'expected_amount' => '630.00', 'collected_amount' => '630.00', 'status' => CodCollection::COLLECTED,
            'collection_reference' => 'COD-FIRST-PARCEL', 'collected_at' => now(),
        ]);
        CodCollection::create([
            'shipment_id' => $second->id, 'seller_order_id' => $sellerOrder->id, 'order_id' => $fixture['order']->id,
            'rider_profile_id' => $fixture['rider']->id, 'logistics_center_id' => $fixture['center']->id,
            'expected_amount' => '420.00', 'collected_amount' => '420.00', 'status' => CodCollection::COLLECTED,
            'collection_reference' => 'COD-SECOND-PARCEL', 'collected_at' => now(),
        ]);

        $allocations = app(FinancialAllocationService::class);
        $firstAllocation = $allocations->initializeForShipment($first);
        $secondAllocation = $allocations->initializeForShipment($second);
        $this->assertNotSame($firstAllocation->id, $secondAllocation->id);
        $this->assertSame('30.00', $firstAllocation->shipping_fee);
        $this->assertSame('18.00', $firstAllocation->rider_allocation);
        $this->assertSame('12.00', $firstAllocation->logistics_allocation);
        $this->assertSame('20.00', $secondAllocation->shipping_fee);
        $this->assertSame('12.00', $secondAllocation->rider_allocation);
        $this->assertSame('8.00', $secondAllocation->logistics_allocation);
        $this->assertSame(2, SellerSettlement::query()->where('seller_order_id', $sellerOrder->id)->count());
        $this->assertSame('100.00', CommissionTransaction::query()->where('seller_order_id', $sellerOrder->id)->value('commission_amount'));
    }

    private function parcelFixture(): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $otherSellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $category = Category::create(['name' => 'Finance test category', 'slug' => 'finance-test-category', 'is_active' => true]);
        $seller = SellerProfile::create(['user_id' => $sellerUser->id, 'primary_category_id' => $category->id, 'business_name' => 'Finance test seller', 'status' => 'ACTIVE']);
        SellerProfile::create(['user_id' => $otherSellerUser->id, 'primary_category_id' => $category->id, 'business_name' => 'Other finance seller', 'status' => 'ACTIVE']);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id, 'code' => 'FINANCE-CENTER', 'business_name' => 'Finance Center', 'status' => 'ACTIVE',
        ]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id, 'logistics_center_id' => $center->id, 'vehicle_type' => 'motorcycle',
            'plate_number' => 'FIN-PLATE-01', 'drivers_license_number' => 'FIN-LICENSE-01', 'status' => 'ACTIVE',
        ]);
        $order = Order::create(['order_number' => 'FIN-ORDER-001', 'buyer_user_id' => $buyer->id, 'status' => 'PROCESSING', 'grand_total' => '1050.00']);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'FIN-SELLER-ORDER-001', 'order_id' => $order->id, 'seller_profile_id' => $seller->id,
            'status' => 'COMPLETED', 'item_subtotal' => '1000.00', 'voucher_discount' => '0.00', 'shipping_fee' => '50.00', 'grand_total' => '1050.00',
        ]);
        $item = $sellerOrder->items()->create([
            'product_name' => 'Finance test item', 'sku' => 'FIN-001', 'original_unit_price' => '1000.00',
            'unit_price' => '1000.00', 'quantity' => 1, 'discount_amount' => '0.00', 'line_total' => '1000.00',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id, 'order_item_id' => $item->id,
            'tracking_number' => 'PARCEL-FIN-001', 'logistics_center_id' => $center->id,
            'destination_province_code' => '0403400000', 'destination_province_name' => 'Laguna',
            'destination_municipality_code' => '0403424000', 'destination_municipality_name' => 'San Pablo',
            'destination_barangay_code' => '0403424001', 'destination_barangay_name' => 'Bagong Bayan',
            'current_status' => 'OUT_FOR_DELIVERY',
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $shipment->id, 'rider_profile_id' => $rider->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY, 'status' => 'COMPLETED',
        ]);

        return compact('buyer', 'sellerUser', 'otherSellerUser', 'centerUser', 'riderUser', 'admin', 'seller', 'center', 'rider', 'order', 'sellerOrder', 'item', 'shipment', 'assignment');
    }

    private function recordCollection(array $fixture, string $amount): CodCollection
    {
        $collectionService = app(CodCollectionService::class);
        $collectionService->attachDeliveryRider($fixture['shipment'], $fixture['assignment']);

        return $collectionService->recordSuccessfulCollection(
            $fixture['shipment'], $fixture['assignment'], $fixture['riderUser'], ['collected_amount' => $amount],
        );
    }

    private function assertFinanceThrows(callable $callback): void
    {
        try {
            $callback();
        } catch (\RuntimeException) {
            $this->assertTrue(true);

            return;
        }
        $this->fail('Expected a finance transition to reject the operation.');
    }
}
