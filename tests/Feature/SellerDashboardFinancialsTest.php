<?php

namespace Tests\Feature;

use App\Models\Admin\CommissionTransaction;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\SellerSettlement;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Logistics\Shipment;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Reports\SellerOrderValueChartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerDashboardFinancialsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private SellerProfile $sellerProfile;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $this->sellerProfile = $this->seller->sellerProfile;
        $this->buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
    }

    public function test_dashboard_and_reports_share_delivered_ledger_totals_and_inclusive_local_date_bounds(): void
    {
        $productA = $this->product('Financial product A');
        $productB = $this->product('Financial product B');
        $this->financialRecord(
            $this->sellerProfile,
            'PICKED_UP',
            '2026-10-01 00:00:00',
            '1000.00',
            '77.00',
            [[$productA, '600.00'], [$productB, '400.00']],
            '2026-09-30 23:59:00',
        );
        $this->financialRecord(
            $this->sellerProfile,
            'COMPLETED',
            '2026-10-17 23:59:59',
            '500.00',
            '3.00',
            [[$productA, '500.00']],
            '2026-10-18 00:01:00',
        );
        $this->financialRecord(
            $this->sellerProfile,
            'PICKED_UP',
            '2026-10-15 08:00:00',
            '333.00',
            '33.00',
            [],
            null,
            'PENDING',
            ['AT_SORTING_CENTER'],
        );
        $this->financialRecord($this->sellerProfile, 'CANCELLED', '2026-10-10 12:00:00', '900.00', '90.00');
        $this->financialRecord($this->sellerProfile, 'PLACED', '2026-10-11 12:00:00', '700.00', '70.00', [], null, 'VOID');
        $otherSeller = $this->otherSeller();
        $this->financialRecord($otherSeller, 'COMPLETED', '2026-10-12 12:00:00', '2000.00', '200.00');

        $period = ['from' => '2026-10-01', 'to' => '2026-10-17'];
        $dashboard = $this->actingAs($this->seller)
            ->get(route('seller.dashboard', $period))
            ->assertOk();
        $report = $this->get(route('seller.reports', $period))->assertOk();

        $this->assertSame(1500.0, $dashboard->viewData('financialSummary')['gross']);
        $this->assertSame(80.0, $dashboard->viewData('financialSummary')['commission']);
        $this->assertSame(1420.0, $dashboard->viewData('financialSummary')['net']);
        $reportSummary = $report->viewData('reportSummary');
        $this->assertSame([
            'gross' => $reportSummary['gross'],
            'commission' => $reportSummary['commission'],
            'net' => $reportSummary['net'],
            'orders' => $reportSummary['financial_orders'],
        ], $dashboard->viewData('financialSummary'));
        $this->assertSame(1500.0, $report->viewData('reportSummary')['gross']);
        $this->assertSame(80.0, $report->viewData('reportSummary')['commission']);
        $this->assertSame(1420.0, $report->viewData('reportSummary')['net']);

        $firstDay = $this->get(route('seller.reports', ['from' => '2026-10-01', 'to' => '2026-10-01']))->assertOk();
        $this->assertSame(1000.0, $firstDay->viewData('reportSummary')['gross']);
        $this->assertSame(77.0, $firstDay->viewData('reportSummary')['commission']);

        $emptyPeriod = $this->get(route('seller.dashboard', ['from' => '2026-10-18', 'to' => '2026-10-19']))->assertOk();
        $this->assertSame(0.0, $emptyPeriod->viewData('financialSummary')['gross']);
        $this->assertSame(0.0, $emptyPeriod->viewData('financialSummary')['commission']);
        $this->assertSame(0.0, $emptyPeriod->viewData('financialSummary')['net']);

        $this->get(route('seller.reports', ['from' => '2026-10-17', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
        $this->get(route('seller.dashboard', ['from' => 'not-a-date', 'to' => '2026-10-17']))
            ->assertSessionHasErrors('from');
        $this->get(route('seller.reports', ['from' => '2026-10-01', 'to' => 'not-a-date']))
            ->assertSessionHasErrors('to');
    }

    public function test_seller_order_value_chart_waits_for_parent_order_completion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Manila'));
        $completed = $this->financialRecord(
            $this->sellerProfile,
            'COMPLETED',
            '2026-10-16 12:00:00',
            '500.00',
            '0.00',
            [],
            '2026-10-10 12:00:00',
        );
        $completed->order()->update(['status' => 'COMPLETED', 'completed_at' => now()->subMinutes(30)]);

        $this->financialRecord(
            $this->sellerProfile,
            'PLACED',
            '2026-10-26 11:00:00',
            '1200.00',
            '0.00',
            [],
            '2026-10-26 11:00:00',
        );

        $chart = app(SellerOrderValueChartService::class)->build($this->sellerProfile->id, '7');

        $this->assertSame(500.0, $chart['gross']);
        $this->assertTrue($chart['has_orders']);
        $this->assertSame(500.0, $chart['chart']->firstWhere('key', now()->toDateString())['total']);
    }

    public function test_product_scoped_report_allocates_only_that_products_share_of_the_assessed_order_commission(): void
    {
        $productA = $this->product('Scoped financial product A');
        $productB = $this->product('Scoped financial product B');
        $this->financialRecord(
            $this->sellerProfile,
            'COMPLETED',
            '2026-10-07 10:00:00',
            '1000.00',
            '77.00',
            [[$productA, '600.00'], [$productB, '400.00']],
        );

        $response = $this->actingAs($this->seller)
            ->get(route('seller.reports', [
                'from' => '2026-10-07',
                'to' => '2026-10-07',
                'product_id' => $productA->id,
            ]))
            ->assertOk();

        $summary = $response->viewData('reportSummary');
        $this->assertSame(600.0, $summary['gross']);
        $this->assertSame(46.2, $summary['commission']);
        $this->assertSame(553.8, $summary['net']);
        $this->assertSame(1, $summary['financial_orders']);
    }

    public function test_product_table_and_pdf_use_recognized_historical_gross_and_round_commission_without_drift(): void
    {
        $productA = $this->product('Historical financial product A');
        $productB = $this->product('Historical financial product B');
        $productC = $this->product('Historical financial product C');
        $sellerOrder = $this->financialRecord(
            $this->sellerProfile,
            'COMPLETED',
            '2026-10-17 23:59:59',
            '100.00',
            '10.01',
            [[$productA, '33.33'], [$productB, '33.33'], [$productC, '33.34']],
            '2026-10-18 00:01:00',
            'PENDING',
            ['DELIVERED', 'COMPLETED'],
        );
        $sellerOrder->forceFill(['created_at' => '2026-10-18 00:01:00'])->save();
        $productA->update(['status' => 'ARCHIVED']);
        $productC->delete();
        ProductVariant::query()->where('product_id', $productA->id)->update(['price' => '9999.00']);

        $period = ['from' => '2026-10-17', 'to' => '2026-10-17'];
        $response = $this->actingAs($this->seller)->get(route('seller.reports', $period))->assertOk();
        $response->assertSee('Asia/Manila');
        $summary = $response->viewData('reportSummary');
        $products = collect($response->viewData('reportProducts'))->keyBy('id');

        $this->assertSame(0, $summary['orders'], 'Operational order counts use order creation date.');
        $this->assertSame(1, $summary['financial_orders'], 'Financial values use the ledger recognition date.');
        $this->assertSame(100.0, $summary['gross']);
        $this->assertSame(10.01, $summary['commission']);
        $this->assertSame(89.99, $summary['net']);
        $this->assertSame(100.0, $products->sum('gross'));
        $this->assertSame(10.01, $products->sum('commission'));
        $this->assertSame(89.99, $products->sum('net'));
        $this->assertSame(33.33, $products->get($productA->id)['gross'], 'Current catalog prices do not replace the order snapshot.');
        $this->assertSame(3.34, $products->get($productA->id)['commission'], 'Residual cents use a deterministic largest-remainder allocation.');
        $this->assertSame(1, $products->get($productC->id)['orders'], 'Soft-deleted products retain historical report attribution.');
        $response->assertSee('Historical financial product C');

        $productReport = $this->get(route('seller.reports', [
            ...$period,
            'product_id' => $productA->id,
        ]))->assertOk();
        $productSummary = $productReport->viewData('reportSummary');
        $this->assertSame(33.33, $productSummary['gross']);
        $this->assertSame(3.34, $productSummary['commission']);
        $this->assertSame(29.99, $productSummary['net']);

        $pdf = $this->get(route('seller.reports.download', [
            ...$period,
            'product_id' => $productA->id,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('PHP 33.33', $pdf);
        $this->assertStringContainsString('PHP 3.34', $pdf);
        $this->assertStringContainsString('PHP 29.99', $pdf);
        $this->assertStringContainsString('\\(Asia/Manila\\)', $pdf);
        $this->assertStringContainsString('Seller: '.$this->sellerProfile->business_name, $pdf);
    }

    public function test_report_rejects_a_foreign_sellers_product_filter(): void
    {
        $otherSeller = $this->otherSeller();
        $foreignProduct = Product::query()->create([
            'seller_profile_id' => $otherSeller->id,
            'category_id' => $otherSeller->primary_category_id ?: Category::query()->firstOrFail()->id,
            'name' => 'Foreign report product',
            'slug' => 'foreign-report-'.Str::lower(Str::random(8)),
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.reports', ['product_id' => $foreignProduct->id]))
            ->assertNotFound();
        $this->get(route('seller.reports.download', ['product_id' => $foreignProduct->id]))
            ->assertNotFound();
    }

    public function test_report_and_dashboard_show_ledger_settlement_states_and_refund_reversals_separately(): void
    {
        $product = $this->product('Settlement report product');
        $orders = [
            $this->financialRecord($this->sellerProfile, 'COMPLETED', '2026-10-17 08:00:00', '100.00', '10.00', [[$product, '100.00']]),
            $this->financialRecord($this->sellerProfile, 'COMPLETED', '2026-10-17 09:00:00', '100.00', '10.00', [[$product, '100.00']]),
            $this->financialRecord($this->sellerProfile, 'COMPLETED', '2026-10-17 10:00:00', '100.00', '10.00', [[$product, '100.00']]),
            $this->financialRecord($this->sellerProfile, 'COMPLETED', '2026-10-17 11:00:00', '100.00', '10.00', [[$product, '100.00']]),
        ];
        $settlements = [
            ['PENDING', '90.00', '90.00'],
            ['REVIEW_REQUIRED', null, '80.00'],
            ['AUTHORIZED', '70.00', '70.00'],
            ['PAID', '60.00', '0.00'],
        ];

        foreach ($orders as $index => $sellerOrder) {
            $shipment = $sellerOrder->shipments()->firstOrFail();
            $allocation = ShipmentFinancialAllocation::query()->create([
                'shipment_id' => $shipment->id,
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'currency' => 'PHP',
                'original_product_amount' => '100.00',
                'product_amount' => '100.00',
                'discount_amount' => '0.00',
                'shipping_fee' => '50.00',
                'platform_commission' => '10.00',
                'seller_payable' => $settlements[$index][1],
                'logistics_allocation' => '20.00',
                'rider_allocation' => '30.00',
                'discount_funding_status' => 'NOT_APPLICABLE',
                'status' => 'PENDING',
                'calculated_at' => '2026-10-17 12:00:00',
            ]);
            SellerSettlement::query()->create([
                'shipment_financial_allocation_id' => $allocation->id,
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'seller_profile_id' => $this->sellerProfile->id,
                'original_product_amount' => '100.00',
                'discount_amount' => '0.00',
                'platform_commission' => '10.00',
                'seller_payable' => $settlements[$index][1],
                'payout_amount' => $settlements[$index][0] === 'PAID' ? '60.00' : '0.00',
                'outstanding_balance' => $settlements[$index][2],
                'payout_status' => $settlements[$index][0],
            ]);
        }

        foreach ([
            ['SELLER', 'REFUND_SELLER_REVERSAL', '15.00'],
            ['PLATFORM', 'REFUND_COMMISSION_REVERSAL', '1.50'],
        ] as [$accountType, $entryType, $amount]) {
            FinancialLedgerEntry::query()->create([
                'seller_order_id' => $orders[0]->id,
                'order_id' => $orders[0]->order_id,
                'account_type' => $accountType,
                'entry_type' => $entryType,
                'direction' => 'DEBIT',
                'amount' => $amount,
                'status' => 'POSTED',
                'idempotency_key' => 'report-'.$entryType,
                'posted_at' => '2026-10-17 13:00:00',
            ]);
        }

        $period = ['from' => '2026-10-17', 'to' => '2026-10-17'];
        $report = $this->actingAs($this->seller)->get(route('seller.reports', $period))->assertOk();
        $summary = $report->viewData('reportSummary');
        $this->assertSame(400.0, $summary['gross']);
        $this->assertSame(40.0, $summary['commission']);
        $this->assertSame(360.0, $summary['net']);
        $this->assertSame(170.0, $summary['pending_balance']);
        $this->assertSame(70.0, $summary['authorized_balance']);
        $this->assertSame(60.0, $summary['paid_balance']);
        $this->assertSame(240.0, $summary['outstanding_balance']);
        $this->assertSame(15.0, $summary['seller_refund_adjustment']);
        $this->assertSame(1.5, $summary['commission_refund_reversal']);
        $this->assertSame(360.0, $summary['gross'] - $summary['commission'], 'Refund rows remain separate from the defined Net Sales formula.');
        $report->assertSee('Outstanding settlement balance is not the same as Net Sales.');

        $pdf = $this->get(route('seller.reports.download', $period))->assertOk()->getContent();
        $this->assertStringContainsString('Pending / review: PHP 170.00', $pdf);
        $this->assertStringContainsString('Authorized: PHP 70.00', $pdf);
        $this->assertStringContainsString('Paid: PHP 60.00', $pdf);
        $this->assertStringContainsString('Outstanding: PHP 240.00', $pdf);
        $this->assertStringContainsString('Posted seller refund adjustments: PHP 15.00', $pdf);

        $dashboard = $this->get(route('seller.dashboard', $period))->assertOk();
        $dashboardSummary = $dashboard->viewData('settlementSummary');
        $this->assertSame(170.0, $dashboardSummary['pending']);
        $this->assertSame(70.0, $dashboardSummary['authorized']);
        $this->assertSame(60.0, $dashboardSummary['paid']);
        $this->assertSame(240.0, $dashboardSummary['outstanding']);
    }

    public function test_dashboard_counts_fulfillment_states_once_per_seller_order_across_multiple_parcels(): void
    {
        $this->statusOrder('PLACED');
        $this->statusOrder('CONFIRMED');
        $this->statusOrder('PREPARING');
        $this->statusOrder('READY_FOR_PICKUP');
        $pickedUp = $this->statusOrder('PICKED_UP');
        $this->shipment($pickedUp, 'PICKED_UP');
        $this->shipment($pickedUp, 'PICKED_UP');
        $inTransit = $this->statusOrder('PICKED_UP');
        $this->shipment($inTransit, 'AT_SORTING_CENTER');
        $this->shipment($inTransit, 'DELIVERED');
        $delivered = $this->statusOrder('PICKED_UP');
        $this->shipment($delivered, 'DELIVERED');
        $this->shipment($delivered, 'COMPLETED');
        $completed = $this->statusOrder('COMPLETED');
        $this->shipment($completed, 'DELIVERED');
        $this->shipment($completed, 'COMPLETED');
        $cancelled = $this->statusOrder('CANCELLED');
        $this->shipment($cancelled, 'CANCELLED');
        $otherSeller = $this->otherSeller();
        $this->statusOrder('PLACED', $otherSeller);

        $response = $this->actingAs($this->seller)->get(route('seller.dashboard'))->assertOk();

        $this->assertSame(9, $response->viewData('dashboardStats')['orders']);
        $this->assertSame([
            'placed' => 1,
            'confirmed' => 1,
            'preparing' => 1,
            'ready-for-pickup' => 1,
            'picked-up' => 1,
            'in-transit' => 1,
            'delivered' => 1,
            'completed' => 1,
            'cancelled' => 1,
            'other' => 0,
            'shipping' => 2,
        ], $response->viewData('orderStatusCounts'));
    }

    public function test_dashboard_recent_rows_and_status_cards_share_one_seller_order_mapping(): void
    {
        $this->statusOrder('PLACED');
        $ready = $this->statusOrder('READY_FOR_PICKUP');
        $this->shipment($ready, 'READY_FOR_PICKUP');
        $this->shipment($ready, 'READY_FOR_PICKUP');
        $pickedUp = $this->statusOrder('PICKED_UP');
        $this->shipment($pickedUp, 'PICKED_UP');
        $this->shipment($pickedUp, 'PICKED_UP');
        $inTransit = $this->statusOrder('PICKED_UP');
        $this->shipment($inTransit, 'AT_SORTING_CENTER');
        $this->shipment($inTransit, 'DELIVERED');
        $delivered = $this->statusOrder('PICKED_UP');
        $this->shipment($delivered, 'DELIVERED');
        $this->shipment($delivered, 'COMPLETED');
        $this->statusOrder('COMPLETED');
        $this->statusOrder('CANCELLED');
        $otherSeller = $this->otherSeller();
        $this->statusOrder('PLACED', $otherSeller);

        $response = $this->actingAs($this->seller)->get(route('seller.dashboard'))->assertOk();
        $recentRows = collect($response->viewData('sellerOrders'));
        $recentIds = $recentRows->pluck('db_id');
        $recentStatusCounts = $recentRows->countBy('status_key');
        $statusCounts = $response->viewData('orderStatusCounts');
        $statusKeys = [
            'placed', 'confirmed', 'preparing', 'ready-for-pickup', 'picked-up',
            'in-transit', 'delivered', 'completed', 'cancelled', 'other',
        ];

        $this->assertSame($recentRows->count(), $recentIds->unique()->count(), 'Recent Orders should contain each SellerOrder once.');
        $this->assertSame(7, $response->viewData('dashboardStats')['orders']);
        $this->assertSame('In Transit', $recentRows->firstWhere('db_id', $inTransit->id)['status']);
        $this->assertSame('Delivered, awaiting completion', $recentRows->firstWhere('db_id', $delivered->id)['status']);
        foreach ($statusKeys as $statusKey) {
            $this->assertSame(
                (int) $recentStatusCounts->get($statusKey, 0),
                $statusCounts[$statusKey],
                "The {$statusKey} card should match the same SellerOrder status mapping shown in Recent Orders.",
            );
        }
        $this->assertSame(1, $statusCounts['ready-for-pickup']);
        $this->assertSame(1, $statusCounts['picked-up']);
        $this->assertSame(1, $statusCounts['in-transit'], 'The multi-parcel order should be counted once at SellerOrder level.');
        $this->assertSame(1, $statusCounts['delivered']);
        $this->assertSame(1, $statusCounts['completed']);
        $this->assertSame(1, $statusCounts['cancelled']);
    }

    public function test_dashboard_inventory_alerts_list_each_product_once_with_variant_counts(): void
    {
        $lowAndOut = $this->product('Dashboard Low and Out Inventory');
        $this->inventoryVariant($lowAndOut, 'DASH-LOW-OUT-1', 0);
        $this->inventoryVariant($lowAndOut, 'DASH-LOW-OUT-2', 4);
        $this->inventoryVariant($lowAndOut, 'DASH-LOW-OUT-3', 8);
        $outOnly = $this->product('Dashboard Out Inventory');
        $this->inventoryVariant($outOnly, 'DASH-OUT-1', 0);
        $this->inventoryVariant($outOnly, 'DASH-OUT-2', 0);

        $response = $this->actingAs($this->seller)->get(route('seller.dashboard'))->assertOk();
        $alerts = collect($response->viewData('inventoryAlerts'));
        $alertIds = $alerts->pluck('product_id');
        $lowAndOutAlert = $alerts->firstWhere('product_id', $lowAndOut->id);
        $outOnlyAlert = $alerts->firstWhere('product_id', $outOnly->id);

        $this->assertSame($alertIds->count(), $alertIds->unique()->count(), 'Inventory alert cards should be unique by product.');
        $this->assertSame(1, $lowAndOutAlert['out_count']);
        $this->assertSame(2, $lowAndOutAlert['low_count']);
        $this->assertSame(2, $outOnlyAlert['out_count']);
        $this->assertSame(0, $outOnlyAlert['low_count']);
    }

    public function test_dashboard_labels_completed_order_value_separately_from_recognized_gross_sales(): void
    {
        $completed = $this->financialRecord(
            $this->sellerProfile,
            'COMPLETED',
            '2026-10-17 12:00:00',
            '160.00',
            '12.32',
        );
        $completed->update(['grand_total' => '200.00']);
        $this->financialRecord(
            $this->sellerProfile,
            'COMPLETED',
            '2026-09-30 12:00:00',
            '45.00',
            '3.47',
        );

        $response = $this->actingAs($this->seller)
            ->get(route('seller.dashboard', ['from' => '2026-10-17', 'to' => '2026-10-17']))
            ->assertOk();

        $this->assertSame(245.0, $response->viewData('dashboardStats')['completed_order_value_all_time']);
        $this->assertSame(160.0, $response->viewData('financialSummary')['gross']);
        $response
            ->assertSee('Includes shipping and Additional Care; this is not your payout.')
            ->assertSee('Recognized merchandise sales from delivered orders. Shipping and Additional Care are excluded.')
            ->assertDontSee('Completed Order Total')
            ->assertSee('Gross sales')
            ->assertSee('Commission')
            ->assertSee('Net sales')
            ->assertSee('Completed orders');
    }

    public function test_dashboard_chart_orders_daily_gross_and_zero_fills_the_other_six_days(): void
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $salesDay = $today->copy()->subDays(3);
        $calculatedAt = $salesDay->copy()->setTime(9, 30)->toDateTimeString();
        $this->financialRecord($this->sellerProfile, 'COMPLETED', $calculatedAt, '120.00', '12.00');
        $this->financialRecord($this->sellerProfile, 'COMPLETED', $calculatedAt, '80.00', '8.00');

        $otherSeller = $this->otherSeller();
        $this->financialRecord($otherSeller, 'COMPLETED', $calculatedAt, '900.00', '90.00');
        $this->financialRecord(
            $this->sellerProfile,
            'CANCELLED',
            $today->copy()->subDays(2)->setTime(11, 0)->toDateTimeString(),
            '700.00',
            '70.00',
        );

        $response = $this->actingAs($this->seller)->get(route('seller.dashboard'))->assertOk();
        $chart = $response->viewData('salesChart');

        $this->assertSame(
            [0.0, 0.0, 0.0, 200.0, 0.0, 0.0, 0.0],
            $chart->pluck('value')->all(),
        );
        $this->assertSame($today->copy()->subDays(6)->format('D'), $chart->first()['label']);
        $this->assertSame($today->format('D'), $chart->last()['label']);
        $this->assertSame(200.0, $response->viewData('dashboardStats')['weekly_revenue']);
        $response->assertSee('₱200.00');
    }

    private function financialRecord(
        SellerProfile $seller,
        string $status,
        string $calculatedAt,
        string $gross,
        string $commission,
        array $items = [],
        ?string $orderCreatedAt = null,
        string $commissionStatus = 'PENDING',
        ?array $shipmentStatuses = null,
    ): SellerOrder {
        $order = Order::query()->create([
            'order_number' => 'FIN-'.Str::upper(Str::random(12)),
            'buyer_user_id' => $this->buyer->id,
            'status' => $status === 'CANCELLED' ? 'CANCELLED' : 'PROCESSING',
            'grand_total' => $gross,
            'placed_at' => $orderCreatedAt ?: $calculatedAt,
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'FIN-SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => $status,
            'grand_total' => $gross,
        ]);

        foreach ($items as [$product, $amount]) {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => 'FIN-'.Str::upper(Str::random(8)),
                'price' => $amount,
                'stock' => 10,
                'is_default' => true,
                'is_active' => true,
            ]);
            OrderItem::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name' => $product->name,
                'sku' => $variant->sku,
                'unit_price' => $amount,
                'original_unit_price' => $amount,
                'quantity' => 1,
                'discount_amount' => 0,
                'line_total' => $amount,
            ]);
        }

        CommissionTransaction::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'commission_rate' => 0.077,
            'commissionable_amount' => $gross,
            'commission_amount' => $commission,
            'status' => $commissionStatus,
            'calculated_at' => Carbon::parse($calculatedAt, 'Asia/Manila'),
        ]);

        $shipmentStatuses ??= in_array($status, ['PICKED_UP', 'COMPLETED'], true) && $commissionStatus !== 'VOID'
            ? ['DELIVERED']
            : [];
        foreach ($shipmentStatuses as $shipmentStatus) {
            $this->shipment($sellerOrder, $shipmentStatus);
        }

        return $sellerOrder;
    }

    private function product(string $name): Product
    {
        return Product::query()->create([
            'seller_profile_id' => $this->sellerProfile->id,
            'category_id' => $this->sellerProfile->primary_category_id ?: Category::query()->firstOrFail()->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => 'ACTIVE',
        ]);
    }

    private function otherSeller(): SellerProfile
    {
        $user = User::factory()->create(['account_type' => User::TYPE_SELLER, 'status' => User::STATUS_ACTIVE]);

        return SellerProfile::query()->create([
            'user_id' => $user->id,
            'primary_category_id' => $this->sellerProfile->primary_category_id,
            'business_name' => 'Other financial seller '.Str::upper(Str::random(6)),
            'status' => 'ACTIVE',
        ]);
    }

    private function statusOrder(string $status, ?SellerProfile $seller = null): SellerOrder
    {
        $seller ??= $this->sellerProfile;
        $order = Order::query()->create([
            'order_number' => 'STATE-'.Str::upper(Str::random(12)),
            'buyer_user_id' => $this->buyer->id,
            'status' => $status === 'CANCELLED' ? 'CANCELLED' : 'PROCESSING',
        ]);

        return SellerOrder::query()->create([
            'seller_order_number' => 'STATE-SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => $status,
        ]);
    }

    private function shipment(SellerOrder $sellerOrder, string $status): Shipment
    {
        return Shipment::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'STATE-TRK-'.Str::upper(Str::random(12)),
            'destination_province_code' => 'TEST-P',
            'destination_province_name' => 'Test Province',
            'destination_municipality_code' => 'TEST-M',
            'destination_municipality_name' => 'Test Municipality',
            'destination_barangay_code' => 'TEST-B',
            'destination_barangay_name' => 'Test Barangay',
            'current_status' => $status,
        ]);
    }

    private function inventoryVariant(Product $product, string $sku, int $stock): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'price' => 100.00,
            'stock' => $stock,
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
