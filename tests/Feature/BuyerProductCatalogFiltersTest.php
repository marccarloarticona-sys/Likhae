<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use App\Services\Marketplace\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerProductCatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_adjusted_variant_price_is_inclusive_in_range_filters(): void
    {
        [$buyer, $product, $variant] = $this->catalogFixture();
        $variant->update([
            'price' => 350,
            'discount_type' => 'fixed',
            'discount_value' => 50,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.products', [
                'category' => $product->category->slug,
                'min_price' => '300.00',
                'max_price' => '300.00',
            ]))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('type="hidden" name="min_price" value="300.00"', escape: false)
            ->assertSee('type="hidden" name="max_price" value="300.00"', escape: false);

        $this->get(route('buyer.products', [
            'category' => $product->category->slug,
            'min_price' => '300.01',
        ]))->assertOk()->assertDontSee($product->name);

        $this->get(route('buyer.products', [
            'category' => $product->category->slug,
            'max_price' => '299.99',
        ]))->assertOk()->assertDontSee($product->name);

        $this->get(route('buyer.products', [
            'category' => $product->category->slug,
            'min_price' => '',
            'max_price' => '',
        ]))->assertOk()->assertSee($product->name);

        $product->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
        ]);
        $variant->update([
            'price' => 350,
            'discount_type' => 'fixed',
            'discount_value' => 50,
            'flash_sale_price' => 250,
            'flash_sale_allocation' => 2,
            'flash_sale_sold' => 0,
        ]);

        $this->get(route('buyer.products', [
            'category' => $product->category->slug,
            'min_price' => '250.00',
            'max_price' => '250.00',
        ]))->assertOk()->assertSee($product->name);
        $this->get(route('buyer.products', [
            'category' => $product->category->slug,
            'min_price' => '250.01',
        ]))->assertOk()->assertDontSee($product->name);
    }

    public function test_search_trims_and_matches_case_insensitively_and_escapes_like_wildcards(): void
    {
        [$buyer, $product] = $this->catalogFixture();

        $this->actingAs($buyer)
            ->get(route('buyer.products', ['q' => '  SAMPLE handmade TOTE  ']))
            ->assertOk()
            ->assertSee($product->name);

        $this->get(route('buyer.products', ['q' => '%']))
            ->assertOk()
            ->assertSee('No products found')
            ->assertDontSee($product->name);

        $this->get(route('buyer.products', ['q' => '_']))
            ->assertOk()
            ->assertSee('No products found')
            ->assertDontSee($product->name);

        $this->get(route('buyer.products', ['q' => 'Sampel Handmade Tote']))
            ->assertOk()
            ->assertSee('Did you mean:')
            ->assertSee($product->name);

        $this->get(route('buyer.products', ['q' => 'Totee']))
            ->assertOk()
            ->assertSee('Did you mean:')
            ->assertSee($product->name);

        $this->get(route('buyer.products', ['q' => '   ']))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_category_price_rating_location_search_and_sort_work_together(): void
    {
        [$buyer, $product, $variant] = $this->catalogFixture();
        $variant->update(['price' => 350, 'discount_type' => 'none', 'discount_value' => 0]);
        $product->sellerProfile->businessAddress->update(['province_name' => 'Laguna']);
        $order = Order::query()->create([
            'order_number' => 'TEST-'.Str::uuid(),
            'buyer_user_id' => $buyer->id,
            'status' => 'COMPLETED',
            'subtotal' => 350,
            'grand_total' => 350,
            'payment_status' => 'PAID',
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'TEST-SELLER-'.Str::uuid(),
            'order_id' => $order->id,
            'seller_profile_id' => $product->seller_profile_id,
            'status' => 'COMPLETED',
            'item_subtotal' => 350,
            'grand_total' => 350,
        ]);
        $orderItem = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'unit_price' => 350,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 350,
        ]);
        Review::query()->create([
            'order_item_id' => $orderItem->id,
            'buyer_user_id' => $buyer->id,
            'rating' => 5,
            'status' => 'PUBLISHED',
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.products', [
                'q' => '  TOTE  ',
                'category' => $product->category->slug,
                'min_price' => '350',
                'max_price' => '350.00',
                'rating' => '4.5',
                'province' => 'Laguna',
                'sort' => 'price-low',
            ]))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('id="catalogProvince" name="province"', escape: false)
            ->assertSee('type="hidden" name="max_price" value="350.00"', escape: false)
            ->assertSee('value="Laguna"', escape: false);
    }

    public function test_invalid_price_ranges_show_validation_errors(): void
    {
        [$buyer] = $this->catalogFixture();

        $this->actingAs($buyer)
            ->get(route('buyer.products', ['min_price' => '-1']))
            ->assertOk()
            ->assertSee('role="alert"', escape: false)
            ->assertSee('value="-1"', escape: false);

        $reversedRange = route('buyer.products', ['min_price' => '301', 'max_price' => '300']);
        $this->get($reversedRange)
            ->assertOk()
            ->assertSee('Maximum price must be greater than or equal to minimum price.')
            ->assertSee('value="301"', escape: false)
            ->assertSee('value="300"', escape: false);

        $this->get(route('buyer.products', ['min_price' => '301', 'max_price' => '300', 'q' => ['array-value']]))
            ->assertOk()
            ->assertSee('Maximum price must be greater than or equal to minimum price.');
    }

    public function test_vacation_mode_disables_catalog_purchase_and_rejects_cart_submission(): void
    {
        [$buyer, $product, $variant] = $this->catalogFixture();
        $product->sellerProfile->update(['vacation_mode' => true]);

        $this->actingAs($buyer)
            ->get(route('buyer.products'))
            ->assertOk()
            ->assertSee('Seller on Vacation');

        $this->from(route('buyer.products'))
            ->post(route('buyer.cart.add', $product), [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('product_variant_id');

        $this->assertDatabaseMissing('cart_items', ['product_variant_id' => $variant->id]);

        $apiToken = Str::random(40);
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $apiToken),
            'api_token_expires_at' => now()->addHour(),
        ])->save();
        $this->withToken($apiToken)
            ->postJson(route('api.v1.buyer.cart.store'), [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');

        $product->sellerProfile->update(['vacation_mode' => false]);
        $cartItem = app(CartService::class)->add($buyer, $product, $variant->id, 1);
        $product->sellerProfile->update(['vacation_mode' => true]);
        $buyer->addresses()->firstOrFail()->update(['latitude' => 14.5995, 'longitude' => 120.9842]);

        $this->from(route('buyer.cart'))
            ->post(route('buyer.checkout.post'), ['cart_item_ids' => [$cartItem->id]])
            ->assertSessionHasErrors('cart_item_ids');

        $this->withToken($apiToken)
            ->postJson(route('api.v1.buyer.checkout.store'), [
                'checkout_attempt_key' => (string) Str::uuid(),
                'cart_item_ids' => [$cartItem->id],
                'address_id' => $buyer->addresses()->value('id'),
                'payment_method' => 'COD',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart');
    }

    public function test_out_of_stock_products_stay_visible_with_clear_availability_text(): void
    {
        [$buyer, $product, $variant] = $this->catalogFixture();
        $variant->update(['stock' => 0]);

        $this->actingAs($buyer)
            ->get(route('buyer.products'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('Out of Stock');
    }

    public function test_price_sort_uses_current_selling_prices(): void
    {
        [$buyer, $source] = $this->catalogFixture();
        $category = $source->category;
        $saleProduct = $this->createCatalogProduct($source, 'Sale price sort low', 200, 'ACTIVE', true, 'fixed', 100);
        $regularProduct = $this->createCatalogProduct($source, 'Sale price sort high', 150);

        $lowToHigh = $this->actingAs($buyer)
            ->get(route('buyer.products', ['category' => $category->slug, 'q' => 'Sale price sort', 'sort' => 'price-low']))
            ->assertOk()
            ->getContent();
        $this->assertLessThan(strpos($lowToHigh, $regularProduct->name), strpos($lowToHigh, $saleProduct->name));

        $highToLow = $this->get(route('buyer.products', ['category' => $category->slug, 'q' => 'Sale price sort', 'sort' => 'price-high']))
            ->assertOk()
            ->getContent();
        $this->assertLessThan(strpos($highToLow, $saleProduct->name), strpos($highToLow, $regularProduct->name));
    }

    public function test_pagination_keeps_the_selected_catalog_filters(): void
    {
        [$buyer, $source] = $this->catalogFixture();
        $source->sellerProfile->businessAddress->update(['province_name' => 'Laguna']);
        for ($index = 1; $index <= 25; $index++) {
            $this->createCatalogProduct($source, 'Page Filter '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 150);
        }

        $filters = [
            'q' => 'Page Filter',
            'category' => $source->category->slug,
            'min_price' => '150',
            'max_price' => '150.00',
            'province' => 'Laguna',
            'sort' => 'price-low',
        ];
        $response = $this->actingAs($buyer)->get(route('buyer.products', $filters))->assertOk();

        $this->assertSame(25, $response->viewData('products')->total());
        parse_str((string) parse_url($response->viewData('products')->url(2), PHP_URL_QUERY), $pageTwoQuery);
        $this->assertSame('2', (string) ($pageTwoQuery['page'] ?? ''));
        foreach ($filters as $key => $value) {
            $this->assertSame((string) $value, (string) ($pageTwoQuery[$key] ?? ''));
        }
    }

    public function test_inactive_and_draft_products_are_not_listed_or_suggested(): void
    {
        [$buyer, $source] = $this->catalogFixture();
        $draft = $this->createCatalogProduct($source, 'Draft only listing', 100, 'DRAFT');
        $inactiveVariant = $this->createCatalogProduct($source, 'Unavailable variant listing', 100, 'ACTIVE', false);
        $deleted = $this->createCatalogProduct($source, 'Deleted catalog listing', 100);
        $deleted->delete();

        $draftResponse = $this->actingAs($buyer)
            ->get(route('buyer.products', ['q' => 'Draft only listing']))
            ->assertOk()
            ->assertSee('No products found');
        $this->assertSame(0, $draftResponse->viewData('products')->total());
        $this->assertSame([], $draftResponse->viewData('searchSuggestions'));

        $inactiveResponse = $this->get(route('buyer.products', ['q' => 'Unavailable variant listing']))
            ->assertOk()
            ->assertSee('No products found');
        $this->assertSame(0, $inactiveResponse->viewData('products')->total());
        $this->assertSame([], $inactiveResponse->viewData('searchSuggestions'));

        $deletedResponse = $this->get(route('buyer.products', ['q' => 'Deleted catalog listing']))
            ->assertOk()
            ->assertSee('No products found');
        $this->assertSame(0, $deletedResponse->viewData('products')->total());
        $this->assertSame([], $deletedResponse->viewData('searchSuggestions'));
    }

    /** @return array{User, Product, ProductVariant} */
    private function catalogFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()
            ->with(['variants', 'category', 'sellerProfile'])
            ->where('slug', 'sample-handmade-tote')
            ->firstOrFail();

        return [$buyer, $product, $product->variants->firstOrFail()];
    }

    private function createCatalogProduct(
        Product $source,
        string $name,
        float $price,
        string $status = 'ACTIVE',
        bool $variantActive = true,
        string $discountType = 'none',
        float $discountValue = 0,
    ): Product {
        $product = Product::query()->create([
            'seller_profile_id' => $source->seller_profile_id,
            'category_id' => $source->category_id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => 'Catalog filter fixture.',
            'status' => $status,
            'published_at' => $status === 'ACTIVE' ? now() : null,
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'CAT-'.Str::upper(Str::random(10)),
            'price' => $price,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'stock' => 5,
            'is_default' => true,
            'is_active' => $variantActive,
        ]);

        return $product;
    }
}
