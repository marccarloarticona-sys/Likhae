<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Marketplace\ProductCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SellerProductDiscountMarketingTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $this->category = Category::query()->findOrFail($this->seller->sellerProfile->primary_category_id);
    }

    public function test_product_editor_keeps_marketing_discounts_read_only_and_does_not_accept_discount_inputs(): void
    {
        $this->actingAs($this->seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Product Editor Discount Separation',
            'category_id' => $this->category->id,
            'price' => 100,
            'stock' => 8,
            'discount_type' => 'invalid-marketing-input',
            'discount_value' => 100000,
            'flash_sale_price' => 0,
            'flash_sale_allocation' => 999999,
            'flash_sale_starts_at' => 'not-a-date',
            'flash_sale_ends_at' => 'also-not-a-date',
            'listing_status' => 'draft',
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Product Editor Discount Separation')->sole();
        $variant = $product->variants()->sole();
        $this->assertSame('none', $variant->resolved_discount_type);
        $this->assertSame(0.0, (float) $variant->discount_value);
        $this->assertNull($variant->flash_sale_price);
        $this->assertNull($product->flash_sale_starts_at);

        $product->update(['status' => 'ACTIVE', 'published_at' => now()]);
        $variant->update(['discount_type' => 'fixed', 'discount_value' => 25]);
        $response = $this->get(route('seller.products', ['mode' => 'edit', 'product' => $product->id]))
            ->assertOk()
            ->assertSee('Product discounts · Active')
            ->assertSee('PHP 25.00 off')
            ->assertSee('Manage Discounts in Marketing');

        $this->assertStringNotContainsString('name="discount_type"', $response->getContent());
        $this->assertStringNotContainsString('name="discount_value"', $response->getContent());

        $this->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => $product->name,
            'category_id' => $product->category_id,
            'price' => 100,
            'stock' => 8,
            'sku' => $variant->sku,
            'single_variant_id' => $variant->id,
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'flash_sale_price' => 0.01,
            'flash_sale_allocation' => 999999,
            'flash_sale_starts_at' => 'not-a-date',
            'listing_status' => 'draft',
        ])->assertRedirect();
        $this->assertSame('fixed', $variant->fresh()->resolved_discount_type);
        $this->assertSame('25.00', $variant->fresh()->discount_value);
        $this->assertNull($variant->fresh()->flash_sale_price);
        $this->assertNull($product->fresh()->flash_sale_starts_at);
    }

    public function test_marketing_saves_scheduled_discounts_and_flash_sale_has_priority_without_compounding(): void
    {
        [$product, $variant] = $this->activeProduct('Discount Priority', 100);
        $startsAt = now()->addHour();
        $endsAt = $startsAt->copy()->addHours(2);

        $this->actingAs($this->seller)->patch(route('seller.marketing.product-discounts.update', ['product' => $product]), [
            'discounts_enabled' => 1,
            'discount_starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'discount_ends_at' => $endsAt->format('Y-m-d H:i:s'),
            'variants' => [['id' => $variant->id, 'discount_type' => 'percentage', 'discount_value' => 10]],
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(100.0, (float) $variant->fresh()->load('product')->final_price, 'A scheduled discount must not apply before its start.');
        $this->travelTo($startsAt);
        $this->assertSame(90.0, (float) $variant->fresh()->load('product')->final_price);

        $this->patch(route('seller.marketing.flash-sales.update', ['product' => $product, 'section' => 'flash-sales']), [
            'flash_sale_starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'flash_sale_ends_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'variants' => [['id' => $variant->id, 'flash_sale_price' => 95, 'flash_sale_allocation' => 4]],
        ])->assertRedirect();

        $this->assertSame(95.0, (float) $variant->fresh()->load('product')->final_price, 'Flash Sale must replace the product discount even when its price is higher.');

        $this->patch(route('seller.marketing.flash-sales.update', ['product' => $product, 'section' => 'flash-sales']), [
            'variants' => [['id' => $variant->id, 'flash_sale_price' => null, 'flash_sale_allocation' => null]],
        ])->assertRedirect();
        $this->assertSame(90.0, (float) $variant->fresh()->load('product')->final_price);

        $this->patch(route('seller.marketing.product-discounts.toggle', ['product' => $product]))->assertRedirect();
        $this->assertSame(100.0, (float) $variant->fresh()->load('product')->final_price);
        $this->patch(route('seller.marketing.product-discounts.toggle', ['product' => $product]))->assertRedirect();
        $this->assertSame(90.0, (float) $variant->fresh()->load('product')->final_price);
        $this->travelTo($endsAt);
        $this->assertSame(100.0, (float) $variant->fresh()->load('product')->final_price, 'The regular price must return after the product discount expires.');
        $this->travelBack();
    }

    public function test_discounted_catalog_query_uses_only_current_positive_resolved_prices(): void
    {
        [$activeProduct, $activeVariant] = $this->activeProduct('Resolved Active Deal', 100);
        $activeVariant->update(['discount_type' => 'fixed', 'discount_value' => 10]);
        [$scheduledProduct, $scheduledVariant] = $this->activeProduct('Resolved Scheduled Deal', 100);
        $scheduledVariant->update(['discount_type' => 'fixed', 'discount_value' => 10]);
        $scheduledProduct->update(['discount_starts_at' => now()->addDay()]);
        [$pausedProduct, $pausedVariant] = $this->activeProduct('Resolved Paused Deal', 100);
        $pausedVariant->update(['discount_type' => 'fixed', 'discount_value' => 10]);
        $pausedProduct->update(['discounts_enabled' => false]);
        [$expiredProduct, $expiredVariant] = $this->activeProduct('Resolved Expired Deal', 100);
        $expiredVariant->update(['discount_type' => 'fixed', 'discount_value' => 10]);
        $expiredProduct->update(['discount_ends_at' => now()->subDay()]);

        $query = app(ProductCatalogService::class)->applyFilters(
            app(ProductCatalogService::class)->visibleQuery(),
            Request::create('/products', 'GET', ['deals' => '1']),
        );
        $ids = $query->pluck('products.id')->map(fn ($id): int => (int) $id)->all();

        $this->assertContains((int) $activeProduct->id, $ids);
        $this->assertNotContains((int) $scheduledProduct->id, $ids);
        $this->assertNotContains((int) $pausedProduct->id, $ids);
        $this->assertNotContains((int) $expiredProduct->id, $ids);
    }

    public function test_additive_discount_schedule_migration_backfills_legacy_windows_and_is_retry_safe(): void
    {
        [$product, $variant] = $this->activeProduct('Legacy Discount Schedule', 100);
        $startsAt = now()->addHours(2)->startOfMinute();
        $endsAt = $startsAt->copy()->addHours(3);
        $product->update([
            'flash_sale_starts_at' => $startsAt,
            'flash_sale_ends_at' => $endsAt,
            'discount_starts_at' => null,
            'discount_ends_at' => null,
        ]);
        $variant->update(['discount_type' => 'fixed', 'discount_value' => 10]);

        $migration = require base_path('database/migrations/2026_10_29_000200_add_discounts_enabled_to_products.php');
        $migration->up();

        $this->assertSame($startsAt->timestamp, $product->fresh()->discount_starts_at->timestamp);
        $this->assertSame($endsAt->timestamp, $product->fresh()->discount_ends_at->timestamp);

        $migration->up();
        $this->assertSame($startsAt->timestamp, $product->fresh()->discount_starts_at->timestamp);
        $this->assertSame($endsAt->timestamp, $product->fresh()->discount_ends_at->timestamp);
    }

    public function test_seller_cannot_manage_another_sellers_product_discount(): void
    {
        $foreignUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $foreignProfile = SellerProfile::query()->create([
            'user_id' => $foreignUser->id,
            'primary_category_id' => $this->category->id,
            'business_name' => 'Another Seller',
            'status' => 'ACTIVE',
        ]);
        $product = Product::query()->create([
            'seller_profile_id' => $foreignProfile->id,
            'category_id' => $this->category->id,
            'name' => 'Foreign Discount Product',
            'slug' => 'foreign-discount-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'FOREIGN-DISCOUNT-PRODUCT',
            'price' => 100,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->seller)->patch(route('seller.marketing.product-discounts.update', ['product' => $product]), [
            'discounts_enabled' => 1,
            'variants' => [['id' => $variant->id, 'discount_type' => 'percentage', 'discount_value' => 10]],
        ])->assertNotFound();

        $this->assertSame('none', $variant->fresh()->resolved_discount_type);
    }

    /** @return array{Product, ProductVariant} */
    private function activeProduct(string $name, float $price): array
    {
        $product = Product::query()->create([
            'seller_profile_id' => $this->seller->sellerProfile->id,
            'category_id' => $this->category->id,
            'name' => $name,
            'slug' => str()->slug($name),
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'TEST-'.str()->upper(str()->random(8)),
            'price' => $price,
            'stock' => 10,
            'is_default' => true,
            'is_active' => true,
        ]);

        return [$product, $variant];
    }
}
