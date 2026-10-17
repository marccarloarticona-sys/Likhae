<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Buyer\SellerFollower;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerStorefrontFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_page_shows_real_details_and_only_its_visible_products(): void
    {
        [$buyer, $seller, $otherSeller, $category] = $this->storeFixture();
        $storeProduct = $this->createStoreProduct($seller, $category, 'Seller Store Walnut Table', 400);
        $otherProduct = $this->createStoreProduct($otherSeller, $category, 'Other Store Walnut Table', 400);
        $this->addPublishedReview($buyer, $seller, $storeProduct, 5);
        $this->addPublishedReview($buyer, $seller, $storeProduct, 4);
        SellerFollower::query()->create([
            'buyer_user_id' => $buyer->id,
            'seller_profile_id' => $seller->id,
        ]);

        $response = $this->actingAs($buyer)
            ->get(route('buyer.shop', ['seller' => $seller->id]))
            ->assertOk()
            ->assertSee($seller->business_name)
            ->assertSee('Verified LIKHAE store')
            ->assertSee('data-store-stat="products"', escape: false)
            ->assertSee('data-store-stat="rating"', escape: false)
            ->assertSee('data-store-stat="followers"', escape: false)
            ->assertSee('2 ratings')
            ->assertSee('data-store-follower-count>1</dd>', escape: false)
            ->assertSee('Message')
            ->assertSee(route('buyer.messages', ['seller' => $seller->id]), escape: false)
            ->assertSee($storeProduct->name)
            ->assertDontSee($otherProduct->name);

        $expectedStoreCount = Product::query()->visible()->where('seller_profile_id', $seller->id)->count();
        $this->assertSame($expectedStoreCount, $response->viewData('storeProductCount'));
        $this->assertMatchesRegularExpression(
            '/data-store-stat="products"><dd[^>]*>'.preg_quote(number_format($expectedStoreCount), '/').'<\/dd>/',
            $response->getContent(),
        );
        $response->assertSee('>4.5</dd>', escape: false);
        $this->assertSame(2, (int) data_get($response->viewData('seller'), 'rating_count'));
        $this->assertContains($storeProduct->id, $response->viewData('products')->getCollection()->pluck('id')->all());
        $this->assertTrue($response->viewData('products')->getCollection()->every(
            fn ($item) => (int) $item->seller_profile_id === (int) $seller->id,
        ));
    }

    public function test_store_search_is_trimmed_case_insensitive_and_escapes_wildcards(): void
    {
        [$buyer, $seller, , $category] = $this->storeFixture();
        $product = $this->createStoreProduct($seller, $category, 'Blue Walnut Table', 250);

        $this->actingAs($buyer)
            ->get(route('buyer.shop', ['seller' => $seller->id, 'q' => '  WALNUT TaBlE  ']))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('value="WALNUT TaBlE"', escape: false);

        foreach (['%', '_'] as $wildcard) {
            $this->get(route('buyer.shop', ['seller' => $seller->id, 'q' => $wildcard]))
                ->assertOk()
                ->assertSee('No store products found')
                ->assertDontSee($product->name);
        }

        $this->get(route('buyer.shop', ['seller' => $seller->id, 'q' => '   ']))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_store_combines_sale_price_rating_search_and_seller_scope(): void
    {
        [$buyer, $seller, $otherSeller, $category] = $this->storeFixture();
        $product = $this->createStoreProduct($seller, $category, 'Blue Table Lamp', 500, 'fixed', 100);
        $otherProduct = $this->createStoreProduct($otherSeller, $category, 'Blue Table Lamp', 500, 'fixed', 100);
        $this->addPublishedReview($buyer, $seller, $product, 5);
        $this->addPublishedReview($buyer, $otherSeller, $otherProduct, 5);

        $response = $this->actingAs($buyer)
            ->get(route('buyer.shop', [
                'seller' => $seller->id,
                'q' => '  blue table  ',
                'min_price' => '400.00',
                'max_price' => '400',
                'rating' => '4',
                'sort' => 'price-low',
            ]))
            ->assertOk();

        $this->assertSame(1, $response->viewData('products')->total());
        $this->assertSame('4', $response->viewData('storeFilterValues')['rating']);
        $this->assertSame([$product->id], $response->viewData('products')->getCollection()->pluck('id')->all());
        $this->assertSame('blue table', $response->viewData('storeFilterValues')['q']);
        $response->assertSee('value="400.00"', escape: false);

        $this->get(route('buyer.shop', ['seller' => $seller->id, 'rating' => '4', 'q' => 'No matching product']))
            ->assertOk()
            ->assertSee('No store products found');
    }

    public function test_store_price_filter_allows_empty_bounds_and_uses_inclusive_sale_price(): void
    {
        [$buyer, $seller, , $category] = $this->storeFixture();
        $product = $this->createStoreProduct($seller, $category, 'Store Range Sample', 500, 'fixed', 100);

        $this->actingAs($buyer)
            ->get(route('buyer.shop', ['seller' => $seller->id, 'min_price' => '', 'max_price' => '']))
            ->assertOk()
            ->assertSee($product->name);

        $this->get(route('buyer.shop', ['seller' => $seller->id, 'min_price' => '400']))
            ->assertOk()
            ->assertSee($product->name);

        $this->get(route('buyer.shop', ['seller' => $seller->id, 'max_price' => '400.00']))
            ->assertOk()
            ->assertSee($product->name);

        $this->get(route('buyer.shop', ['seller' => $seller->id, 'min_price' => '400.01']))
            ->assertOk()
            ->assertDontSee($product->name);

        $this->get(route('buyer.shop', ['seller' => $seller->id, 'max_price' => '399.99']))
            ->assertOk()
            ->assertDontSee($product->name);
    }

    public function test_store_price_validation_is_inline_and_preserves_entered_values(): void
    {
        [$buyer, $seller] = $this->storeFixture();

        $this->actingAs($buyer)
            ->get(route('buyer.shop', ['seller' => $seller->id, 'min_price' => '-1']))
            ->assertOk()
            ->assertSee('role="alert"', escape: false)
            ->assertSee('value="-1"', escape: false);

        $this->get(route('buyer.shop', ['seller' => $seller->id, 'min_price' => '401', 'max_price' => '400']))
            ->assertOk()
            ->assertSee('Maximum price must be greater than or equal to minimum price.')
            ->assertSee('value="401"', escape: false)
            ->assertSee('value="400"', escape: false)
            ->assertSee('Clear Filters');
    }

    public function test_store_pagination_keeps_search_price_rating_and_sort_filters(): void
    {
        [$buyer, $seller, , $category] = $this->storeFixture();
        for ($index = 1; $index <= 25; $index++) {
            $this->createStoreProduct($seller, $category, 'Paged Store Lamp '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 200);
        }

        $filters = [
            'q' => 'Paged Store Lamp',
            'min_price' => '200',
            'max_price' => '200.00',
            'sort' => 'price-low',
        ];
        $response = $this->actingAs($buyer)
            ->get(route('buyer.shop', ['seller' => $seller->id] + $filters))
            ->assertOk();

        $this->assertSame(25, $response->viewData('products')->total());
        parse_str((string) parse_url($response->viewData('products')->url(2), PHP_URL_QUERY), $pageTwoQuery);
        $this->assertSame('2', (string) ($pageTwoQuery['page'] ?? ''));
        foreach ($filters as $key => $value) {
            $this->assertSame((string) $value, (string) ($pageTwoQuery[$key] ?? ''));
        }

        $this->get(route('buyer.shop', ['seller' => $seller->id]))
            ->assertOk()
            ->assertSee(route('buyer.shop', ['seller' => $seller->id]), escape: false);
    }

    public function test_invalid_store_key_returns_not_found(): void
    {
        [$buyer] = $this->storeFixture();

        $this->actingAs($buyer)->get(route('buyer.shop', ['seller' => '999999999']))->assertNotFound();
    }

    public function test_guest_store_access_redirects_to_sign_in(): void
    {
        [, $seller] = $this->storeFixture();

        $this->get(route('buyer.shop', ['seller' => $seller->id]))
            ->assertRedirect(route('login'));
    }

    /** @return array{User, SellerProfile, SellerProfile, Category} */
    private function storeFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->firstOrFail();
        $sellers = SellerProfile::query()->where('status', 'ACTIVE')->orderBy('id')->take(2)->get();

        return [$buyer, $sellers[0], $sellers[1], Category::query()->firstOrFail()];
    }

    private function createStoreProduct(
        SellerProfile $seller,
        Category $category,
        string $name,
        float $price,
        string $discountType = 'none',
        float $discountValue = 0,
    ): Product {
        $product = Product::query()->create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => 'Storefront filter fixture.',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'STORE-'.Str::upper(Str::random(10)),
            'price' => $price,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);

        return $product;
    }

    private function addPublishedReview(User $buyer, SellerProfile $seller, Product $product, int $rating): void
    {
        $order = Order::query()->create([
            'order_number' => 'STORE-'.Str::uuid(),
            'buyer_user_id' => $buyer->id,
            'status' => 'COMPLETED',
            'subtotal' => 400,
            'grand_total' => 400,
            'payment_status' => 'PAID',
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'STORE-SELLER-'.Str::uuid(),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'COMPLETED',
            'item_subtotal' => 400,
            'grand_total' => 400,
        ]);
        $variant = $product->variants()->firstOrFail();
        $item = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'unit_price' => 400,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 400,
        ]);
        Review::query()->create([
            'order_item_id' => $item->id,
            'buyer_user_id' => $buyer->id,
            'rating' => $rating,
            'status' => 'PUBLISHED',
        ]);
    }
}
