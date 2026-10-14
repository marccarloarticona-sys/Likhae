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
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderMobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_rider_endpoints_return_the_existing_rider_data_shapes(): void
    {
        $rider = $this->createRider();
        $token = $this->issueToken($rider);

        $this->withToken($token)
            ->getJson(route('api.v1.rider.dashboard'))
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['stats', 'recent_parcels'],
            ]);

        $this->getJson(route('api.v1.rider.assignments.index'))
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.stats.assigned', 0)
            ->assertJsonStructure(['data' => ['stats']]);

        $this->getJson(route('api.v1.rider.pickups.index'))
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.stats.assigned', 0)
            ->assertJsonStructure(['data' => ['stats']]);

        $this->getJson(route('api.v1.rider.history.index'))
            ->assertOk()
            ->assertJsonPath('data.items', []);

        $this->getJson(route('api.v1.rider.earnings.index'))
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.notice', 'Recorded earnings from completed pickup and delivery assignments.');

        $this->getJson(route('api.v1.rider.profile.show'))
            ->assertOk()
            ->assertJsonPath('data.user.id', $rider->id)
            ->assertJsonPath('data.rider.vehicle_type', 'motorcycle');

        $this->getJson(route('api.v1.rider.messages.index'))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_rider_mobile_endpoints_require_a_rider_profile(): void
    {
        $this->getJson(route('api.v1.rider.dashboard'))->assertUnauthorized();

        $seller = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->withToken($this->issueToken($seller))
            ->getJson(route('api.v1.rider.dashboard'))
            ->assertForbidden();
    }

    public function test_active_delivery_locations_are_saved_and_returned_to_the_order_buyer(): void
    {
        $rider = $this->createRider();
        [$assignment, $buyer, $order] = $this->createDeliveryAssignment($rider->riderProfile);

        $this->withToken($this->issueToken($rider))
            ->postJson(route('api.v1.rider.assignments.location', $assignment), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy' => 12.5,
                'recorded_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.latitude', 14.5995)
            ->assertJsonPath('data.longitude', 120.9842);

        $this->withToken($this->issueToken($rider))
            ->postJson(route('api.v1.rider.assignments.location', $assignment), [
                'latitude' => 14.6001,
                'longitude' => 120.9851,
                'accuracy' => 10.2,
                'recorded_at' => now()->addSecond()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.latitude', 14.6001);

        $this->assertDatabaseCount('rider_assignment_locations', 1);
        $this->assertEqualsWithDelta(
            14.6001,
            $assignment->liveLocation()->firstOrFail()->latitude,
            0.000001,
        );

        $this->withToken($this->issueToken($buyer))
            ->getJson(route('api.v1.buyer.orders.index'))
            ->assertOk()
            ->assertJsonPath('data.0.rider_location.latitude', 14.6001)
            ->assertJsonPath('data.0.rider_location.longitude', 120.9851);

        $this->withToken($this->issueToken($buyer))
            ->getJson(route('api.v1.buyer.orders.live-location', $order))
            ->assertOk()
            ->assertJsonPath('data.rider_location.latitude', 14.6001);

        $otherBuyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->withToken($this->issueToken($otherBuyer))
            ->getJson(route('api.v1.buyer.orders.live-location', $order))
            ->assertForbidden();

        $assignment->update(['status' => 'ACCEPTED']);
        $this->assertDatabaseCount('rider_assignment_locations', 0);
        $this->withToken($this->issueToken($rider))
            ->postJson(route('api.v1.rider.assignments.location', $assignment), [
                'latitude' => 14.601,
                'longitude' => 120.986,
                'accuracy' => 8.8,
                'recorded_at' => now()->addSeconds(2)->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.latitude', 14.601)
            ->assertJsonPath('data.longitude', 120.986);
    }

    public function test_live_location_rejects_poor_accuracy_stale_timestamps_and_inactive_assignments(): void
    {
        $rider = $this->createRider();
        [$assignment] = $this->createDeliveryAssignment($rider->riderProfile);
        $token = $this->issueToken($rider);

        $this->withToken($token)
            ->postJson(route('api.v1.rider.assignments.location', $assignment), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy' => 251,
                'recorded_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accuracy');

        $this->withToken($token)
            ->postJson(route('api.v1.rider.assignments.location', $assignment), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy' => 12,
                'recorded_at' => now()->subMinutes(3)->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recorded_at');

        $assignment->update(['status' => 'ASSIGNED']);
        $this->withToken($token)
            ->postJson(route('api.v1.rider.assignments.location', $assignment), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy' => 12,
                'recorded_at' => now()->toIso8601String(),
            ])
            ->assertStatus(409);

        $otherRider = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);
        RiderProfile::create([
            'user_id' => $otherRider->id,
            'logistics_center_id' => $rider->riderProfile->logistics_center_id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'OTHER-RIDER-PLATE',
            'drivers_license_number' => 'OTHER-RIDER-LICENSE',
            'status' => 'ACTIVE',
        ]);
        $this->withToken($this->issueToken($otherRider))
            ->postJson(route('api.v1.rider.assignments.location', $assignment), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy' => 12,
                'recorded_at' => now()->toIso8601String(),
            ])
            ->assertForbidden();
    }

    private function createRider(): User
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'MOBILE-RIDER-CENTER',
            'business_name' => 'Mobile Rider Center',
            'status' => 'ACTIVE',
        ]);
        $rider = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);

        RiderProfile::create([
            'user_id' => $rider->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'MOBILE-RIDER-PLATE',
            'drivers_license_number' => 'MOBILE-RIDER-LICENSE',
            'status' => 'ACTIVE',
        ]);

        return $rider;
    }

    /**
     * @return array{RiderAssignment, User, Order}
     */
    private function createDeliveryAssignment(RiderProfile $rider): array
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create([
            'name' => 'Rider API category',
            'slug' => 'rider-api-category',
            'is_active' => true,
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Rider API Store',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'RIDER-API-ORDER',
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
            'street_address' => '1 Rider API Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'RIDER-API-SELLER-ORDER',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'RIDER-API-TRACKING',
            'destination_province_code' => 'P1',
            'destination_province_name' => 'Province',
            'destination_municipality_code' => 'M1',
            'destination_municipality_name' => 'Municipality',
            'destination_barangay_code' => 'B1',
            'destination_barangay_name' => 'Barangay',
            'current_status' => 'OUT_FOR_DELIVERY',
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY,
            'status' => 'IN_PROGRESS',
            'assigned_at' => now(),
            'started_at' => now(),
        ]);

        return [$assignment, $buyer, $order];
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
