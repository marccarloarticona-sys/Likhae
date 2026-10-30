<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\SellerPromotionComponent;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\ProductPriceResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerHomeDealsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_available_discounted_products_and_no_fake_countdown(): void
    {
        $buyer = $this->seededBuyer();
        $discounted = $this->product('Home Discount Product', 'percentage', 15, 200, 5);

        $this->actingAs($buyer)
            ->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('Save on your next find')
            ->assertSee('Discounts For You')
            ->assertSee('Flash Deals')
            ->assertDontSee('Deals For You')
            ->assertDontSee('Current Deals')
            ->assertSee('Platform Events')
            ->assertSee('No scheduled events right now')
            ->assertDontSee('11.11 MEGA SALE')
            ->assertSee($discounted->name)
            ->assertSee('₱170.00')
            ->assertSee('₱200.00')
            ->assertDontSee('data-flash-countdown')
            ->assertSee(route('buyer.products', ['category' => 'womens-apparel']), escape: false)
            ->assertSee(route('buyer.products', ['category' => 'electronics-and-gadgets']), escape: false)
            ->assertSee(route('buyer.rewards'), escape: false)
            ->assertSee(route('buyer.flash-deals'), escape: false);

        foreach ([
            'womens-apparel',
            'electronics-and-gadgets',
            'home-and-garden',
            'books-and-media',
            'health-and-beauty',
            'sports-and-outdoors',
            'kids-and-baby-toys',
            'handmade-goods',
        ] as $categorySlug) {
            $this->get(route('buyer.products', ['category' => $categorySlug]))->assertOk();
        }
    }

    public function test_home_has_the_four_promotion_shortcuts_and_existing_buyer_pages_remain_available(): void
    {
        $buyer = $this->seededBuyer();
        $home = $this->actingAs($buyer)->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('Discounts')
            ->assertSee('Products on sale')
            ->assertSee('Flash Deals')
            ->assertSee('Limited-time offers')
            ->assertSee('Claim and save')
            ->assertSee('Free Gifts')
            ->assertSee('Buy and get rewards')
            ->assertSee(route('buyer.discounts'), escape: false)
            ->assertSee(route('buyer.flash-deals'), escape: false)
            ->assertSee(route('buyer.rewards', ['tab' => 'vouchers']), escape: false)
            ->assertSee(route('buyer.free-gifts'), escape: false);

        $document = new \DOMDocument;
        @$document->loadHTML($home->getContent());
        $xpath = new \DOMXPath($document);
        $quickCards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " lk-quick-grid ")]/a');
        $this->assertSame(4, $quickCards->length);
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Homepage sections"]')->length, 'The homepage should use normal scrolling instead of category tabs.');

        $this->get(route('buyer.orders'))->assertOk();
        $this->get(route('buyer.wishlist'))->assertOk();
        $this->get(route('buyer.messages'))->assertOk();
    }

    public function test_active_flash_sales_and_buy_x_get_y_offers_are_discovered_separately(): void
    {
        $buyer = $this->seededBuyer();
        $active = $this->product('Home Active Flash Item', 'none', 0, 200, 5);
        $active->update([
            'flash_sale_starts_at' => now()->subMinutes(5),
            'flash_sale_ends_at' => now()->addHour(),
            'flash_sale_is_paused' => false,
        ]);
        ProductVariant::query()->where('product_id', $active->id)->firstOrFail()->update([
            'flash_sale_price' => 120,
            'flash_sale_allocation' => 3,
            'flash_sale_sold' => 1,
        ]);

        $upcoming = $this->product('Home Upcoming Flash Item', 'none', 0, 300, 4);
        $upcoming->update([
            'flash_sale_starts_at' => now()->addHour(),
            'flash_sale_ends_at' => now()->addHours(2),
            'flash_sale_is_paused' => false,
        ]);
        ProductVariant::query()->where('product_id', $upcoming->id)->firstOrFail()->update([
            'flash_sale_price' => 210,
            'flash_sale_allocation' => 2,
            'flash_sale_sold' => 0,
        ]);

        $expired = $this->product('Home Expired Flash Item', 'none', 0, 300, 4);
        $expired->update([
            'flash_sale_starts_at' => now()->subDays(2),
            'flash_sale_ends_at' => now()->subHour(),
            'flash_sale_is_paused' => false,
        ]);
        ProductVariant::query()->where('product_id', $expired->id)->firstOrFail()->update([
            'flash_sale_price' => 180,
            'flash_sale_allocation' => 2,
            'flash_sale_sold' => 0,
        ]);

        $soldOut = $this->product('Home Sold Out Flash Item', 'none', 0, 300, 4);
        $soldOut->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
            'flash_sale_is_paused' => false,
        ]);
        ProductVariant::query()->where('product_id', $soldOut->id)->firstOrFail()->update([
            'flash_sale_price' => 180,
            'flash_sale_allocation' => 2,
            'flash_sale_sold' => 2,
        ]);

        $buyProduct = $this->product('Home Gift Qualifier', 'none', 0, 400, 5);
        $rewardProduct = $this->product('Home Gift Reward', 'none', 0, 100, 5);
        $buyVariant = ProductVariant::query()->where('product_id', $buyProduct->id)->firstOrFail();
        $rewardVariant = ProductVariant::query()->where('product_id', $rewardProduct->id)->firstOrFail();
        $sellerId = (int) $buyProduct->seller_profile_id;
        $giftOffer = SellerPromotion::query()->create([
            'seller_profile_id' => $sellerId,
            'name' => 'Homepage Buy One Get One',
            'promotion_type' => SellerPromotion::TYPE_BUY_X_GET_Y,
            'buy_variant_id' => $buyVariant->id,
            'buy_quantity' => 1,
            'reward_variant_id' => $rewardVariant->id,
            'reward_product_id' => $rewardProduct->id,
            'reward_quantity' => 1,
            'reward_discount_percent' => 100,
            'redemptions_used' => 0,
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.flash-deals'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertSee($upcoming->name)
            ->assertSee('Upcoming flash sales')
            ->assertDontSee($expired->name)
            ->assertSee($soldOut->name)
            ->assertSee('Flash Sale allocation sold out');

        $this->get(route('buyer.discounts'))
            ->assertOk()
            ->assertDontSee($active->name)
            ->assertDontSee($upcoming->name);

        $this->get(route('buyer.home'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertSee('Homepage Buy One Get One')
            ->assertSee(route('buyer.cart.offer-claim', $giftOffer), escape: false);

        $this->get(route('buyer.free-gifts'))
            ->assertOk()
            ->assertSee('Homepage Buy One Get One')
            ->assertSee($buyProduct->name)
            ->assertSee($rewardProduct->name);
    }

    public function test_product_discounts_list_only_visible_discounted_products_with_stock(): void
    {
        $buyer = $this->seededBuyer();
        $discounted = $this->product('Available Discount Product', 'fixed', 25, 200, 3);
        $discountedVariant = ProductVariant::query()->where('product_id', $discounted->id)->with('product')->firstOrFail();
        $this->assertSame(175.0, $discountedVariant->final_price);
        $this->assertTrue(Product::query()->visible()->whereKey($discounted->id)
            ->whereHas('variants', fn ($variants) => $variants->whereRaw(app(ProductPriceResolver::class)->sqlExpression().' < product_variants.price'))
            ->exists());
        $scheduledActive = $this->product('Scheduled Active Discount Product', 'percentage', 12, 250, 3);
        $scheduledActive->update(['discount_starts_at' => now()->subMinutes(5), 'discount_ends_at' => now()->addHour()]);
        $scheduledFuture = $this->product('Scheduled Future Discount Product', 'percentage', 12, 250, 3);
        $scheduledFuture->update(['discount_starts_at' => now()->addHour(), 'discount_ends_at' => now()->addHours(2)]);
        $expired = $this->product('Expired Discount Product', 'percentage', 12, 250, 3);
        $expired->update(['discount_starts_at' => now()->subHours(2), 'discount_ends_at' => now()->subHour()]);
        $ordinary = $this->product('Ordinary Price Product', 'none', 0, 200, 3);
        $outOfStock = $this->product('Out Of Stock Discount Product', 'percentage', 20, 200, 0);
        $draft = $this->product('Draft Discount Product', 'percentage', 20, 200, 3, 'DRAFT');

        $this->actingAs($buyer)
            ->get(route('buyer.discounts'))
            ->assertOk()
            ->assertSee($discounted->name)
            ->assertSee($scheduledActive->name)
            ->assertSee('₱175.00')
            ->assertSee('₱200.00')
            ->assertDontSee($ordinary->name)
            ->assertDontSee($scheduledFuture->name)
            ->assertDontSee($expired->name)
            ->assertDontSee($outOfStock->name)
            ->assertDontSee($draft->name)
            ->assertSee(route('buyer.products'), escape: false);
    }

    public function test_active_platform_voucher_does_not_create_a_product_sale_countdown(): void
    {
        $buyer = $this->seededBuyer();
        Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'ACTIVE10',
            'code' => 'ACTIVE10',
            'name' => 'Platform voucher campaign',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'usage_limit' => 10,
            'per_user_limit' => 5,
            'campaign_budget_total' => 500,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
            'is_active' => true,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.home'))
            ->assertOk()
            ->assertDontSee('data-flash-countdown')
            ->assertSee('Platform Events')
            ->assertSee('Platform voucher campaign')
            ->assertSee('10%')
            ->assertSee('No minimum spend')
            ->assertSee('No discount cap');
    }

    public function test_public_seller_voucher_is_visible_on_home_store_catalog_and_product_details(): void
    {
        $buyer = $this->seededBuyer();
        $product = $this->product('Voucher card visibility product', 'percentage', 0, 200, 5);
        Voucher::query()->create([
            'seller_profile_id' => $product->seller_profile_id,
            'code' => 'CARDVOUCHER10',
            'name' => 'Public card voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 150,
            'visibility' => 'PUBLIC',
            'applies_to' => 'STORE',
            'is_active' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('10% OFF voucher')
            ->assertSee('Min ₱150.00');

        $this->get(route('buyer.shop', ['seller' => $product->seller_profile_id]))
            ->assertOk()
            ->assertSee('Collect a store voucher')
            ->assertSee('Public card voucher')
            ->assertSee('10% off');

        $this->get(route('buyer.products'))
            ->assertOk()
            ->assertSee('Voucher card visibility product')
            ->assertSee('10% OFF voucher');

        $this->get(route('buyer.product-details', ['slug' => $product->slug]))
            ->assertOk()
            ->assertSee('Store vouchers for this product')
            ->assertSee('Public card voucher')
            ->assertSee('Collect voucher');

        $this->get(route('products.show', ['slug' => $product->slug]))
            ->assertOk()
            ->assertSee('Seller vouchers for this product')
            ->assertSee('Sign in to collect');
    }

    public function test_flash_deals_listing_paginates(): void
    {
        $buyer = $this->seededBuyer();
        for ($index = 1; $index <= 25; $index++) {
            $this->product('Paged Discount Product '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'percentage', 10, 100, 1);
        }

        $this->actingAs($buyer)
            ->get(route('buyer.discounts', ['page' => 2]))
            ->assertOk()
            ->assertSee('Paged Discount Product 01')
            ->assertDontSee('Paged Discount Product 02');
    }

    private function seededBuyer(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::query()->where('email', 'buyer@likhae.com')->firstOrFail();
    }

    private function product(
        string $name,
        string $discountType,
        float $discountValue,
        float $price,
        int $stock,
        string $status = 'ACTIVE',
    ): Product {
        $seller = SellerProfile::query()->where('status', 'ACTIVE')->firstOrFail();
        $category = Category::query()->firstOrFail();
        $product = Product::query()->create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => 'A buyer catalog test product.',
            'status' => $status,
            'published_at' => $status === 'ACTIVE' ? now() : null,
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'TEST-'.Str::upper(Str::random(10)),
            'price' => $price,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'payment_method' => 'cod_online',
            'stock' => $stock,
            'is_default' => true,
            'is_active' => true,
        ]);

        return $product;
    }
}
