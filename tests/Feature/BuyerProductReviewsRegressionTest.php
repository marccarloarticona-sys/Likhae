<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Seller\Product;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerProductReviewsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ratings_and_reviews_render_alongside_unchanged_product_purchase_details(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->firstOrFail();
        $product = Product::query()->with('variants')->where('slug', 'sample-handmade-tote')->firstOrFail();
        $seller = $product->sellerProfile;
        $variant = $product->variants->firstOrFail();

        foreach ([5, 5, 4, 4, 3, 5] as $index => $rating) {
            $order = Order::query()->create([
                'order_number' => 'PDP-REVIEW-'.Str::uuid(),
                'buyer_user_id' => $buyer->id,
                'status' => 'COMPLETED',
                'subtotal' => 500,
                'grand_total' => 500,
                'payment_status' => 'PAID',
            ]);
            $sellerOrder = SellerOrder::query()->create([
                'seller_order_number' => 'PDP-REVIEW-SELLER-'.Str::uuid(),
                'order_id' => $order->id,
                'seller_profile_id' => $seller->id,
                'status' => 'COMPLETED',
                'item_subtotal' => 500,
                'grand_total' => 500,
            ]);
            $item = OrderItem::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name' => $product->name,
                'sku' => $variant->sku,
                'variant_description' => 'Review variation '.$index,
                'unit_price' => 500,
                'quantity' => 1,
                'discount_amount' => 0,
                'line_total' => 500,
            ]);
            Review::query()->create([
                'order_item_id' => $item->id,
                'buyer_user_id' => $buyer->id,
                'rating' => $rating,
                'comment' => 'Published product review '.$index,
                'status' => 'PUBLISHED',
            ]);
        }

        $response = $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('data-test-add-cart', escape: false)
            ->assertSee('data-test-buy-now', escape: false)
            ->assertSee('id="reviews"', escape: false)
            ->assertSee('Ratings & Reviews', escape: false)
            ->assertSee('4.3')
            ->assertSee('6 verified ratings')
            ->assertSee('Published product review 0')
            ->assertSee('data-review-rating', escape: false)
            ->assertSee('View all 6 reviews');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $reviewItems = $xpath->query('//article[@data-review-item]');
        $this->assertSame(6, $reviewItems->length);
        $this->assertFalse($reviewItems->item(4)->hasAttribute('hidden'));
        $this->assertTrue($reviewItems->item(5)->hasAttribute('hidden'));
        $this->assertSame(1, $xpath->query('//button[@data-test-add-cart]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-test-buy-now]')->length);
    }
}
