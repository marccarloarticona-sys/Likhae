<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\PurchaseLimitService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseLimitPerBuyerTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_is_nullable_unsigned_intent_without_an_index(): void
    {
        $columns = collect(Schema::getColumns('products'));
        $column = $columns->firstWhere('name', 'purchase_limit_per_buyer');

        $this->assertNotNull($column);
        $this->assertSame('integer', strtolower((string) $column['type']));
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertFalse(collect(Schema::getIndexes('products'))->contains(
            fn (array $index): bool => in_array('purchase_limit_per_buyer', $index['columns'] ?? [], true),
        ));
    }

    public function test_seller_can_set_and_clear_the_limit_and_invalid_values_are_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $payload = [
            'product_type' => 'single',
            'name' => 'Limited Seller Product',
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 30,
            'listing_status' => 'draft',
        ];

        foreach (['0', '-1', '1.5'] as $invalidValue) {
            $this->actingAs($seller)
                ->from(route('seller.products', ['mode' => 'add']))
                ->post(route('seller.products.store'), $payload + ['purchase_limit_per_buyer' => $invalidValue])
                ->assertRedirect(route('seller.products', ['mode' => 'add']))
                ->assertSessionHasErrors('purchase_limit_per_buyer');
        }

        $this->actingAs($seller)
            ->post(route('seller.products.store'), $payload + ['purchase_limit_per_buyer' => '5'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('name', 'Limited Seller Product')->sole();
        $this->assertSame(5, (int) $product->purchase_limit_per_buyer);

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'edit', 'product' => $product->id]))
            ->assertOk()
            ->assertSee('name="purchase_limit_per_buyer"', escape: false)
            ->assertSee('value="5"', escape: false);

        $variant = $product->variants()->sole();
        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), [
                'product_type' => 'single',
                'name' => $product->name,
                'category_id' => $category->id,
                'price' => 100,
                'stock' => 30,
                'sku' => $variant->sku,
                'single_variant_id' => $variant->id,
                'listing_status' => 'draft',
                'purchase_limit_per_buyer' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->purchase_limit_per_buyer);

        $otherSeller = User::query()->where('email', 'backstreetclub@likhae.online')->sole();
        $foreignProduct = Product::query()->create([
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'category_id' => $otherSeller->sellerProfile->primary_category_id,
            'name' => 'Foreign Purchase Limit Product',
            'slug' => 'foreign-purchase-limit-product',
            'status' => 'DRAFT',
        ]);

        $this->actingAs($seller)
            ->put(route('seller.products.update', $foreignProduct), $payload + ['purchase_limit_per_buyer' => '1'])
            ->assertForbidden();
        $this->assertNull($foreignProduct->fresh()->purchase_limit_per_buyer);
    }

    public function test_null_is_unlimited_and_limit_one_blocks_further_cart_and_buy_now_units(): void
    {
        [$buyer, $product, $variant] = $this->purchaseFixture();
        $variant->update(['stock' => 100]);
        $product->update(['purchase_limit_per_buyer' => null]);

        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 20);
        $this->assertSame(20, (int) $item->quantity);

        $item->delete();
        $product->update(['purchase_limit_per_buyer' => 1]);
        app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);

        $this->actingAs($buyer)->postJson(route('buyer.cart.add', $product), [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->actingAs($buyer)->postJson(route('buyer.buy-now', $product), [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_limit_five_aggregates_cart_quantities_across_variants(): void
    {
        [$buyer, $product, $variant] = $this->purchaseFixture();
        $secondVariant = $this->secondVariant($product, 'LIMIT-FIVE-SECOND');
        $product->update(['purchase_limit_per_buyer' => 5]);
        $cart = app(CartService::class);

        $firstLine = $cart->add($buyer, $product, (int) $variant->id, 3);
        $secondLine = $cart->add($buyer, $product, (int) $secondVariant->id, 2);
        $this->assertSame(3, (int) $firstLine->quantity);
        $this->assertSame(2, (int) $secondLine->quantity);

        try {
            $cart->add($buyer, $product, (int) $secondVariant->id, 1);
            $this->fail('The cumulative quantity across variants must stay within the product limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        try {
            $cart->update($buyer, $secondLine, 3);
            $this->fail('Updating an existing cart line must include other variants in the total.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
    }

    public function test_cart_load_flags_stale_stock_and_purchase_limit_overages(): void
    {
        $this->withoutVite();
        [$buyer, $product, $variant] = $this->purchaseFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 2);
        $variant->update(['stock' => 1]);
        $product->update(['purchase_limit_per_buyer' => 1]);

        $response = $this->actingAs($buyer)->get(route('buyer.cart'));

        $response->assertOk()
            ->assertSee('Only 1 available.')
            ->assertSee("Cart quantity exceeds this product's purchase limit.", escape: false)
            ->assertSee('reduce quantities to fit stock and purchase limits before checkout.');
        $this->assertSame(2, (int) $item->fresh()->quantity);
    }

    public function test_cart_preview_rejects_selected_quantities_over_purchase_limit(): void
    {
        [$buyer, $product, $variant] = $this->purchaseFixture();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 2);
        $product->update(['purchase_limit_per_buyer' => 1]);

        $this->actingAs($buyer)->postJson(route('buyer.cart.preview'), [
            'cart_item_ids' => [$item->id],
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'One or more selected products exceed the purchase limit. Reduce the cart quantity before checkout.');
    }

    public function test_historical_count_excludes_cancelled_orders_and_counts_returns_once(): void
    {
        [$buyer, $product] = $this->buyerProductFixture();
        $sellerId = (int) $product->seller_profile_id;
        $product->update(['purchase_limit_per_buyer' => 5]);

        $placedLine = $this->createOrderLine($buyer, $product, $sellerId, 2, 'PLACED', 'PLACED');
        $this->createOrderLine($buyer, $product, $sellerId, 3, 'CANCELLED', 'PLACED');
        $this->createOrderLine($buyer, $product, $sellerId, 4, 'PLACED', 'CANCELLED');
        $this->createOrderLine($buyer, $product, $sellerId, 1, 'RETURNED', 'COMPLETED');

        $limits = app(PurchaseLimitService::class);
        $this->assertSame(3, $limits->purchasedQuantity((int) $buyer->id, (int) $product->id));
        $this->assertSame(2, $limits->remainingAllowance($buyer, $product));
        $snapshotName = $placedLine->product_name;
        $product->update(['purchase_limit_per_buyer' => 1]);
        $this->assertSame($snapshotName, $placedLine->fresh()->product_name);
        $this->assertSame(3, $limits->purchasedQuantity((int) $buyer->id, (int) $product->id));

        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
    }

    public function test_buyer_detail_shows_remaining_allowance_but_guest_does_not_get_a_fake_remaining_value(): void
    {
        $this->withoutVite();
        [$buyer, $product, $variant] = $this->purchaseFixture();
        $product->update(['purchase_limit_per_buyer' => 5]);
        $this->createOrderLine($buyer, $product, (int) $product->seller_profile_id, 2, 'PLACED', 'PLACED');
        app(CartService::class)->add($buyer, $product, (int) $variant->id, 2);

        $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk()
            ->assertSee('Purchase limit: 5 per Buyer')
            ->assertSee('1 unit remaining for you.');

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee('Purchase limit: 5 per Buyer')
            ->assertDontSee('remaining for you');
    }

    public function test_checkout_rechecks_limits_and_aggregates_all_requested_variants(): void
    {
        [$buyer, $address, $item, $product, $variant] = $this->checkoutFixture();
        $secondVariant = $this->secondVariant($product, 'CHECKOUT-LIMIT-SECOND');
        $item->update(['quantity' => 3]);
        $secondItem = CartItem::query()->create([
            'cart_id' => $item->cart_id,
            'product_variant_id' => $secondVariant->id,
            'quantity' => 3,
        ]);
        $product->update(['purchase_limit_per_buyer' => 5]);

        try {
            app(CheckoutService::class)->placeOrder($buyer, $address, [$item->id, $secondItem->id], 'COD');
            $this->fail('Checkout must aggregate selected quantities across product variants.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }

        $this->assertDatabaseCount('orders', 0);

        $item->update(['quantity' => 1]);
        $secondItem->delete();
        $product->update(['purchase_limit_per_buyer' => 1]);
        $this->createOrderLine($buyer, $product, (int) $product->seller_profile_id, 1, 'PLACED', 'PLACED');
        try {
            app(CheckoutService::class)->placeOrder($buyer, $address, [$item->id], 'COD');
            $this->fail('Final checkout validation must include prior successful purchases.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
    }

    public function test_raising_limit_restores_allowance_and_duplicate_checkout_remains_idempotent(): void
    {
        [$buyer, $address, $item, $product] = $this->checkoutFixture();
        $product->update(['purchase_limit_per_buyer' => 5]);
        $this->createOrderLine($buyer, $product, (int) $product->seller_profile_id, 2, 'PLACED', 'PLACED');
        $limits = app(PurchaseLimitService::class);
        $this->assertSame(2, $limits->remainingAllowance($buyer, $product));

        $product->update(['purchase_limit_per_buyer' => 1]);
        $this->assertSame(0, $limits->remainingAllowance($buyer, $product));

        $product->update(['purchase_limit_per_buyer' => 4]);
        $this->assertSame(1, $limits->remainingAllowance($buyer, $product));

        // Remove the earlier history line from this isolated case so the
        // subsequent checkout can succeed while retaining the limit of 4.
        $historicalItem = OrderItem::query()->where('product_id', $product->id)->sole();
        $historicalSellerOrder = $historicalItem->sellerOrder;
        $historicalOrder = $historicalSellerOrder->order;
        $historicalItem->delete();
        $historicalSellerOrder->delete();
        $historicalOrder->delete();
        $attemptKey = (string) Str::uuid();
        $first = app(CheckoutService::class)->placeOrder($buyer, $address, [$item->id], 'COD', [], null, [], [], $attemptKey);
        $replay = app(CheckoutService::class)->placeOrder($buyer, $address, [$item->id], 'COD', [], null, [], [], $attemptKey);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('orders', 1);
    }

    /** @return array{User, Product, ProductVariant} */
    private function purchaseFixture(): array
    {
        [$buyer, $product] = $this->buyerProductFixture();
        $variant = $product->variants()->where('is_active', true)->firstOrFail();

        return [$buyer, $product, $variant];
    }

    /** @return array{User, Product} */
    private function buyerProductFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();

        return [$buyer, $product];
    }

    /** @return array{User, Address, CartItem, Product, ProductVariant} */
    private function checkoutFixture(): array
    {
        [$buyer, $product, $variant] = $this->purchaseFixture();
        $address = $buyer->addresses()->where('label', 'Home')->firstOrFail();
        $address->forceFill([
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'latitude' => 14.2400,
            'longitude' => 121.3600,
        ])->save();
        $product->sellerProfile->businessAddress->forceFill([
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
        ])->save();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);

        return [$buyer, $address->fresh(), $item, $product->fresh(), $variant->fresh()];
    }

    private function secondVariant(Product $product, string $sku): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'price' => 100,
            'stock' => 20,
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    private function createOrderLine(
        User $buyer,
        Product $product,
        int $sellerId,
        int $quantity,
        string $orderStatus,
        string $sellerOrderStatus,
    ): OrderItem {
        $order = Order::query()->create([
            'order_number' => 'LIMIT-'.Str::upper(Str::random(14)),
            'buyer_user_id' => $buyer->id,
            'status' => $orderStatus,
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'LIMIT-SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'seller_profile_id' => $sellerId,
            'status' => $sellerOrderStatus,
        ]);

        return OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $product->variants()->value('id'),
            'product_name' => $product->name,
            'sku' => (string) $product->variants()->value('sku'),
            'variant_description' => 'Snapshot variant',
            'unit_price' => 100,
            'quantity' => $quantity,
            'line_total' => 100 * $quantity,
        ]);
    }
}
