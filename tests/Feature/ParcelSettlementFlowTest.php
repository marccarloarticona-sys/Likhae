<?php

namespace Tests\Feature;

use App\Models\Admin\CommissionTransaction;
use App\Models\Buyer\Order;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Admin\Dispute;
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
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\Seller\Voucher;
use App\Models\Returns\CashRefundObligation;
use App\Models\User;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Support\FinancialRules;
use App\Services\Finance\CodCollectionService;
use App\Services\Finance\EarningPayoutService;
use App\Services\Finance\FinancialAllocationService;
use App\Services\Finance\ProductProceedsService;
use App\Services\Finance\PlatformCampaignBudgetService;
use App\Services\Finance\RemittanceService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Returns\CashRefundWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $logisticsPayout = $payouts->recordLogisticsPayout($fixture['admin'], $logisticsPool, '20.00', 'GCash', 'ADMIN-LOG-001', null, 'payout-proofs/logistics-test.pdf', 'logistics-proof.pdf');
        $this->assertSame('PAYOUT_PENDING_ACK', $logisticsPayout->status);
        $this->assertSame('ADMIN-LOG-001', data_get($logisticsPayout->metadata, 'payout_external_reference'));
        $this->assertSame('payout-proofs/logistics-test.pdf', data_get($logisticsPayout->metadata, 'payout_proof_path'));
        $payouts->acknowledgeLogisticsPayout($fixture['centerUser'], $logisticsPayout, '20.00');
        $this->assertSame('PAID', $logisticsPayout->fresh()->status);
        $this->assertSame('PAID', $logisticsPool->fresh()->status);

        $settlement = SellerSettlement::query()->where('shipment_financial_allocation_id', $allocation->id)->firstOrFail();
        $sellerService = app(SellerSettlementService::class);
        $sellerService->authorize($fixture['admin'], $settlement);
        $sellerService->pay($fixture['admin'], $settlement->fresh(), '900.00', 'GCash', 'GC-TRANSFER-01', null, 'payout-proofs/seller-test.pdf', 'seller-proof.pdf');
        $this->assertSame('PAYOUT_PENDING_ACK', $settlement->fresh()->payout_status);
        $this->assertSame('GC-TRANSFER-01', $settlement->fresh()->payout_external_reference);
        $this->assertSame('payout-proofs/seller-test.pdf', $settlement->fresh()->payout_proof_path);
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

    public function test_additional_care_is_split_with_shipping_40_60_and_is_not_left_unallocated(): void
    {
        $fixture = $this->parcelFixture();
        $this->setAdditionalCare($fixture, '280.00');
        $collection = $this->recordCollection($fixture, '1330.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);

        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());

        $this->assertSame(CodCollection::COLLECTED, $collection->fresh()->status);
        $this->assertSame('50.00', $allocation->shipping_fee);
        $this->assertSame('280.00', $allocation->protection_amount);
        $this->assertSame('ALLOCATED_TO_EARNINGS', $allocation->protection_account_status);
        $this->assertSame('132.00', $allocation->logistics_allocation);
        $this->assertSame('198.00', $allocation->rider_allocation);
        $this->assertSame('900.00', $allocation->seller_payable, 'Additional Care must not reduce Seller product earnings.');
        $this->assertSame('132.00', $allocation->logisticsPool()->value('total_amount'));
        $this->assertSame('198.00', $allocation->riderPool()->value('total_amount'));
        $this->assertDatabaseMissing('financial_ledger_entries', [
            'shipment_financial_allocation_id' => $allocation->id,
            'entry_type' => 'PROTECTION_UNALLOCATED',
        ]);
        $riderEntry = FinancialLedgerEntry::query()->where('shipment_financial_allocation_id', $allocation->id)->where('entry_type', 'RIDER_ALLOCATION')->firstOrFail();
        $this->assertSame('280.00', data_get($riderEntry->metadata, 'additional_care_amount'));
        $this->assertSame('330.00', data_get($riderEntry->metadata, 'combined_delivery_earnings'));

        $retry = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());
        $this->assertSame($allocation->id, $retry->id);
        $this->assertSame(1, $allocation->riderPool()->count());
        $this->assertSame(1, FinancialLedgerEntry::query()->where('shipment_financial_allocation_id', $allocation->id)->where('entry_type', 'RIDER_ALLOCATION')->count());
    }

    public function test_additional_care_three_hundred_twenty_pesos_uses_the_same_delivery_split(): void
    {
        $fixture = $this->parcelFixture();
        $this->setAdditionalCare($fixture, '320.00');
        $this->recordCollection($fixture, '1370.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);

        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());

        $this->assertSame('50.00', $allocation->shipping_fee);
        $this->assertSame('320.00', $allocation->protection_amount);
        $this->assertSame('148.00', $allocation->logistics_allocation);
        $this->assertSame('222.00', $allocation->rider_allocation);
        $this->assertSame(0, FinancialRules::cents($allocation->shipping_fee) + FinancialRules::cents($allocation->protection_amount)
            - FinancialRules::cents($allocation->logistics_allocation) - FinancialRules::cents($allocation->rider_allocation));
    }

    public function test_regional_shipping_amount_is_used_with_additional_care(): void
    {
        $fixture = $this->parcelFixture();
        $this->setAdditionalCare($fixture, '280.00', '75.33');
        $this->recordCollection($fixture, '1355.33');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);

        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());

        $this->assertSame('75.33', $allocation->shipping_fee);
        $this->assertSame('280.00', $allocation->protection_amount);
        $this->assertSame('142.13', $allocation->logistics_allocation);
        $this->assertSame('213.20', $allocation->rider_allocation);
    }

    public function test_platform_shipping_voucher_does_not_reduce_pool_or_duplicate_campaign_expense(): void
    {
        $fixture = $this->parcelFixture();
        $this->setAdditionalCare($fixture, '280.00');
        $fixture['sellerOrder']->update([
            'shipping_voucher_discount' => '20.00',
            'platform_shipping_voucher_discount' => '20.00',
            'funding_snapshot_version' => 1,
            'grand_total' => '1310.00',
        ]);
        $fixture['order']->update(['grand_total' => '1310.00']);
        $fixture['item']->update([
            'platform_shipping_voucher_discount' => '20.00',
            'funding_snapshot_version' => 1,
        ]);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'SHIPTEST20',
            'benefit_scope' => 'SHIPPING',
            'campaign_budget_total' => '100.00',
            'code' => 'SHIPTEST20',
            'name' => 'Shipping subsidy test',
            'discount_type' => 'FIXED',
            'discount_value' => '20.00',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $reservation = app(PlatformCampaignBudgetService::class)->reserve($voucher, $fixture['item'], '20.00', $fixture['buyer'], 'SHIPPING_VOUCHER');
        $this->assertNotNull($reservation);
        $this->assertSame($fixture['item']->id, (int) $reservation->order_item_id);
        $this->assertSame('CAMPAIGN_SUBSIDY_RESERVED', $reservation->entry_type);
        $this->assertSame('RESERVED', $reservation->status);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('financial_ledger_entries', 'order_item_id'));
        app(PlatformCampaignBudgetService::class)->consumeForOrderItem($fixture['item']->fresh(), $fixture['buyer']);
        $this->recordCollection($fixture, '1310.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);

        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());

        $this->assertSame('50.00', $allocation->shipping_fee);
        $this->assertSame('280.00', $allocation->protection_amount);
        $this->assertSame('132.00', $allocation->logistics_allocation);
        $this->assertSame('198.00', $allocation->rider_allocation);
        $this->assertSame(1, FinancialLedgerEntry::query()->where('order_item_id', $fixture['item']->id)->where('entry_type', 'PLATFORM_PROMOTIONAL_EXPENSE')->count());
        $this->assertSame('20.00', FinancialLedgerEntry::query()->where('order_item_id', $fixture['item']->id)->where('entry_type', 'PLATFORM_PROMOTIONAL_EXPENSE')->value('amount'));
    }

    public function test_refund_reversal_uses_the_matching_campaign_source_and_is_idempotent(): void
    {
        $fixture = $this->parcelFixture();
        $campaigns = collect([
            ['source' => 'PRODUCT_VOUCHER', 'scope' => 'PRODUCT', 'code' => 'PRODREFUND20'],
            ['source' => 'SHIPPING_VOUCHER', 'scope' => 'SHIPPING', 'code' => 'SHIPREFUND20'],
        ])->mapWithKeys(function (array $data) use ($fixture): array {
            $voucher = Voucher::query()->create([
                'seller_profile_id' => null,
                'platform_code_key' => $data['code'],
                'benefit_scope' => $data['scope'],
                'campaign_budget_total' => '100.00',
                'code' => $data['code'],
                'name' => $data['code'],
                'discount_type' => 'FIXED',
                'discount_value' => '20.00',
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addDay(),
                'is_active' => true,
            ]);
            FinancialLedgerEntry::query()->create([
                'voucher_id' => $voucher->id,
                'order_item_id' => $fixture['item']->id,
                'seller_order_id' => $fixture['sellerOrder']->id,
                'order_id' => $fixture['order']->id,
                'account_type' => 'PLATFORM_PROMOTIONAL_EXPENSE',
                'entry_type' => 'PLATFORM_PROMOTIONAL_EXPENSE',
                'direction' => 'DEBIT',
                'amount' => '20.00',
                'status' => 'POSTED',
                'idempotency_key' => 'test-campaign-expense-'.$voucher->id,
                'actor_user_id' => $fixture['buyer']->id,
                'posted_at' => now(),
                'metadata' => ['source' => $data['source']],
            ]);

            return [$data['source'] => $voucher];
        });

        $budgets = app(PlatformCampaignBudgetService::class);
        $budgets->reverseForOrderItem($fixture['item'], '5.00', 'test-refund-1', $fixture['buyer'], 'SHIPPING_VOUCHER');
        $budgets->reverseForOrderItem($fixture['item'], '5.00', 'test-refund-1', $fixture['buyer'], 'SHIPPING_VOUCHER');

        $this->assertSame(1, FinancialLedgerEntry::query()
            ->where('voucher_id', $campaigns['SHIPPING_VOUCHER']->id)
            ->where('entry_type', 'PLATFORM_PROMOTIONAL_EXPENSE_REVERSAL')
            ->count());
        $this->assertSame('5.00', FinancialLedgerEntry::query()
            ->where('voucher_id', $campaigns['SHIPPING_VOUCHER']->id)
            ->where('entry_type', 'PLATFORM_PROMOTIONAL_EXPENSE_REVERSAL')
            ->value('amount'));
        $this->assertSame(0, FinancialLedgerEntry::query()
            ->where('voucher_id', $campaigns['PRODUCT_VOUCHER']->id)
            ->where('entry_type', 'PLATFORM_PROMOTIONAL_EXPENSE_REVERSAL')
            ->count());
        $this->assertSame(1500, $budgets->summary($campaigns['SHIPPING_VOUCHER'])['spent_cents']);
        $this->assertSame(2000, $budgets->summary($campaigns['PRODUCT_VOUCHER'])['spent_cents']);
    }

    public function test_pending_cod_and_cancelled_delivery_do_not_create_payout_allocations(): void
    {
        $fixture = $this->parcelFixture();
        $this->setAdditionalCare($fixture, '280.00');
        app(CodCollectionService::class)->initializeForShipment($fixture['shipment']);
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);

        $this->assertNull(app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh()));
        $this->assertDatabaseMissing('shipment_financial_allocations', ['shipment_id' => $fixture['shipment']->id]);
        $this->assertDatabaseMissing('rider_earning_pools', ['shipment_id' => $fixture['shipment']->id]);

        $cancelled = $this->parcelFixture();
        $this->setAdditionalCare($cancelled, '280.00');
        $cancelled['shipment']->update(['current_status' => 'CANCELLED']);
        $collection = app(CodCollectionService::class)->initializeForShipment($cancelled['shipment']);
        $this->assertNotNull($collection);
        $this->assertSame(CodCollection::PENDING, $collection->status);
        $this->assertNull(app(FinancialAllocationService::class)->initializeForShipment($cancelled['shipment']->fresh()));
        $this->assertDatabaseMissing('shipment_financial_allocations', ['shipment_id' => $cancelled['shipment']->id]);
    }

    public function test_refundable_additional_care_partial_refund_reverses_each_share_once(): void
    {
        $fixture = $this->parcelFixture();
        $this->setAdditionalCare($fixture, '280.00');
        $this->recordCollection($fixture, '1330.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());

        $dispute = Dispute::query()->create([
            'dispute_number' => 'REFUND-CARE-'.strtoupper((string) random_int(100000, 999999)),
            'opened_by_user_id' => $fixture['buyer']->id,
            'order_id' => $fixture['order']->id,
            'seller_order_id' => $fixture['sellerOrder']->id,
            'shipment_id' => $fixture['shipment']->id,
            'type' => 'RETURN_REFUND',
            'subject' => 'Partial Additional Care refund',
            'description' => 'Approved return of part of the eligible delivery charge.',
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);
        $request = ReturnRefundRequest::query()->create([
            'request_number' => 'RR-CARE-'.strtoupper((string) random_int(100000, 999999)),
            'dispute_id' => $dispute->id,
            'order_id' => $fixture['order']->id,
            'buyer_user_id' => $fixture['buyer']->id,
            'issue_category' => 'DAMAGED',
            'issue_reason' => 'Additional Care adjustment',
            'solution' => 'REFUND',
            'description' => 'A partial Additional Care refund was approved.',
            'refund_method' => 'CASH',
            'refundable_amount' => '50.00',
            'requested_amount' => '50.00',
            'buyer_email' => $fixture['buyer']->email,
            'status' => 'APPROVED',
            'submitted_at' => now(),
        ]);
        $sellerRequest = SellerReturnRefundRequest::query()->create([
            'return_refund_request_id' => $request->id,
            'seller_order_id' => $fixture['sellerOrder']->id,
            'seller_profile_id' => $fixture['seller']->id,
            'status' => SellerReturnRefundRequest::STATUS_APPROVED,
            'requested_amount' => '50.00',
            'approved_amount' => '50.00',
            'reviewed_by_user_id' => $fixture['sellerUser']->id,
            'reviewed_at' => now(),
        ]);
        ReturnRefundRequestItem::query()->create([
            'return_refund_request_id' => $request->id,
            'seller_return_refund_request_id' => $sellerRequest->id,
            'order_item_id' => $fixture['item']->id,
            'quantity' => 1,
            'refundable_amount' => '50.00',
        ]);
        $obligation = CashRefundObligation::query()->create([
            'seller_return_refund_request_id' => $sellerRequest->id,
            'return_refund_request_id' => $request->id,
            'buyer_user_id' => $fixture['buyer']->id,
            'order_id' => $fixture['order']->id,
            'seller_order_id' => $fixture['sellerOrder']->id,
            'approved_amount' => '50.00',
            'product_refund_amount' => '0.00',
            'shipping_refund_amount' => '0.00',
            'protection_refund_amount' => '50.00',
            'shipping_refund_policy' => 'PENDING_OWNER_POLICY',
            'protection_refund_policy' => 'REFUNDABLE',
            'state' => CashRefundObligation::CASH_READY,
            'idempotency_key' => 'partial-care-refund-'.$sellerRequest->id,
            'approved_by_user_id' => $fixture['admin']->id,
            'approved_at' => now(),
            'cash_ready_by_user_id' => $fixture['admin']->id,
            'cash_ready_at' => now(),
        ]);
        config(['likhae.cash_refund_authorized_account_types' => [User::TYPE_ADMIN]]);

        $refunds = app(CashRefundWorkflowService::class);
        $refunds->markCashPaid($obligation, $fixture['admin'], 'CARE-PARTIAL-50');
        $refunds->markCashPaid($obligation->fresh(), $fixture['admin'], 'CARE-PARTIAL-50');

        $this->assertSame('280.00', $allocation->protection_amount);
        $this->assertSame('112.00', $allocation->logisticsPool()->value('total_amount'));
        $this->assertSame('168.00', $allocation->riderPool()->value('total_amount'));
        $this->assertSame('168.00', $allocation->riderPool()->firstOrFail()->participants()->sole()->share_amount);
        $this->assertDatabaseHas('financial_ledger_entries', [
            'shipment_financial_allocation_id' => $allocation->id,
            'account_type' => 'LOGISTICS',
            'entry_type' => 'REFUND_LOGISTICS_REVERSAL',
            'direction' => 'DEBIT',
            'amount' => '20.00',
            'status' => 'POSTED',
        ]);
        $this->assertDatabaseHas('financial_ledger_entries', [
            'shipment_financial_allocation_id' => $allocation->id,
            'account_type' => 'RIDER',
            'entry_type' => 'REFUND_RIDER_REVERSAL',
            'direction' => 'DEBIT',
            'amount' => '30.00',
            'status' => 'POSTED',
        ]);
        $this->assertSame(1, FinancialLedgerEntry::query()->where('idempotency_key', 'cash-refund-delivery-LOGISTICS-additional_care-'.$obligation->id)->count());
        $this->assertSame(1, FinancialLedgerEntry::query()->where('idempotency_key', 'cash-refund-delivery-RIDER-additional_care-'.$obligation->id)->count());
    }

    public function test_rider_reassignment_after_allocation_does_not_duplicate_the_committed_rider_share(): void
    {
        $fixture = $this->parcelFixture();
        $this->setAdditionalCare($fixture, '280.00');
        $this->recordCollection($fixture, '1330.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());
        $pool = $allocation->riderPool()->firstOrFail();
        $firstParticipant = $pool->participants()->sole();

        $replacementUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $replacement = RiderProfile::create([
            'user_id' => $replacementUser->id, 'logistics_center_id' => $fixture['center']->id,
            'vehicle_type' => 'motorcycle', 'plate_number' => 'FIN-PLATE-03',
            'drivers_license_number' => 'FIN-LICENSE-03', 'status' => 'ACTIVE',
        ]);
        RiderAssignment::create([
            'shipment_id' => $fixture['shipment']->id, 'rider_profile_id' => $replacement->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY, 'status' => 'COMPLETED',
        ]);

        app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());
        $pool->refresh();

        $this->assertSame(1, $pool->participants()->count());
        $this->assertSame($firstParticipant->id, $pool->participants()->sole()->id);
        $this->assertSame($fixture['rider']->id, (int) $pool->participants()->sole()->rider_profile_id);
        $this->assertSame('198.00', $pool->participants()->sole()->share_amount);
        $this->assertSame('198.00', $pool->total_amount);
        $this->assertSame('0.00', $pool->unresolved_share_amount);
        $this->assertSame(1, FinancialLedgerEntry::query()->where('shipment_financial_allocation_id', $allocation->id)->where('entry_type', 'RIDER_ALLOCATION')->count());
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
        $service->pay($fixture['admin'], $settlement->fresh(), '900.00', 'GCash', 'BANK-FAILED-01', null, 'payout-proofs/seller-failed.pdf', 'failed-proof.pdf');
        $service->failPayout($fixture['admin'], $settlement->fresh(), 'Receiving account rejected the transfer.');
        $this->assertSame('FAILED', $settlement->fresh()->payout_status);
        $this->assertSame('Receiving account rejected the transfer.', $settlement->fresh()->payout_failure_reason);
        $this->assertFinanceThrows(fn () => $service->acknowledgeBySeller($fixture['sellerUser'], $settlement->fresh()));

        $service->authorize($fixture['admin'], $settlement->fresh(), 'Seller corrected the receiving account.');
        $service->pay($fixture['admin'], $settlement->fresh(), '900.00', 'GCash', 'BANK-RETRY-02', null, 'payout-proofs/seller-retry.pdf', 'retry-proof.pdf');
        $service->acknowledgeBySeller($fixture['sellerUser'], $settlement->fresh());
        $this->assertSame('PAID', $settlement->fresh()->payout_status);
        $this->assertSame('BANK-RETRY-02', $settlement->fresh()->payout_external_reference);
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'SELLER_PAYOUT_FAILED']);
    }

    public function test_manual_seller_and_logistics_transfers_keep_private_proof_and_open_support_threads(): void
    {
        Storage::fake('local');
        $fixture = $this->parcelFixture();
        $collection = $this->recordCollection($fixture, '1050.00');
        $fixture['shipment']->update(['current_status' => 'DELIVERED']);
        $allocation = app(FinancialAllocationService::class)->initializeForShipment($fixture['shipment']->fresh());
        $remittances = app(RemittanceService::class);
        $remittance = $remittances->submit($fixture['riderUser'], [$collection->id], '1050.00');
        $remittances->verify($fixture['centerUser'], $remittance, '1050.00');
        $remittances->reconcile($fixture['centerUser'], $remittance->fresh());
        $proceeds = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
            ->firstOrFail();
        $proceedsService = app(ProductProceedsService::class);
        $proceedsService->submitToAdmin($fixture['centerUser'], $proceeds, '1020.00');
        $proceedsService->acknowledgeByAdmin($fixture['admin'], $proceeds->fresh(), '1020.00');

        $settlement = SellerSettlement::query()->where('shipment_financial_allocation_id', $allocation->id)->firstOrFail();
        $requestResponse = $this->actingAs($fixture['sellerUser'])->post(route('seller.settlements.request-payout', $settlement));
        $this->assertTrue($requestResponse->isRedirect(), 'Seller request returned '.$requestResponse->status());
        $this->actingAs($fixture['sellerUser'])->get(route('seller.wallet'))->assertViewHas('payoutBalances', fn (array $balances): bool => (float) $balances['available'] === 0.0 && (float) $balances['requested'] === 900.0);
        app(SellerSettlementService::class)->authorize($fixture['admin'], $settlement->fresh());
        $this->actingAs($fixture['admin'])->post(route('admin.finance.seller-settlements.pay', $settlement), [
            'amount' => '900.00',
            'payout_external_reference' => 'GC-SELLER-RECEIPT-77',
            'payout_proof' => UploadedFile::fake()->create('seller-receipt.pdf', 20, 'application/pdf'),
        ])->assertRedirect()->assertSessionHas('status');
        $settlement->refresh();
        $this->assertSame('GC-SELLER-RECEIPT-77', $settlement->payout_external_reference);
        $this->assertSame('PAYOUT_PENDING_ACK', $settlement->payout_status);
        $this->actingAs($fixture['sellerUser'])->get(route('seller.wallet'))->assertViewHas('payoutBalances', fn (array $balances): bool => (float) $balances['awaiting_ack'] === 900.0);
        Storage::disk('local')->assertExists($settlement->payout_proof_path);
        $this->actingAs($fixture['sellerUser'])->get(route('seller.settlements.payout-proof', $settlement))->assertOk();
        $this->actingAs($fixture['admin'])->get(route('admin.finance.seller-settlements.proof', $settlement))->assertOk();
        $this->actingAs($fixture['otherSellerUser'])->get(route('seller.settlements.payout-proof', $settlement))->assertForbidden();
        $this->actingAs($fixture['sellerUser'])->get(route('seller.wallet'))->assertOk()->assertSee('GC-SELLER-RECEIPT-77')->assertSee('View transfer proof');
        $supportResponse = $this->actingAs($fixture['sellerUser'])->post(route('seller.settlements.report-payout-issue', $settlement));
        $this->assertTrue($supportResponse->isRedirect(), 'Seller support returned '.$supportResponse->status());
        $this->assertTrue(Message::query()->where('sender_user_id', $fixture['sellerUser']->id)->where('body', 'like', 'PAYOUT SUPPORT%')->exists());

        $pool = LogisticsEarningPool::query()->where('shipment_financial_allocation_id', $allocation->id)->firstOrFail();
        $requestResponse = $this->actingAs($fixture['centerUser'])->post(route('logistics.wallet.payout-requests.store', $pool));
        $this->assertTrue($requestResponse->isRedirect(), 'Logistics request returned '.$requestResponse->status());
        $this->actingAs($fixture['centerUser'])->get(route('logistics.wallet'))->assertViewHas('payoutSummary', fn (array $summary): bool => (float) $summary['available'] === 0.0 && (float) $summary['requested'] === 20.0);
        $response = $this->actingAs($fixture['admin'])->post(route('admin.finance.logistics-pools.pay', $pool), [
            'amount' => '20.00',
            'payout_external_reference' => 'GC-LOGISTICS-RECEIPT-88',
            'payout_proof' => UploadedFile::fake()->create('logistics-receipt.pdf', 20, 'application/pdf'),
        ]);
        $this->assertTrue($response->isRedirect(), 'Logistics Admin payout returned '.$response->status());
        $response->assertSessionHas('status');
        $logisticsReceipt = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'LOGISTICS_PAYOUT')
            ->firstOrFail();
        $this->assertSame('GC-LOGISTICS-RECEIPT-88', data_get($logisticsReceipt->metadata, 'payout_external_reference'));
        $this->actingAs($fixture['centerUser'])->get(route('logistics.wallet'))->assertViewHas('payoutSummary', fn (array $summary): bool => (float) $summary['awaiting_ack'] === 20.0);
        Storage::disk('local')->assertExists(data_get($logisticsReceipt->metadata, 'payout_proof_path'));
        $this->actingAs($fixture['centerUser'])->get(route('logistics.wallet.payout-proof', $logisticsReceipt))->assertOk();
        $this->actingAs($fixture['admin'])->get(route('admin.finance.logistics-payouts.proof', $logisticsReceipt))->assertOk();
        $this->actingAs($fixture['centerUser'])->get(route('logistics.wallet'))->assertOk()->assertSee('GC-LOGISTICS-RECEIPT-88')->assertSee('View transfer proof');
        $supportResponse = $this->actingAs($fixture['centerUser'])->post(route('logistics.wallet.payout-report-issue', $logisticsReceipt));
        $this->assertTrue($supportResponse->isRedirect(), 'Logistics support returned '.$supportResponse->status());
        $this->assertSame(2, Conversation::query()->count());
        $this->actingAs($fixture['admin'])->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Admin payout overview')
            ->assertSee('GC-SELLER-RECEIPT-77')
            ->assertSee('GC-LOGISTICS-RECEIPT-88')
            ->assertSee('View proof');
    }

    public function test_multiple_item_parcels_get_independent_allocations_and_shipping_split(): void
    {
        $fixture = $this->parcelFixture();
        $sellerOrder = $fixture['sellerOrder'];
        $firstItem = $fixture['item'];
        $firstItem->update([
            'original_unit_price' => '600.00', 'unit_price' => '600.00', 'line_total' => '600.00',
            'protection_fee' => '280.00', 'protection_selected' => true, 'protection_handling_flag' => true,
        ]);
        $secondItem = $sellerOrder->items()->create([
            'product_name' => 'Second parcel item', 'sku' => 'SECOND', 'original_unit_price' => '400.00',
            'unit_price' => '400.00', 'quantity' => 1, 'discount_amount' => 0, 'line_total' => '400.00',
            'protection_fee' => '320.00', 'protection_selected' => true, 'protection_handling_flag' => true,
        ]);
        $sellerOrder->update([
            'item_subtotal' => '1000.00', 'shipping_fee' => '50.00',
            'protection_total' => '600.00', 'grand_total' => '1650.00',
        ]);
        $fixture['order']->update(['protection_total' => '600.00', 'grand_total' => '1650.00']);
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
        $secondRiderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $secondRider = RiderProfile::create([
            'user_id' => $secondRiderUser->id, 'logistics_center_id' => $fixture['center']->id,
            'vehicle_type' => 'motorcycle', 'plate_number' => 'FIN-PLATE-02',
            'drivers_license_number' => 'FIN-LICENSE-02', 'status' => 'ACTIVE',
        ]);
        RiderAssignment::create([
            'shipment_id' => $second->id, 'rider_profile_id' => $secondRider->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY, 'status' => 'COMPLETED',
        ]);
        $firstCollection = CodCollection::create([
            'shipment_id' => $first->id, 'seller_order_id' => $sellerOrder->id, 'order_id' => $fixture['order']->id,
            'rider_profile_id' => $fixture['rider']->id, 'logistics_center_id' => $fixture['center']->id,
            'expected_amount' => '910.00', 'collected_amount' => '910.00', 'status' => CodCollection::COLLECTED,
            'collection_reference' => 'COD-FIRST-PARCEL', 'collected_at' => now(),
        ]);
        CodCollection::create([
            'shipment_id' => $second->id, 'seller_order_id' => $sellerOrder->id, 'order_id' => $fixture['order']->id,
            'rider_profile_id' => $secondRider->id, 'logistics_center_id' => $fixture['center']->id,
            'expected_amount' => '740.00', 'collected_amount' => '740.00', 'status' => CodCollection::COLLECTED,
            'collection_reference' => 'COD-SECOND-PARCEL', 'collected_at' => now(),
        ]);

        $allocations = app(FinancialAllocationService::class);
        $firstAllocation = $allocations->initializeForShipment($first);
        $secondAllocation = $allocations->initializeForShipment($second);
        $this->assertNotSame($firstAllocation->id, $secondAllocation->id);
        $this->assertSame('30.00', $firstAllocation->shipping_fee);
        $this->assertSame('280.00', $firstAllocation->protection_amount);
        $this->assertSame('186.00', $firstAllocation->rider_allocation);
        $this->assertSame('124.00', $firstAllocation->logistics_allocation);
        $this->assertSame('20.00', $secondAllocation->shipping_fee);
        $this->assertSame('320.00', $secondAllocation->protection_amount);
        $this->assertSame('204.00', $secondAllocation->rider_allocation);
        $this->assertSame('136.00', $secondAllocation->logistics_allocation);
        $this->assertSame($fixture['rider']->id, (int) $firstAllocation->riderPool()->firstOrFail()->participants()->sole()->rider_profile_id);
        $this->assertSame($secondRider->id, (int) $secondAllocation->riderPool()->firstOrFail()->participants()->sole()->rider_profile_id);
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
        $categorySuffix = (string) random_int(100000, 999999);
        $category = Category::create(['name' => 'Finance test category '.$categorySuffix, 'slug' => 'finance-test-category-'.$categorySuffix, 'is_active' => true]);
        $seller = SellerProfile::create(['user_id' => $sellerUser->id, 'primary_category_id' => $category->id, 'business_name' => 'Finance test seller', 'status' => 'ACTIVE', 'payout_method' => 'GCash', 'payout_account_name' => 'Finance Test Seller', 'payout_account_number' => '09170000002']);
        SellerProfile::create(['user_id' => $otherSellerUser->id, 'primary_category_id' => $category->id, 'business_name' => 'Other finance seller', 'status' => 'ACTIVE']);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id, 'code' => 'FINANCE-CENTER-'.$categorySuffix, 'business_name' => 'Finance Center '.$categorySuffix, 'status' => 'ACTIVE', 'approved_by_user_id' => $admin->id, 'approved_at' => now(), 'payout_method' => 'GCash', 'payout_account_name' => 'Finance Test Center', 'payout_account_number' => '09170000003',
        ]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id, 'logistics_center_id' => $center->id, 'vehicle_type' => 'motorcycle',
            'plate_number' => 'FIN-PLATE-01-'.$categorySuffix, 'drivers_license_number' => 'FIN-LICENSE-01-'.$categorySuffix, 'status' => 'ACTIVE',
            'payout_method' => 'GCash', 'payout_account_name' => 'Finance Test Rider', 'payout_account_number' => '09170000001',
        ]);
        $order = Order::create(['order_number' => 'FIN-ORDER-'.$categorySuffix, 'buyer_user_id' => $buyer->id, 'status' => 'PROCESSING', 'grand_total' => '1050.00']);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'FIN-SELLER-ORDER-'.$categorySuffix, 'order_id' => $order->id, 'seller_profile_id' => $seller->id,
            'status' => 'COMPLETED', 'item_subtotal' => '1000.00', 'voucher_discount' => '0.00', 'shipping_fee' => '50.00', 'grand_total' => '1050.00',
        ]);
        $item = $sellerOrder->items()->create([
            'product_name' => 'Finance test item', 'sku' => 'FIN-'.$categorySuffix, 'original_unit_price' => '1000.00',
            'unit_price' => '1000.00', 'quantity' => 1, 'discount_amount' => '0.00', 'line_total' => '1000.00',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id, 'order_item_id' => $item->id,
            'tracking_number' => 'PARCEL-FIN-'.$categorySuffix, 'logistics_center_id' => $center->id,
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

    private function setAdditionalCare(array $fixture, string $protectionAmount, string $shippingFee = '50.00'): void
    {
        $total = FinancialRules::amount(
            FinancialRules::cents($fixture['sellerOrder']->item_subtotal)
            + FinancialRules::cents($shippingFee)
            + FinancialRules::cents($protectionAmount),
        );
        $fixture['item']->update([
            'protection_fee' => $protectionAmount,
            'protection_selected' => FinancialRules::cents($protectionAmount) > 0,
            'protection_handling_flag' => FinancialRules::cents($protectionAmount) > 0,
        ]);
        $fixture['sellerOrder']->update([
            'shipping_fee' => $shippingFee,
            'protection_total' => $protectionAmount,
            'grand_total' => $total,
        ]);
        $fixture['order']->update(['protection_total' => $protectionAmount, 'grand_total' => $total]);
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
