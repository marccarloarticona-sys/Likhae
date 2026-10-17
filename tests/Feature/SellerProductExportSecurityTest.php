<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerProductExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_is_seller_scoped_and_served_as_pdf(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $otherSeller = User::query()->where('email', 'backstreetclub@likhae.online')->sole();
        $category = Category::query()->create([
            'name' => "\t=1+1",
            'slug' => 'pdf-export-category',
            'is_active' => true,
        ]);

        $ownProduct = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'Own Seller Product',
            'slug' => 'pdf-export-product',
            'status' => 'DRAFT',
        ]);
        $ownProduct->variants()->create([
            'sku' => 'PDF-OWN',
            'price' => 25,
            'stock' => 3,
            'is_default' => true,
            'is_active' => true,
        ]);

        $foreignProduct = Product::query()->create([
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'category_id' => $otherSeller->sellerProfile->primary_category_id,
            'name' => 'Foreign Seller Product',
            'slug' => 'pdf-foreign-product',
            'status' => 'ACTIVE',
        ]);
        $foreignProduct->variants()->create([
            'sku' => 'PDF-FOREIGN',
            'price' => 99,
            'stock' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($seller)->get(route('seller.products.export'));
        $response->assertOk()->assertHeader('content-type', 'application/pdf')->assertDownload();
        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Own Seller Product', $pdf);
        $this->assertStringNotContainsString('Foreign Seller Product', $pdf);
    }
}
