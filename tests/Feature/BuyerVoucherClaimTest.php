<?php

namespace Tests\Feature;

use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Buyer\SellerFollower;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Marketplace\VoucherRedemptionCounter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuyerVoucherClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $sellerUser;

    private int $sellerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $this->sellerUser = User::query()->where('email', 'seller@likhae.com')->sole();
        $this->sellerId = (int) $this->sellerUser->sellerProfile->id;
    }

    public function test_claim_persists_as_collected_and_is_idempotent_without_redeeming(): void
    {
        $voucher = $this->voucher();

        $this->actingAs($this->buyer)
            ->get(route('buyer.rewards'))
            ->assertOk()
            ->assertSee($voucher->code)
            ->assertSee('Claim');

        $this->post(route('buyer.vouchers.claim', $voucher))
            ->assertRedirect(route('buyer.rewards'));
        $this->post(route('buyer.vouchers.claim', $voucher))
            ->assertRedirect(route('buyer.rewards'));

        $this->assertDatabaseCount('buyer_voucher_claims', 1);
        $this->assertDatabaseHas('buyer_voucher_claims', [
            'buyer_user_id' => $this->buyer->id,
            'voucher_id' => $voucher->id,
        ]);
        $this->assertSame(0, VoucherRedemptionCounter::count((int) $voucher->id, (int) $this->buyer->id));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('seller_orders', 0);

        $this->get(route('buyer.rewards'))
            ->assertOk()
            ->assertSee($voucher->code)
            ->assertSee('Collected')
            ->assertDontSee(route('buyer.vouchers.claim', $voucher), escape: false);
    }

    public function test_homepage_voucher_claim_returns_json_without_reloading_rewards(): void
    {
        $voucher = $this->voucher(['code' => 'AJAXHOMECLAIM']);

        $this->actingAs($this->buyer)
            ->postJson(route('buyer.vouchers.claim', $voucher))
            ->assertOk()
            ->assertJsonPath('claimed', true)
            ->assertJsonPath('message', 'Voucher collected. Checkout will recheck its availability and order eligibility.');

        $this->assertDatabaseHas('buyer_voucher_claims', [
            'buyer_user_id' => $this->buyer->id,
            'voucher_id' => $voucher->id,
        ]);

        $this->actingAs($this->buyer)
            ->postJson(route('buyer.vouchers.claim', $voucher))
            ->assertOk()
            ->assertJsonPath('claimed', true);
        $this->assertDatabaseCount('buyer_voucher_claims', 1);
    }

    public function test_unavailable_seller_vouchers_cannot_be_claimed(): void
    {
        $cases = [
            ['PAUSEDCLAIM', ['is_active' => false], 'paused'],
            ['UPCOMINGCLAIM', ['starts_at' => now()->addDay()], 'not available yet'],
            ['EXPIREDCLAIM', ['ends_at' => now()->subDay()], 'expired'],
            ['EXHAUSTEDCLAIM', ['usage_limit' => 0], 'usage limit'],
        ];

        foreach ($cases as [$code, $overrides, $message]) {
            $voucher = $this->voucher(array_merge(['code' => $code], $overrides));
            $this->actingAs($this->buyer)
                ->from(route('buyer.rewards'))
                ->post(route('buyer.vouchers.claim', $voucher))
                ->assertRedirect(route('buyer.rewards'))
                ->assertSessionHasErrors('voucher');
            $this->assertDatabaseMissing('buyer_voucher_claims', ['voucher_id' => $voucher->id]);

            $this->assertStringContainsString($message, (string) session()->get('errors')?->first('voucher'));
        }
    }

    public function test_claim_requires_an_active_seller_and_does_not_accept_platform_campaigns(): void
    {
        $sellerVoucher = $this->voucher(['code' => 'SELLERINACTIVECLAIM']);
        $this->sellerUser->sellerProfile->update(['status' => 'SUSPENDED']);

        $this->actingAs($this->buyer)
            ->from(route('buyer.rewards'))
            ->post(route('buyer.vouchers.claim', $sellerVoucher))
            ->assertRedirect(route('buyer.rewards'))
            ->assertSessionHasErrors('voucher');
        $this->assertDatabaseMissing('buyer_voucher_claims', ['voucher_id' => $sellerVoucher->id]);

        $campaign = Voucher::query()->create([
            'seller_profile_id' => null,
            'platform_code_key' => 'PLATFORMCLAIM10',
            'code' => 'PLATFORMCLAIM10',
            'name' => 'Platform campaign',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);
        $this->post(route('buyer.vouchers.claim', $campaign))->assertNotFound();
        $this->assertDatabaseMissing('buyer_voucher_claims', ['voucher_id' => $campaign->id]);
    }

    public function test_checkout_requires_a_collected_voucher_and_rechecks_expiry_pause_and_limits(): void
    {
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->firstOrFail();
        $item = app(CartService::class)->add($this->buyer, $product, (int) $variant->id, 1);
        $voucher = $this->voucher([
            'code' => 'CLAIMRECHECK',
            'discount_type' => 'PERCENT',
            'discount_value' => 50,
            'minimum_order_amount' => (float) $variant->final_price + 1,
            'maximum_discount_amount' => 2,
            'usage_limit' => 5,
            'per_user_limit' => 2,
        ]);
        $checkout = app(CheckoutService::class);

        try {
            $checkout->preview(collect([$item]), $voucher->code, $this->buyer);
            $this->fail('An uncollected seller voucher must not be applied.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Collect this Seller voucher', (string) $exception->errors()['voucher_code'][0]);
        }
        $this->assertSame(0, VoucherRedemptionCounter::count((int) $voucher->id));
        $this->assertDatabaseCount('orders', 0);

        $this->actingAs($this->buyer)->post(route('buyer.vouchers.claim', $voucher))->assertRedirect();
        try {
            $checkout->preview(collect([$item]), $voucher->code, $this->buyer);
            $this->fail('The minimum spend must be checked at checkout.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Add PHP', (string) $exception->errors()['voucher_code'][0]);
        }

        $voucher->update(['minimum_order_amount' => 0]);
        $preview = $checkout->preview(collect([$item]), $voucher->code, $this->buyer);
        $this->assertEquals(2.00, $preview['discount_total']);
        $this->assertSame(0, VoucherRedemptionCounter::count((int) $voucher->id));

        foreach ([
            [['is_active' => false], 'not active yet'],
            [['is_active' => true, 'ends_at' => now()->subMinute()], 'expired'],
            [['is_active' => true, 'ends_at' => now()->addDay(), 'usage_limit' => 0], 'redemption limit'],
        ] as [$changes, $expected]) {
            $voucher->update($changes);
            try {
                $checkout->preview(collect([$item]), $voucher->code, $this->buyer);
                $this->fail('Checkout must reject a collected voucher that is no longer redeemable.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($expected, mb_strtolower((string) $exception->errors()['voucher_code'][0]));
            }
            $this->assertDatabaseHas('buyer_voucher_claims', [
                'buyer_user_id' => $this->buyer->id,
                'voucher_id' => $voucher->id,
            ]);
        }
    }

    public function test_claim_belongs_only_to_its_buyer_and_collected_voucher_is_seller_scoped(): void
    {
        $voucher = $this->voucher(['code' => 'OWNERSCOPEDCLAIM']);
        $otherBuyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->buyer)->post(route('buyer.vouchers.claim', $voucher))->assertRedirect();
        $this->assertDatabaseMissing('buyer_voucher_claims', [
            'buyer_user_id' => $otherBuyer->id,
            'voucher_id' => $voucher->id,
        ]);
        $this->actingAs($otherBuyer)
            ->get(route('buyer.rewards'))
            ->assertOk()
            ->assertSee($voucher->code)
            ->assertSee('Claim')
            ->assertDontSee('Collected');

        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->firstOrFail();
        $item = app(CartService::class)->add($otherBuyer, $product, (int) $variant->id, 1);

        try {
            app(CheckoutService::class)->preview(collect([$item]), $voucher->code, $otherBuyer);
            $this->fail('Another Buyer must claim the Seller voucher independently.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Collect this Seller voucher', (string) $exception->errors()['voucher_code'][0]);
        }

        $wrongSellerItem = $this->itemForAnotherSeller($otherBuyer, (int) $product->seller_profile_id);
        try {
            app(CheckoutService::class)->preview(collect([$wrongSellerItem]), $voucher->code, $this->buyer);
            $this->fail('A Seller voucher cannot apply to another Seller’s items.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('voucher_code', $exception->errors());
        }
    }

    public function test_database_unique_constraint_blocks_duplicate_claim_rows(): void
    {
        $voucher = $this->voucher();
        BuyerVoucherClaim::query()->create(['buyer_user_id' => $this->buyer->id, 'voucher_id' => $voucher->id]);

        try {
            BuyerVoucherClaim::query()->create(['buyer_user_id' => $this->buyer->id, 'voucher_id' => $voucher->id]);
            $this->fail('The unique Buyer/voucher constraint must reject a duplicate row.');
        } catch (QueryException) {
            $this->assertDatabaseCount('buyer_voucher_claims', 1);
        }
    }

    public function test_follower_reward_is_claimable_and_redeemable_only_while_following_the_store(): void
    {
        $voucher = $this->voucher([
            'code' => 'FOLLOWERREWARD10',
            'visibility' => 'FOLLOWERS',
        ]);
        $claimService = app(\App\Services\Marketplace\BuyerVoucherClaimService::class);

        $this->assertFalse($claimService->claimableSellerVouchers($this->buyer)->contains('id', $voucher->id));
        $this->actingAs($this->buyer)
            ->from(route('buyer.rewards'))
            ->post(route('buyer.vouchers.claim', $voucher))
            ->assertRedirect(route('buyer.rewards'))
            ->assertSessionHasErrors('voucher');

        SellerFollower::query()->create([
            'buyer_user_id' => $this->buyer->id,
            'seller_profile_id' => $this->sellerId,
        ]);
        $this->assertTrue($claimService->claimableSellerVouchers($this->buyer)->contains('id', $voucher->id));
        $this->get(route('buyer.shop', ['seller' => $this->sellerId]))->assertOk()->assertSee('Collect a store voucher');
        $this->get(route('buyer.product-details', ['slug' => 'sample-handmade-tote']))
            ->assertOk()
            ->assertSee('Store vouchers for this product')
            ->assertSee($voucher->name)
            ->assertSee('Collect voucher');
        $this->actingAs($this->buyer)->post(route('buyer.vouchers.claim', $voucher))->assertRedirect();

        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->firstOrFail();
        $item = app(CartService::class)->add($this->buyer, $product, (int) $variant->id, 1);
        $preview = app(CheckoutService::class)->preview(
            collect([$item]),
            ['seller' => [$this->sellerId => $voucher->code]],
            $this->buyer,
        );
        $this->assertGreaterThan(0, $preview['groups'][$this->sellerId]['seller_voucher_discount']);

        SellerFollower::query()->where('buyer_user_id', $this->buyer->id)->where('seller_profile_id', $this->sellerId)->delete();
        try {
            app(CheckoutService::class)->preview(
                collect([$item]),
                ['seller' => [$this->sellerId => $voucher->code]],
                $this->buyer,
            );
            $this->fail('A buyer who unfollowed a store cannot use its follower voucher.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Follow this store to use its follower reward.', (string) $exception->errors()['seller_voucher_codes.'.$this->sellerId][0]);
        }
    }

    public function test_invalid_legacy_percentage_is_hidden_from_claims_and_rejected_at_checkout(): void
    {
        $voucher = $this->voucher([
            'code' => 'LEGACY500OFF',
            'discount_type' => 'PERCENT',
            'discount_value' => 500,
            'requires_review' => false,
        ]);
        $this->assertTrue($voucher->requiresReview());
        $this->assertFalse(app(\App\Services\Marketplace\BuyerVoucherClaimService::class)
            ->claimableSellerVouchers($this->buyer)->contains('id', $voucher->id));

        $this->actingAs($this->buyer)
            ->get(route('buyer.rewards'))
            ->assertOk()
            ->assertDontSee('LEGACY500OFF')
            ->assertDontSee('500% off');
        $this->actingAs($this->buyer)
            ->from(route('buyer.rewards'))
            ->post(route('buyer.vouchers.claim', $voucher))
            ->assertRedirect(route('buyer.rewards'))
            ->assertSessionHasErrors('voucher');

        BuyerVoucherClaim::query()->create(['buyer_user_id' => $this->buyer->id, 'voucher_id' => $voucher->id]);
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->firstOrFail();
        $item = app(CartService::class)->add($this->buyer, $product, (int) $variant->id, 1);
        try {
            app(CheckoutService::class)->preview(collect([$item]), ['seller' => [$this->sellerId => $voucher->code]], $this->buyer);
            $this->fail('An invalid legacy voucher cannot be redeemed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('under review', (string) $exception->errors()['seller_voucher_codes.'.$this->sellerId][0]);
        }

        $voucherReviewMigration = require base_path('database/migrations/2026_10_29_000300_add_voucher_review_flags.php');
        $voucherReviewMigration->up();
        $voucherReviewMigration->up();
        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'is_active' => false,
            'requires_review' => true,
        ]);
        $this->assertDatabaseHas('buyer_voucher_claims', [
            'buyer_user_id' => $this->buyer->id,
            'voucher_id' => $voucher->id,
        ]);
    }

    private function voucher(array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'seller_profile_id' => $this->sellerId,
            'code' => 'CLAIM'.str()->upper(str()->random(10)),
            'name' => 'Claimable seller voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(2),
            'is_active' => true,
        ], $overrides));
    }

    private function itemForAnotherSeller(User $buyer, int $currentSellerId)
    {
        $variant = ProductVariant::query()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->whereHas('product', fn ($query) => $query->where('seller_profile_id', '<>', $currentSellerId))
            ->firstOrFail();
        $product = Product::query()->findOrFail($variant->product_id);

        return app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
    }
}
