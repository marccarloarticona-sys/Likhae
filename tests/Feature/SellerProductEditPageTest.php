<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\Seller\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerProductEditPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_open_the_product_edit_page(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $product = Product::query()->whereBelongsTo($seller->sellerProfile)->sole();

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'edit', 'product' => $product->id]))
            ->assertOk()
            ->assertSee(route('seller.products.update', $product), escape: false)
            ->assertSee('data-listing-status-field', escape: false)
            ->assertSee("this.form.elements.listing_status.value='active'", escape: false);
    }

    public function test_seller_cannot_edit_a_missing_product(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'edit', 'product' => 999999]))
            ->assertNotFound();
    }

    public function test_seller_can_save_a_single_product_as_a_draft(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Single Database Product',
            'category_id' => $category->id,
            'description' => 'Stored using the existing catalog schema.',
            'price' => 599,
            'stock' => 30,
            'listing_status' => 'draft',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $product = Product::query()->where('name', 'Single Database Product')->sole();
        $this->assertSame('DRAFT', $product->status);
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_seller_can_publish_a_product_with_variations(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Variant Database Product',
            'category_id' => $category->id,
            'listing_status' => 'active',
            'options' => [['name' => 'Size', 'values' => 'Small, Large']],
            'variants' => [
                ['values' => 'Small', 'price' => 399, 'stock' => 15, 'is_active' => 1],
                ['values' => 'Large', 'price' => 599, 'stock' => 10, 'is_active' => 1],
            ],
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'ACTIVE');

        $product = Product::query()->where('name', 'Variant Database Product')->sole();
        $this->assertSame('ACTIVE', $product->status);
        $this->assertSame(2, $product->variants()->where('is_active', true)->count());
        $this->assertSame(2, $product->options()->firstOrFail()->values()->count());
    }

    public function test_seller_can_soft_delete_and_restore_a_product_within_the_recovery_window(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $product = Product::query()->whereBelongsTo($seller->sellerProfile)->sole();

        $this->actingAs($seller)
            ->delete(route('seller.products.destroy', $product))
            ->assertRedirect(route('seller.products'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->actingAs($seller)->get(route('seller.products.trash'))->assertOk()->assertSee($product->name);
        $this->post(route('seller.products.restore', $product->id))->assertRedirect();
        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }
}
