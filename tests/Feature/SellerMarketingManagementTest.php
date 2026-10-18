<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\Voucher;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $response = $this->actingAs($this->seller)->get(route('seller.marketing'));

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
