<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerProductListSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_management_search_data_includes_inactive_variant_skus(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $product = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $seller->sellerProfile->primary_category_id,
            'name' => 'Archived Size Product',
            'slug' => 'archived-size-product',
            'status' => 'DRAFT',
        ]);
        $product->variants()->create([
            'sku' => 'SEARCH-INACTIVE-SKU',
            'price' => 100,
            'stock' => 0,
            'is_default' => true,
            'is_active' => false,
        ]);

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'list']))
            ->assertOk()
            ->assertSee('data-product-name="archived size product search-inactive-sku"', false);
    }

    public function test_product_list_is_paginated_and_search_filters_all_seller_products(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        for ($index = 1; $index <= 21; $index++) {
            $product = Product::query()->create([
                'seller_profile_id' => $seller->sellerProfile->id,
                'category_id' => $seller->sellerProfile->primary_category_id,
                'name' => 'Audit Product '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'slug' => 'audit-product-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'status' => 'DRAFT',
            ]);
            $product->variants()->create([
                'sku' => 'AUDIT-SKU-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'price' => 100,
                'stock' => 20,
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'list']))
            ->assertOk()
            ->assertSee('Audit Product 21')
            ->assertSee(route('seller.products', ['mode' => 'list', 'page' => 2]))
            ->assertSee('All Products');

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'list', 'page' => 2]))
            ->assertOk()
            ->assertSee('Audit Product 01');

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'list', 'search' => 'Audit Product 01', 'status' => 'draft']))
            ->assertOk()
            ->assertSee('Audit Product 01')
            ->assertDontSee('Audit Product 02')
            ->assertSee('name="search" value="Audit Product 01"', false);
    }
}
