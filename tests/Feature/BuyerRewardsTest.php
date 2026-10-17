<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\Seller\Voucher;
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
}
