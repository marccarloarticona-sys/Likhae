<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerPriceRangeSliderTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_dual_slider_uses_selling_price_domain_and_keeps_precise_inputs(): void
    {
        [$buyer, $seller, $otherSeller, $category] = $this->sliderFixture();
        $this->createProduct($otherSeller, $category, 'High catalog range product', 100_000_000, 'fixed', 1_000_000);

        $catalogResponse = $this->actingAs($buyer)
            ->get(route('buyer.products', ['min_price' => '25.75', 'max_price' => '50.25', 'rating' => '4']))
            ->assertOk()
            ->assertSee('id="catalogMinSlider"', escape: false)
            ->assertSee('id="catalogMaxSlider"', escape: false)
            ->assertSee('data-range-available="true"', escape: false)
            ->assertSee('type="hidden" name="min_price"', escape: false)
            ->assertSee('type="hidden" name="max_price"', escape: false)
            ->assertSee('value="25.75"', escape: false)
            ->assertSee('value="50.25"', escape: false);

        $catalogRange = $catalogResponse->viewData('catalogPriceRange');
        $this->assertGreaterThanOrEqual(99_000_000, $catalogRange['max']);
        $this->assertGreaterThan(1, $catalogRange['step']);

        $this->get(route('buyer.products', ['min_price' => '300', 'max_price' => '250']))
            ->assertOk()
            ->assertSee('Maximum price must be greater than or equal to minimum price.')
            ->assertSee('value="300"', escape: false)
            ->assertSee('value="250"', escape: false);
    }

    public function test_store_dual_slider_bounds_are_scoped_to_the_viewed_seller(): void
    {
        [$buyer, $seller, $otherSeller, $category] = $this->sliderFixture();
        $sellerProduct = $this->createProduct($seller, $category, 'Seller scoped range product', 80_000, 'fixed', 1_000);
        $otherSellerProduct = $this->createProduct($otherSeller, $category, 'Other seller very expensive product', 100_000_000, 'none', 0);

        $storeResponse = $this->actingAs($buyer)
            ->get(route('buyer.shop', ['seller' => $seller->id]))
            ->assertOk()
            ->assertSee('id="storeMinSlider"', escape: false)
            ->assertSee('id="storeMaxSlider"', escape: false)
            ->assertSee('type="hidden" name="min_price"', escape: false)
            ->assertSee('type="hidden" name="max_price"', escape: false)
            ->assertSee($sellerProduct->name)
            ->assertDontSee($otherSellerProduct->name);

        $storeRange = $storeResponse->viewData('storePriceRange');
        $this->assertLessThan(100_000_000, $storeRange['max']);
        $this->assertLessThanOrEqual(79_000, $storeRange['min']);
        $this->assertGreaterThanOrEqual(79_000, $storeRange['max']);
    }

    /** @return array{User, SellerProfile, SellerProfile, Category} */
    private function sliderFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $sellers = SellerProfile::query()->where('status', 'ACTIVE')->orderBy('id')->take(2)->get();

        return [$buyer, $sellers[0], $sellers[1], Category::query()->firstOrFail()];
    }

    private function createProduct(
        SellerProfile $seller,
        Category $category,
        string $name,
        float $price,
        string $discountType,
        float $discountValue,
    ): Product {
        $product = Product::query()->create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => 'Buyer price slider fixture.',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'RANGE-'.Str::upper(Str::random(10)),
            'price' => $price,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);

        return $product;
    }
}
