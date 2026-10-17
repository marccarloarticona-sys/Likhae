<?php

namespace Tests\Unit;

use App\Services\Reports\SellerReportPdfService;
use Tests\TestCase;

class SellerReportPdfServiceTest extends TestCase
{
    public function test_it_creates_a_valid_pdf_business_intelligence_brief(): void
    {
        $pdf = app(SellerReportPdfService::class)->render([
            'title' => 'Product Performance Report',
            'seller_name' => 'Ceramics Studio',
            'scope' => 'All catalog products',
            'range_label' => 'Oct 01, 2026 - Oct 06, 2026',
            'generated_at' => 'Oct 06, 2026 8:30 PM',
            'summary' => [
                'gross' => 174965,
                'commission' => 12497.50,
                'net' => 162467.50,
                'orders' => 2,
                'financial_orders' => 2,
                'completion_rate' => 50,
                'finance_schema_ready' => true,
                'pending_balance' => 50,
                'authorized_balance' => 20,
                'paid_balance' => 100,
                'outstanding_balance' => 70,
                'seller_payable' => 162467.50,
                'discounts' => 0,
                'seller_refund_adjustment' => 0,
                'commission_refund_reversal' => 0,
            ],
            'performance_counts' => ['high' => 1, 'mid' => 1, 'low' => 1, 'no_sales' => 1],
            'insights' => ['Woven Bag is the strongest performer with PHP 12,000.00 from 20 completed units.'],
            'products' => [
                ['name' => 'Woven Bag', 'tier' => 'high', 'units' => 20, 'orders' => 2, 'gross' => 12000, 'commission' => 1200, 'net' => 10800],
                ['name' => 'Ceramic Cup', 'tier' => 'no_sales', 'units' => 0, 'orders' => 0, 'gross' => 0, 'commission' => 0, 'net' => 0],
            ],
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/Type /Page', $pdf);
        $this->assertStringContainsString('Product Performance Report', $pdf);
        $this->assertStringContainsString('Seller: Ceramics Studio', $pdf);
        $this->assertStringContainsString('Commission', $pdf);
        $this->assertStringContainsString('PHP 12,497.50', $pdf);
        $this->assertStringContainsString('Pending / review', $pdf);
        $this->assertStringContainsString('COMMISSION', $pdf);
        $this->assertStringContainsString('Woven Bag', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }
}
