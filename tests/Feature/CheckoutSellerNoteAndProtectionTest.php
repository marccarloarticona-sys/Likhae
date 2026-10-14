<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Protection\FragileProtectionRule;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutSellerNoteAndProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.psgc.local_first' => false, 'services.psgc.token' => null]);
        Http::fake([
            'https://psgc.cloud/api/v2/*' => Http::response([
                ['code' => 'B-NOTE', 'name' => 'Note Barangay'],
            ]),
        ]);
    }

    public function test_cart_and_checkout_present_products_in_separate_seller_groups(): void
    {
        $fixture = $this->checkoutFixture();

        $cartResponse = $this->actingAs($fixture['buyer'])
            ->get(route('buyer.cart'))
            ->assertOk();
        $cartDocument = new \DOMDocument;
        @$cartDocument->loadHTML($cartResponse->getContent());
        $cartXpath = new \DOMXPath($cartDocument);
        $sellerGroups = $cartXpath->query('//section[@data-seller-group]');

        $this->assertSame(2, $sellerGroups->length);
        $this->assertSame(2, $cartXpath->query('//input[@data-seller-select]')->length);
        $this->assertSame(2, $cartXpath->query('//a[normalize-space(.)="View Shop"]')->length);

        foreach ([
            [$fixture['sellerA'], $fixture['itemA'], '100.00'],
            [$fixture['sellerB'], $fixture['itemB'], '200.00'],
        ] as [$seller, $item, $subtotal]) {
            $sellerGroup = $cartXpath->query('//section[@data-seller-group and @data-seller-id="'.$seller->id.'"]')->item(0);

            $this->assertNotNull($sellerGroup);
            $sellerCartItems = $cartXpath->query('.//article[@data-cart-item]', $sellerGroup);
            $this->assertSame(1, $sellerCartItems->length);
            $this->assertSame((string) $item->id, $sellerCartItems->item(0)->getAttribute('data-item-id'));
            $this->assertStringContainsString($subtotal, $sellerGroup->textContent);
        }

        $checkoutResponse = $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('one seller shipment')
            ->assertSee('Line total');
        $checkoutDocument = new \DOMDocument;
        @$checkoutDocument->loadHTML($checkoutResponse->getContent());
        $checkoutXpath = new \DOMXPath($checkoutDocument);

        $this->assertSame(2, $checkoutXpath->query('//section[@data-seller-card]')->length);
        $this->assertSame(2, $checkoutXpath->query('//section[@data-seller-card]//article')->length);

        $fixture['itemA']->productVariant()->update(['stock' => 0]);
        $unavailableCart = $this->actingAs($fixture['buyer'])
            ->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee('Only 0 available.');
        $unavailableDocument = new \DOMDocument;
        @$unavailableDocument->loadHTML($unavailableCart->getContent());
        $unavailableXpath = new \DOMXPath($unavailableDocument);
        $checkoutButton = $unavailableXpath->query('//button[@data-place-order]')->item(0);

        $this->assertNotNull($checkoutButton);
        $this->assertTrue($checkoutButton->hasAttribute('disabled'));
    }

    public function test_preview_keeps_voucher_and_one_shipping_fee_scoped_to_each_seller_group(): void
    {
        $fixture = $this->checkoutFixture();
        Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'SELLERA10',
            'name' => 'Seller A ten percent off',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $preview = app(CheckoutService::class)->preview(
            collect([$fixture['itemA'], $fixture['itemB']]),
            'SELLERA10',
            $fixture['buyer'],
        );
        $sellerAGroup = $preview['groups'][$fixture['sellerA']->id];
        $sellerBGroup = $preview['groups'][$fixture['sellerB']->id];

        $this->assertEquals(100.00, $sellerAGroup['item_subtotal']);
        $this->assertEquals(10.00, $sellerAGroup['voucher_discount']);
        $this->assertSame('SELLERA10', $sellerAGroup['voucher']->code);
        $this->assertEquals(200.00, $sellerBGroup['item_subtotal']);
        $this->assertEquals(0.00, $sellerBGroup['voucher_discount']);
        $this->assertNull($sellerBGroup['voucher']);
        $this->assertEquals(50.00, $sellerAGroup['shipping_fee']);
        $this->assertEquals(50.00, $sellerBGroup['shipping_fee']);
        $this->assertEquals(100.00, $preview['shipping_total']);
    }

    public function test_checkout_renders_a_blocked_notice_when_a_cart_product_has_no_seller(): void
    {
        $fixture = $this->checkoutFixture();
        $item = $fixture['itemA'];
        $variant = $item->productVariant()->with('product')->firstOrFail();
        $product = $variant->product;
        $product->setRelation('sellerProfile', null);
        $variant->setRelation('product', $product);
        $item->setRelation('productVariant', $variant);

        $cartService = \Mockery::mock(CartService::class);
        $cartService->shouldReceive('selectedItems')
            ->once()
            ->andReturn(collect([$item]));
        $this->app->instance(CartService::class, $cartService);

        $response = $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$item->id]])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('no longer available')
            ->assertSee('Remove the unavailable item from your cart');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $placeOrderButton = $xpath->query('//button[@data-place-order-submit]')->item(0);

        $this->assertNotNull($placeOrderButton);
        $this->assertTrue($placeOrderButton->hasAttribute('disabled'));
    }

    public function test_checkout_keeps_notes_per_seller_and_charges_the_most_specific_selected_care_rule(): void
    {
        $fixture = $this->checkoutFixture();
        $categoryRule = $this->rule(['category_id' => $fixture['category']->id, 'percentage_rate' => 1]);
        $subcategoryRule = $this->rule(['subcategory_id' => $fixture['subcategory']->id, 'percentage_rate' => 2]);
        $productRule = $this->rule(['product_id' => $fixture['productA']->id, 'percentage_rate' => 3]);

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('Add Product Protection')
            ->assertSee('PHP 3.00 per unit');

        $response = $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]])
            ->post(route('buyer.order.store'), [
                'address_id' => $fixture['address']->id,
                'payment_method' => 'COD',
                'protection_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id],
                'seller_notes' => [
                    $fixture['sellerA']->id => '<script>alert(\'x\')</script> pack sealed',
                    $fixture['sellerB']->id => ' Separate Seller B instruction 42 ',
                ],
            ]);

        $response->assertRedirect();
        $order = Order::query()->latest('id')->with(['sellerOrders.items', 'payments'])->firstOrFail();
        $sellerOrderA = $order->sellerOrders->firstWhere('seller_profile_id', $fixture['sellerA']->id);
        $sellerOrderB = $order->sellerOrders->firstWhere('seller_profile_id', $fixture['sellerB']->id);

        $this->assertSame('<script>alert(\'x\')</script> pack sealed', $sellerOrderA->buyer_note);
        $this->assertSame('Separate Seller B instruction 42', $sellerOrderB->buyer_note);
        $this->assertEquals(3.00, (float) $sellerOrderA->protection_total);
        $this->assertEquals(4.00, (float) $sellerOrderB->protection_total);
        $this->assertEquals(7.00, (float) $order->protection_total);
        $this->assertEquals(300.00, (float) $order->subtotal);
        $this->assertEquals(100.00, (float) $order->shipping_total);
        $this->assertEquals(407.00, (float) $order->grand_total);
        $this->assertEquals(407.00, (float) $order->payments->first()->amount);

        $itemA = $sellerOrderA->items->first();
        $itemB = $sellerOrderB->items->first();
        $this->assertTrue($itemA->protection_selected);
        $this->assertTrue($itemA->protection_handling_flag);
        $this->assertSame($productRule->id, $itemA->protection_rule_id);
        $this->assertSame($subcategoryRule->id, $itemB->protection_rule_id);
        $this->assertSame($productRule->id, $itemA->protection_rule_snapshot['id']);
        $this->assertDatabaseCount('protection_selection_audits', 2);
        $this->assertNotSame($categoryRule->id, $itemB->protection_rule_id);

        $sellerAResponse = $this->actingAs($fixture['sellerUserA'])
            ->get(route('seller.orders', ['mode' => 'show', 'order' => $sellerOrderA->seller_order_number]));
        $sellerAResponse->assertOk()
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; pack sealed', false)
            ->assertDontSee('Separate Seller B instruction 42', false);

        $sellerBResponse = $this->actingAs($fixture['sellerUserB'])
            ->get(route('seller.orders', ['mode' => 'show', 'order' => $sellerOrderB->seller_order_number]));
        $sellerBResponse->assertOk()->assertSee('Separate Seller B instruction 42');
    }

    public function test_checkout_rejects_notes_for_other_sellers_and_notes_over_500_characters(): void
    {
        $fixture = $this->checkoutFixture();
        $session = ['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]];
        $payload = [
            'address_id' => $fixture['address']->id,
            'payment_method' => 'COD',
        ];

        $this->actingAs($fixture['buyer'])
            ->from(route('buyer.checkout'))
            ->withSession($session)
            ->post(route('buyer.order.store'), $payload + ['seller_notes' => [999999 => 'Wrong seller']])
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHasErrors('seller_notes');

        $this->actingAs($fixture['buyer'])
            ->from(route('buyer.checkout'))
            ->withSession($session)
            ->post(route('buyer.order.store'), $payload + ['seller_notes' => [$fixture['sellerA']->id => str_repeat('x', 501)]])
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHasErrors('seller_notes.'.$fixture['sellerA']->id);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_checkout_without_notes_saves_null_for_each_seller_order(): void
    {
        $fixture = $this->checkoutFixture();

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]])
            ->post(route('buyer.order.store'), [
                'address_id' => $fixture['address']->id,
                'payment_method' => 'COD',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('seller_orders', 2);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('shipments', 2);
        $this->assertDatabaseMissing('seller_orders', ['buyer_note' => '']);
        $this->assertSame([null, null], SellerOrder::query()->orderBy('seller_profile_id')->pluck('buyer_note')->all());

        $sellerOrderA = SellerOrder::query()->where('seller_profile_id', $fixture['sellerA']->id)->with('items')->sole();
        $sellerOrderB = SellerOrder::query()->where('seller_profile_id', $fixture['sellerB']->id)->with('items')->sole();
        $this->assertSame([$fixture['productA']->id], $sellerOrderA->items->pluck('product_id')->all());
        $this->assertSame([$fixture['productB']->id], $sellerOrderB->items->pluck('product_id')->all());
    }

    public function test_inactive_and_out_of_date_rules_are_not_eligible(): void
    {
        $fixture = $this->checkoutFixture();
        $rule = $this->rule([
            'subcategory_id' => $fixture['subcategory']->id,
            'percentage_rate' => 2,
            'effective_from' => now()->addDay(),
        ]);

        $quote = app(\App\Services\Protection\FragileProtectionRuleService::class)->quoteForCartItem($fixture['itemA']);
        $this->assertNull($quote);

        $rule->update(['effective_from' => now()->subDay(), 'active' => false]);
        $this->assertNull(app(\App\Services\Protection\FragileProtectionRuleService::class)->quoteForCartItem($fixture['itemA']));
    }

    public function test_admin_cannot_save_ambiguous_targets_or_zero_rate_rules(): void
    {
        $admin = User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Admin Category', 'slug' => 'admin-category', 'is_active' => true]);
        $subcategory = Category::create([
            'parent_id' => $category->id,
            'name' => 'Admin Subcategory',
            'slug' => 'admin-subcategory',
            'is_active' => true,
        ]);
        $base = [
            'risk_level' => 'LOW',
            'calculation_type' => 'PERCENTAGE',
            'scope' => FragileProtectionRule::SCOPE_PER_UNIT,
            'percentage_rate' => 1,
            'active' => '1',
        ];

        $this->actingAs($admin)
            ->from(route('admin.protection-rules'))
            ->post(route('admin.protection-rules.store'), $base + [
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
            ])
            ->assertRedirect(route('admin.protection-rules'))
            ->assertSessionHasErrors('category_id');

        $this->actingAs($admin)
            ->from(route('admin.protection-rules'))
            ->post(route('admin.protection-rules.store'), array_merge($base, [
                'category_id' => $category->id,
                'percentage_rate' => 0,
            ]))
            ->assertRedirect(route('admin.protection-rules'))
            ->assertSessionHasErrors('percentage_rate');

        $this->actingAs($admin)
            ->from(route('admin.protection-rules'))
            ->post(route('admin.protection-rules.store'), array_merge($base, [
                'subcategory_id' => $subcategory->id,
            ]))
            ->assertRedirect(route('admin.protection-rules'));

        $this->assertDatabaseCount('fragile_protection_rules', 1);
        $this->assertDatabaseHas('fragile_protection_rules', [
            'subcategory_id' => $subcategory->id,
            'percentage_rate' => '1.0000',
        ]);
    }

    /** @return array<string, mixed> */
    private function checkoutFixture(): array
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS, 'status' => User::STATUS_ACTIVE]);
        $centerAddress = Address::create([
            'user_id' => $centerUser->id,
            'label' => 'Center',
            'recipient_name' => 'Note Center',
            'contact_number' => '09170000000',
            'province_code' => 'P-NOTE',
            'province_name' => 'Note Province',
            'municipality_code' => 'M-NOTE',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => 'B-NOTE',
            'barangay_name' => 'Note Barangay',
            'street_address' => '2 Test Street',
            'latitude' => '14.5995000',
            'longitude' => '120.9842000',
            'is_default' => true,
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'approved_by_user_id' => $buyer->id,
            'approved_at' => now(),
            'address_id' => $centerAddress->id,
            'code' => 'NOTE-CENTER',
            'business_name' => 'Note Center',
            'status' => 'ACTIVE',
            'shipping_fee' => 50,
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'NOTE-AREA',
            'name' => 'Note Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => 'P-NOTE',
            'province_name' => 'Note Province',
            'municipality_code' => 'M-NOTE',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => 'B-NOTE',
            'barangay_name' => 'Note Barangay',
        ]);

        $category = Category::create(['name' => 'Protected Goods', 'slug' => 'protected-goods', 'is_active' => true]);
        $subcategory = Category::create([
            'parent_id' => $category->id,
            'name' => 'Delicate Goods',
            'slug' => 'delicate-goods',
            'is_active' => true,
        ]);
        $cart = Cart::create(['buyer_user_id' => $buyer->id, 'status' => Cart::STATUS_ACTIVE]);

        [$sellerUserA, $sellerA, $productA, $itemA] = $this->sellerItem($cart, $category, $subcategory, 'A', 100);
        [$sellerUserB, $sellerB, $productB, $itemB] = $this->sellerItem($cart, $category, $subcategory, 'B', 200);

        $address = Address::create([
            'user_id' => $buyer->id,
            'label' => 'Home',
            'recipient_name' => $buyer->name,
            'contact_number' => $buyer->contact_number,
            'province_code' => 'P-NOTE',
            'province_name' => 'Note Province',
            'municipality_code' => 'M-NOTE',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => 'B-NOTE',
            'barangay_name' => 'Note Barangay',
            'street_address' => '1 Test Street',
            'latitude' => '14.5995000',
            'longitude' => '120.9842000',
            'is_default' => true,
        ]);

        return compact('buyer', 'center', 'address', 'category', 'subcategory', 'sellerUserA', 'sellerA', 'productA', 'itemA', 'sellerUserB', 'sellerB', 'productB', 'itemB');
    }

    /** @return array{User, SellerProfile, Product, CartItem} */
    private function sellerItem(Cart $cart, Category $category, Category $subcategory, string $suffix, float $price): array
    {
        $sellerUser = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $businessAddress = Address::create([
            'user_id' => $sellerUser->id,
            'label' => 'Business',
            'recipient_name' => 'Seller '.$suffix,
            'contact_number' => '09170000000',
            'province_code' => 'P-NOTE',
            'province_name' => 'Note Province',
            'municipality_code' => 'M-NOTE',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => 'B-NOTE',
            'barangay_name' => 'Note Barangay',
            'street_address' => '3 Test Street',
            'latitude' => '14.5995000',
            'longitude' => '120.9842000',
            'is_default' => true,
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $businessAddress->id,
            'business_name' => 'Seller '.$suffix,
            'status' => 'ACTIVE',
        ]);
        $product = Product::create([
            'seller_profile_id' => $seller->id,
            'category_id' => $subcategory->id,
            'name' => 'Product '.$suffix,
            'slug' => 'product-'.$suffix,
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.$suffix,
            'price' => $price,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $item = CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        return [$sellerUser, $seller, $product, $item];
    }

    /** @param array<string, mixed> $attributes */
    private function rule(array $attributes): FragileProtectionRule
    {
        return FragileProtectionRule::query()->create($attributes + [
            'risk_level' => 'MEDIUM',
            'calculation_type' => 'PERCENTAGE',
            'scope' => FragileProtectionRule::SCOPE_PER_UNIT,
            'active' => true,
        ]);
    }
}
