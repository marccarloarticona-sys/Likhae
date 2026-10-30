<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlashSaleMigrationPreservesOrderDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_campaign_migration_backfills_existing_flash_snapshots_without_changing_orders_or_tables(): void
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::query()->create(['name' => 'Migration Test', 'slug' => 'flash-migration-test', 'is_active' => true]);
        $seller = SellerProfile::query()->create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Flash Migration Seller',
            'status' => 'ACTIVE',
        ]);
        $product = Product::query()->create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Existing Flash Sale Order',
            'slug' => 'existing-flash-sale-order',
            'status' => 'ACTIVE',
            'published_at' => now(),
            'flash_sale_starts_at' => now()->subHour(),
            'flash_sale_ends_at' => now()->addHour(),
            'flash_sale_campaign_key' => null,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'MIGRATION-FLASH-1',
            'price' => 100,
            'flash_sale_price' => 80,
            'flash_sale_allocation' => 3,
            'flash_sale_sold' => 1,
            'stock' => 2,
            'is_default' => true,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'FLASH-MIGRATION-'.str()->upper(str()->random(10)),
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'subtotal' => 80,
            'grand_total' => 130,
            'payment_status' => 'PENDING',
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'FLASH-MIGRATION-'.str()->upper(str()->random(10)),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'PLACED',
            'item_subtotal' => 80,
            'shipping_fee' => 50,
            'grand_total' => 130,
        ]);
        $orderItem = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'variant_description' => 'Standard',
            'unit_price' => 80,
            'quantity' => 1,
            'flash_sale_quantity' => 1,
            'flash_sale_campaign_key' => null,
            'discount_amount' => 20,
            'line_total' => 80,
        ]);

        $tableNamesBefore = collect(Schema::getTableListing())->sort()->values()->all();
        $snapshotBefore = [$order->status, (string) $orderItem->unit_price, (int) $orderItem->quantity, (int) $variant->flash_sale_sold];
        $migration = require database_path('migrations/2026_10_29_000200_add_flash_sale_controls_to_existing_tables.php');
        $migration->up();

        $campaignKey = $product->fresh()->flash_sale_campaign_key;
        $this->assertNotEmpty($campaignKey);
        $this->assertSame($campaignKey, $orderItem->fresh()->flash_sale_campaign_key);
        $this->assertSame($snapshotBefore, [
            $order->fresh()->status,
            (string) $orderItem->fresh()->unit_price,
            (int) $orderItem->fresh()->quantity,
            (int) $variant->fresh()->flash_sale_sold,
        ]);
        $this->assertSame($tableNamesBefore, collect(Schema::getTableListing())->sort()->values()->all());
    }
}
