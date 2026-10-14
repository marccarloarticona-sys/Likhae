<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerCartStoreGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_groups_cart_items_by_seller_id_and_shows_store_counts_and_subtotals(): void
    {
        $this->withoutVite();
        [$buyer, $cart, $category] = $this->cartBase();
        $sellerA = $this->seller($category, 'LIKHAE Sample Shop', 'A');
        $sellerB = $this->seller($category, 'Backstreet Club', 'B');
        $this->cartItem($cart, $category, $sellerA, 'A-TABLE', 'Conference Table OZ-ML31-32', 24995.00);
        $this->cartItem($cart, $category, $sellerB, 'B-SHORTS-L', "Men's Active Sports Shorts Black / L", 899.75);
        $this->cartItem($cart, $category, $sellerB, 'B-SHORTS-XL', "Men's Active Sports Shorts Black / XL", 899.75);

        $response = $this->actingAs($buyer)->get(route('buyer.cart'))->assertOk();
        $content = $response->getContent();

        $this->assertSame(2, substr_count($content, 'data-seller-group data-seller-id='));
        $this->assertStringContainsString('data-seller-group data-seller-id="'.$sellerA->id.'"', $content);
        $this->assertStringContainsString('data-seller-group data-seller-id="'.$sellerB->id.'"', $content);
        $this->assertStringContainsString('Select all items from LIKHAE Sample Shop', $content);
        $this->assertStringContainsString('Select all items from Backstreet Club', $content);
        $this->assertSame(1, substr_count($content, '<span class="min-w-0 text-sm font-bold uppercase tracking-wide text-stone-900">Backstreet Club</span>'));
        $this->assertSame(2, preg_match_all('/<article[^>]*data-cart-item[^>]*data-seller-id="'.$sellerB->id.'"/s', $content));
        preg_match_all('/<span[^>]*data-seller-count[^>]*>(.*?)<\/span>/s', $content, $countMatches);
        $storeCounts = array_map(fn ($count) => trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($count)))), $countMatches[1]);
        $this->assertContains('2 items', $storeCounts, 'Rendered store counts: '.json_encode($storeCounts));
        preg_match_all('/<strong[^>]*data-seller-subtotal[^>]*>(.*?)<\/strong>/s', $content, $subtotalMatches);
        $storeSubtotals = array_map(fn ($subtotal) => trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($subtotal)))), $subtotalMatches[1]);
        $this->assertContains('₱1,799.50', $storeSubtotals);
        $this->assertStringContainsString('Store subtotal', $content);
        $this->assertStringContainsString('Order Summary', $content);
        $this->assertStringContainsString('Selected items', $content);
        $this->assertStringContainsString('data-summary-shipping', $content);
        $this->assertStringContainsString('data-summary-discount', $content);
        $this->assertStringContainsString('data-summary-total', $content);
        $this->assertStringContainsString('data-selected-count>', $content);
        $this->assertStringContainsString("input.name = 'cart_item_ids[]';", $content);
        $this->assertStringContainsString('selector.indeterminate = selectedItems.length > 0', $content);
    }

    public function test_sellers_with_duplicate_business_names_still_get_distinct_store_groups(): void
    {
        $this->withoutVite();
        [$buyer, $cart, $category] = $this->cartBase();
        $sellerA = $this->seller($category, 'Shared Store Name', 'C');
        $sellerB = $this->seller($category, 'Shared Store Name', 'D');
        $this->cartItem($cart, $category, $sellerA, 'C-PRODUCT', 'Product C', 100);
        $this->cartItem($cart, $category, $sellerB, 'D-PRODUCT', 'Product D', 200);

        $content = $this->actingAs($buyer)->get(route('buyer.cart'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, 'data-seller-group data-seller-id='));
        $this->assertStringContainsString('data-seller-group data-seller-id="'.$sellerA->id.'"', $content);
        $this->assertStringContainsString('data-seller-group data-seller-id="'.$sellerB->id.'"', $content);
    }

    /** @return array{User, Cart, Category} */
    private function cartBase(): array
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $category = Category::create([
            'name' => 'Cart Grouping',
            'slug' => 'cart-grouping',
            'is_active' => true,
        ]);
        $cart = Cart::create([
            'buyer_user_id' => $buyer->id,
            'status' => Cart::STATUS_ACTIVE,
        ]);

        return [$buyer, $cart, $category];
    }

    private function seller(Category $category, string $businessName, string $suffix): SellerProfile
    {
        $user = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'Business',
            'recipient_name' => $businessName,
            'contact_number' => '09170000000',
            'province_code' => 'P-CART',
            'province_name' => 'Cart Province',
            'municipality_code' => 'M-CART',
            'municipality_name' => 'Cart Municipality',
            'barangay_code' => 'B-CART',
            'barangay_name' => 'Cart Barangay',
            'street_address' => '1 Store Street',
        ]);

        return SellerProfile::create([
            'user_id' => $user->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $address->id,
            'business_name' => $businessName,
            'status' => 'ACTIVE',
        ]);
    }

    private function cartItem(
        Cart $cart,
        Category $category,
        SellerProfile $seller,
        string $sku,
        string $name,
        float $price,
    ): CartItem {
        $product = Product::create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => strtolower($sku),
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'price' => $price,
            'stock' => 10,
            'is_default' => true,
            'is_active' => true,
        ]);

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);
    }
}
