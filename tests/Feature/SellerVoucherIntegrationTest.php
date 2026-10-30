<?php

namespace Tests\Feature;

use App\Models\Seller\Voucher;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
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
            ->get(route('seller.marketing', ['section' => 'vouchers']))
            ->assertOk()
            ->assertSee('name="maximum_discount_amount"', escape: false)
            ->assertSee('name="per_user_limit"', escape: false);

        $this->post(route('seller.marketing.campaigns.store'), [
            'code' => 'STORELIMIT10',
            'name' => 'Store limit voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'visibility' => 'FOLLOWERS',
            'minimum_order_amount' => 300,
            'maximum_discount_amount' => 50,
            'seller_discount_budget_total' => 5000,
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
        $this->assertSame('5000.00', $voucher->seller_discount_budget_total);
        $this->assertSame('FOLLOWERS', $voucher->visibility);
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

        $this->assertDatabaseMissing('vouchers', ['code' => 'TOOMUCH']);
        $this->assertDatabaseMissing('vouchers', ['code' => 'BADDATES']);
    }

    public function test_seller_can_create_a_voucher_for_an_active_flash_sale_variant(): void
    {
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->firstOrFail();
        $product->update([
            'flash_sale_starts_at' => now()->subMinute(),
            'flash_sale_ends_at' => now()->addHour(),
            'flash_sale_campaign_key' => (string) str()->uuid(),
        ]);
        $variant->update([
            'flash_sale_price' => round((float) $variant->final_price * 0.8, 2),
            'flash_sale_allocation' => 2,
            'flash_sale_sold' => 0,
        ]);

        $this->actingAs($this->seller)
            ->from(route('seller.marketing', ['section' => 'vouchers']))
            ->post(route('seller.marketing.campaigns.store', ['section' => 'vouchers']), [
                'code' => 'FLASHVOUCHEROK',
                'name' => 'Flash sale compatible voucher',
                'discount_type' => 'FIXED',
                'discount_value' => 1,
                'applies_to' => 'VARIANTS',
                'variant_ids' => [$variant->id],
                'visibility' => 'PUBLIC',
                'minimum_order_amount' => 0,
            ])
            ->assertRedirect(route('seller.marketing', ['section' => 'vouchers']))
            ->assertSessionHas('status', 'Voucher saved.');

        $this->assertDatabaseHas('vouchers', ['code' => 'FLASHVOUCHEROK', 'seller_profile_id' => $this->seller->sellerProfile->id]);
    }
}
