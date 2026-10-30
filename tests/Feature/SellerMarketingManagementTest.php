<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Reports\SellerMarketingOverviewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SellerMarketingManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seller = User::query()->where('email', 'seller@likhae.com')->sole();
    }

    public function test_marketing_lists_only_owned_vouchers_and_shows_real_validity_and_capacity(): void
    {
        $sellerProfile = $this->seller->sellerProfile;
        $foreignProfile = $this->createForeignSellerProfile();
        $this->makeVoucher($sellerProfile, [
            'code' => 'B6ACTIVE',
            'usage_limit' => 5,
            'per_user_limit' => 2,
            'maximum_discount_amount' => 50,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $this->makeVoucher($sellerProfile, [
            'code' => 'B6UPCOMING',
            'starts_at' => now()->addDay(),
        ]);
        $this->makeVoucher($sellerProfile, [
            'code' => 'B6EXPIRED',
            'ends_at' => now()->subSecond(),
        ]);
        $exhausted = $this->makeVoucher($sellerProfile, ['code' => 'B6EXHAUSTED', 'usage_limit' => 1]);
        $this->recordSellerOrder($sellerProfile, $exhausted, 'PLACED');
        $this->recordSellerOrder($sellerProfile, $exhausted, 'CANCELLED');
        $this->makeVoucher($foreignProfile, ['code' => 'FOREIGNVOUCHER']);

        $response = $this->actingAs($this->seller)->get(route('seller.marketing', ['section' => 'vouchers']));

        $response->assertOk()
            ->assertSee('B6ACTIVE')
            ->assertSee('B6UPCOMING')
            ->assertSee('B6EXPIRED')
            ->assertSee('B6EXHAUSTED')
            ->assertSee('Usage exhausted')
            ->assertSee('1 / 1')
            ->assertSee('0 uses left')
            ->assertSee('2 per buyer')
            ->assertDontSee('FOREIGNVOUCHER');
    }

    public function test_marketing_page_remains_available_before_product_targeting_migration(): void
    {
        $this->makeVoucher($this->seller->sellerProfile, ['code' => 'SCHEMAFALLBACK']);
        $schema = \Mockery::mock(Schema::getFacadeRoot())->makePartial();
        $schema->shouldReceive('hasColumn')->with('vouchers', 'applies_to')->andReturn(false);
        Schema::swap($schema);

        try {
            $this->actingAs($this->seller)
                ->get(route('seller.marketing', ['section' => 'vouchers']))
                ->assertOk()
                ->assertSee('Vouchers for selected products are temporarily unavailable')
                ->assertSee('SCHEMAFALLBACK')
                ->assertSee('Entire store')
                ->assertDontSee('voucher_products');
        } finally {
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function test_product_promotions_page_explains_when_its_schema_migration_is_pending(): void
    {
        $schema = \Mockery::mock(Schema::getFacadeRoot())->makePartial();
        $schema->shouldReceive('hasTable')->with('seller_promotions')->andReturn(false);
        Schema::swap($schema);

        try {
            $this->actingAs($this->seller)
                ->get(route('seller.promotions'))
                ->assertOk()
                ->assertSee('Product Promotions')
                ->assertSee('Product promotions are temporarily unavailable while the product promotions database migration is pending.')
                ->assertDontSee('Create a Bundle Deal')
                ->assertDontSee('Save Buy X Get Y Offer');
        } finally {
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function test_marketing_center_has_six_working_sections_with_the_current_section_active(): void
    {
        foreach ([
            'product-discounts' => 'Product and variant discounts',
            'flash-sales' => 'Variant Flash Sales',
            'vouchers' => 'Your Vouchers',
            'bundle-deals' => 'Bundle Deals',
            'buy-x-get-y' => 'Buy X Get Y',
            'overview' => 'Confirmed promotional discounts',
        ] as $section => $heading) {
            $this->actingAs($this->seller)
                ->get(route('seller.marketing', ['section' => $section]))
                ->assertOk()
                ->assertSee('aria-current="page"', escape: false)
                ->assertSee($heading);
        }
    }

    public function test_marketing_overview_shows_claim_counts_and_campaign_history(): void
    {
        $voucher = $this->makeVoucher($this->seller->sellerProfile, [
            'name' => 'Overview history voucher',
            'code' => 'OVERVIEWHISTORY',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);
        $buyer = User::query()->where('account_type', User::TYPE_BUYER)->firstOrFail();
        BuyerVoucherClaim::query()->create([
            'buyer_user_id' => $buyer->id,
            'voucher_id' => $voucher->id,
        ]);

        $overview = app(SellerMarketingOverviewService::class)->forSeller((int) $this->seller->sellerProfile->id);
        $this->assertSame(1, $overview['voucher_claims']);
        $this->assertSame('active', $overview['campaign_history']->firstWhere('name', 'Overview history voucher')['status']);

        $this->actingAs($this->seller)
            ->get(route('seller.marketing', ['section' => 'overview']))
            ->assertOk()
            ->assertSee('Voucher claims')
            ->assertSee('Bundle sets sold')
            ->assertSee('Buy X Get Y redemptions')
            ->assertSee('Promotion-attributed revenue')
            ->assertSee('Seller-funded discount cost')
            ->assertSee('Active campaigns')
            ->assertSee('Scheduled campaigns')
            ->assertSee('Active product discounts')
            ->assertSee('Scheduled product discounts')
            ->assertSee('Active flash sales')
            ->assertSee('Scheduled flash sales')
            ->assertSee('Campaign history')
            ->assertSee('Overview history voucher')
            ->assertSee('recognized completed seller orders');
    }

    public function test_seller_can_edit_own_voucher_but_cannot_edit_or_pause_another_sellers_or_platform_voucher(): void
    {
        $sellerProfile = $this->seller->sellerProfile;
        $foreignProfile = $this->createForeignSellerProfile();
        $owned = $this->makeVoucher($sellerProfile, ['code' => 'EDITME']);
        $foreign = $this->makeVoucher($foreignProfile, ['code' => 'FOREIGNEDIT']);
        $platform = $this->makeVoucher(null, ['code' => 'PLATFORMEDIT', 'platform_code_key' => 'PLATFORMEDIT']);

        $this->actingAs($this->seller)
            ->patch(route('seller.marketing.campaigns.update', $owned), [
                'code' => '  EDITED  ',
                'name' => '  Revised voucher  ',
                'discount_type' => 'FIXED',
                'discount_value' => 25,
                'minimum_order_amount' => 100,
                'maximum_discount_amount' => null,
                'usage_limit' => 10,
                'per_user_limit' => 1,
                'starts_at' => null,
                'ends_at' => null,
                'seller_profile_id' => $foreignProfile->id,
                'platform_code_key' => 'FORGEDPLATFORM',
                'is_active' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Voucher updated. Existing redemptions and order totals are unchanged.');

        $this->assertDatabaseHas('vouchers', [
            'id' => $owned->id,
            'seller_profile_id' => $sellerProfile->id,
            'platform_code_key' => null,
            'code' => 'EDITED',
            'name' => 'Revised voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 25,
            'is_active' => true,
        ]);

        foreach ([$foreign, $platform] as $protectedVoucher) {
            $this->patch(route('seller.marketing.campaigns.update', $protectedVoucher), [
                'code' => 'TAMPERED',
                'name' => 'Tampered voucher',
                'discount_type' => 'PERCENT',
                'discount_value' => 5,
            ])->assertForbidden();
            $this->patch(route('seller.marketing.campaigns.toggle', $protectedVoucher))->assertForbidden();
        }

        $this->assertDatabaseHas('vouchers', ['id' => $foreign->id, 'code' => 'FOREIGNEDIT']);
        $this->assertDatabaseHas('vouchers', ['id' => $platform->id, 'code' => 'PLATFORMEDIT']);
    }

    public function test_equal_voucher_start_and_end_times_are_rejected(): void
    {
        $sameTime = now()->addDay()->format('Y-m-d H:i:s');

        $this->actingAs($this->seller)
            ->from(route('seller.marketing'))
            ->post(route('seller.marketing.campaigns.store'), [
                'code' => 'EQUALDATES',
                'name' => 'Equal date voucher',
                'discount_type' => 'PERCENT',
                'discount_value' => 10,
                'starts_at' => $sameTime,
                'ends_at' => $sameTime,
            ])
            ->assertRedirect(route('seller.marketing'))
            ->assertSessionHasErrors('ends_at');

        $this->assertDatabaseMissing('vouchers', ['code' => 'EQUALDATES']);
    }

    public function test_fixed_voucher_discount_must_be_positive(): void
    {
        $this->actingAs($this->seller);
        foreach ([0, -1] as $discountValue) {
            $this->from(route('seller.marketing'))
                ->post(route('seller.marketing.campaigns.store'), [
                    'code' => 'INVALIDFIXED'.$discountValue,
                    'name' => 'Invalid fixed discount',
                    'discount_type' => 'FIXED',
                    'discount_value' => $discountValue,
                    'minimum_order_amount' => 0,
                    'applies_to' => 'STORE',
                ])
                ->assertRedirect(route('seller.marketing'))
                ->assertSessionHasErrors('discount_value');
        }

        $this->assertDatabaseMissing('vouchers', ['code' => 'INVALIDFIXED0']);
        $this->assertDatabaseMissing('vouchers', ['code' => 'INVALIDFIXED-1']);
    }

    public function test_seller_can_target_owned_active_products_but_not_another_sellers_product(): void
    {
        $ownedProduct = Product::query()->where('seller_profile_id', $this->seller->sellerProfile->id)->where('status', 'ACTIVE')->firstOrFail();
        $foreignSeller = $this->createForeignSellerProfile();
        $foreignProduct = Product::query()->create([
            'seller_profile_id' => $foreignSeller->id,
            'category_id' => $this->seller->sellerProfile->primary_category_id,
            'name' => 'Foreign eligible product',
            'slug' => 'foreign-eligible-product',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.marketing', ['section' => 'vouchers']))
            ->assertOk()
            ->assertSee('name="applies_to"', escape: false)
            ->assertSee('name="product_ids[]"', escape: false)
            ->assertSee($ownedProduct->name)
            ->assertDontSee($foreignProduct->name);

        $this->from(route('seller.marketing', ['section' => 'vouchers']))
            ->post(route('seller.marketing.campaigns.store'), [
                'code' => 'TARGETOWNED',
                'name' => 'Owned product voucher',
                'discount_type' => 'FIXED',
                'discount_value' => 25,
                'minimum_order_amount' => 100,
                'applies_to' => 'PRODUCTS',
                'product_ids' => [$ownedProduct->id],
            ])
            ->assertRedirect(route('seller.marketing', ['section' => 'vouchers']))
            ->assertSessionHas('status', 'Voucher saved.');

        $voucher = Voucher::query()->where('code', 'TARGETOWNED')->sole();
        $this->assertSame('PRODUCTS', $voucher->applies_to);
        $this->assertSame([$ownedProduct->id], $voucher->eligibleProducts()->pluck('products.id')->all());

        $this->from(route('seller.marketing', ['section' => 'vouchers']))
            ->post(route('seller.marketing.campaigns.store'), [
                'code' => 'TARGETFOREIGN',
                'name' => 'Foreign product voucher',
                'discount_type' => 'FIXED',
                'discount_value' => 25,
                'applies_to' => 'PRODUCTS',
                'product_ids' => [$foreignProduct->id],
            ])
            ->assertRedirect(route('seller.marketing', ['section' => 'vouchers']))
            ->assertSessionHasErrors('product_ids');

        $this->assertDatabaseMissing('vouchers', ['code' => 'TARGETFOREIGN']);
    }

    public function test_seller_can_target_exact_owned_variants_but_not_another_sellers_variant(): void
    {
        $ownedProduct = Product::query()
            ->where('seller_profile_id', $this->seller->sellerProfile->id)
            ->where('status', 'ACTIVE')
            ->firstOrFail();
        $ownedVariant = $ownedProduct->variants()->where('is_active', true)->firstOrFail();
        $foreignSeller = $this->createForeignSellerProfile();
        $foreignProduct = Product::query()->create([
            'seller_profile_id' => $foreignSeller->id,
            'category_id' => $this->seller->sellerProfile->primary_category_id,
            'name' => 'Foreign variant product',
            'slug' => 'foreign-variant-product',
            'status' => 'ACTIVE',
        ]);
        $foreignVariant = ProductVariant::query()->create([
            'product_id' => $foreignProduct->id,
            'sku' => 'FOREIGN-VOUCHER-VARIANT',
            'price' => 100,
            'stock' => 2,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.marketing', ['section' => 'vouchers']))
            ->assertOk()
            ->assertSee('Selected variants only')
            ->assertSee('name="variant_ids[]"', escape: false)
            ->assertSee($ownedProduct->name)
            ->assertDontSee($foreignProduct->name);

        $this->from(route('seller.marketing', ['section' => 'vouchers']))
            ->post(route('seller.marketing.campaigns.store', ['section' => 'vouchers']), [
                'code' => 'ONLYONEVARIANT',
                'name' => 'One variant voucher',
                'discount_type' => 'PERCENT',
                'discount_value' => 10,
                'minimum_order_amount' => 0,
                'applies_to' => 'VARIANTS',
                'variant_ids' => [$ownedVariant->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Voucher saved.');

        $voucher = Voucher::query()->where('code', 'ONLYONEVARIANT')->sole();
        $this->assertSame('VARIANTS', $voucher->applies_to);
        $this->assertSame([$ownedVariant->id], $voucher->eligibleVariants()->pluck('product_variants.id')->all());

        $this->from(route('seller.marketing', ['section' => 'vouchers']))
            ->post(route('seller.marketing.campaigns.store', ['section' => 'vouchers']), [
                'code' => 'FOREIGNVARIANT',
                'name' => 'Foreign variant voucher',
                'discount_type' => 'PERCENT',
                'discount_value' => 10,
                'applies_to' => 'VARIANTS',
                'variant_ids' => [$foreignVariant->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('variant_ids');

        $this->assertDatabaseMissing('vouchers', ['code' => 'FOREIGNVARIANT']);
    }

    public function test_claimed_voucher_terms_cannot_be_changed_but_name_and_pause_remain_available(): void
    {
        $this->actingAs($this->seller);
        $voucher = $this->makeVoucher($this->seller->sellerProfile, [
            'code' => 'CLAIMEDTERMS',
            'discount_type' => 'FIXED',
            'discount_value' => 25,
            'minimum_order_amount' => 100,
        ]);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);

        $basePayload = [
            'code' => 'CLAIMEDTERMS',
            'name' => 'Renamed claimed voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 25,
            'minimum_order_amount' => 100,
            'maximum_discount_amount' => null,
            'usage_limit' => null,
            'per_user_limit' => null,
            'starts_at' => null,
            'ends_at' => null,
            'applies_to' => 'STORE',
            'product_ids' => [],
        ];

        $this->from(route('seller.marketing'))
            ->patch(route('seller.marketing.campaigns.update', $voucher), array_replace($basePayload, ['discount_value' => 30]))
            ->assertRedirect(route('seller.marketing'))
            ->assertSessionHasErrors('discount_value');
        $this->assertSame('25.00', $voucher->fresh()->discount_value);

        $ownedProduct = Product::query()->where('seller_profile_id', $this->seller->sellerProfile->id)->where('status', 'ACTIVE')->firstOrFail();
        $this->from(route('seller.marketing'))
            ->patch(route('seller.marketing.campaigns.update', $voucher), array_replace($basePayload, [
                'applies_to' => 'PRODUCTS',
                'product_ids' => [$ownedProduct->id],
            ]))
            ->assertRedirect(route('seller.marketing'))
            ->assertSessionHasErrors(['applies_to', 'product_ids']);

        $this->actingAs($this->seller)
            ->patch(route('seller.marketing.campaigns.update', $voucher), $basePayload)
            ->assertRedirect()
            ->assertSessionHas('status', 'Voucher updated. Existing redemptions and order totals are unchanged.');
        $this->assertSame('Renamed claimed voucher', $voucher->fresh()->name);

        $this->patch(route('seller.marketing.campaigns.toggle', $voucher))
            ->assertRedirect();
        $this->assertFalse($voucher->fresh()->is_active);
        $this->assertDatabaseHas('buyer_voucher_claims', ['buyer_user_id' => $buyer->id, 'voucher_id' => $voucher->id]);
    }

    private function createForeignSellerProfile(): SellerProfile
    {
        $user = User::factory()->create(['account_type' => User::TYPE_SELLER]);

        return SellerProfile::query()->create([
            'user_id' => $user->id,
            'primary_category_id' => $this->seller->sellerProfile->primary_category_id,
            'business_name' => 'Foreign Test Shop',
            'status' => 'ACTIVE',
        ]);
    }

    private function makeVoucher(?SellerProfile $seller, array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'seller_profile_id' => $seller?->id,
            'code' => 'B6-'.str()->upper(str()->random(8)),
            'name' => 'Batch 6 test voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ], $overrides));
    }

    private function recordSellerOrder(SellerProfile $seller, Voucher $voucher, string $status): void
    {
        $buyer = User::query()->where('account_type', User::TYPE_BUYER)->firstOrFail();
        $order = Order::query()->create([
            'order_number' => 'B6-'.str()->upper(str()->random(12)),
            'buyer_user_id' => $buyer->id,
            'status' => $status === 'CANCELLED' ? 'CANCELLED' : 'PLACED',
            'currency' => 'PHP',
            'subtotal' => 100,
            'discount_total' => 10,
            'shipping_total' => 0,
            'grand_total' => 90,
            'payment_status' => 'PENDING',
            'placed_at' => now(),
        ]);

        SellerOrder::query()->create([
            'seller_order_number' => 'B6-'.str()->upper(str()->random(12)),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'voucher_id' => $voucher->id,
            'status' => $status,
            'item_subtotal' => 100,
            'voucher_discount' => 10,
            'shipping_fee' => 0,
            'grand_total' => 90,
        ]);
    }
}
