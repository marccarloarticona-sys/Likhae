<?php

namespace Tests\Feature;

use App\Models\Buyer\WishlistItem;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerWishlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_wishlist_state_is_database_backed_and_repeated_updates_are_idempotent(): void
    {
        [$buyer, $product] = $this->wishlistFixture();
        $url = route('buyer.wishlist.toggle', ['product' => $product->id]);

        $this->actingAs($buyer)
            ->postJson($url, ['saved' => true])
            ->assertOk()
            ->assertJsonPath('saved', true);
        $this->postJson($url, ['saved' => true])->assertOk()->assertJsonPath('saved', true);
        $this->postJson($url, ['saved' => true])->assertOk()->assertJsonPath('saved', true);

        $this->assertSame(1, WishlistItem::query()
            ->where('user_id', $buyer->id)
            ->where('product_id', $product->id)
            ->count());

        $this->get(route('buyer.products'))
            ->assertOk()
            ->assertSee('data-product-id="'.$product->id.'"', escape: false)
            ->assertSee('aria-pressed="true"', escape: false)
            ->assertSee('aria-label="Remove '.$product->name.' from wishlist"', escape: false);

        $this->get(route('buyer.wishlist'))
            ->assertOk()
            ->assertSee('aria-pressed="true"', escape: false);

        $this->get(route('buyer.product-details', ['slug' => $product->slug]))
            ->assertOk()
            ->assertSee('aria-pressed="true"', escape: false)
            ->assertSee('Remove from Wishlist');

        $this->postJson($url, ['saved' => false])->assertOk()->assertJsonPath('saved', false);
        $this->postJson($url, ['saved' => false])->assertOk()->assertJsonPath('saved', false);
        $this->assertDatabaseMissing('wishlist_items', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);

        $this->get(route('buyer.products'))
            ->assertOk()
            ->assertSee('aria-pressed="false"', escape: false)
            ->assertSee('aria-label="Save '.$product->name.' to wishlist"', escape: false);
    }

    public function test_guest_requires_login_and_another_buyer_cannot_remove_saved_item(): void
    {
        [$owner, $product] = $this->wishlistFixture();
        $url = route('buyer.wishlist.toggle', ['product' => $product->id]);

        $this->post($url, ['saved' => true])->assertRedirect(route('login'));
        $this->actingAs($owner)->postJson($url, ['saved' => true])->assertOk();

        $otherBuyer = User::factory()->create();
        $this->actingAs($otherBuyer)
            ->postJson($url, ['saved' => false])
            ->assertOk()
            ->assertJsonPath('saved', false);

        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $owner->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseMissing('wishlist_items', [
            'user_id' => $otherBuyer->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_soft_deleted_saved_product_is_safe_to_display_and_remove(): void
    {
        [$buyer, $product] = $this->wishlistFixture();
        WishlistItem::query()->create(['user_id' => $buyer->id, 'product_id' => $product->id]);
        $product->delete();

        $this->actingAs($buyer)
            ->get(route('buyer.wishlist'))
            ->assertOk()
            ->assertSee('Unavailable', escape: false)
            ->assertSee('aria-pressed="true"', escape: false)
            ->assertDontSee($product->name)
            ->assertDontSee($product->sellerProfile->business_name);

        $this->postJson(route('buyer.wishlist.toggle', ['product' => $product->id]), ['saved' => false])
            ->assertOk()
            ->assertJsonPath('saved', false);

        $this->assertDatabaseMissing('wishlist_items', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_out_of_stock_wishlist_product_is_marked_unavailable_and_cannot_be_added(): void
    {
        [$buyer, $product, $variant] = $this->wishlistFixture();
        $variant->update(['stock' => 0]);
        WishlistItem::query()->create(['user_id' => $buyer->id, 'product_id' => $product->id]);

        $this->actingAs($buyer)
            ->get(route('buyer.wishlist'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('Unavailable', escape: false)
            ->assertSee('disabled', escape: false)
            ->assertDontSee('data-cart-add-form', escape: false);

        $this->postJson(route('buyer.cart.add', ['product' => $product->id]), [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('product_variant_id');
    }

    public function test_available_wishlist_product_uses_the_existing_cart_endpoint(): void
    {
        [$buyer, $product, $variant] = $this->wishlistFixture();
        WishlistItem::query()->create(['user_id' => $buyer->id, 'product_id' => $product->id]);

        $this->actingAs($buyer)
            ->from(route('buyer.wishlist'))
            ->get(route('buyer.wishlist'))
            ->assertOk()
            ->assertSee('action="'.route('buyer.cart.add', ['product' => $product->id]).'"', escape: false);

        $this->from(route('buyer.wishlist'))
            ->post(route('buyer.cart.add', ['product' => $product->id]), [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('buyer.wishlist'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cart_items', [
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);
    }

    /** @return array{User, Product, ProductVariant} */
    private function wishlistFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()
            ->with(['variants', 'sellerProfile'])
            ->where('slug', 'sample-handmade-tote')
            ->firstOrFail();

        return [$buyer, $product, $product->variants->firstOrFail()];
    }
}
