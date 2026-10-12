<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminOperationsController;
use App\Models\Admin\SellerComplianceAction;
use App\Models\Buyer\Address;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminProductsRemainingIssuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_checkout_rejects_a_suspended_product_and_preserves_the_cart(): void
    {
        [$buyer, $address, $item, $product] = $this->checkoutFixture();
        $product->update(['status' => 'SUSPENDED']);

        try {
            app(CheckoutService::class)->placeOrder(
                $buyer,
                $address,
                app(CartService::class)->selectedItems($buyer, [$item->id]),
                'COD',
            );
            $this->fail('A suspended product must not be ordered.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no longer available', (string) $exception->errors()['cart'][0]);
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
    }

    public function test_active_product_remains_purchasable_through_the_shared_checkout_service(): void
    {
        [$buyer, $address, $item] = $this->checkoutFixture();

        $order = app(CheckoutService::class)->placeOrder(
            $buyer,
            $address,
            app(CartService::class)->selectedItems($buyer, [$item->id]),
            'COD',
        );

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'buyer_user_id' => $buyer->id]);
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_api_checkout_rejects_the_same_suspended_product_rule(): void
    {
        [$buyer, $address, $item, $product] = $this->checkoutFixture();
        $product->update(['status' => 'SUSPENDED']);
        $plainToken = Str::random(40);
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $plainToken),
            'api_token_expires_at' => now()->addHour(),
        ])->save();

        $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$plainToken,
        ])->postJson(route('api.v1.buyer.checkout.store'), [
            'cart_item_ids' => [$item->id],
            'address_id' => $address->id,
            'payment_method' => 'COD',
        ])->assertStatus(422)->assertJsonValidationErrors('cart');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_primary_and_active_subcategories_are_permitted_but_unrelated_categories_are_not(): void
    {
        $primary = (new Category)->setAttribute('id', 10)->setAttribute('is_active', true);
        $child = (new Category)->setAttribute('id', 11)->setAttribute('parent_id', 10)->setAttribute('is_active', true);
        $unrelated = (new Category)->setAttribute('id', 12)->setAttribute('is_active', true);
        $primary->setRelation('children', collect([$child]));
        $seller = (new SellerProfile)->setRelation('primaryCategory', $primary);
        $controller = new AdminOperationsController;
        $method = new \ReflectionMethod($controller, 'productCategoryMismatch');
        $method->setAccessible(true);

        foreach ([$primary, $child] as $allowed) {
            $product = (new Product)->setAttribute('category_id', $allowed->id)->setRelation('sellerProfile', $seller);
            $this->assertFalse($method->invoke($controller, $product));
        }

        $product = (new Product)->setAttribute('category_id', $unrelated->id)->setRelation('sellerProfile', $seller);
        $this->assertTrue($method->invoke($controller, $product));
    }

    public function test_seller_cannot_republish_or_toggle_an_admin_suspended_product(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $product->update(['status' => 'SUSPENDED']);

        $this->actingAs($seller)->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => $product->name,
            'category_id' => $product->category_id,
            'listing_status' => 'ACTIVE',
            'price' => 350,
            'stock' => 20,
        ])->assertRedirect();

        $this->assertSame('SUSPENDED', $product->fresh()->status);
        $this->actingAs($seller)->patch(route('seller.products.toggle', $product))->assertForbidden();
        $this->assertSame('SUSPENDED', $product->fresh()->status);
    }

    public function test_admin_product_search_and_status_filter_are_combined(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();

        $this->actingAs($admin)
            ->get(route('admin.products', ['search' => 'Shorts', 'status' => 'ACTIVE']))
            ->assertOk()
            ->assertSee('Lightweight Weekend Shorts')
            ->assertDontSee('Sample Handmade Tote');
    }

    public function test_product_warning_does_not_change_an_active_listing_status(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.products.warn', $product), [
            'category' => 'OTHER',
            'reason' => 'The listing needs additional compliance context.',
            'corrective_action' => 'Add the missing product documentation.',
        ])->assertRedirect();

        $this->assertSame('ACTIVE', $product->fresh()->status);
        $this->assertDatabaseHas('seller_compliance_actions', [
            'action_type' => 'WARNING',
            'performed_by_user_id' => $admin->id,
        ]);
    }

    public function test_filtered_product_pdf_export_keeps_the_active_search_and_status_scope(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();

        $response = $this->actingAs($admin)->get(route('admin.products.export', [
            'search' => 'Shorts',
            'status' => 'ACTIVE',
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    /** @return array{0: User, 1: Address, 2: \App\Models\Buyer\CartItem, 3: Product, 4: ProductVariant} */
    private function checkoutFixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = $product->variants()->where('is_active', true)->firstOrFail();
        $address = $buyer->addresses()->where('label', 'Home')->firstOrFail();
        $address->forceFill([
            'barangay_code' => 'SAN-MIGUEL',
            'barangay_name' => 'San Miguel',
            'latitude' => 14.2400,
            'longitude' => 121.3600,
        ])->save();
        $item = app(CartService::class)->add($buyer, $product, $variant->id, 1);

        return [$buyer, $address->fresh(), $item, $product->fresh(), $variant->fresh()];
    }
}
