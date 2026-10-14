<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SellerDropoffWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_parcel_uses_the_normal_rider_pickup_queue_and_cannot_be_received_directly(): void
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'code' => 'PICKUP-ONLY-CENTER',
            'business_name' => 'Pickup Only Center',
            'status' => 'ACTIVE',
        ]);
        $category = Category::create(['name' => 'Pickup Only Category', 'slug' => 'pickup-only-category', 'is_active' => true]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Pickup Only Seller',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'PICKUP-ONLY-ORDER',
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'grand_total' => 100,
            'placed_at' => now(),
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $buyer->name,
            'contact_number' => $buyer->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Buyer Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'PICKUP-ONLY-SELLER-ORDER',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'PLACED',
            'grand_total' => 100,
        ]);
        $workflow = app(ShipmentWorkflowService::class);
        $workflow->sellerTransition($sellerOrder, 'confirm', $sellerUser);
        $workflow->sellerTransition($sellerOrder, 'prepare', $sellerUser);
        $workflow->sellerTransition($sellerOrder, 'ready', $sellerUser);

        $shipment = $sellerOrder->shipment()->firstOrFail();
        $pickupRequest = $shipment->pickupRequests()->firstOrFail();

        $this->assertFalse(Route::has('seller.logistics.pickup'));
        $this->assertSame('READY_FOR_PICKUP', $shipment->current_status);
        $this->assertSame('PENDING', $pickupRequest->status);
        $this->assertStringStartsWith('LOGISTICS_PICKUP:', (string) $pickupRequest->notes);
        $this->assertStringNotContainsString('SELLER_DROPOFF:', (string) $pickupRequest->notes);

        $this->expectException(RuntimeException::class);
        $workflow->receiveAtCenter($shipment, $center, $centerUser, $shipment->tracking_number, 'BARCODE');
    }
}
