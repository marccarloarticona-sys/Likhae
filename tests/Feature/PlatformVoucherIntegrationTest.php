<?php

namespace Tests\Feature;

use App\Models\Seller\Voucher;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformVoucherIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_scoped_platform_voucher_and_sellers_cannot(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        $this->actingAs($admin)
            ->get(route('admin.marketing'))
            ->assertOk()
            ->assertSee('name="maximum_discount_amount"', escape: false)
            ->assertSee('name="per_user_limit"', escape: false);

        $this->post(route('admin.marketing.campaigns.store'), [
            'code' => 'PLATFORMCAP10',
            'name' => 'Platform cap voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'benefit_scope' => 'PRODUCT',
            'minimum_order_amount' => 250,
            'maximum_discount_amount' => 40,
            'usage_limit' => 100,
            'per_user_limit' => 1,
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));

        $voucher = Voucher::query()->where('code', 'PLATFORMCAP10')->sole();
        $this->assertNull($voucher->seller_profile_id);
        $this->assertSame('PLATFORMCAP10', $voucher->platform_code_key);
        $this->assertSame('PRODUCT', $voucher->benefit_scope);
        $this->assertSame('40.00', $voucher->maximum_discount_amount);
        $this->assertSame(1, $voucher->per_user_limit);
        $this->assertSame(100, $voucher->usage_limit);

        $this->actingAs($seller)
            ->post(route('admin.marketing.campaigns.store'), [
                'code' => 'SELLERPLATFORM',
                'name' => 'Unauthorized platform voucher',
                'discount_type' => 'FIXED',
                'discount_value' => 10,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('vouchers', ['code' => 'SELLERPLATFORM']);
    }
}
