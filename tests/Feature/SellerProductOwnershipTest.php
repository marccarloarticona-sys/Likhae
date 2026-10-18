<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerProductOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_cannot_edit_delete_or_delete_another_sellers_product_image(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $otherSeller = User::query()->where('email', 'backstreetclub@likhae.online')->sole();
        $category = $seller->sellerProfile->primaryCategory;
        $foreignProduct = Product::query()->create([
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'category_id' => $otherSeller->sellerProfile->primary_category_id,
            'name' => 'Foreign Seller Product',
            'slug' => 'ownership-foreign-product',
            'status' => 'ACTIVE',
        ]);
        $image = $foreignProduct->images()->create([
            'file_path' => 'foreign/product.webp',
            'is_primary' => true,
        ]);

        $this->actingAs($seller)->put(route('seller.products.update', $foreignProduct), [
            'product_type' => 'single',
            'name' => 'Tampered Foreign Product',
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 1,
            'listing_status' => 'draft',
        ])->assertForbidden();

        $this->actingAs($seller)->delete(route('seller.products.destroy', $foreignProduct))->assertForbidden();
        $this->actingAs($seller)
            ->delete(route('seller.products.images.destroy', ['product' => $foreignProduct, 'image' => $image]))
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $foreignProduct->id, 'name' => 'Foreign Seller Product', 'deleted_at' => null]);
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'product_id' => $foreignProduct->id]);
    }

    public function test_seller_cannot_restore_another_sellers_deleted_product(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $otherSeller = User::query()->where('email', 'backstreetclub@likhae.online')->sole();
        $foreignProduct = Product::query()->create([
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'category_id' => $otherSeller->sellerProfile->primary_category_id,
            'name' => 'Foreign Deleted Product',
            'slug' => 'ownership-foreign-deleted-product',
            'status' => 'DRAFT',
        ]);
        $foreignProduct->delete();

        $this->actingAs($seller)->post(route('seller.products.restore', $foreignProduct->id))->assertNotFound();
        $this->assertSoftDeleted('products', ['id' => $foreignProduct->id]);
    }
}
