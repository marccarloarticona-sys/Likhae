<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderPickupScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_pickup_assignment_without_a_seller_batch_cannot_be_scanned_individually(): void
    {
        $this->withoutVite();
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'PICKUP-SCAN-CENTER',
            'business_name' => 'Pickup Scan Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'PICKUP-SCAN-PLATE',
            'drivers_license_number' => 'PICKUP-SCAN-LICENSE',
            'status' => 'ACTIVE',
        ]);
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create(['name' => 'Scan category', 'slug' => 'scan-category', 'is_active' => true]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Scan test store',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'PICKUP-SCAN-ORDER',
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $buyer->name,
            'contact_number' => $buyer->contact_number,
            'province_code' => 'P1',
            'province_name' => 'Province',
            'municipality_code' => 'M1',
            'municipality_name' => 'Municipality',
            'barangay_code' => 'B1',
            'barangay_name' => 'Barangay',
            'street_address' => '1 Test Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'PICKUP-SCAN-SELLER-ORDER',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
        ]);
        $tracking = 'PICKUP-SCAN-WAYBILL';
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => $tracking,
            'logistics_center_id' => $center->id,
            'destination_province_code' => 'P1',
            'destination_province_name' => 'Province',
            'destination_municipality_code' => 'M1',
            'destination_municipality_name' => 'Municipality',
            'destination_barangay_code' => 'B1',
            'destination_barangay_name' => 'Barangay',
            'current_status' => 'READY_FOR_PICKUP',
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => 'PICKUP',
            'status' => 'IN_PROGRESS',
            'assigned_at' => now(),
            'started_at' => now(),
        ]);

        $this->actingAs($riderUser)
            ->postJson(route('rider.assignments.location', $assignment), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy' => 15,
                'recorded_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.accuracy', 15);

        $this->actingAs($riderUser)
            ->get(route('rider.pickups.show', $assignment))
            ->assertStatus(409);

        $this->actingAs($riderUser)
            ->from(route('rider.pickups.show', $assignment))
            ->patch(route('rider.assignments.transition', $assignment), [
                'action' => 'pickup_complete',
                'scanned_code' => $tracking,
            ])
            ->assertRedirect(route('rider.pickups.show', $assignment))
            ->assertSessionHasErrors('action');

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'current_status' => 'READY_FOR_PICKUP',
        ]);
    }
}
