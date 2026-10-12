<?php

namespace Tests\Feature;

use App\Models\Buyer\CartItem;
use App\Models\Seller\Product;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerPurchaseActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_add_to_cart_form_persists_the_product(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();
        $catalogUrl = route('buyer.products');
        $response = $this->actingAs($buyer)->get($catalogUrl)->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@action="'.route('buyer.cart.add', $product).'"]')->item(0);
        $this->assertNotNull($form, 'The catalog must submit an add-to-cart form.');
        $this->assertSame('POST', $form->getAttribute('method'));
        $fields = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $this->from($catalogUrl)->post($form->getAttribute('action'), $fields)
            ->assertRedirect($catalogUrl)
            ->assertSessionHas('buyer_notice', 'Product added to cart.')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cart_items', [
            'product_variant_id' => $variantId,
            'quantity' => 1,
        ]);
        $this->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('/storage/products/sample-tote.jpg', escape: false);
    }

    public function test_add_to_cart_stays_in_the_shopping_flow_without_starting_checkout(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();

        $this->actingAs($buyer)
            ->from(route('buyer.product-details', $product->slug))
            ->post(route('buyer.cart.add', $product), [
                'product_variant_id' => $variantId,
                'quantity' => 2,
            ])
            ->assertRedirect(route('buyer.product-details', $product->slug))
            ->assertSessionMissing('checkout_cart_item_ids');

        $this->assertDatabaseHas('cart_items', [
            'product_variant_id' => $variantId,
            'quantity' => 2,
        ]);
    }

    public function test_ajax_add_to_cart_returns_confirmation_without_redirecting(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();

        $this->actingAs($buyer)
            ->postJson(route('buyer.cart.add', $product), [
                'product_variant_id' => $variantId,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Product added to cart.',
            ])
            ->assertSessionMissing('checkout_cart_item_ids');
    }

    public function test_buy_now_opens_checkout_for_only_the_purchased_item(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();

        $response = $this->actingAs($buyer)->post(route('buyer.cart.add', $product), [
            'product_variant_id' => $variantId,
            'quantity' => 1,
            'checkout' => 1,
        ]);

        $item = CartItem::query()->where('product_variant_id', $variantId)->sole();

        $response
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHas('checkout_cart_item_ids', [$item->id]);
    }

    public function test_checkout_uses_the_items_selected_from_the_cart(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();
        $item = app(CartService::class)->add($buyer, $product, $variantId, 1);

        $this->actingAs($buyer)
            ->post(route('buyer.checkout.post'), ['cart_item_ids' => [$item->id]])
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHas('checkout_cart_item_ids', [$item->id]);
    }

    public function test_cart_lists_available_vouchers_and_forwards_a_manual_code(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();
        $item = app(CartService::class)->add($buyer, $product, $variantId, 1);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $product->seller_profile_id,
            'code' => 'SAVE10',
            'name' => 'Save ten percent',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee($voucher->code)
            ->assertSee('data-use-voucher', escape: false);

        $this->post(route('buyer.checkout.post'), [
            'cart_item_ids' => [$item->id],
            'voucher_codes' => [$product->seller_profile_id => 'save10'],
        ])->assertRedirect(route('buyer.checkout'))
            ->assertSessionHas('checkout_voucher_codes', [
                $product->seller_profile_id => 'SAVE10',
            ]);
    }

    /** @return array{User, Product, int} */
    private function purchaseFixture(): array
    {
        $this->seed(DatabaseSeeder::class);

        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->with('variants')->where('slug', 'sample-handmade-tote')->firstOrFail();

        return [$buyer, $product, (int) $product->variants->firstOrFail()->id];
    }
}
