<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_open_a_product_details_page(): void
    {
        $this->seed(DatabaseSeeder::class);

        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();

        $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('data-test-add-cart', escape: false)
            ->assertSee('data-test-buy-now', escape: false)
            ->assertSee('data-variation-id="'.$product->variants()->firstOrFail()->id.'"', escape: false)
            ->assertSee(route('buyer.cart.add', $product), escape: false)
            ->assertSee('data-wishlist', escape: false)
            ->assertSee(route('buyer.shop', ['seller' => $product->seller_profile_id]), escape: false)
            ->assertSee('Visit Shop')
            ->assertSee('Share Shop')
            ->assertSee('data-share-shop', escape: false)
            ->assertSee('Message')
            ->assertSee('Description')
            ->assertSee('Ratings & Reviews', escape: false);
    }

    public function test_guest_product_detail_visit_shop_uses_the_public_seller_store_url(): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $shopUrl = route('shops.show', ['seller' => $product->seller_profile_id]);

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee('Visit Shop')
            ->assertSee('Share Shop')
            ->assertSee('href="'.$shopUrl.'"', escape: false)
            ->assertSee('data-shop-url="'.$shopUrl.'"', escape: false);
    }

    public function test_product_details_show_primary_image_first_and_all_product_images(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('filesystems.disks.public.url', 'http://127.0.0.1:8000/storage');

        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();

        for ($index = 1; $index <= 10; $index++) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'file_path' => "sellers/1/products/{$product->id}/gallery-{$index}.jpg",
                'alt_text' => "{$product->name} view {$index}",
                'is_primary' => $index === 10,
                'sort_order' => $index,
            ]);
        }

        $response = $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk();

        $response->assertSee('gallery-10.jpg', escape: false)
            ->assertSee('data-gallery-main', escape: false);
        $response->assertDontSee('127.0.0.1:8000');
        $this->assertSame(10, substr_count($response->getContent(), 'data-gallery-thumb'));
    }

    public function test_buy_now_does_not_preselect_a_variant_when_the_product_has_multiple_variants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->with('variants')->where('slug', 'sample-handmade-tote')->firstOrFail();
        $firstVariant = $product->variants->firstOrFail();
        $product->variants()->create([
            'sku' => 'PDP-MULTI-VARIANT-TEST',
            'price' => (float) $firstVariant->price + 40,
            'stock' => 4,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk()
            ->assertSee('name="product_variant_id" value=""', escape: false);
    }

    public function test_discounted_variant_uses_stored_sale_price_and_rewards_route_without_fake_countdown(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->with('variants')->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = $product->variants->firstOrFail();
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'file_path' => "sellers/1/products/{$product->id}/variant-detail.jpg",
            'alt_text' => 'Selected variant image',
            'is_primary' => false,
            'sort_order' => 99,
        ]);
        $variant->update([
            'price' => 350,
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'product_image_id' => $image->id,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk()
            ->assertSee('₱280.00')
            ->assertSee('₱350.00')
            ->assertSee('data-variation-price="280.00"', escape: false)
            ->assertSee('/storage/sellers/1/products/'.$product->id.'/variant-detail.jpg')
            ->assertSee('20.00% OFF')
            ->assertSee(route('buyer.rewards'), escape: false)
            ->assertDontSee('data-flash-countdown');
    }

    public function test_vacation_mode_is_explained_and_disables_product_detail_purchase_buttons(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $product->sellerProfile->update(['vacation_mode' => true]);

        $response = $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk()
            ->assertSee('data-seller-vacation="1"', escape: false)
            ->assertSee('This seller is currently on vacation and is not accepting purchases.');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $purchaseButtons = $xpath->query('//button[@data-product-purchase]');
        $this->assertSame(2, $purchaseButtons->length);
        foreach ($purchaseButtons as $button) {
            $this->assertTrue($button->hasAttribute('disabled'));
        }
    }
}
