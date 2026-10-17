<?php

namespace Tests\Feature;

use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\SellerFollower;
use App\Models\Buyer\WishlistItem;
use App\Models\Admin\Notification;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerStoreFollowNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_follow_and_unfollow_a_store_and_the_count_stays_accurate(): void
    {
        [$buyer, $seller] = $this->storeFixture();

        $initialStore = $this->actingAs($buyer)->get(route('buyer.shop', ['seller' => $seller->id]));
        $initialStore->assertOk()
            ->assertSee('data-store-follow', escape: false)
            ->assertSee('Follow')
            ->assertSee('data-store-follower-count>0</dd>', escape: false)
            ->assertSee('followers');

        $this->post(route('buyer.shop.follow', ['seller' => $seller->id]))->assertRedirect();
        $this->post(route('buyer.shop.follow', ['seller' => $seller->id]))->assertRedirect();

        $this->assertSame(1, SellerFollower::query()
            ->where('buyer_user_id', $buyer->id)
            ->where('seller_profile_id', $seller->id)
            ->count());

        $store = $this->get(route('buyer.shop', ['seller' => $seller->id]))
            ->assertOk()
            ->assertSee('Unfollow')
            ->assertSee('data-store-follower-count>1</dd>', escape: false)
            ->assertSee('follower</dt>', escape: false);
        $this->assertSame(1, $store->viewData('seller')->followers);
        $this->assertTrue($store->viewData('seller')->is_following);

        $this->delete(route('buyer.shop.unfollow', ['seller' => $seller->id]))->assertRedirect();
        $this->delete(route('buyer.shop.unfollow', ['seller' => $seller->id]))->assertRedirect();

        $store = $this->get(route('buyer.shop', ['seller' => $seller->id]))
            ->assertOk()
            ->assertSee('Follow')
            ->assertSee('data-store-follower-count>0</dd>', escape: false)
            ->assertSee('followers');
        $this->assertSame(0, $store->viewData('seller')->followers);
        $this->assertFalse($store->viewData('seller')->is_following);
    }

    public function test_guest_cannot_mutate_store_follow_state(): void
    {
        [, $seller] = $this->storeFixture();

        $this->post(route('buyer.shop.follow', ['seller' => $seller->id]))
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('seller_followers', 0);
    }

    public function test_restock_alert_is_sent_only_to_followers_with_a_wishlist_or_active_cart_item(): void
    {
        [$buyer, $seller, $product, $variant] = $this->storeFixtureWithProduct(stock: 0);
        $cartBuyer = User::factory()->create();

        SellerFollower::query()->create([
            'buyer_user_id' => $buyer->id,
            'seller_profile_id' => $seller->id,
        ]);
        SellerFollower::query()->create([
            'buyer_user_id' => $cartBuyer->id,
            'seller_profile_id' => $seller->id,
        ]);
        WishlistItem::query()->create(['user_id' => $buyer->id, 'product_id' => $product->id]);
        $cart = Cart::query()->create(['buyer_user_id' => $cartBuyer->id, 'status' => Cart::STATUS_ACTIVE]);
        CartItem::query()->create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);

        $variant->increment('stock', 5);

        $this->assertSame(1, Notification::query()->where('user_id', $buyer->id)->where('type', 'STORE_RESTOCK')->count());
        $this->assertSame(1, Notification::query()->where('user_id', $cartBuyer->id)->where('type', 'STORE_RESTOCK')->count());
        $this->assertSame(2, Notification::query()->where('type', 'STORE_RESTOCK')->count());
    }

    public function test_unfollowing_suppresses_future_store_notifications(): void
    {
        [$buyer, $seller, , $variant] = $this->storeFixtureWithProduct(stock: 0);
        SellerFollower::query()->create([
            'buyer_user_id' => $buyer->id,
            'seller_profile_id' => $seller->id,
        ]);
        WishlistItem::query()->create(['user_id' => $buyer->id, 'product_id' => $variant->product_id]);

        $this->actingAs($buyer)->delete(route('buyer.shop.unfollow', ['seller' => $seller->id]));
        $variant->update(['stock' => 3]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_persisted_seller_discount_changes_create_one_notification_for_current_followers(): void
    {
        [$buyer, $seller, $product, $variant] = $this->storeFixtureWithProduct(stock: 4);
        SellerFollower::query()->create([
            'buyer_user_id' => $buyer->id,
            'seller_profile_id' => $seller->id,
        ]);

        $variant->update(['discount_type' => 'percentage', 'discount_value' => 15]);

        $notification = Notification::query()->where('user_id', $buyer->id)->where('type', 'STORE_DISCOUNT')->sole();
        $this->assertSame('A store discount is available', $notification->title);
        $this->assertSame('/buyer/products/'.$product->slug, $notification->action_url);
        $this->assertSame(1, Notification::query()->where('type', 'STORE_DISCOUNT')->count());

        $this->actingAs($buyer)
            ->get(route('buyer.notifications.stream'))
            ->assertOk()
            ->assertJsonPath('notifications.0.category', 'stores');
        $this->get(route('buyer.notifications', ['type' => 'stores']))
            ->assertOk()
            ->assertSee('A store discount is available');
    }

    public function test_vacationing_seller_does_not_send_unpurchasable_restock_or_discount_alerts(): void
    {
        [$buyer, $seller, , $variant] = $this->storeFixtureWithProduct(stock: 0);
        SellerFollower::query()->create([
            'buyer_user_id' => $buyer->id,
            'seller_profile_id' => $seller->id,
        ]);
        WishlistItem::query()->create(['user_id' => $buyer->id, 'product_id' => $variant->product_id]);
        $seller->update(['vacation_mode' => true]);

        $variant->update(['stock' => 4]);
        $variant->update(['discount_type' => 'percentage', 'discount_value' => 15]);

        $this->assertDatabaseCount('notifications', 0);
    }

    /** @return array{User, SellerProfile} */
    private function storeFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->firstOrFail();
        $seller = SellerProfile::query()->where('status', 'ACTIVE')->orderBy('id')->firstOrFail();

        return [$buyer, $seller];
    }

    /** @return array{User, SellerProfile, Product, ProductVariant} */
    private function storeFixtureWithProduct(int $stock): array
    {
        [$buyer, $seller] = $this->storeFixture();
        $product = Product::query()->create([
            'seller_profile_id' => $seller->id,
            'category_id' => Category::query()->firstOrFail()->id,
            'name' => 'Follow Notification Sample',
            'slug' => 'follow-notification-'.Str::lower(Str::random(10)),
            'description' => 'Buyer store notification fixture.',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'FOLLOW-'.Str::upper(Str::random(10)),
            'price' => 250,
            'discount_type' => 'none',
            'discount_value' => 0,
            'stock' => $stock,
            'is_default' => true,
            'is_active' => true,
        ]);

        return [$buyer, $seller, $product, $variant];
    }
}
