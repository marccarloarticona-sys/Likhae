<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerReportsPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_view_business_intelligence_and_download_a_pdf_brief(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        $this->actingAs($seller)
            ->get(route('seller.reports', ['report' => 'products']))
            ->assertOk()
            ->assertSee('Product Performance')
            ->assertSee('Download PDF brief')
            ->assertSee('Recognized sales by product');

        $response = $this->actingAs($seller)
            ->get(route('seller.reports.download', ['report' => 'products']));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
    }

    public function test_analytics_tab_can_preview_and_download_the_matching_pdf(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        $this->actingAs($seller)
            ->get(route('seller.reports', ['tab' => 'analytics']))
            ->assertOk()
            ->assertSee('Store Analytics')
            ->assertSee('Preview Analytics PDF')
            ->assertSee('Download Analytics PDF')
            ->assertSee('Recognized sales over time')
            ->assertSee('Seller settlement');

        $preview = $this->get(route('seller.reports.preview', ['tab' => 'analytics', 'report' => 'sales']));
        $preview->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline;', (string) $preview->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-1.4', $preview->getContent());
        $this->assertStringContainsString('Store Analytics Overview', $preview->getContent());

        $download = $this->get(route('seller.reports.download', ['tab' => 'analytics', 'report' => 'sales']));
        $download->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-1.4', $download->getContent());
        $this->assertStringContainsString('Store Analytics Overview', $download->getContent());
    }
}
