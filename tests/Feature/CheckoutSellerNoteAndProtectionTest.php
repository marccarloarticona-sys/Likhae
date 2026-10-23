<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\BuyerVoucherClaim;
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
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutSellerNoteAndProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.psgc.local_first' => false, 'services.psgc.token' => 'checkout-test-token']);
        Http::fake([
            'https://classification.psa.gov.ph/psgc/*' => Http::response([
                ['code' => 'B-NOTE', 'name' => 'Note Barangay'],
            ]),
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
            ->assertSee('E-Wallet')
            ->assertSee('Coming Soon')
            ->assertSee('0 / 200')
            ->assertSee('Delivery fee: &#8369;50.00 per seller parcel', false)
            ->assertSee('one seller shipment')
            ->assertSee('100 points =')
            ->assertSee('Line total');
        $checkoutDocument = new \DOMDocument;
        @$checkoutDocument->loadHTML($checkoutResponse->getContent());
        $checkoutXpath = new \DOMXPath($checkoutDocument);

        $this->assertSame(2, $checkoutXpath->query('//section[@data-seller-card]')->length);
        $this->assertSame(2, $checkoutXpath->query('//section[@data-seller-card]//article')->length);
        $rewardPointsInput = $checkoutXpath->query('//input[@id="checkout-reward-points"]')->item(0);
        $this->assertNotNull($rewardPointsInput);
        $this->assertSame('100', $rewardPointsInput->getAttribute('min'));
        $this->assertSame('100', $rewardPointsInput->getAttribute('step'));
        $codInput = $checkoutXpath->query('//input[@name="payment_method" and @value="COD"]')->item(0);
        $walletInput = $checkoutXpath->query('//input[@type="radio" and @disabled]')->item(0);
        $this->assertNotNull($codInput);
        $this->assertTrue($codInput->hasAttribute('required'));
        $this->assertNotNull($walletInput);
        $this->assertFalse($walletInput->hasAttribute('name'));

        $fixture['itemA']->productVariant()->update(['stock' => 0]);
        $unavailableCart = $this->actingAs($fixture['buyer'])
            ->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee('Unavailable');
        $unavailableDocument = new \DOMDocument;
        @$unavailableDocument->loadHTML($unavailableCart->getContent());
        $unavailableXpath = new \DOMXPath($unavailableDocument);
        $checkoutButton = $unavailableXpath->query('//button[@data-place-order]')->item(0);

        $this->assertNotNull($checkoutButton);
        $this->assertFalse($checkoutButton->hasAttribute('disabled'), 'Available cart items may still proceed without the unavailable item.');
    }

    public function test_preview_keeps_voucher_and_one_shipping_fee_scoped_to_each_seller_group(): void
    {
        $fixture = $this->checkoutFixture();
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'SELLERA10',
            'name' => 'Seller A ten percent off',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create([
            'buyer_user_id' => $fixture['buyer']->id,
            'voucher_id' => $voucher->id,
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

    public function test_seller_platform_and_shipping_vouchers_stack_with_separate_order_accounting(): void
    {
        $fixture = $this->checkoutFixture();
        $sellerVoucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'SELLERA10',
            'name' => 'Seller A ten off',
            'discount_type' => 'FIXED',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'voucher_id' => $sellerVoucher->id]);
        $platformVoucher = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'PLATFORM10',
            'benefit_scope' => 'PRODUCT',
            'code' => 'PLATFORM10',
            'name' => 'Platform ten percent',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'maximum_discount_amount' => 20,
            'is_active' => true,
        ]);
        $shippingVoucher = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'SHIP30',
            'benefit_scope' => 'SHIPPING',
            'code' => 'SHIP30',
            'name' => 'Shipping thirty off',
            'discount_type' => 'FIXED',
            'discount_value' => 30,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        $selection = [
            'seller' => [$fixture['sellerA']->id => 'SELLERA10'],
            'platform' => 'PLATFORM10',
            'shipping' => 'SHIP30',
        ];

        $preview = app(CheckoutService::class)->preview(
            collect([$fixture['itemA'], $fixture['itemB']]),
            $selection,
            $fixture['buyer'],
        );

        $this->assertEquals(10.00, $preview['groups'][$fixture['sellerA']->id]['seller_voucher_discount']);
        $this->assertEquals(6.21, $preview['groups'][$fixture['sellerA']->id]['platform_voucher_discount']);
        $this->assertEquals(13.79, $preview['groups'][$fixture['sellerB']->id]['platform_voucher_discount']);
        $this->assertEquals(15.00, $preview['groups'][$fixture['sellerA']->id]['shipping_voucher_discount']);
        $this->assertEquals(15.00, $preview['groups'][$fixture['sellerB']->id]['shipping_voucher_discount']);
        $this->assertEquals(60.00, $preview['total_discount']);
        $this->assertEquals(340.00, $preview['grand_total']);

        $order = app(CheckoutService::class)->placeOrder(
            $fixture['buyer'],
            $fixture['address'],
            [$fixture['itemA']->id, $fixture['itemB']->id],
            'COD',
            $selection,
            null,
            [],
            [],
            '00000000-0000-4000-8000-000000000061',
        );

        $this->assertSame('PLATFORM10', $order->platformVoucher?->code);
        $this->assertSame('SHIP30', $order->shippingVoucher?->code);
        $this->assertEquals(20.00, $order->platform_voucher_discount);
        $this->assertEquals(30.00, $order->shipping_voucher_discount);
        $this->assertEquals(60.00, $order->discount_total);
        $this->assertEquals(340.00, $order->grand_total);
        $this->assertSame($sellerVoucher->id, (int) $order->sellerOrders()->where('seller_profile_id', $fixture['sellerA']->id)->value('voucher_id'));
        $this->assertSame(1, \App\Services\Marketplace\VoucherRedemptionCounter::count($sellerVoucher->id));
        $this->assertSame(1, \App\Services\Marketplace\VoucherRedemptionCounter::count($platformVoucher->id));
        $this->assertSame(1, \App\Services\Marketplace\VoucherRedemptionCounter::count($shippingVoucher->id));

        $order->update(['status' => 'CANCELLED']);
        $order->sellerOrders()->update(['status' => 'CANCELLED']);
        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count($sellerVoucher->id));
        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count($platformVoucher->id));
        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count($shippingVoucher->id));
    }

    public function test_voucher_scope_and_minimum_spend_are_validated_server_side(): void
    {
        $fixture = $this->checkoutFixture();
        $sellerVoucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'MINIMUM200',
            'name' => 'Minimum spend voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 200,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'voucher_id' => $sellerVoucher->id]);
        Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'WRONGSCOPE',
            'benefit_scope' => 'PRODUCT',
            'code' => 'WRONGSCOPE',
            'name' => 'Shipping only',
            'discount_type' => 'FIXED',
            'discount_value' => 20,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        try {
            app(CheckoutService::class)->preview(collect([$fixture['itemA']]), [
                'seller' => [$fixture['sellerA']->id => 'MINIMUM200'],
            ], $fixture['buyer']);
            $this->fail('Seller voucher minimum spend must be enforced.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('seller_voucher_codes.'.$fixture['sellerA']->id, $exception->errors());
        }

        try {
            app(CheckoutService::class)->preview(collect([$fixture['itemA']]), [
                'shipping' => 'WRONGSCOPE',
            ], $fixture['buyer']);
            $this->fail('A shipping voucher must not be accepted in the wrong category.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('shipping_voucher_code', $exception->errors());
        }
    }

    public function test_stacked_fixed_vouchers_are_capped_at_eligible_amounts_and_never_make_total_negative(): void
    {
        $fixture = $this->checkoutFixture();
        Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'PLATFORM999',
            'benefit_scope' => 'PRODUCT',
            'code' => 'PLATFORM999',
            'name' => 'Large product discount',
            'discount_type' => 'FIXED',
            'discount_value' => 999,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'SHIP999',
            'benefit_scope' => 'SHIPPING',
            'code' => 'SHIP999',
            'name' => 'Large shipping discount',
            'discount_type' => 'FIXED',
            'discount_value' => 999,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $preview = app(CheckoutService::class)->preview(collect([$fixture['itemA'], $fixture['itemB']]), [
            'platform' => 'PLATFORM999',
            'shipping' => 'SHIP999',
        ], $fixture['buyer']);

        $this->assertEquals(300.00, $preview['platform_voucher_discount']);
        $this->assertEquals(100.00, $preview['shipping_voucher_discount']);
        $this->assertEquals(0.00, $preview['grand_total']);
        $this->assertGreaterThanOrEqual(0, $preview['grand_total']);
    }

    public function test_reward_points_require_100_minimum_and_each_100_points_discount_one_peso(): void
    {
        $fixture = $this->checkoutFixture();
        foreach ([1, 2] as $index) {
            Order::query()->create([
                'order_number' => 'LK-REWARD-'.$index,
                'buyer_user_id' => $fixture['buyer']->id,
                'status' => 'COMPLETED',
                'currency' => 'PHP',
                'subtotal' => 100,
                'discount_total' => 0,
                'shipping_total' => 0,
                'grand_total' => 100,
                'payment_status' => 'PAID',
                'placed_at' => now()->subDays(3 - $index),
                'completed_at' => now()->subDays(2 - $index),
            ]);
        }

        $checkout = app(CheckoutService::class);
        $this->assertSame(100, app(\App\Services\Marketplace\RewardPointsService::class)->balance($fixture['buyer']));

        try {
            $checkout->preview(collect([$fixture['itemA']]), [], $fixture['buyer'], rewardPointsToRedeem: 50);
            $this->fail('Redemption below 100 points must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('reward_points', $exception->errors());
        }

        $preview = $checkout->preview(collect([$fixture['itemA']]), [], $fixture['buyer'], rewardPointsToRedeem: 100);
        $this->assertSame(100, $preview['reward_points_redeemed']);
        $this->assertEquals(1.00, $preview['reward_points_discount']);
        $this->assertEquals(149.00, $preview['grand_total']);

        $order = $checkout->placeOrder(
            $fixture['buyer'],
            $fixture['address'],
            [$fixture['itemA']->id],
            'COD',
            [],
            null,
            [],
            [],
            '00000000-0000-4000-8000-000000000031',
            null,
            100,
        );

        $sellerOrder = $order->sellerOrders()->sole();
        $this->assertSame(100, (int) $order->reward_points_redeemed);
        $this->assertEquals(1.00, $order->reward_points_discount);
        $this->assertEquals(1.00, $order->discount_total);
        $this->assertEquals(149.00, $order->grand_total);
        $this->assertSame(100, (int) $sellerOrder->reward_points_redeemed);
        $this->assertEquals(1.00, $sellerOrder->reward_points_discount);
        $this->assertSame(0, app(\App\Services\Marketplace\RewardPointsService::class)->balance($fixture['buyer']));
    }

    public function test_collected_seller_voucher_redeems_once_on_order_and_claim_survives_cancellation(): void
    {
        $fixture = $this->checkoutFixture();
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'SELLERCLAIMONCE',
            'name' => 'Collected seller voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'usage_limit' => 1,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);
        $checkoutKey = 'c62bb9fa-7850-4f1d-a740-863a6cf14988';
        $checkout = app(CheckoutService::class);
        try {
            $checkout->placeOrder(
                $fixture['buyer'],
                $fixture['address'],
                [$fixture['itemA']->id],
                'COD',
                $voucher->code,
                null,
                [],
                [],
                $checkoutKey,
            );
            $this->fail('Checkout must reject an uncollected Seller voucher.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('Collect this Seller voucher', (string) $exception->errors()['voucher_code'][0]);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count((int) $voucher->id));

        BuyerVoucherClaim::query()->create([
            'buyer_user_id' => $fixture['buyer']->id,
            'voucher_id' => $voucher->id,
        ]);

        $order = $checkout->placeOrder(
            $fixture['buyer'],
            $fixture['address'],
            [$fixture['itemA']->id],
            'COD',
            $voucher->code,
            null,
            [],
            [],
            $checkoutKey,
        );
        $replay = $checkout->placeOrder(
            $fixture['buyer'],
            $fixture['address'],
            [$fixture['itemA']->id],
            'COD',
            $voucher->code,
            null,
            [],
            [],
            $checkoutKey,
        );

        $this->assertSame($order->id, $replay->id);
        $this->assertSame(1, SellerOrder::query()->where('voucher_id', $voucher->id)->count());
        $this->assertSame(1, \App\Services\Marketplace\VoucherRedemptionCounter::count((int) $voucher->id, (int) $fixture['buyer']->id));

        $this->actingAs($fixture['buyer'])
            ->postJson(route('buyer.orders.cancel', $order), ['reason' => 'ORDERED_BY_MISTAKE'])
            ->assertOk();

        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count((int) $voucher->id, (int) $fixture['buyer']->id));
        $this->assertDatabaseHas('buyer_voucher_claims', [
            'buyer_user_id' => $fixture['buyer']->id,
            'voucher_id' => $voucher->id,
        ]);
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
            ->assertSee('&#8369;3.00 per unit', false);

        $response = $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]])
            ->post(route('buyer.order.store'), [
                'checkout_attempt_key' => '00000000-0000-4000-8000-000000000010',
                'cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id],
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

    public function test_checkout_rejects_notes_for_other_sellers_and_notes_over_200_characters(): void
    {
        $fixture = $this->checkoutFixture();
        $session = ['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]];
        $payload = [
            'checkout_attempt_key' => '00000000-0000-4000-8000-000000000011',
            'cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id],
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
            ->post(route('buyer.order.store'), $payload + ['seller_notes' => [$fixture['sellerA']->id => str_repeat('x', 201)]])
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHasErrors('seller_notes.'.$fixture['sellerA']->id);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_accepts_a_200_character_seller_note(): void
    {
        $fixture = $this->checkoutFixture();
        $note = str_repeat('x', 200);

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$fixture['itemA']->id]])
            ->post(route('buyer.order.store'), [
                'checkout_attempt_key' => '00000000-0000-4000-8000-000000000021',
                'cart_item_ids' => [$fixture['itemA']->id],
                'address_id' => $fixture['address']->id,
                'payment_method' => 'COD',
                'seller_notes' => [$fixture['sellerA']->id => $note],
            ])
            ->assertRedirect();

        $this->assertSame($note, SellerOrder::query()->sole()->buyer_note);
    }

    public function test_unserviceable_address_disables_checkout_and_is_rejected_before_order_creation(): void
    {
        $fixture = $this->checkoutFixture();
        $fixture['address']->update(['municipality_code' => 'M-OTHER']);
        $session = ['checkout_cart_item_ids' => [$fixture['itemA']->id]];

        $unserviceableCheckout = $this->actingAs($fixture['buyer'])
            ->withSession($session)
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('Delivery is not available for this address yet');
        $document = new \DOMDocument;
        @$document->loadHTML($unserviceableCheckout->getContent());
        $xpath = new \DOMXPath($document);
        $placeOrderButton = $xpath->query('//button[@data-place-order-submit]')->item(0);
        $this->assertNotNull($placeOrderButton);
        $this->assertTrue($placeOrderButton->hasAttribute('disabled'));

        $response = $this->actingAs($fixture['buyer'])
            ->from(route('buyer.checkout'))
            ->withSession($session)
            ->post(route('buyer.order.store'), [
                'checkout_attempt_key' => '00000000-0000-4000-8000-000000000022',
                'cart_item_ids' => [$fixture['itemA']->id],
                'address_id' => $fixture['address']->id,
                'payment_method' => 'COD',
            ])
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHasErrors('address_id');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, (int) $fixture['itemA']->productVariant()->value('stock'));
    }

    public function test_price_change_requires_a_second_checkout_submission(): void
    {
        $fixture = $this->checkoutFixture();
        $session = ['checkout_cart_item_ids' => [$fixture['itemA']->id]];
        $checkout = $this->actingAs($fixture['buyer'])
            ->withSession($session)
            ->get(route('buyer.checkout'))
            ->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($checkout->getContent());
        $xpath = new \DOMXPath($document);
        $attemptKey = $xpath->query('//input[@name="checkout_attempt_key"]')->item(0)?->getAttribute('value');
        $this->assertNotEmpty($attemptKey);

        $fixture['itemA']->productVariant()->update(['price' => 125]);
        $payload = [
            'checkout_attempt_key' => $attemptKey,
            'cart_item_ids' => [$fixture['itemA']->id],
            'address_id' => $fixture['address']->id,
            'payment_method' => 'COD',
        ];

        $this->actingAs($fixture['buyer'])
            ->from(route('buyer.checkout'))
            ->post(route('buyer.order.store'), $payload)
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHasErrors('price_changed');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, (int) $fixture['itemA']->productVariant()->value('stock'));

        $this->actingAs($fixture['buyer'])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('updated prices and total are shown below');

        $this->actingAs($fixture['buyer'])
            ->post(route('buyer.order.store'), $payload)
            ->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_a_checkout_without_notes_saves_null_for_each_seller_order(): void
    {
        $fixture = $this->checkoutFixture();

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id]])
            ->post(route('buyer.order.store'), [
                'checkout_attempt_key' => '00000000-0000-4000-8000-000000000012',
                'cart_item_ids' => [$fixture['itemA']->id, $fixture['itemB']->id],
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

    public function test_place_order_with_multiple_matching_hubs_creates_an_unrouted_parcel_for_manual_review(): void
    {
        $fixture = $this->checkoutFixture();
        $secondCenter = $fixture['center']->replicate();
        $secondCenter->owner_user_id = User::factory()->create(['account_type' => User::TYPE_LOGISTICS])->id;
        $secondCenter->code = 'NOTE-CENTER-SECOND';
        $secondCenter->save();
        $secondArea = ServiceArea::create([
            'logistics_center_id' => $secondCenter->id,
            'code' => 'NOTE-AREA-SECOND',
            'name' => 'Second Note Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $secondArea->id,
            'province_code' => 'P-NOTE',
            'province_name' => 'Note Province',
            'municipality_code' => 'M-NOTE',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => 'B-NOTE',
            'barangay_name' => 'Note Barangay',
        ]);
        $itemIds = [$fixture['itemA']->id];

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => $itemIds])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('Delivery details may need confirmation after your order is placed.')
            ->assertSee('Place Order');

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => $itemIds])
            ->post(route('buyer.order.store'), [
                'checkout_attempt_key' => '00000000-0000-4000-8000-000000000016',
                'cart_item_ids' => $itemIds,
                'address_id' => $fixture['address']->id,
                'payment_method' => 'COD',
            ])
            ->assertRedirect();

        $order = Order::query()->with('sellerOrders.shipments.events')->sole();
        $shipment = $order->sellerOrders->sole()->shipments->sole();
        $this->assertNull($shipment->logistics_center_id);
        $this->assertNull($shipment->service_area_id);
        $this->assertSame('PLACED', $shipment->current_status);
        $this->assertStringContainsString('ROUTING_REVIEW_REQUIRED', (string) $shipment->events->first()->notes);
    }

    public function test_browser_checkout_replay_returns_the_same_order_without_repeating_financial_or_stock_effects(): void
    {
        $fixture = $this->checkoutFixture();
        $fixture['itemA']->update(['quantity' => 2]);
        $fixture['itemB']->update(['quantity' => 2]);
        $itemIds = [$fixture['itemA']->id, $fixture['itemB']->id];
        $attemptKey = '00000000-0000-4000-8000-000000000013';
        $payload = [
            'checkout_attempt_key' => $attemptKey,
            'cart_item_ids' => $itemIds,
            'address_id' => $fixture['address']->id,
            'payment_method' => 'COD',
        ];

        $firstResponse = $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => $itemIds])
            ->post(route('buyer.order.store'), $payload)
            ->assertRedirect();
        $order = Order::query()->sole();

        $this->actingAs($fixture['buyer'])
            ->post(route('buyer.order.store'), $payload)
            ->assertRedirect(route('buyer.orders.success', ['order' => $order->order_number]));

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('seller_orders', 2);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('shipments', 2);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cod_collections', 2);
        $this->assertSame(3, (int) $fixture['itemA']->productVariant()->value('stock'));
        $this->assertSame(3, (int) $fixture['itemB']->productVariant()->value('stock'));
        $this->assertSame(route('buyer.orders.success', ['order' => $order->order_number]), $firstResponse->headers->get('Location'));
    }

    public function test_one_seller_order_keeps_each_distinct_product_line_in_its_own_parcel_and_waybill(): void
    {
        $fixture = $this->checkoutFixture();
        $fixture['itemA']->update(['quantity' => 2]);
        $secondProduct = Product::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'category_id' => $fixture['subcategory']->id,
            'name' => 'Second seller parcel product',
            'slug' => 'second-seller-parcel-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $secondVariant = ProductVariant::query()->create([
            'product_id' => $secondProduct->id,
            'sku' => 'SKU-A-SECOND',
            'price' => 50,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $secondItem = CartItem::query()->create([
            'cart_id' => $fixture['itemA']->cart_id,
            'product_variant_id' => $secondVariant->id,
            'quantity' => 2,
        ]);
        $itemIds = [$fixture['itemA']->id, $secondItem->id];

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => $itemIds])
            ->post(route('buyer.order.store'), [
                'checkout_attempt_key' => '00000000-0000-4000-8000-000000000015',
                'cart_item_ids' => $itemIds,
                'address_id' => $fixture['address']->id,
                'payment_method' => 'COD',
            ])
            ->assertRedirect();

        $sellerOrder = SellerOrder::query()
            ->where('seller_profile_id', $fixture['sellerA']->id)
            ->with('items.shipment')
            ->sole();
        $this->assertCount(2, $sellerOrder->items);
        $this->assertSame([2, 2], $sellerOrder->items->pluck('quantity')->sort()->values()->all());
        $shipments = $sellerOrder->items->map->shipment;
        $this->assertCount(2, $shipments);
        $this->assertSame(2, $shipments->pluck('tracking_number')->unique()->count());
        $this->assertSame(2, $shipments->pluck('order_item_id')->unique()->count());

        $workflow = app(ShipmentWorkflowService::class);
        $workflow->sellerTransition($sellerOrder, 'confirm', $fixture['sellerUserA']);
        $workflow->sellerTransition($sellerOrder, 'prepare', $fixture['sellerUserA']);
        $workflow->sellerTransition($sellerOrder, 'pack', $fixture['sellerUserA']);
        $workflow->sellerTransition($sellerOrder, 'ready', $fixture['sellerUserA']);

        $this->assertDatabaseCount('waybills', 2);
        $this->assertSame(2, $shipments->sum(fn ($shipment) => $shipment->fresh()->waybills()->count()));
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('shipments', 2);
    }

    public function test_another_buyer_cannot_reuse_a_checkout_attempt_key(): void
    {
        $fixture = $this->checkoutFixture();
        $itemIds = [$fixture['itemA']->id, $fixture['itemB']->id];
        $payload = [
            'checkout_attempt_key' => '00000000-0000-4000-8000-000000000014',
            'cart_item_ids' => $itemIds,
            'address_id' => $fixture['address']->id,
            'payment_method' => 'COD',
        ];

        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => $itemIds])
            ->post(route('buyer.order.store'), $payload)
            ->assertRedirect();

        $otherBuyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($otherBuyer)
            ->from(route('buyer.checkout'))
            ->post(route('buyer.order.store'), $payload)
            ->assertRedirect(route('buyer.checkout'))
            ->assertSessionHasErrors('checkout_attempt_key');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cod_collections', 2);
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
            'region_name' => 'NCR',
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
            'region_name' => 'NCR',
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
            'region_name' => 'NCR',
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
