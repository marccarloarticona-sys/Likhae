<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
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
            ->assertSee('Deals For You')
            ->assertSee('Current Deals')
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

    public function test_flash_deals_list_only_visible_discounted_products_with_stock(): void
    {
        $buyer = $this->seededBuyer();
        $discounted = $this->product('Available Discount Product', 'fixed', 25, 200, 3);
        $ordinary = $this->product('Ordinary Price Product', 'none', 0, 200, 3);
        $outOfStock = $this->product('Out Of Stock Discount Product', 'percentage', 20, 200, 0);
        $draft = $this->product('Draft Discount Product', 'percentage', 20, 200, 3, 'DRAFT');

        $this->actingAs($buyer)
            ->get(route('buyer.flash-deals'))
            ->assertOk()
            ->assertSee($discounted->name)
            ->assertSee('₱175.00')
            ->assertSee('₱200.00')
            ->assertDontSee($ordinary->name)
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
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
            'is_active' => true,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.home'))
            ->assertOk()
            ->assertDontSee('data-flash-countdown')
            ->assertSee('Current Discounts');
    }

    public function test_flash_deals_listing_paginates(): void
    {
        $buyer = $this->seededBuyer();
        for ($index = 1; $index <= 25; $index++) {
            $this->product('Paged Discount Product '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'percentage', 10, 100, 1);
        }

        $this->actingAs($buyer)
            ->get(route('buyer.flash-deals', ['page' => 2]))
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
