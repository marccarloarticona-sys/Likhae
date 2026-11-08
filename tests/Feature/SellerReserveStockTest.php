<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerReserveStockTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $this->category = Category::query()->findOrFail($this->seller->sellerProfile->primary_category_id);
    }

    public function test_single_product_reserve_stock_is_saved_prefilled_and_editable(): void
    {
        $this->actingAs($this->seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Single Reserve Stock Product',
            'category_id' => $this->category->id,
            'price' => 250,
            'stock' => 50,
            'reserve_stock' => 5,
            'listing_status' => 'draft',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $product = Product::query()->where('name', 'Single Reserve Stock Product')->sole();
        $variant = $product->variants()->sole();
        $this->assertSame(5, $variant->reserve_stock);

        $this->get(route('seller.products', ['mode' => 'edit', 'product' => $product->id]))
            ->assertOk()
            ->assertSee('name="reserve_stock" type="number" min="0" max="999999" value="5"', false)
            ->assertSee('Checkout fallback only. Buyers see regular stock.');

        $this->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 250,
            'stock' => 50,
            'reserve_stock' => 8,
            'single_variant_id' => $variant->id,
            'listing_status' => 'draft',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $this->assertSame(8, $variant->fresh()->reserve_stock);
    }

    public function test_each_variation_reserve_stock_is_saved_and_prefilled_in_the_editor(): void
    {
        $this->actingAs($this->seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Variation Reserve Stock Product',
            'category_id' => $this->category->id,
            'listing_status' => 'draft',
            'options' => [['name' => 'Color', 'values' => 'Red, Blue']],
            'variants' => [
                ['sku' => 'RS-RED', 'values' => 'Red', 'price' => 300, 'stock' => 20, 'reserve_stock' => 4],
                ['sku' => 'RS-BLUE', 'values' => 'Blue', 'price' => 320, 'stock' => 15, 'reserve_stock' => 2],
            ],
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $product = Product::query()->where('name', 'Variation Reserve Stock Product')->sole();
        $variants = $product->variants()->with('optionValues')->get()
            ->keyBy(fn (ProductVariant $variant): string => (string) $variant->optionValues->sole()->value);
        $this->assertSame(4, $variants['Red']->reserve_stock);
        $this->assertSame(2, $variants['Blue']->reserve_stock);

        $this->get(route('seller.products', ['mode' => 'edit', 'product' => $product->id]))
            ->assertOk()
            ->assertSee('name="variants[0][reserve_stock]" type="number" min="0" max="999999" value="4"', false)
            ->assertSee('name="variants[1][reserve_stock]" type="number" min="0" max="999999" value="2"', false)
            ->assertSee('private checkout fallback');

        $this->put(route('seller.products.update', $product), [
            'product_type' => 'variations',
            'name' => $product->name,
            'category_id' => $product->category_id,
            'listing_status' => 'draft',
            'options' => [['name' => 'Color', 'values' => 'Red, Blue']],
            'variants' => [
                ['id' => $variants['Red']->id, 'sku' => 'RS-RED', 'values' => 'Red', 'price' => 300, 'stock' => 20, 'reserve_stock' => 7],
                ['id' => $variants['Blue']->id, 'sku' => 'RS-BLUE', 'values' => 'Blue', 'price' => 320, 'stock' => 15, 'reserve_stock' => 6],
            ],
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $this->assertSame(7, $variants['Red']->fresh()->reserve_stock);
        $this->assertSame(6, $variants['Blue']->fresh()->reserve_stock);
    }
}
