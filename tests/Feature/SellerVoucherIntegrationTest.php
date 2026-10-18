<?php

namespace Tests\Feature;

use App\Models\Seller\Voucher;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerVoucherIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seller = User::query()->where('email', 'seller@likhae.com')->sole();
    }

    public function test_seller_can_configure_existing_voucher_cap_and_buyer_limit_fields(): void
    {
        $this->actingAs($this->seller)
            ->get(route('seller.marketing'))
            ->assertOk()
            ->assertSee('name="maximum_discount_amount"', escape: false)
            ->assertSee('name="per_user_limit"', escape: false);

        $this->post(route('seller.marketing.campaigns.store'), [
            'code' => 'STORELIMIT10',
            'name' => 'Store limit voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 300,
            'maximum_discount_amount' => 50,
            'usage_limit' => 80,
            'per_user_limit' => 2,
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            // These unvalidated fields must never let a seller create a
            // platform voucher or assign a voucher to another seller.
            'seller_profile_id' => null,
            'platform_code_key' => 'SPOOFEDPLATFORM',
        ])->assertRedirect()->assertSessionHas('status', 'Voucher saved.');

        $voucher = Voucher::query()->where('code', 'STORELIMIT10')->sole();
        $this->assertSame($this->seller->sellerProfile->id, $voucher->seller_profile_id);
        $this->assertNull($voucher->platform_code_key);
        $this->assertSame('50.00', $voucher->maximum_discount_amount);
        $this->assertSame(2, $voucher->per_user_limit);
        $this->assertSame(80, $voucher->usage_limit);
        $this->assertSame('300.00', $voucher->minimum_order_amount);
    }

    public function test_seller_voucher_rejects_invalid_percentage_and_backwards_dates(): void
    {
        $this->actingAs($this->seller)
            ->from(route('seller.marketing'))
            ->post(route('seller.marketing.campaigns.store'), [
                'code' => 'TOOMUCH',
                'name' => 'Invalid percentage voucher',
                'discount_type' => 'PERCENT',
                'discount_value' => 101,
            ])
            ->assertRedirect(route('seller.marketing'))
            ->assertSessionHasErrors('discount_value');

        $this->post(route('seller.marketing.campaigns.store'), [
            'code' => 'BADDATES',
            'name' => 'Invalid date voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 20,
            'starts_at' => '2026-10-20 12:00:00',
            'ends_at' => '2026-10-19 12:00:00',
        ])->assertRedirect(route('seller.marketing'))->assertSessionHasErrors('ends_at');

        $this->assertDatabaseCount('vouchers', 0);
    }
}
