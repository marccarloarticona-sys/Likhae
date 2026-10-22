<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\Seller\Voucher;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerRewardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_open_each_rewards_tab_and_see_available_vouchers(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();

        Voucher::query()->create([
            'seller_profile_id' => $product->seller_profile_id,
            'code' => 'REWARD10',
            'name' => 'Rewards discount',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.rewards'))
            ->assertOk()
            ->assertSee('Rewards &amp; Vouchers', escape: false)
            ->assertSee('REWARD10')
            ->assertSee('Seller vouchers')
            ->assertSee('Claim');

        foreach (['points' => 'Reward Points'] as $tab => $heading) {
            $this->get(route('buyer.rewards', ['tab' => $tab]))
                ->assertOk()
                ->assertSee($heading);
        }

        $this->get(route('buyer.rewards', ['tab' => 'cashback']))
            ->assertOk()
            ->assertSee('My Vouchers')
            ->assertDontSee('Cashback');
    }

    public function test_use_now_opens_the_voucher_sellers_store_and_marks_it_selected_without_redeeming(): void
    {
        $this->seed(DatabaseSeeder::class);
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $product->seller_profile_id,
            'code' => 'USE-STORE-10',
            'name' => 'Seller store ten percent',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 500,
            'is_active' => true,
        ]);
        BuyerVoucherClaim::query()->create([
            'buyer_user_id' => $buyer->id,
            'voucher_id' => $voucher->id,
        ]);
        $storeUrl = route('buyer.shop', [
            'seller' => $product->seller_profile_id,
            'voucher' => $voucher->id,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.rewards'))
            ->assertOk()
            ->assertSee('Use Now')
            ->assertSee('href="'.$storeUrl.'"', escape: false);

        $store = $this->get($storeUrl)
            ->assertOk();
        $this->assertCount(1, $store->viewData('storeVouchers'));
        $this->assertTrue((bool) $store->viewData('storeVouchers')->first()->selected_for_store);
        $store
            ->assertSee('10% OFF')
            ->assertSee('voucher selected')
            ->assertSee('10% OFF voucher', escape: false)
            ->assertSee('Min. &#8369;500.00', escape: false);

        $this->assertSame($voucher->id, (int) $store->viewData('storeVouchers')->first()->id);
        $this->assertDatabaseHas('buyer_voucher_claims', [
            'buyer_user_id' => $buyer->id,
            'voucher_id' => $voucher->id,
        ]);
        $this->assertDatabaseCount('seller_orders', 0);
    }
}
