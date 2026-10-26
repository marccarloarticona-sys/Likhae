<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerFlashSaleValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_save_flash_price_and_allocation_for_a_single_variant(): void
    {
        $seller = $this->seller();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Allocated Single Flash Sale',
            'category_id' => $category->id,
            'description' => 'A draft with a dedicated sale price.',
            'price' => 100,
            'stock' => 10,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'flash_sale_price' => 75,
            'flash_sale_allocation' => 4,
            'flash_sale_starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'flash_sale_ends_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
            'listing_status' => 'draft',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $variant = ProductVariant::query()->whereHas('product', fn ($query) => $query->where('name', 'Allocated Single Flash Sale'))->sole();
        $this->assertSame(75.0, (float) $variant->flash_sale_price);
        $this->assertSame(4, (int) $variant->flash_sale_allocation);
        $this->assertSame(0, (int) $variant->flash_sale_sold);
    }

    public function test_seller_flash_sale_validation_rejects_allocation_over_stock_and_price_not_below_regular(): void
    {
        $seller = $this->seller();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $base = [
            'product_type' => 'single',
            'name' => 'Invalid Flash Sale',
            'category_id' => $category->id,
            'description' => 'A draft used to verify server validation.',
            'price' => 100,
            'stock' => 3,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'flash_sale_price' => 80,
            'flash_sale_allocation' => 4,
            'listing_status' => 'draft',
        ];

        $this->actingAs($seller)->from(route('seller.products'))
            ->post(route('seller.products.store'), $base)
            ->assertRedirect(route('seller.products'))
            ->assertSessionHasErrors('flash_sale_allocation');

        $this->from(route('seller.products'))
            ->post(route('seller.products.store'), array_replace($base, [
                'name' => 'Flash Price Must Beat Regular',
                'flash_sale_price' => 90,
                'flash_sale_allocation' => 2,
            ]))
            ->assertRedirect(route('seller.products'))
            ->assertSessionHasErrors('flash_sale_price');

        $this->assertDatabaseMissing('products', ['name' => 'Invalid Flash Sale']);
        $this->assertDatabaseMissing('products', ['name' => 'Flash Price Must Beat Regular']);
    }

    public function test_variation_flash_sale_allocation_is_saved_per_variant(): void
    {
        $seller = $this->seller();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Variant Flash Sale',
            'category_id' => $category->id,
            'description' => 'Each variation has its own quota.',
            'flash_sale_starts_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'flash_sale_ends_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
            'listing_status' => 'draft',
            'options' => [['name' => 'Size', 'values' => 'Small, Large']],
            'variants' => [
                ['values' => 'Small', 'price' => 100, 'stock' => 8, 'flash_sale_price' => 70, 'flash_sale_allocation' => 2],
                ['values' => 'Large', 'price' => 150, 'stock' => 10, 'flash_sale_price' => 120, 'flash_sale_allocation' => 5],
            ],
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $variants = ProductVariant::query()
            ->whereHas('product', fn ($query) => $query->where('name', 'Variant Flash Sale'))
            ->orderBy('price')
            ->get();
        $this->assertSame([2, 5], $variants->pluck('flash_sale_allocation')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([70.0, 120.0], $variants->pluck('flash_sale_price')->map(fn ($value) => (float) $value)->all());
    }

    public function test_marketing_center_saves_a_scheduled_flash_sale_for_only_the_selected_variant(): void
    {
        $seller = $this->seller();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $product = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'Central Flash Sale Product',
            'slug' => 'central-flash-sale-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $selected = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'CENTRAL-FLASH-ONE',
            'price' => 100,
            'stock' => 8,
            'is_default' => true,
            'is_active' => true,
        ]);
        $untouched = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'CENTRAL-FLASH-TWO',
            'price' => 150,
            'stock' => 10,
            'is_default' => false,
            'is_active' => true,
        ]);
        $start = now()->addHour()->format('Y-m-d H:i:s');
        $end = now()->addHours(3)->format('Y-m-d H:i:s');

        $this->actingAs($seller)
            ->from(route('seller.marketing', ['section' => 'flash-sales']))
            ->patch(route('seller.marketing.flash-sales.update', ['product' => $product, 'section' => 'flash-sales']), [
                'flash_sale_starts_at' => $start,
                'flash_sale_ends_at' => $end,
                'variants' => [
                    ['id' => $selected->id, 'flash_sale_price' => 80, 'flash_sale_allocation' => 5],
                    ['id' => $untouched->id, 'flash_sale_price' => null, 'flash_sale_allocation' => null],
                ],
            ])
            ->assertRedirect(route('seller.marketing', ['section' => 'flash-sales']))
            ->assertSessionHas('status', 'Flash sale prices, allocations, and schedule were saved. Existing order prices remain unchanged.');

        $this->assertSame('80.00', $selected->fresh()->flash_sale_price);
        $this->assertSame(5, (int) $selected->fresh()->flash_sale_allocation);
        $this->assertNull($untouched->fresh()->flash_sale_price);
        $this->assertNull($untouched->fresh()->flash_sale_allocation);
        $this->assertNotNull($product->fresh()->flash_sale_starts_at);

        $this->actingAs($seller)
            ->get(route('seller.marketing', ['section' => 'flash-sales']))
            ->assertOk()
            ->assertSee('Marketing Center')
            ->assertSee('Scheduled')
            ->assertSee('CENTRAL-FLASH-ONE')
            ->assertSee('CENTRAL-FLASH-TWO');
    }

    private function seller(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::query()->where('email', 'seller@likhae.com')->sole();
    }
}
