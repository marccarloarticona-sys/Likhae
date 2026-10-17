<?php

namespace Tests\Feature;

use App\Models\Admin\ProductModerationScan;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use App\Services\Marketplace\BuyerProductAvailabilityService;
use App\Services\Marketplace\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerProductAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_service_distinguishes_listing_seller_variant_and_stock_states(): void
    {
        [$buyer, $product, $variant] = $this->availabilityFixture();
        $service = app(BuyerProductAvailabilityService::class);
        $seller = $product->sellerProfile;
        $sellerUser = $seller->user;

        $this->assertTrue($service->inspect($variant, $product, $seller, $sellerUser)['available']);

        $product->update(['status' => 'SUSPENDED']);
        $this->assertSame('This product is temporarily unavailable.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);

        $product->update(['status' => 'ARCHIVED']);
        $this->assertSame('This product is no longer available.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);

        $product->update(['status' => 'ACTIVE', 'published_at' => now()->addDay()]);
        $this->assertSame('This product is not available yet.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);
        $product->update(['published_at' => now()->subDay()]);

        $seller->update(['vacation_mode' => true]);
        $this->assertSame('This seller is currently on vacation and is not accepting purchases.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);
        $seller->update(['vacation_mode' => false, 'status' => 'SUSPENDED']);
        $this->assertSame('This shop is currently unavailable.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);
        $seller->update(['status' => 'ACTIVE']);

        $variant->update(['is_active' => false]);
        $this->assertSame('This variation is currently unavailable.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);
        $variant->update(['is_active' => true, 'stock' => 0]);
        $this->assertSame('This variation is out of stock.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);
        $variant->update(['stock' => 1]);
        $this->assertSame('Requested quantity exceeds available stock.', $service->inspect($variant, $product, $seller, $sellerUser, 2)['message']);

        $product->delete();
        $this->assertSame('This product is no longer available.', $service->inspect($variant, $product, $seller, $sellerUser)['message']);
    }

    public function test_suspended_moderation_hold_uses_a_safe_message_in_cart_preview_and_checkout(): void
    {
        [$buyer, $product, $variant, $item] = $this->availabilityFixture(withCartItem: true);
        $product->update(['status' => 'SUSPENDED']);
        ProductModerationScan::query()->create([
            'product_id' => $product->id,
            'seller_profile_id' => $product->seller_profile_id,
            'revision_hash' => hash('sha256', 'fixture-moderation-hold'),
            'policy_version' => 'test-policy',
            'scan_status' => 'COMPLETE',
            'decision_status' => 'PENDING',
            'text_findings' => ['PRIVATE_INTERNAL_REVIEW_REASON'],
            'evidence_summary' => 'PRIVATE_INTERNAL_REVIEW_REASON',
            'held_at' => now(),
        ]);

        $cart = $this->actingAs($buyer)
            ->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee('This product is temporarily unavailable.')
            ->assertDontSee('PRIVATE_INTERNAL_REVIEW_REASON');

        $this->postJson(route('buyer.cart.preview'), ['cart_item_ids' => [$item->id]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This product is temporarily unavailable.');

        $this->withSession(['checkout_cart_item_ids' => [$item->id]])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('This product is temporarily unavailable.')
            ->assertSee('Remove the unavailable item from your cart')
            ->assertDontSee('PRIVATE_INTERNAL_REVIEW_REASON');
    }

    public function test_archived_listing_is_blocked_in_cart_and_checkout(): void
    {
        [$buyer, $product, $variant, $item] = $this->availabilityFixture(withCartItem: true);
        $product->update(['status' => 'ARCHIVED']);

        $this->actingAs($buyer)
            ->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee('This product is no longer available.');

        $this->withSession(['checkout_cart_item_ids' => [$item->id]])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('This product is no longer available.')
            ->assertSee('Review cart');
    }

    public function test_vacation_seller_checkout_returns_buyer_to_cart_with_a_clear_notice(): void
    {
        [$buyer, $product, $variant, $item] = $this->availabilityFixture(withCartItem: true);
        $product->sellerProfile->update(['vacation_mode' => true]);

        $this->actingAs($buyer)
            ->withSession(['checkout_cart_item_ids' => [$item->id]])
            ->get(route('buyer.checkout'))
            ->assertRedirect(route('buyer.cart'))
            ->assertSessionHas('buyer_notice', 'A seller in your selected items is on vacation and is not accepting purchases. Remove those items to continue.');

        $this->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee('This seller is currently on vacation and is not accepting purchases.');
    }

    /** @return array{User, Product, ProductVariant, ?\App\Models\Buyer\CartItem} */
    private function availabilityFixture(bool $withCartItem = false): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()
            ->with('sellerProfile.user')
            ->where('slug', 'sample-handmade-tote')
            ->firstOrFail();
        $variant = $product->variants()->where('is_active', true)->firstOrFail();
        $item = $withCartItem
            ? app(CartService::class)->add($buyer, $product, (int) $variant->id, 1)
            : null;

        return [$buyer, $product, $variant, $item];
    }
}
