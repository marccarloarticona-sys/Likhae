<?php

namespace Tests\Feature;

use App\Models\Admin\Dispute;
use App\Models\Buyer\Order;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerReturnRefundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_structured_return_refund_payload_is_supported(): void
    {
        [$buyer, $order] = $this->createDeliveredOrder();
        $token = $this->issueToken($buyer);
        $payload = [
            'request_type' => 'Refund only',
            'reason_category' => 'Defective item',
            'details' => 'The device does not power on after charging.',
        ];

        $this->withToken($token)
            ->postJson(route('api.v1.buyer.orders.return-refund', $order), $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.already_submitted', false);

        $this->withToken($token)
            ->postJson(route('api.v1.buyer.orders.return-refund', $order), $payload)
            ->assertOk()
            ->assertJsonPath('data.already_submitted', true);

        $this->assertSame(1, Dispute::query()->count());
    }

    public function test_legacy_reason_only_return_refund_payload_is_supported(): void
    {
        [$buyer, $order] = $this->createDeliveredOrder();

        $response = $this->withToken($this->issueToken($buyer))
            ->postJson(route('api.v1.buyer.orders.return-refund', $order), [
                'reason' => 'The delivered item is not working correctly.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'OPEN');

        $dispute = Dispute::query()->findOrFail($response->json('data.dispute_id'));
        $this->assertStringContainsString('Return and refund', $dispute->subject);
        $this->assertSame('The delivered item is not working correctly.', $dispute->description);
    }

    public function test_legacy_return_refund_payload_is_rejected_outside_the_seven_day_delivery_window(): void
    {
        [$buyer, $order, $shipment] = $this->createDeliveredOrder();
        $token = $this->issueToken($buyer);
        $payload = [
            'request_type' => 'Return and refund',
            'reason_category' => 'Damaged item',
            'details' => 'The parcel arrived with a damaged item.',
        ];

        $shipment->update(['current_status' => 'OUT_FOR_DELIVERY']);
        $this->withToken($token)
            ->postJson(route('api.v1.buyer.orders.return-refund', $order), $payload)
            ->assertConflict();

        $shipment->update(['current_status' => 'DELIVERED']);
        $shipment->events()->firstOrFail()->update(['occurred_at' => now()->subDays(8)]);
        $this->withToken($token)
            ->postJson(route('api.v1.buyer.orders.return-refund', $order), $payload)
            ->assertConflict();

        $this->assertSame(0, Dispute::query()->count());
    }

    public function test_legacy_structured_payload_validates_details(): void
    {
        [$buyer, $order] = $this->createDeliveredOrder();

        $this->withToken($this->issueToken($buyer))
            ->postJson(route('api.v1.buyer.orders.return-refund', $order), [
                'request_type' => 'Refund only',
                'reason_category' => 'Defective item',
                'details' => 'Too short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details']);
    }

    private function createDeliveredOrder(): array
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $sellerUser = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $category = Category::create([
            'name' => 'Return API category',
            'slug' => 'return-api-category',
            'is_active' => true,
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Return API store',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'RETURN-API-ORDER',
            'buyer_user_id' => $buyer->id,
            'status' => 'PROCESSING',
            'placed_at' => now()->subDays(2),
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'RETURN-API-SELLER-ORDER',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'COMPLETED',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'RETURN-API-TRACKING',
            'destination_province_code' => 'P1',
            'destination_province_name' => 'Province',
            'destination_municipality_code' => 'M1',
            'destination_municipality_name' => 'Municipality',
            'destination_barangay_code' => 'B1',
            'destination_barangay_name' => 'Barangay',
            'current_status' => 'DELIVERED',
        ]);
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => 'DELIVERED',
            'actor_user_id' => $sellerUser->id,
            'notes' => 'Delivered to buyer.',
            'occurred_at' => now()->subDay(),
        ]);

        return [$buyer, $order, $shipment];
    }

    private function issueToken(User $user): string
    {
        $token = Str::random(64);
        $user->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addDay(),
        ])->save();

        return $token;
    }
}
