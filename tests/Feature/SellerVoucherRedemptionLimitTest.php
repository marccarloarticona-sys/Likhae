<?php

namespace Tests\Feature;

use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerVoucherRedemptionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_buyer_redemption_limit_is_scoped_to_buyer_and_cancelled_use_does_not_count(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole()->sellerProfile;
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->firstOrFail();
        $cartItem = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $otherCartItem = app(CartService::class)->add($otherBuyer, $product, (int) $variant->id, 1);
        $voucher = Voucher::query()->create([
            'seller_profile_id' => $seller->id,
            'code' => 'BUYERLIMITB6',
            'name' => 'Per buyer test voucher',
            'discount_type' => 'FIXED',
            'discount_value' => 10,
            'minimum_order_amount' => 0,
            'usage_limit' => 3,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        $this->recordUse($buyer, $seller->id, $voucher, 'PLACED');
        $this->recordUse($buyer, $seller->id, $voucher, 'CANCELLED');

        $checkout = app(CheckoutService::class);
        $buyerVouchers = $checkout->availableVouchers(collect([$cartItem]), $buyer)->get((int) $seller->id, collect());
        $otherBuyerVouchers = $checkout->availableVouchers(collect([$otherCartItem]), $otherBuyer)->get((int) $seller->id, collect());

        $this->assertCount(0, $buyerVouchers);
        $this->assertSame([$voucher->id], $otherBuyerVouchers->modelKeys());
    }

    private function recordUse(User $buyer, int $sellerProfileId, Voucher $voucher, string $status): void
    {
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
            'seller_profile_id' => $sellerProfileId,
            'voucher_id' => $voucher->id,
            'status' => $status,
            'item_subtotal' => 100,
            'voucher_discount' => 10,
            'shipping_fee' => 0,
            'grand_total' => 90,
        ]);
    }
}
