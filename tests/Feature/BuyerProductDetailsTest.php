<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
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
            ->assertSee(route('buyer.cart.add', $product), escape: false);
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

        $primaryUrl = "/storage/sellers/1/products/{$product->id}/gallery-10.jpg";

        $response->assertSee('src="'.$primaryUrl.'" alt="'.$product->name.'" data-gallery-main', escape: false);
        $response->assertDontSee('127.0.0.1:8000');
        $this->assertSame(10, substr_count($response->getContent(), 'data-gallery-thumb'));
    }
}
