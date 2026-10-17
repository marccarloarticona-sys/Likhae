<?php

namespace Tests\Feature;

use App\Models\Buyer\CartItem;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
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

    public function test_product_purchase_requires_a_variation_and_rejects_a_foreign_variant(): void
    {
        [$buyer, $product] = $this->purchaseFixture();

        $this->actingAs($buyer)
            ->postJson(route('buyer.cart.add', $product), ['quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id')
            ->assertJsonPath('errors.product_variant_id.0', 'Please select a variation.');

        $this->postJson(route('buyer.buy-now', $product), ['quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id')
            ->assertJsonPath('errors.product_variant_id.0', 'Please select a variation.');

        $foreignVariant = ProductVariant::query()
            ->where('product_id', '<>', $product->id)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->firstOrFail();

        $this->postJson(route('buyer.cart.add', $product), [
            'product_variant_id' => $foreignVariant->id,
            'quantity' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id')
            ->assertJsonPath('errors.product_variant_id.0', 'The selected variation is invalid or unavailable.');
    }

    public function test_server_rejects_out_of_stock_variants_and_quantities_above_stock(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();
        $variant = ProductVariant::query()->findOrFail($variantId);
        $variant->update(['stock' => 0]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.cart.add', $product), [
                'product_variant_id' => $variantId,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id')
            ->assertJsonPath('errors.product_variant_id.0', 'This variation is out of stock.');

        $variant->update(['stock' => 2]);
        $this->postJson(route('buyer.buy-now', $product), [
            'product_variant_id' => $variantId,
            'quantity' => 3,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity')
            ->assertJsonPath('errors.quantity.0', 'Requested quantity exceeds available stock.');

        $this->assertDatabaseMissing('cart_items', ['product_variant_id' => $variantId]);
    }

    public function test_buy_now_selects_only_the_requested_line_and_preserves_other_cart_items(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();
        $otherVariant = ProductVariant::query()
            ->where('product_id', '<>', $product->id)
            ->where('is_active', true)
            ->where('stock', '>', 1)
            ->firstOrFail();
        $otherProduct = Product::query()->findOrFail($otherVariant->product_id);
        $unrelatedItem = app(CartService::class)->add($buyer, $otherProduct, (int) $otherVariant->id, 1);

        $response = $this->actingAs($buyer)->post(route('buyer.buy-now', $product), [
            'product_variant_id' => $variantId,
            'quantity' => 2,
        ]);
        $selectedItem = CartItem::query()->where('product_variant_id', $variantId)->sole();

        $response
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHas('checkout_cart_item_ids', [$selectedItem->id]);
        $this->assertSame(2, (int) $selectedItem->quantity);
        $this->assertDatabaseHas('cart_items', ['id' => $unrelatedItem->id, 'quantity' => 1]);
        $this->assertNotSame($unrelatedItem->id, $selectedItem->id);
    }

    public function test_vacation_mode_blocks_direct_add_to_cart_and_buy_now_requests(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();
        $product->sellerProfile->update(['vacation_mode' => true]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.cart.add', $product), [
                'product_variant_id' => $variantId,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');

        $this->postJson(route('buyer.buy-now', $product), [
            'product_variant_id' => $variantId,
            'quantity' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');
    }

    public function test_inactive_or_deleted_products_cannot_be_purchased(): void
    {
        [$buyer, $product, $variantId] = $this->purchaseFixture();
        $product->update(['status' => 'DRAFT']);

        $this->actingAs($buyer)
            ->postJson(route('buyer.cart.add', $product), [
                'product_variant_id' => $variantId,
                'quantity' => 1,
            ])
            ->assertNotFound();

        $product->update(['status' => 'ACTIVE']);
        $product->delete();
        $this->postJson(route('buyer.buy-now', $product->id), [
            'product_variant_id' => $variantId,
            'quantity' => 1,
        ])->assertNotFound();
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
