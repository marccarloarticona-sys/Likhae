<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Protection\FragileProtectionRule;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\BuyerVoucherClaimService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use App\Support\OrderItemParcelAmount;
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
                ['code' => '0401010001', 'name' => 'Note Barangay'],
            ]),
            'https://psgc.cloud/api/v2/*' => Http::response([
                ['code' => '0401010001', 'name' => 'Note Barangay'],
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

    public function test_preview_charges_one_shipping_fee_per_seller_order(): void
    {
        $fixture = $this->checkoutFixture();
        $secondProduct = Product::create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'category_id' => $fixture['subcategory']->id,
            'name' => 'Product A Second Parcel',
            'slug' => 'product-a-second-parcel',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $secondVariant = ProductVariant::create([
            'product_id' => $secondProduct->id,
            'sku' => 'SKU-A-SECOND-PARCEL',
            'price' => 250,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $secondItem = CartItem::create([
            'cart_id' => $fixture['cart']->id,
            'product_variant_id' => $secondVariant->id,
            'quantity' => 1,
        ]);

        $preview = app(CheckoutService::class)->preview(
            collect([$fixture['itemA'], $secondItem, $fixture['itemB']]),
            [],
            $fixture['buyer'],
        );

        $sellerAGroup = $preview['groups'][$fixture['sellerA']->id];
        $sellerBGroup = $preview['groups'][$fixture['sellerB']->id];
        $this->assertEquals(50.00, $sellerAGroup['shipping_fee']);
        $this->assertEquals(50.00, $sellerBGroup['shipping_fee']);
        $this->assertEquals(100.00, $preview['shipping_total']);
        $this->assertSame('50.00', $sellerAGroup['shipping_rate_snapshot']['shipping_fee']);
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
            'campaign_budget_total' => 100000,
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
            'campaign_budget_total' => 100000,
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

        $campaignBudgets = app(\App\Services\Finance\PlatformCampaignBudgetService::class);
        $productBudget = $campaignBudgets->summary($platformVoucher->fresh());
        $shippingBudget = $campaignBudgets->summary($shippingVoucher->fresh());
        $this->assertSame(2000, $productBudget['reserved_cents']);
        $this->assertSame(0, $productBudget['spent_cents'], 'Pending COD must not recognize campaign expense.');
        $this->assertSame(9998000, $productBudget['available_cents']);
        $this->assertSame(3000, $shippingBudget['reserved_cents']);
        $this->assertSame(0, $shippingBudget['spent_cents'], 'Pending COD must not recognize shipping campaign expense.');
        $this->assertSame(9997000, $shippingBudget['available_cents']);

        $order->update(['status' => 'CANCELLED']);
        $order->sellerOrders()->update(['status' => 'CANCELLED']);
        foreach ($order->sellerOrders as $sellerOrder) {
            $campaignBudgets->releaseForSellerOrder($sellerOrder, $fixture['buyer']);
            $campaignBudgets->releaseForSellerOrder($sellerOrder, $fixture['buyer']);
        }
        $this->assertSame(0, $campaignBudgets->summary($platformVoucher->fresh())['reserved_cents']);
        $this->assertSame(0, $campaignBudgets->summary($platformVoucher->fresh())['spent_cents']);
        $this->assertSame(0, $campaignBudgets->summary($shippingVoucher->fresh())['reserved_cents']);
        $this->assertSame(0, $campaignBudgets->summary($shippingVoucher->fresh())['spent_cents']);
        $this->assertSame(2, \App\Models\Finance\FinancialLedgerEntry::query()
            ->where('voucher_id', $platformVoucher->id)
            ->where('entry_type', 'CAMPAIGN_SUBSIDY_RELEASED')
            ->count());
        $this->assertSame(2, \App\Models\Finance\FinancialLedgerEntry::query()
            ->where('voucher_id', $shippingVoucher->id)
            ->where('entry_type', 'CAMPAIGN_SUBSIDY_RELEASED')
            ->count());
        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count($sellerVoucher->id));
        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count($platformVoucher->id));
        $this->assertSame(0, \App\Services\Marketplace\VoucherRedemptionCounter::count($shippingVoucher->id));
    }

    public function test_selected_product_seller_voucher_uses_only_eligible_subtotal_when_stacked(): void
    {
        $fixture = $this->checkoutFixture();
        $otherProduct = Product::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'category_id' => $fixture['subcategory']->id,
            'name' => 'Other Seller A product',
            'slug' => 'other-seller-a-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $otherVariant = ProductVariant::query()->create([
            'product_id' => $otherProduct->id,
            'sku' => 'SKU-A-OTHER',
            'price' => 300,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $otherItem = CartItem::query()->create([
            'cart_id' => $fixture['itemA']->cart_id,
            'product_variant_id' => $otherVariant->id,
            'quantity' => 1,
        ]);
        $sellerVoucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'SELECTEDPRODUCT50',
            'name' => 'Selected product fifty off',
            'discount_type' => 'FIXED',
            'discount_value' => 50,
            'minimum_order_amount' => 101,
            'applies_to' => 'PRODUCTS',
            'is_active' => true,
        ]);
        $sellerVoucher->eligibleProducts()->sync([$fixture['productA']->id]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'voucher_id' => $sellerVoucher->id]);
        $platformVoucher = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'TARGETSTACK10',
            'benefit_scope' => 'PRODUCT',
            'code' => 'TARGETSTACK10',
            'name' => 'Target stack product voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'campaign_budget_total' => 100000,
            'is_active' => true,
        ]);
        $shippingVoucher = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'TARGETSHIP30',
            'benefit_scope' => 'SHIPPING',
            'code' => 'TARGETSHIP30',
            'name' => 'Target stack shipping voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 30,
            'minimum_order_amount' => 0,
            'campaign_budget_total' => 100000,
            'is_active' => true,
        ]);
        $items = collect([$fixture['itemA'], $otherItem, $fixture['itemB']]);
        $checkout = app(CheckoutService::class);
        $selection = [
            'seller' => [$fixture['sellerA']->id => $sellerVoucher->code],
            'platform' => $platformVoucher->code,
            'shipping' => $shippingVoucher->code,
        ];

        $this->assertCount(0, $checkout->availableVouchers(collect([$otherItem]), $fixture['buyer'])->get($fixture['sellerA']->id, collect()));
        $this->assertSame([$sellerVoucher->id], $checkout->availableVouchers(collect([$fixture['itemA'], $otherItem]), $fixture['buyer'])->get($fixture['sellerA']->id, collect())->modelKeys());

        try {
            $checkout->preview($items, $selection, $fixture['buyer']);
            $this->fail('The unrelated product subtotal must not satisfy a selected-product minimum spend.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('more in eligible products', $exception->errors()['seller_voucher_codes.'.$fixture['sellerA']->id][0]);
        }

        $sellerVoucher->update(['minimum_order_amount' => 100]);
        $preview = $checkout->preview($items, $selection, $fixture['buyer']);
        $this->assertEquals(400.00, $preview['groups'][$fixture['sellerA']->id]['item_subtotal']);
        $this->assertEquals(50.00, $preview['groups'][$fixture['sellerA']->id]['seller_voucher_discount']);
        $this->assertEquals(35.00, $preview['groups'][$fixture['sellerA']->id]['platform_voucher_discount']);
        $this->assertEquals(20.00, $preview['groups'][$fixture['sellerB']->id]['platform_voucher_discount']);
        $this->assertEquals(15.00, $preview['groups'][$fixture['sellerA']->id]['shipping_voucher_discount']);
        $this->assertEquals(15.00, $preview['groups'][$fixture['sellerB']->id]['shipping_voucher_discount']);
        $this->assertEquals(135.00, $preview['total_discount']);
        $this->assertGreaterThanOrEqual(0, $preview['grand_total']);
    }

    public function test_flash_sale_price_precedes_regular_seller_discount_and_is_snapshotted_at_checkout(): void
    {
        $fixture = $this->checkoutFixture();
        $variant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $campaignKey = (string) str()->uuid();
        $fixture['productA']->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
            'flash_sale_campaign_key' => $campaignKey,
        ]);
        $variant->update([
            'price' => 100,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'flash_sale_price' => 80,
            'flash_sale_allocation' => 2,
            'flash_sale_sold' => 0,
        ]);

        $platformVoucher = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'FLASHSTACK10',
            'benefit_scope' => 'PRODUCT',
            'code' => 'FLASHSTACK10',
            'name' => 'Flash stacked platform ten percent',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'campaign_budget_total' => 100000,
            'is_active' => true,
        ]);
        $selection = ['platform' => $platformVoucher->code];

        $checkout = app(CheckoutService::class);
        $preview = $checkout->preview(collect([$fixture['itemA']]), $selection, $fixture['buyer']);
        $this->assertEquals(80.00, $preview['subtotal']);
        $this->assertEquals(8.00, $preview['platform_voucher_discount']);

        $order = $checkout->placeOrder(
            $fixture['buyer'], $fixture['address'], [$fixture['itemA']->id], 'COD', $selection, null, [], [],
            '00000000-0000-4000-8000-000000000071',
        );
        $orderItem = $order->sellerOrders()->with('items')->where('seller_profile_id', $fixture['sellerA']->id)->sole()->items->sole();

        $this->assertEquals(80.00, $orderItem->unit_price);
        $this->assertEquals(20.00, $orderItem->discount_amount);
        $this->assertEquals(8.00, $order->platform_voucher_discount);
        $this->assertSame(4, (int) $variant->fresh()->stock);
        $this->assertSame(1, (int) $variant->fresh()->flash_sale_sold);
        $this->assertSame(1, (int) $orderItem->flash_sale_quantity);
        $this->assertSame($campaignKey, $orderItem->flash_sale_campaign_key);

        $fixture['productA']->update([
            'discounts_enabled' => true,
            'discount_starts_at' => now()->subMinute(),
            'discount_ends_at' => now()->addDay(),
        ]);
        $variant->update(['discount_type' => 'fixed', 'discount_value' => 25]);
        $this->assertEquals(80.00, $orderItem->fresh()->unit_price, 'Later discount changes must not rewrite a flash sale order snapshot.');
        $this->assertEquals(100.00, $orderItem->fresh()->original_unit_price);

        $this->actingAs($fixture['buyer'])
            ->postJson(route('buyer.orders.cancel', $order), ['reason' => 'ORDERED_BY_MISTAKE'])
            ->assertOk();

        $this->assertSame(5, (int) $variant->fresh()->stock);
        $this->assertSame(0, (int) $variant->fresh()->flash_sale_sold);
        $this->assertEquals(80.00, $orderItem->fresh()->unit_price, 'Cancellation must preserve the purchased price snapshot.');
    }

    public function test_flash_sale_quota_rejects_overage_then_regular_price_applies_after_exhaustion(): void
    {
        $fixture = $this->checkoutFixture();
        $variant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $fixture['productA']->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
        ]);
        $variant->update([
            'price' => 100,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'flash_sale_price' => 80,
            'flash_sale_allocation' => 1,
            'flash_sale_sold' => 0,
        ]);
        $fixture['itemA']->update(['quantity' => 2]);

        try {
            app(CheckoutService::class)->preview(collect([$fixture['itemA']]), [], $fixture['buyer']);
            $this->fail('A checkout cannot exceed the remaining flash-sale allocation.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }

        $variant->update(['flash_sale_sold' => 1]);
        $freshItem = CartItem::query()->findOrFail($fixture['itemA']->id);
        $preview = app(CheckoutService::class)->preview(collect([$freshItem]), [], $fixture['buyer']);
        $this->assertEquals(180.00, $preview['subtotal']);

        $order = app(CheckoutService::class)->placeOrder(
            $fixture['buyer'], $fixture['address'], [$fixture['itemA']->id], 'COD', [], null, [], [],
            '00000000-0000-4000-8000-000000000072',
        );
        $orderItem = $order->sellerOrders()->with('items')->where('seller_profile_id', $fixture['sellerA']->id)->sole()->items->sole();
        $this->assertEquals(90.00, $orderItem->unit_price);
        $this->assertSame(1, (int) $variant->fresh()->flash_sale_sold);
        $this->assertSame(3, (int) $variant->fresh()->stock);
    }

    public function test_flash_sale_remaining_is_capped_by_live_variant_stock_including_zero_stock(): void
    {
        $fixture = $this->checkoutFixture();
        $variant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $fixture['productA']->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
        ]);
        $variant->update([
            'price' => 100,
            'flash_sale_price' => 70,
            'flash_sale_allocation' => 4,
            'flash_sale_sold' => 0,
            'stock' => 1,
        ]);

        $variant->refresh()->load('product');
        $this->assertSame(1, (int) $variant->flash_sale_remaining);
        $this->assertSame(70.0, (float) $variant->final_price);

        $variant->update(['stock' => 0]);
        $variant->refresh()->load('product');
        $this->assertSame(0, (int) $variant->flash_sale_remaining);
        $this->assertNull($variant->active_flash_sale_price);
        $this->assertSame('SOLD_OUT', $variant->flash_sale_status);
        $this->assertSame(100.0, (float) $variant->final_price);
    }

    public function test_seller_voucher_can_be_applied_to_a_flash_sale_at_checkout(): void
    {
        $fixture = $this->checkoutFixture();
        $variant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $campaignKey = (string) str()->uuid();
        $fixture['productA']->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
            'flash_sale_campaign_key' => $campaignKey,
        ]);
        $variant->update([
            'flash_sale_price' => 80,
            'flash_sale_allocation' => 2,
            'flash_sale_sold' => 0,
        ]);
        $this->assertSame('ACTIVE', $variant->fresh()->load('product')->flash_sale_status);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'FLASHSELLER10',
            'name' => 'Flash sale eligible seller voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'voucher_id' => $voucher->id]);

        $preview = app(CheckoutService::class)->preview(
            collect([$fixture['itemA']]),
            ['seller' => [$fixture['sellerA']->id => $voucher->code]],
            $fixture['buyer'],
        );

        $this->assertEquals(80.00, $preview['subtotal']);
        $this->assertGreaterThan(0, $preview['groups'][$fixture['sellerA']->id]['seller_voucher_discount']);
    }

    public function test_flash_sale_buyer_limit_is_shared_across_variants_and_enforced_at_checkout(): void
    {
        $fixture = $this->checkoutFixture();
        $campaignKey = (string) str()->uuid();
        $fixture['productA']->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
            'flash_sale_limit_per_buyer' => 1,
            'flash_sale_campaign_key' => $campaignKey,
        ]);
        $variantA = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $variantA->update(['flash_sale_price' => 80, 'flash_sale_allocation' => 3, 'flash_sale_sold' => 0]);
        $variantB = ProductVariant::query()->create([
            'product_id' => $fixture['productA']->id,
            'sku' => 'SKU-A-FLASH-LIMIT-2',
            'price' => 200,
            'flash_sale_price' => 160,
            'flash_sale_allocation' => 3,
            'flash_sale_sold' => 0,
            'stock' => 5,
            'is_default' => false,
            'is_active' => true,
        ]);

        $checkout = app(CheckoutService::class);
        $firstOrder = Order::query()->create([
            'order_number' => 'FLASH-LIMIT-'.str()->upper(str()->random(10)),
            'buyer_user_id' => $fixture['buyer']->id,
            'status' => 'PLACED',
            'currency' => 'PHP',
            'subtotal' => 80,
            'grand_total' => 130,
            'payment_status' => 'PENDING',
            'placed_at' => now(),
        ]);
        $firstSellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'FLASH-LIMIT-'.str()->upper(str()->random(10)),
            'order_id' => $firstOrder->id,
            'seller_profile_id' => $fixture['sellerA']->id,
            'status' => 'PLACED',
            'item_subtotal' => 80,
            'shipping_fee' => 50,
            'grand_total' => 130,
        ]);
        $firstItem = OrderItem::query()->create([
            'seller_order_id' => $firstSellerOrder->id,
            'product_id' => $fixture['productA']->id,
            'product_variant_id' => $variantA->id,
            'product_name' => $fixture['productA']->name,
            'sku' => $variantA->sku,
            'variant_description' => 'Standard',
            'unit_price' => 80,
            'quantity' => 1,
            'flash_sale_quantity' => 1,
            'flash_sale_campaign_key' => $campaignKey,
            'discount_amount' => 20,
            'line_total' => 80,
        ]);
        $this->assertSame($campaignKey, $firstItem->flash_sale_campaign_key);
        $this->assertSame(1, (int) $firstItem->flash_sale_quantity);

        $secondCart = Cart::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'status' => Cart::STATUS_ACTIVE]);
        $secondItem = CartItem::query()->create([
            'cart_id' => $secondCart->id,
            'product_variant_id' => $variantB->id,
            'quantity' => 1,
        ]);
        try {
            $checkout->preview(collect([$secondItem->load('productVariant.product')]), [], $fixture['buyer']);
            $this->fail('A Buyer must not exceed the campaign cap across variants.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        try {
            $checkout->placeOrder(
                $fixture['buyer'], $fixture['address'], [$secondItem->id], 'COD', [], null, [], [], (string) str()->uuid(),
            );
            $this->fail('A Buyer must not exceed the campaign cap across variants.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        $this->assertSame(1, Order::query()->where('buyer_user_id', $fixture['buyer']->id)->count());
        $this->assertSame(0, (int) $variantB->fresh()->flash_sale_sold);
    }

    public function test_scheduled_flash_price_activates_and_expiry_restores_regular_discount(): void
    {
        $fixture = $this->checkoutFixture();
        $variant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $startsAt = now()->addHour();
        $endsAt = $startsAt->copy()->addHour();
        $fixture['productA']->update(['flash_sale_starts_at' => $startsAt, 'flash_sale_ends_at' => $endsAt]);
        $variant->update([
            'price' => 100,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'flash_sale_price' => 70,
            'flash_sale_allocation' => 3,
            'flash_sale_sold' => 0,
        ]);

        $this->assertSame(90.0, (float) $variant->fresh()->load('product')->final_price);
        $this->travelTo($startsAt);
        $this->assertSame(70.0, (float) $variant->fresh()->load('product')->final_price);
        $this->travelTo($endsAt);
        $this->assertSame(90.0, (float) $variant->fresh()->load('product')->final_price);
        $this->assertSame('EXPIRED', $variant->fresh()->load('product')->flash_sale_status);
        $this->travelBack();
    }

    public function test_product_discount_checkout_preview_uses_scheduled_marketing_price(): void
    {
        $fixture = $this->checkoutFixture();
        $variant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $fixture['productA']->update([
            'discounts_enabled' => true,
            'discount_starts_at' => now()->subMinute(),
            'discount_ends_at' => now()->addDay(),
        ]);
        $variant->update(['price' => 100, 'discount_type' => 'percentage', 'discount_value' => 10]);

        $checkout = app(CheckoutService::class);
        $preview = $checkout->preview(collect([$fixture['itemA']]), [], $fixture['buyer']);
        $this->assertEquals(90.00, $preview['subtotal']);
        $variant->update(['discount_type' => 'fixed', 'discount_value' => 25]);
        $freshPreview = $checkout->preview(collect([$fixture['itemA']->fresh()]), [], $fixture['buyer']);
        $this->assertEquals(75.00, $freshPreview['subtotal']);
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
            'campaign_budget_total' => 100000,
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
            'campaign_budget_total' => 100000,
            'is_active' => true,
        ]);

        $preview = app(CheckoutService::class)->preview(collect([$fixture['itemA'], $fixture['itemB']]), [
            'platform' => 'PLATFORM999',
            'shipping' => 'SHIP999',
        ], $fixture['buyer']);

        $this->assertEquals(299.98, $preview['platform_voucher_discount']);
        $this->assertEquals(100.00, $preview['shipping_voucher_discount']);
        $this->assertEquals(0.02, $preview['grand_total']);
        $this->assertGreaterThanOrEqual(0, $preview['grand_total']);
    }

    public function test_code_only_seller_voucher_is_hidden_from_rewards_and_applies_by_exact_code(): void
    {
        $fixture = $this->checkoutFixture();
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'PRIVATEA10',
            'name' => 'Private customer voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'visibility' => 'CODE_ONLY',
            'is_active' => true,
        ]);

        $claimable = app(BuyerVoucherClaimService::class)->claimableSellerVouchers($fixture['buyer']);
        $this->assertFalse($claimable->contains('id', $voucher->id));

        $preview = app(CheckoutService::class)->preview(collect([$fixture['itemA']]), [
            'seller' => [$fixture['sellerA']->id => 'PRIVATEA10'],
        ], $fixture['buyer']);

        $this->assertEquals(10.0, $preview['groups'][$fixture['sellerA']->id]['seller_voucher_discount']);
    }

    public function test_private_voucher_still_rejects_wrong_expired_and_ineligible_codes(): void
    {
        $fixture = $this->checkoutFixture();
        Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'PRIVATEEXPIRED',
            'name' => 'Expired private voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'visibility' => 'CODE_ONLY',
            'ends_at' => now()->subMinute(),
            'is_active' => true,
        ]);
        Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'PRIVATEELIGIBLE',
            'name' => 'Ineligible private voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 10,
            'minimum_order_amount' => 200,
            'visibility' => 'CODE_ONLY',
            'is_active' => true,
        ]);

        foreach (['DOESNOTEXIST', 'PRIVATEEXPIRED', 'PRIVATEELIGIBLE'] as $code) {
            try {
                app(CheckoutService::class)->preview(collect([$fixture['itemA']]), [
                    'seller' => [$fixture['sellerA']->id => $code],
                ], $fixture['buyer']);
                $this->fail('An invalid, expired, or ineligible private voucher must not apply.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('seller_voucher_codes.'.$fixture['sellerA']->id, $exception->errors());
            }
        }
    }

    public function test_seller_can_create_bundle_from_owned_variants_and_foreign_variants_are_rejected(): void
    {
        $fixture = $this->checkoutFixture();
        $componentProduct = Product::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'category_id' => $fixture['productA']->category_id,
            'name' => 'Bundle Component Product',
            'slug' => 'bundle-component-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $componentVariant = ProductVariant::query()->create([
            'product_id' => $componentProduct->id,
            'sku' => 'SKU-BUNDLE-COMPONENT',
            'price' => 50,
            'stock' => 10,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($fixture['sellerUserA'])
            ->get(route('seller.promotions'))
            ->assertOk()
            ->assertSee('Create a Bundle Deal');

        $this->actingAs($fixture['sellerUserA'])
            ->post(route('seller.promotions.store'), [
                'promotion_type' => SellerPromotion::TYPE_BUNDLE,
                'name' => 'Summer Bundle',
                'bundle_price' => 120,
                'components' => [
                    ['product_variant_id' => $fixture['itemA']->product_variant_id, 'quantity' => 1],
                    ['product_variant_id' => $componentVariant->id, 'quantity' => 1],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $promotion = SellerPromotion::query()->where('name', 'Summer Bundle')->with('components')->sole();
        $this->assertSame(SellerPromotion::TYPE_BUNDLE, $promotion->promotion_type);
        $this->assertEquals(120.00, (float) $promotion->bundle_price);
        $this->assertEqualsCanonicalizing(
            [(int) $fixture['itemA']->product_variant_id, (int) $componentVariant->id],
            $promotion->components->pluck('product_variant_id')->map(fn ($id): int => (int) $id)->all(),
        );

        $this->actingAs($fixture['sellerUserA'])
            ->post(route('seller.promotions.store'), [
                'promotion_type' => SellerPromotion::TYPE_BUNDLE,
                'name' => 'Invalid Foreign Bundle',
                'bundle_price' => 100,
                'components' => [
                    ['product_variant_id' => $fixture['itemA']->product_variant_id, 'quantity' => 1],
                    ['product_variant_id' => $fixture['itemB']->product_variant_id, 'quantity' => 1],
                ],
            ])
            ->assertSessionHasErrors('components');
        $this->assertDatabaseMissing('seller_promotions', ['name' => 'Invalid Foreign Bundle']);
    }

    public function test_seller_can_create_a_scheduled_buy_x_get_y_offer_with_buyer_selected_reward_variation(): void
    {
        $fixture = $this->checkoutFixture();
        $buyVariant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $startsAt = now()->addHour()->startOfMinute();

        $this->actingAs($fixture['sellerUserA'])
            ->post(route('seller.promotions.store'), [
                'promotion_type' => SellerPromotion::TYPE_BUY_X_GET_Y,
                'name' => 'Scheduled mix reward',
                'buy_variant_id' => $buyVariant->id,
                'buy_quantity' => 2,
                'reward_product_id' => $fixture['productA']->id,
                'reward_quantity' => 1,
                'reward_discount_percent' => 100,
                'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
                'ends_at' => now()->addDays(2)->format('Y-m-d\\TH:i'),
                'max_redemptions' => 10,
                'max_per_buyer' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $promotion = SellerPromotion::query()->where('name', 'Scheduled mix reward')->sole();
        $this->assertSame(SellerPromotion::TYPE_BUY_X_GET_Y, $promotion->promotion_type);
        $this->assertNull($promotion->reward_variant_id);
        $this->assertSame((int) $fixture['productA']->id, (int) $promotion->reward_product_id);
        $this->assertTrue($promotion->starts_at->equalTo($startsAt));
        $this->assertSame(10, (int) $promotion->max_redemptions);
        $this->assertSame(2, (int) $promotion->max_per_buyer);

        $this->actingAs($fixture['sellerUserA'])
            ->get(route('seller.marketing', ['section' => 'buy-x-get-y']))
            ->assertOk()
            ->assertSee('Create a Buy X Get Y Offer')
            ->assertSee('Buyer chooses a variation');
    }

    public function test_bundle_claim_adds_selected_mix_and_match_variation_and_checkout_snapshots_it(): void
    {
        $fixture = $this->checkoutFixture();
        $choiceVariant = ProductVariant::query()->create([
            'product_id' => $fixture['productA']->id,
            'sku' => 'SKU-BUNDLE-CHOICE',
            'description' => 'Blue variation',
            'price' => 100,
            'stock' => 5,
            'is_default' => false,
            'is_active' => true,
        ]);
        $componentProduct = Product::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'category_id' => $fixture['productA']->category_id,
            'name' => 'Bundle component',
            'slug' => 'bundle-component',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $componentVariant = ProductVariant::query()->create([
            'product_id' => $componentProduct->id,
            'sku' => 'SKU-BUNDLE-FIXED',
            'price' => 50,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $promotion = SellerPromotion::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'name' => 'Mix shirt and bag deal',
            'promotion_type' => SellerPromotion::TYPE_BUNDLE,
            'bundle_price' => 120,
            'is_active' => true,
        ]);
        $promotion->components()->createMany([
            ['product_variant_id' => $fixture['itemA']->product_variant_id, 'quantity' => 1, 'choice_group' => 'shirt'],
            ['product_variant_id' => $choiceVariant->id, 'quantity' => 1, 'choice_group' => 'shirt'],
            ['product_variant_id' => $componentVariant->id, 'quantity' => 1],
        ]);

        $this->actingAs($fixture['buyer'])
            ->post(route('buyer.cart.bundle-add', $promotion), [
                'sets' => 1,
                'choices' => ['shirt' => $choiceVariant->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('buyer_notice');

        $cartItems = CartItem::query()->where('cart_id', $fixture['cart']->id)->get()->keyBy('product_variant_id');
        $this->assertSame(1, (int) $cartItems[$choiceVariant->id]->quantity);
        $this->assertSame(1, (int) $cartItems[$componentVariant->id]->quantity);
        $preview = app(CheckoutService::class)->preview(
            collect([$cartItems[$choiceVariant->id], $cartItems[$componentVariant->id]]),
            [],
            $fixture['buyer'],
        );
        $this->assertEquals(30.00, $preview['promotion_discount_total']);
        $this->assertSame((int) $choiceVariant->id, (int) $preview['groups'][$fixture['sellerA']->id]['items']->firstWhere('product_variant_id', $choiceVariant->id)->getAttribute('checkout_promotion_snapshot')[0]['components'][0]['product_variant_id']);
    }

    public function test_bundle_discount_is_snapshotted_charged_and_cancelled_without_duplicate_stock_restore(): void
    {
        $fixture = $this->checkoutFixture();
        $firstVariant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $secondProduct = Product::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'category_id' => $fixture['productA']->category_id,
            'name' => 'Bundle Second Item',
            'slug' => 'bundle-second-item',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $secondVariant = ProductVariant::query()->create([
            'product_id' => $secondProduct->id,
            'sku' => 'SKU-BUNDLE-SECOND',
            'price' => 50,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $secondItem = CartItem::query()->create([
            'cart_id' => $fixture['itemA']->cart_id,
            'product_variant_id' => $secondVariant->id,
            'quantity' => 1,
        ]);
        $promotion = SellerPromotion::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'name' => 'Two item bundle',
            'promotion_type' => SellerPromotion::TYPE_BUNDLE,
            'bundle_price' => 120,
            'is_active' => true,
        ]);
        $promotion->components()->createMany([
            ['product_variant_id' => $firstVariant->id, 'quantity' => 1],
            ['product_variant_id' => $secondVariant->id, 'quantity' => 1],
        ]);

        $items = collect([$fixture['itemA']->fresh(), $secondItem->fresh()]);
        $preview = app(CheckoutService::class)->preview($items, [], $fixture['buyer']);
        $this->actingAs($fixture['buyer'])
            ->withSession(['checkout_cart_item_ids' => $items->pluck('id')->all()])
            ->get(route('buyer.checkout'))
            ->assertOk()
            ->assertSee('Two item bundle')
            ->assertSee('data-seller-promotion-discount="30.00"', false)
            ->assertSee('170.00');

        $this->assertEquals(30.00, $preview['promotion_discount_total']);
        $this->assertEquals(170.00, $preview['grand_total']);

        $order = app(CheckoutService::class)->placeOrder(
            $fixture['buyer'], $fixture['address'], $items->pluck('id')->all(), 'COD', [], null, [], [],
            '00000000-0000-4000-8000-000000000081',
        );
        $sellerOrder = $order->sellerOrders()->with('items')->sole();
        $orderedItems = $sellerOrder->items->keyBy('product_variant_id');
        $this->assertEquals(30.00, (float) $sellerOrder->promotion_discount_total);
        $this->assertEquals(120.00, (float) $sellerOrder->items->sum('line_total'));
        $this->assertNotEmpty($orderedItems[$secondVariant->id]->promotion_snapshot);
        $this->assertEquals(170.00, (float) $order->grand_total);
        $this->assertEquals(30.00, (float) $order->discount_total);
        $this->assertSame(4, (int) $firstVariant->fresh()->stock);
        $this->assertSame(4, (int) $secondVariant->fresh()->stock);

        $this->actingAs($fixture['buyer'])
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Two item bundle')
            ->assertSee('Bundle includes:')
            ->assertSee('Bundle Second Item');

        $this->actingAs($fixture['buyer'])
            ->postJson(route('buyer.orders.cancel', $order), ['reason' => 'ORDERED_BY_MISTAKE'])
            ->assertOk();
        $this->assertSame(5, (int) $firstVariant->fresh()->stock);
        $this->assertSame(5, (int) $secondVariant->fresh()->stock);

        $this->actingAs($fixture['buyer'])
            ->postJson(route('buyer.orders.cancel', $order), ['reason' => 'ORDERED_BY_MISTAKE'])
            ->assertOk();
        $this->assertSame(5, (int) $firstVariant->fresh()->stock);
        $this->assertSame(5, (int) $secondVariant->fresh()->stock);
    }

    public function test_buy_x_get_y_requires_reward_in_cart_prices_the_reward_free_and_releases_limits_on_cancellation(): void
    {
        $fixture = $this->checkoutFixture();
        $buyVariant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $rewardProduct = Product::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'category_id' => $fixture['productA']->category_id,
            'name' => 'Campaign Reward Product',
            'slug' => 'campaign-reward-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $rewardVariant = ProductVariant::query()->create([
            'product_id' => $rewardProduct->id,
            'sku' => 'SKU-CAMPAIGN-REWARD',
            'price' => 30,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $rewardItem = CartItem::query()->create([
            'cart_id' => $fixture['itemA']->cart_id,
            'product_variant_id' => $rewardVariant->id,
            'quantity' => 1,
        ]);
        $fixture['itemA']->update(['quantity' => 2]);
        $promotion = SellerPromotion::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'name' => 'Buy two get one reward',
            'promotion_type' => SellerPromotion::TYPE_BUY_X_GET_Y,
            'buy_variant_id' => $buyVariant->id,
            'buy_quantity' => 2,
            'reward_variant_id' => $rewardVariant->id,
            'reward_product_id' => $rewardProduct->id,
            'reward_quantity' => 1,
            'reward_discount_percent' => 100,
            'max_redemptions' => 1,
            'max_per_buyer' => 1,
            'is_active' => true,
        ]);

        $items = collect([$fixture['itemA']->fresh(), $rewardItem->fresh()]);
        $withoutReward = app(CheckoutService::class)->preview(collect([$fixture['itemA']->fresh()]), [], $fixture['buyer']);
        $this->assertSame(0.0, $withoutReward['promotion_discount_total']);

        $preview = app(CheckoutService::class)->preview($items, [], $fixture['buyer']);
        $this->assertEquals(30.00, $preview['promotion_discount_total']);
        $this->assertEquals(250.00, $preview['grand_total']);

        $order = app(CheckoutService::class)->placeOrder(
            $fixture['buyer'], $fixture['address'], $items->pluck('id')->all(), 'COD', [], null, [], [],
            '00000000-0000-4000-8000-000000000082',
        );
        $sellerOrder = $order->sellerOrders()->with('items')->sole();
        $rewardOrderItem = $sellerOrder->items->firstWhere('product_variant_id', $rewardVariant->id);
        $this->assertNotNull($rewardOrderItem);
        $this->assertSame(0.0, (float) $rewardOrderItem->line_total);
        $this->assertEquals(30.00, (float) $rewardOrderItem->promotion_discount_amount);
        $this->assertSame('Campaign Reward Product', $rewardOrderItem->promotion_snapshot[0]['reward_product_name']);
        $this->assertSame(1, (int) $promotion->fresh()->redemptions_used);
        $this->assertSame(3, (int) $buyVariant->fresh()->stock);
        $this->assertSame(4, (int) $rewardVariant->fresh()->stock);

        $this->actingAs($fixture['buyer'])
            ->postJson(route('buyer.orders.cancel', $order), ['reason' => 'ORDERED_BY_MISTAKE'])
            ->assertOk();
        $this->assertSame(5, (int) $buyVariant->fresh()->stock);
        $this->assertSame(5, (int) $rewardVariant->fresh()->stock);
        $this->assertSame(0, (int) $promotion->fresh()->redemptions_used);
    }

    public function test_same_variant_buy_x_get_y_splits_free_units_for_partial_refunds_and_cancellation(): void
    {
        $fixture = $this->checkoutFixture();
        $buyVariant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $promotion = SellerPromotion::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'name' => 'Buy one get same item free',
            'promotion_type' => SellerPromotion::TYPE_BUY_X_GET_Y,
            'buy_variant_id' => $buyVariant->id,
            'buy_quantity' => 1,
            'reward_variant_id' => $buyVariant->id,
            'reward_product_id' => $fixture['productA']->id,
            'reward_quantity' => 1,
            'reward_discount_percent' => 100,
            'max_redemptions' => 1,
            'max_per_buyer' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($fixture['buyer'])
            ->get(route('buyer.product-details', ['slug' => $fixture['productA']->slug]))
            ->assertOk()
            ->assertSee('Buy one get same item free')
            ->assertSee('Add Items');

        $this->actingAs($fixture['buyer'])
            ->post(route('buyer.cart.offer-claim', $promotion), [
                'sets' => 1,
                'reward_variant_id' => $buyVariant->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('buyer_notice');
        $cartItem = CartItem::query()->where('cart_id', $fixture['cart']->id)
            ->where('product_variant_id', $buyVariant->id)->sole();
        $this->assertSame(3, (int) $cartItem->quantity);
        $this->actingAs($fixture['buyer'])
            ->get(route('buyer.cart'))
            ->assertOk()
            ->assertSee('Buy one get same item free')
            ->assertSee('Add Items');

        $order = app(CheckoutService::class)->placeOrder(
            $fixture['buyer'], $fixture['address'], [$cartItem->id], 'COD', [], null, [], [],
            '00000000-0000-4000-8000-000000000083',
        );
        $sellerOrder = $order->sellerOrders()->with('items')->sole();
        $freeReward = $sellerOrder->items->firstWhere('line_total', 0);
        $paidItems = $sellerOrder->items->where('line_total', '>', 0)->values();
        $this->assertNotNull($freeReward);
        $this->assertSame(1, (int) $freeReward->quantity);
        $this->assertSame(100.0, (float) $freeReward->unit_price);
        $this->assertSame(0.0, (float) $freeReward->line_total);
        $this->assertSame(1, (int) $freeReward->promotion_snapshot[0]['free_reward_quantity']);
        $this->assertCount(1, $paidItems);
        $this->assertSame(2, (int) $paidItems->first()->quantity);
        $this->assertEquals(10000, OrderItemParcelAmount::buyerProductRefundSliceCents($sellerOrder, $paidItems->first(), 0, 1));
        $this->assertSame(0, OrderItemParcelAmount::buyerProductRefundSliceCents($sellerOrder, $freeReward, 0, 1));
        $this->assertSame(1, (int) $promotion->fresh()->redemptions_used);
        $this->assertSame(2, (int) $buyVariant->fresh()->stock);
        $this->actingAs($fixture['buyer'])
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Buy one get same item free');
        $this->actingAs($fixture['sellerUserA'])
            ->get(route('seller.orders', ['mode' => 'show', 'order' => $sellerOrder->seller_order_number]))
            ->assertOk()
            ->assertSee('promotional free reward item(s)');

        $this->actingAs($fixture['buyer'])
            ->postJson(route('buyer.orders.cancel', $order), ['reason' => 'ORDERED_BY_MISTAKE'])
            ->assertOk();
        $this->assertSame(5, (int) $buyVariant->fresh()->stock);
        $this->assertSame(0, (int) $promotion->fresh()->redemptions_used);
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
            'province_code' => '0401',
            'province_name' => 'Note Province',
            'municipality_code' => '0401010000',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => '0401010001',
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
    public function test_variant_scoped_seller_voucher_discounts_only_the_selected_variant_at_checkout(): void
    {
        $fixture = $this->checkoutFixture();
        $selectedVariant = ProductVariant::query()->findOrFail($fixture['itemA']->product_variant_id);
        $unselectedVariant = ProductVariant::query()->create([
            'product_id' => $fixture['productA']->id,
            'sku' => 'SKU-A-UNSELECTED',
            'price' => 200,
            'stock' => 5,
            'is_default' => false,
            'is_active' => true,
        ]);
        $unselectedItem = CartItem::query()->create([
            'cart_id' => $fixture['itemA']->cart_id,
            'product_variant_id' => $unselectedVariant->id,
            'quantity' => 1,
        ]);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'VARIANTONLY10',
            'name' => 'One variant only',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'applies_to' => 'VARIANTS',
            'visibility' => 'PUBLIC',
            'is_active' => true,
        ]);
        $voucher->eligibleVariants()->sync([$selectedVariant->id]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'voucher_id' => $voucher->id]);
        $selection = ['seller' => [$fixture['sellerA']->id => $voucher->code]];
        $checkout = app(CheckoutService::class);

        $preview = $checkout->preview(collect([$fixture['itemA'], $unselectedItem]), $selection, $fixture['buyer']);
        $sellerPreview = $preview['groups'][$fixture['sellerA']->id];
        $this->assertSame(10.00, (float) $sellerPreview['seller_voucher_discount']);
        $this->assertSame(1000, $sellerPreview['seller_voucher_item_discounts'][(int) $fixture['itemA']->id]);
        $this->assertSame(0, $sellerPreview['seller_voucher_item_discounts'][(int) $unselectedItem->id]);

        $order = $checkout->placeOrder(
            $fixture['buyer'], $fixture['address'], [$fixture['itemA']->id, $unselectedItem->id], 'COD', $selection, null, [], [], (string) str()->uuid(),
        );
        $sellerOrder = $order->sellerOrders()->where('seller_profile_id', $fixture['sellerA']->id)->with('items')->sole();
        $orderedItems = $sellerOrder->items->keyBy('product_variant_id');

        $this->assertSame('10.00', $orderedItems->get($selectedVariant->id)->seller_voucher_discount);
        $this->assertSame('0.00', $orderedItems->get($unselectedVariant->id)->seller_voucher_discount);
        $this->assertSame('10.00', $sellerOrder->seller_voucher_discount);
    }

    public function test_seller_voucher_budget_reserves_confirms_releases_and_reverses_without_platform_funding(): void
    {
        $fixture = $this->checkoutFixture();
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $fixture['sellerA']->id,
            'code' => 'SELLERBUDGET5',
            'name' => 'Capped Seller voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 5,
            'minimum_order_amount' => 0,
            'applies_to' => 'STORE',
            'visibility' => 'PUBLIC',
            'seller_discount_budget_total' => 4.99,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'voucher_id' => $voucher->id]);
        $selection = ['seller' => [$fixture['sellerA']->id => $voucher->code]];
        $checkout = app(CheckoutService::class);

        try {
            $checkout->preview(collect([$fixture['itemA']]), $selection, $fixture['buyer']);
            $this->fail('An order above the Seller voucher budget must not receive the voucher.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('seller_voucher_codes.'.$fixture['sellerA']->id, $exception->errors());
        }

        $voucher->update(['seller_discount_budget_total' => 7.50]);
        $checkout->preview(collect([$fixture['itemA']]), $selection, $fixture['buyer']);
        $budgetService = app(\App\Services\Finance\SellerVoucherBudgetService::class);
        $this->assertSame(0, $budgetService->summary($voucher)['reserved_cents']);
        $firstOrder = $checkout->placeOrder(
            $fixture['buyer'], $fixture['address'], [$fixture['itemA']->id], 'COD', $selection, null, [], [], (string) str()->uuid(),
        );
        $firstSellerOrder = $firstOrder->sellerOrders()->where('seller_profile_id', $fixture['sellerA']->id)->with('items')->sole();
        $firstItem = $firstSellerOrder->items->sole();
        $reserved = $budgetService->summary($voucher->fresh());
        $this->assertSame(500, $reserved['reserved_cents']);
        $this->assertSame(0, $reserved['spent_cents']);
        $this->assertSame(250, $reserved['available_cents']);
        $this->assertSame(0, \App\Models\Finance\FinancialLedgerEntry::query()->where('voucher_id', $voucher->id)->where('account_type', 'CAMPAIGN_BUDGET')->count());

        $budgetService->consumeForOrderItem($firstItem);
        $budgetService->consumeForOrderItem($firstItem);
        $confirmed = $budgetService->summary($voucher->fresh());
        $this->assertSame(0, $confirmed['reserved_cents']);
        $this->assertSame(500, $confirmed['spent_cents']);
        $this->assertSame(1, \App\Models\Finance\FinancialLedgerEntry::query()->where('order_item_id', $firstItem->id)->where('entry_type', 'SELLER_VOUCHER_DISCOUNT_SPENT')->count());

        $budgetService->reverseForOrderItem($firstItem, 2.50, 'test-refund-1');
        $budgetService->reverseForOrderItem($firstItem, 2.50, 'test-refund-1');
        $reversed = $budgetService->summary($voucher->fresh());
        $this->assertSame(250, $reversed['spent_cents']);
        $this->assertSame(500, $reversed['available_cents']);
        $this->assertSame(1, \App\Models\Finance\FinancialLedgerEntry::query()->where('order_item_id', $firstItem->id)->where('entry_type', 'SELLER_VOUCHER_DISCOUNT_REVERSAL')->count());

        $secondCart = Cart::query()->create(['buyer_user_id' => $fixture['buyer']->id, 'status' => Cart::STATUS_ACTIVE]);
        $secondItem = CartItem::query()->create([
            'cart_id' => $secondCart->id,
            'product_variant_id' => $fixture['itemA']->product_variant_id,
            'quantity' => 1,
        ]);
        $secondOrder = $checkout->placeOrder(
            $fixture['buyer'], $fixture['address'], [$secondItem->id], 'COD', $selection, null, [], [], (string) str()->uuid(),
        );
        $secondSellerOrder = $secondOrder->sellerOrders()->where('seller_profile_id', $fixture['sellerA']->id)->sole();
        $this->assertSame(500, $budgetService->summary($voucher->fresh())['reserved_cents']);
        $secondSellerOrder->update(['status' => 'CANCELLED']);
        $budgetService->releaseForSellerOrder($secondSellerOrder, $fixture['buyer']);
        $budgetService->releaseForSellerOrder($secondSellerOrder, $fixture['buyer']);
        $afterCancel = $budgetService->summary($voucher->fresh());
        $this->assertSame(0, $afterCancel['reserved_cents']);
        $this->assertSame(250, $afterCancel['spent_cents']);
        $this->assertSame(500, $afterCancel['available_cents']);
        $this->assertSame(1, \App\Models\Finance\FinancialLedgerEntry::query()->where('seller_order_id', $secondSellerOrder->id)->where('entry_type', 'SELLER_VOUCHER_DISCOUNT_RELEASED')->count());
    }

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
            'province_code' => '0401',
            'province_name' => 'Note Province',
            'region_name' => 'NCR',
            'municipality_code' => '0401010000',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => '0401010001',
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
            'province_code' => '0401',
            'province_name' => 'Note Province',
            'municipality_code' => '0401010000',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => '0401010001',
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
            'province_code' => '0401',
            'province_name' => 'Note Province',
            'region_name' => 'NCR',
            'municipality_code' => '0401010000',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => '0401010001',
            'barangay_name' => 'Note Barangay',
            'street_address' => '1 Test Street',
            'latitude' => '14.5995000',
            'longitude' => '120.9842000',
            'is_default' => true,
        ]);

        return compact('buyer', 'center', 'address', 'category', 'subcategory', 'cart', 'sellerUserA', 'sellerA', 'productA', 'itemA', 'sellerUserB', 'sellerB', 'productB', 'itemB');
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
            'province_code' => '0401',
            'province_name' => 'Note Province',
            'region_name' => 'NCR',
            'municipality_code' => '0401010000',
            'municipality_name' => 'Note Municipality',
            'barangay_code' => '0401010001',
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
