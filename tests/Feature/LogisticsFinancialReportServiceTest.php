<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Services\Reports\LogisticsFinancialReportService;
use App\Services\Reports\WorkspaceExportPdfService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LogisticsFinancialReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Manila',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->createReportSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_financial_report_filters_by_center_date_status_rider_and_area_and_summarizes_distinct_stages(): void
    {
        $this->insertLedger(1, 1000, 'LOGISTICS_ALLOCATION', 'CREDIT', '20.00', 'PENDING', '2026-10-22 09:00:00');
        $this->insertLedger(2, 1000, 'PRODUCT_PROCEEDS_REMITTANCE', 'DEBIT', '120.00', 'DISCREPANCY', '2026-10-22 10:00:00', null, [
            'amount_expected' => '120.00',
            'amount_submitted' => '100.00',
            'amount_received' => '100.00',
            'discrepancy_amount' => '20.00',
            'receipt_reference' => 'RCPT-1000',
        ]);
        $this->insertLedger(3, 1000, 'LOGISTICS_PAYOUT', 'CREDIT', '20.00', 'PAID', '2026-10-22 11:00:00', '2026-10-23 08:00:00', [
            'payout_reference' => 'LGP-1000',
        ]);
        $this->insertLedger(4, 2000, 'LOGISTICS_ALLOCATION', 'CREDIT', '999.00', 'PENDING', '2026-10-22 09:00:00');
        DB::table('financial_ledger_entries')->insert([
            'id' => 5,
            'shipment_financial_allocation_id' => 1000,
            'shipment_id' => 2000,
            'account_type' => 'LOGISTICS',
            'entry_type' => 'LOGISTICS_ALLOCATION',
            'direction' => 'CREDIT',
            'amount' => '500.00',
            'status' => 'PENDING',
            'idempotency_key' => 'report-test-5',
            'posted_at' => '2026-10-22 09:00:00',
            'created_at' => '2026-10-22 09:00:00',
            'updated_at' => '2026-10-22 09:00:00',
        ]);

        $service = app(LogisticsFinancialReportService::class);
        $center = new LogisticsCenter;
        $center->id = 1;
        $filters = [
            'date_from' => '2026-10-22',
            'date_to' => '2026-10-23',
            'status' => 'DISCREPANCY',
            'rider_id' => 10,
            'area_id' => 100,
        ];

        $filtered = $service->query($center, $filters)->get();
        $this->assertCount(1, $filtered);
        $this->assertSame(2, $filtered->first()->id);

        $summary = $service->summary($center, array_diff_key($filters, ['status' => true]));
        $this->assertSame(1, $summary['parcel_count']);
        $this->assertSame(3, $summary['transaction_count']);
        $this->assertSame(50.0, $summary['shipping_fees']);
        $this->assertSame(20.0, $summary['logistics_share']);
        $this->assertSame(120.0, $summary['proceeds_expected']);
        $this->assertSame(100.0, $summary['proceeds_received']);
        $this->assertSame(1, $summary['proceeds_discrepancies']);
        $this->assertSame(20.0, $summary['payout_paid']);
        $this->assertSame(10.0, $summary['admin_product_commission']);
        $this->assertArrayNotHasKey('logistics_commission', $summary, 'No unsupported Logistics commission calculation may be introduced.');

        $proceeds = $filtered->first();
        $row = $service->row($proceeds);
        $this->assertSame('LK-1000', $row['tracking']);
        $this->assertSame('DISCREPANCY', $row['status']);
        $this->assertSame('100.00', $row['received']);
        $this->assertSame('20.00', $row['discrepancy']);
        $this->assertSame('RCPT-1000', $row['reference']);
        $this->assertSame('', $row['shipping_fee']);
        $this->assertSame('', $row['logistics_share']);
        $this->assertSame('', $row['admin_product_commission']);
        $this->assertCount(18, $service->csvHeader());
        $this->assertSame(18, count($service->csvRow($proceeds)));
        $this->assertSame(array_values($row), $service->csvRow($proceeds), 'CSV detail rows must match the screen row values.');
        $csvStream = fopen('php://temp', 'w+');
        $service->writeCsvToStream($csvStream, $center, $filters);
        rewind($csvStream);
        $csvRows = [];
        while (($csvRow = fgetcsv($csvStream)) !== false) {
            $csvRows[] = $csvRow;
        }
        fclose($csvStream);
        $this->assertContains(['Financial summary'], $csvRows);
        $this->assertContains(['Product proceeds received PHP', '100.00'], $csvRows);
        $this->assertContains(['Admin product commission PHP', '0.00'], $csvRows, 'The status filter must also scope CSV totals.');
        $csvDetails = array_values(array_filter($csvRows, fn (array $csvRow): bool => ($csvRow[0] ?? '') === $row['date']));
        $this->assertSame([$service->csvRow($proceeds)], $csvDetails);
        $this->assertNotContains('LK-2000', array_merge(...$csvRows), 'Center scoping must also apply to CSV rows.');

        $allocation = $service->query($center, array_diff_key($filters, ['status' => true]))
            ->where('entry_type', 'LOGISTICS_ALLOCATION')
            ->firstOrFail();
        $pdfRow = $service->pdfRow($allocation);
        $this->assertSame('LK-1000 / ORD-600', $pdfRow[1]);
        $this->assertSame('PHP 50.00', $pdfRow[5]);
        $this->assertSame('PHP 20.00', $pdfRow[6]);
        $this->assertSame('PHP 10.00', $pdfRow[7]);

        $pdf = app(WorkspaceExportPdfService::class)->render([
            'workspace' => 'Logistics Portal',
            'title' => 'Parcel transactions and shipping share',
            'description' => 'Admin product commission is distinct from the Logistics shipping share.',
            'generated_at' => '2026-10-23 12:00:00 +08:00',
            'orientation' => 'landscape',
            'summary' => [
                'Parcels with allocation' => '1',
                'Recorded shipping fees' => 'PHP 50.00',
                'Logistics shipping share' => 'PHP 20.00',
                'Admin product commission' => 'PHP 10.00',
            ],
            'columns' => [
                ['label' => 'Date', 'width' => 0.15],
                ['label' => 'Tracking / order', 'width' => 0.18],
                ['label' => 'Transaction', 'width' => 0.18],
                ['label' => 'Amount', 'width' => 0.12],
                ['label' => 'Status', 'width' => 0.12],
                ['label' => 'Shipping fee', 'width' => 0.1],
                ['label' => 'Logistics share', 'width' => 0.1],
                ['label' => 'Admin commission', 'width' => 0.12],
            ],
            'rows' => [$pdfRow],
        ]);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('LK-1000 / ORD-600', $pdf);
        $this->assertStringContainsString('PHP 20.00', $pdf);
        $this->assertStringContainsString('Admin product commission: PHP 10.00', $pdf);
    }

    public function test_date_range_is_inclusive_in_manila_timezone_and_invalid_ranges_are_rejected(): void
    {
        $this->insertLedger(6, 1000, 'PRODUCT_PROCEEDS_REMITTANCE', 'DEBIT', '5.00', 'RECEIVED', '2026-10-21 23:59:59');
        $this->insertLedger(7, 1000, 'PRODUCT_PROCEEDS_REMITTANCE', 'DEBIT', '6.00', 'RECEIVED', '2026-10-23 23:59:59');
        $this->insertLedger(8, 1000, 'PRODUCT_PROCEEDS_REMITTANCE', 'DEBIT', '7.00', 'RECEIVED', '2026-10-22 00:00:00');
        $center = new LogisticsCenter;
        $center->id = 1;

        $entries = app(LogisticsFinancialReportService::class)->query($center, [
            'date_from' => '2026-10-22',
            'date_to' => '2026-10-23',
        ])->get();
        $this->assertSame([7, 8], $entries->pluck('id')->all(), 'Both Manila date boundaries are inclusive, while the prior day is excluded.');

        $invalidRange = Validator::make([
            'date_from' => '2026-10-23',
            'date_to' => '2026-10-22',
        ], [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $this->assertTrue($invalidRange->fails(), 'The report filter contract rejects an end date earlier than the start date.');
    }

    public function test_transaction_export_routes_are_registered_inside_logistics_authentication(): void
    {
        foreach (['logistics.reports.transactions.csv', 'logistics.reports.transactions.pdf'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' should be registered');
            $this->assertContains('GET', $route->methods());
            $this->assertContains('auth', $route->middleware());
            $middleware = implode('|', $route->gatherMiddleware());
            $this->assertStringContainsString('EnsureWorkspaceRole:logistics', $middleware);
            $this->assertStringContainsString('provider.approved', $middleware);
        }
    }

    public function test_logistics_report_template_compiles_with_financial_filters_and_exports(): void
    {
        $source = file_get_contents(resource_path('views/logistics/reports/index.blade.php'));
        $this->assertNotFalse($source);

        $compiled = app('blade.compiler')->compileString($source);

        $this->assertStringContainsString("route('logistics.reports.transactions.csv'", $compiled);
        $this->assertStringContainsString("route('logistics.reports.transactions.pdf'", $compiled);
        $this->assertStringContainsString('$financialTransactions', $compiled);
    }

    private function createReportSchema(): void
    {
        $schema = Schema::connection('sqlite');
        $schema->create('logistics_centers', fn (Blueprint $table) => $table->id());
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('middle_initial')->nullable();
            $table->string('last_name')->nullable();
            $table->string('name_extension')->nullable();
        });
        $schema->create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number');
        });
        $schema->create('seller_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
        });
        $schema->create('service_areas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_center_id');
            $table->string('name');
        });
        $schema->create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_order_id')->nullable();
            $table->unsignedBigInteger('service_area_id')->nullable();
            $table->string('tracking_number');
            $table->string('destination_barangay_name')->nullable();
            $table->string('destination_municipality_name')->nullable();
            $table->string('destination_province_name')->nullable();
        });
        $schema->create('rider_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('logistics_center_id');
        });
        $schema->create('rider_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('rider_profile_id');
            $table->string('assignment_type');
            $table->string('status')->nullable();
        });
        $schema->create('shipment_financial_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('seller_order_id');
            $table->unsignedBigInteger('order_id');
            $table->decimal('shipping_fee', 12, 2);
            $table->decimal('logistics_allocation', 12, 2);
            $table->decimal('platform_commission', 12, 2);
            $table->decimal('rider_allocation', 12, 2);
        });
        $schema->create('logistics_earning_pools', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_financial_allocation_id');
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('logistics_center_id');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('unresolved_share_amount', 12, 2)->default(0);
            $table->string('status')->default('PENDING');
        });
        $schema->create('financial_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shipment_financial_allocation_id')->nullable();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->string('account_type');
            $table->string('entry_type');
            $table->string('direction');
            $table->decimal('amount', 12, 2);
            $table->string('status');
            $table->string('idempotency_key')->unique();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::table('logistics_centers')->insert([['id' => 1], ['id' => 2]]);
        DB::table('users')->insert([
            ['id' => 10, 'first_name' => 'Rider', 'last_name' => 'One'],
            ['id' => 20, 'first_name' => 'Rider', 'last_name' => 'Two'],
        ]);
        DB::table('rider_profiles')->insert([
            ['id' => 10, 'user_id' => 10, 'logistics_center_id' => 1],
            ['id' => 20, 'user_id' => 20, 'logistics_center_id' => 2],
        ]);
        DB::table('service_areas')->insert([
            ['id' => 100, 'logistics_center_id' => 1, 'name' => 'North'],
            ['id' => 200, 'logistics_center_id' => 2, 'name' => 'South'],
        ]);
        DB::table('orders')->insert([
            ['id' => 600, 'order_number' => 'ORD-600'],
            ['id' => 800, 'order_number' => 'ORD-800'],
        ]);
        DB::table('seller_orders')->insert([
            ['id' => 500, 'order_id' => 600],
            ['id' => 700, 'order_id' => 800],
        ]);
        DB::table('shipments')->insert([
            ['id' => 1000, 'seller_order_id' => 500, 'service_area_id' => 100, 'tracking_number' => 'LK-1000', 'destination_barangay_name' => 'Barangay 1', 'destination_municipality_name' => 'Town A', 'destination_province_name' => 'Province A'],
            ['id' => 2000, 'seller_order_id' => 700, 'service_area_id' => 200, 'tracking_number' => 'LK-2000', 'destination_barangay_name' => 'Barangay 2', 'destination_municipality_name' => 'Town B', 'destination_province_name' => 'Province B'],
        ]);
        DB::table('rider_assignments')->insert([
            ['shipment_id' => 1000, 'rider_profile_id' => 10, 'assignment_type' => 'DELIVERY', 'status' => 'COMPLETED'],
            ['shipment_id' => 2000, 'rider_profile_id' => 20, 'assignment_type' => 'DELIVERY', 'status' => 'COMPLETED'],
        ]);
        DB::table('shipment_financial_allocations')->insert([
            ['id' => 1000, 'shipment_id' => 1000, 'seller_order_id' => 500, 'order_id' => 600, 'shipping_fee' => 50, 'logistics_allocation' => 20, 'platform_commission' => 10, 'rider_allocation' => 20],
            ['id' => 2000, 'shipment_id' => 2000, 'seller_order_id' => 700, 'order_id' => 800, 'shipping_fee' => 70, 'logistics_allocation' => 999, 'platform_commission' => 15, 'rider_allocation' => 30],
        ]);
        DB::table('logistics_earning_pools')->insert([
            ['shipment_financial_allocation_id' => 1000, 'shipment_id' => 1000, 'logistics_center_id' => 1, 'total_amount' => 20],
            ['shipment_financial_allocation_id' => 2000, 'shipment_id' => 2000, 'logistics_center_id' => 2, 'total_amount' => 999],
        ]);
    }

    /** @param array<string, mixed>|null $metadata */
    private function insertLedger(int $id, int $shipmentId, string $type, string $direction, string $amount, string $status, string $postedAt, ?string $paidAt = null, ?array $metadata = null): void
    {
        DB::table('financial_ledger_entries')->insert([
            'id' => $id,
            'shipment_financial_allocation_id' => $shipmentId,
            'shipment_id' => $shipmentId,
            'account_type' => 'LOGISTICS',
            'entry_type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'status' => $status,
            'idempotency_key' => 'report-test-'.$id,
            'posted_at' => $postedAt,
            'paid_at' => $paidAt,
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'created_at' => $postedAt,
            'updated_at' => $postedAt,
        ]);
    }
}
