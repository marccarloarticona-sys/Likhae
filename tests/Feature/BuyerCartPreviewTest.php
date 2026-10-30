<?php

namespace Tests\Feature;

use App\Models\Buyer\CartItem;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerCartPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_voucher_preview_uses_only_selected_items_and_does_not_redeem(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $variant->update([
            'discount_type' => 'percentage',
            'discount_value' => 20,
        ]);
        $variant->refresh();
        $selectedItem = app(CartService::class)->add($buyer, $product, (int) $variant->id, 2);

        $otherVariant = ProductVariant::query()
            ->where('product_id', '<>', $product->id)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->firstOrFail();
        $otherProduct = Product::query()->findOrFail($otherVariant->product_id);
        app(CartService::class)->add($buyer, $otherProduct, (int) $otherVariant->id, 3);

        $platformVoucher = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'CARTPREVIEW10',
            'code' => 'CARTPREVIEW10',
            'name' => 'Cart preview ten percent',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'maximum_discount_amount' => 25,
            'usage_limit' => 2,
            'per_user_limit' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'campaign_budget_total' => 1000,
        ]);

        $expectedSubtotal = round((float) $variant->final_price * 2, 2);
        $expectedDiscount = min(25, round($expectedSubtotal * 0.1, 2));

        foreach (range(1, 2) as $attempt) {
            $response = $this->actingAs($buyer)->postJson(route('buyer.cart.preview'), [
                'cart_item_ids' => [$selectedItem->id],
                'platform_voucher_code' => 'cartpreview10',
            ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('voucher_applied', true);

            $this->assertEquals($expectedSubtotal, $response->json('subtotal'));
            $this->assertEquals($expectedDiscount, $response->json('discount_total'));
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('seller_orders', 0);
    }

    public function test_seller_percentage_voucher_previews_ten_percent_of_only_its_shop_subtotal(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $variant->update(['price' => 500, 'discount_type' => 'none', 'discount_value' => 0]);
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $product->seller_profile_id,
            'code' => 'SELLERPREVIEW10',
            'name' => 'Seller ten percent preview',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);

        foreach ([1 => 50, 2 => 100] as $quantity => $expectedDiscount) {
            $item->update(['quantity' => $quantity]);
            $response = $this->actingAs($buyer)->postJson(route('buyer.cart.preview'), [
                'cart_item_ids' => [$item->id],
                'voucher_code' => $voucher->code,
            ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('voucher_applied', true);

            $this->assertSame(500.0 * $quantity, (float) $response->json('subtotal'));
            $this->assertSame((float) $expectedDiscount, (float) $response->json('discount_total'));
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_seller_voucher_does_not_discount_another_sellers_selected_cart_items(): void
    {
        [$buyer, $sellerProduct, $sellerVariant] = $this->cartFixture();
        $otherVariant = ProductVariant::query()
            ->where('product_id', '<>', $sellerProduct->id)
            ->whereHas('product', fn ($query) => $query->where('seller_profile_id', '<>', $sellerProduct->seller_profile_id))
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->firstOrFail();
        $otherProduct = Product::query()->findOrFail($otherVariant->product_id);

        $sellerVariant->update(['price' => 500, 'discount_type' => 'none', 'discount_value' => 0]);
        $otherVariant->update(['price' => 700, 'discount_type' => 'none', 'discount_value' => 0]);
        $cartService = app(CartService::class);
        $sellerItem = $cartService->add($buyer, $sellerProduct, (int) $sellerVariant->id, 1);
        $otherSellerItem = $cartService->add($buyer, $otherProduct, (int) $otherVariant->id, 1);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $sellerProduct->seller_profile_id,
            'code' => 'ONE-SELLER-10',
            'name' => 'One seller only ten percent',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);

        $response = $this->actingAs($buyer)->postJson(route('buyer.cart.preview'), [
            'cart_item_ids' => [$sellerItem->id, $otherSellerItem->id],
            'voucher_code' => $voucher->code,
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('voucher_applied', true);

        $this->assertSame(1200.0, (float) $response->json('subtotal'));
        $this->assertSame(50.0, (float) $response->json('discount_total'));
    }

    public function test_seller_voucher_preview_reports_the_minimum_for_selected_seller_items(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $product->seller_profile_id,
            'code' => 'CARTMIN500',
            'name' => 'Cart minimum voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 50,
            'minimum_order_amount' => 500,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);

        $response = $this->actingAs($buyer)->postJson(route('buyer.cart.preview'), [
            'cart_item_ids' => [$item->id],
            'voucher_code' => 'CARTMIN500',
        ])->assertOk()->assertJsonPath('success', false);

        $this->assertSame(
            'This voucher requires a minimum eligible purchase of ₱500.00.',
            $response->json('voucher_message'),
        );
        $this->assertEquals((float) $variant->final_price, $response->json('subtotal'));
    }

    public function test_invalid_expired_not_yet_active_and_exhausted_vouchers_return_clear_messages(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);

        foreach ([
            ['INVALID-CART-CODE', [], 'This voucher code is invalid.'],
            ['EXPIRED-CART-CODE', ['ends_at' => now()->subDay()], 'This voucher has expired.'],
            ['FUTURE-CART-CODE', ['starts_at' => now()->addDay()], 'This voucher is not available yet.'],
            ['USED-CART-CODE', ['usage_limit' => 0], 'This voucher has reached its usage limit.'],
        ] as [$code, $overrides, $expectedMessage]) {
            if ($code !== 'INVALID-CART-CODE') {
                $voucher = Voucher::query()->create(array_merge([
                    'seller_profile_id' => $product->seller_profile_id,
                    'code' => $code,
                    'name' => 'Cart validation voucher',
                    'discount_type' => 'FIXED',
                    'discount_value' => 10,
                    'minimum_order_amount' => 0,
                    'is_active' => true,
                ], $overrides));
                BuyerVoucherClaim::query()->create(['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);
            }

            $response = $this->actingAs($buyer)->postJson(route('buyer.cart.preview'), [
                'cart_item_ids' => [$item->id],
                'voucher_code' => $code,
            ])->assertOk()->assertJsonPath('success', false);

            $this->assertSame($expectedMessage, $response->json('voucher_message'));
            $this->assertSame(0.0, (float) $response->json('discount_total'));
        }
    }

    public function test_fixed_voucher_cannot_make_the_estimated_total_negative(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $product->seller_profile_id,
            'code' => 'CARTLARGEFIXED',
            'name' => 'Large fixed voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 99999,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);

        $response = $this->actingAs($buyer)->postJson(route('buyer.cart.preview'), [
            'cart_item_ids' => [$item->id],
            'voucher_code' => 'CARTLARGEFIXED',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(max(0, round((float) $variant->final_price - 0.01, 2)), (float) $response->json('discount_total'));
        $this->assertSame(0.01, round((float) $response->json('subtotal') - (float) $response->json('discount_total'), 2));
        $this->assertGreaterThanOrEqual(0, (float) $response->json('estimated_total'));
    }

    public function test_soft_deleted_cart_product_remains_removable_without_revealing_its_details(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $productName = $product->name;
        $product->delete();

        $response = $this->actingAs($buyer)->get(route('buyer.cart'))->assertOk();

        $response->assertSee('Unavailable product')
            ->assertSee('data-item-restricted="1"', escape: false)
            ->assertDontSee($productName);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);

        $this->actingAs($buyer)
            ->delete(route('buyer.cart.remove', $item))
            ->assertRedirect();
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_quantity_endpoint_rejects_zero_and_returns_the_persisted_quantity(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);

        $this->actingAs($buyer)
            ->patchJson(route('buyer.cart.update', $item), ['quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->patchJson(route('buyer.cart.update', $item), ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('quantity', 2);

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 2]);
    }

    public function test_same_variant_additions_merge_and_different_variants_remain_separate(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $secondVariant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'TOTE-CART-SECOND',
            'price' => 420,
            'stock' => 10,
            'is_default' => false,
            'is_active' => true,
        ]);
        $service = app(CartService::class);

        $first = $service->add($buyer, $product, (int) $variant->id, 2);
        $merged = $service->add($buyer, $product, (int) $variant->id, 3);
        $separate = $service->add($buyer, $product, (int) $secondVariant->id, 1);

        $this->assertSame($first->id, $merged->id);
        $this->assertSame(5, (int) $merged->fresh()->quantity);
        $this->assertNotSame($first->id, $separate->id);
        $this->assertSame(2, CartItem::query()->where('cart_id', $first->cart_id)->count());
    }

    public function test_cart_displays_the_current_variant_price_after_a_price_change(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $variant->update([
            'price' => 420,
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee('₱378.00')
            ->assertSee('data-item-price="378"', escape: false);
    }

    public function test_buyer_cannot_update_another_buyers_cart_item(): void
    {
        [$buyer, $product, $variant] = $this->cartFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $otherBuyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($otherBuyer)
            ->patchJson(route('buyer.cart.update', $item), ['quantity' => 2])
            ->assertForbidden();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
    }

    /** @return array{User, Product, ProductVariant} */
    private function cartFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->with('variants')
            ->where('slug', 'sample-handmade-tote')
            ->firstOrFail();
        $variant = $product->variants->firstOrFail();

        return [$buyer, $product, $variant];
    }
}
