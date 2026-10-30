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
use App\Models\Finance\CodCollection;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Services\Communication\ConversationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MobileRiderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_presence_uses_the_web_cache_and_respects_privacy(): void
    {
        $this->postJson('/api/v1/messages/presence')->assertUnauthorized();
        $rider = $this->createRider();
        [$assignment, $buyer] = $this->createDeliveryAssignment($rider->riderProfile);
        app(ConversationService::class)->start($rider, $buyer->id, [
            'type' => 'BUYER_RIDER', 'shipment_id' => $assignment->shipment_id,
            'order_id' => $assignment->shipment->sellerOrder->order_id,
        ]);
        $riderToken = $this->issueToken($rider);
        $buyerToken = $this->issueToken($buyer);
        $this->withToken($buyerToken)->postJson('/api/v1/buyer/messages/presence')
            ->assertOk()->assertJsonPath('online', true);
        $this->assertTrue(Cache::store('database')->has('message-presence:user:'.$buyer->id));
        $this->withToken($riderToken)->getJson('/api/v1/rider/messages')
            ->assertOk()->assertJsonPath('data.0.buyer_is_online', true);
        $this->postJson('/api/v1/messages/presence')->assertOk();
        $this->withToken($buyerToken)->getJson('/api/v1/buyer/messages')
            ->assertOk()->assertJsonPath('data.0.seller_is_online', true);

        $buyer->update(['notification_preferences' => ['show_online_status' => false]]);
        $this->postJson('/api/v1/buyer/messages/presence')
            ->assertOk()->assertJsonPath('online', false);
        $this->withToken($riderToken)->getJson('/api/v1/rider/messages')
            ->assertOk()->assertJsonPath('data.0.buyer_is_online', false);
        $this->assertFalse(Cache::store('database')->has('message-presence:user:'.$buyer->id));
    }

    public function test_legacy_pickup_resolves_owned_batch_and_uses_existing_scan_workflow(): void
    {
        $rider = $this->createRider();
        $rider->riderProfile->update(['approved_parcel_capacity' => 5]);
        [$assignment, $buyer] = $this->createDeliveryAssignment($rider->riderProfile);
        $assignment->update(['assignment_type' => 'PICKUP', 'status' => 'ASSIGNED']);
        $shipment = $assignment->shipment;
        $shipment->update(['current_status' => 'PICKUP_ASSIGNED', 'logistics_center_id' => $rider->riderProfile->logistics_center_id]);
        $batch = RiderLocalTrip::create([
            'rider_profile_id' => $rider->riderProfile->id,
            'logistics_center_id' => $rider->riderProfile->logistics_center_id,
            'seller_profile_id' => $shipment->sellerOrder->seller_profile_id,
            'trip_type' => 'PICKUP', 'status' => 'LOADING',
            'province_code' => 'P1', 'municipality_code' => 'M1', 'barangay_code' => 'B1',
            'approved_parcel_capacity' => 5, 'created_by_user_id' => $rider->id,
        ]);
        RiderLocalTripItem::create(['rider_local_trip_id' => $batch->id,
            'rider_assignment_id' => $assignment->id, 'added_by_user_id' => $rider->id, 'status' => 'QUEUED']);
        $this->withToken($this->issueToken($buyer))
            ->getJson('/api/v1/rider/pickups/'.$assignment->id.'/batch')->assertForbidden();

        $other = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => 'ACTIVE']);
        RiderProfile::create(['user_id' => $other->id,
            'logistics_center_id' => $rider->riderProfile->logistics_center_id,
            'vehicle_type' => 'motorcycle', 'plate_number' => 'OTHER',
            'drivers_license_number' => 'OTHER', 'status' => 'ACTIVE']);
        $this->withToken($this->issueToken($other))
            ->getJson('/api/v1/rider/pickups/'.$assignment->id.'/batch')->assertNotFound();

        $this->withToken($this->issueToken($rider))
            ->getJson('/api/v1/rider/pickups/'.$assignment->id.'/batch')
            ->assertOk()->assertJsonPath('data.id', $batch->id);
        $base = '/api/v1/rider/pickups/batches/'.$batch->id;
        $this->postJson($base.'/accept')->assertOk();
        $this->postJson($base.'/start')->assertOk();
        $this->postJson($base.'/scan', ['tracking_code' => 'WRONG', 'scan_method' => 'MANUAL'])
            ->assertUnprocessable();
        $this->postJson($base.'/scan', ['tracking_code' => $shipment->tracking_number, 'scan_method' => 'BARCODE'])
            ->assertOk()->assertJsonPath('data.shipment_status', 'PICKED_UP');
        $this->postJson($base.'/finish')->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('data.status', 'EN_ROUTE_TO_HUB');
        $this->assertDatabaseHas('shipments', ['id' => $shipment->id, 'current_status' => 'PICKED_UP']);
    }

    public function test_delivery_scan_cod_and_remittance_persist_through_the_real_services(): void
    {
        Storage::fake('public');
        $rider = $this->createRider();
        [$assignment, $buyer, $order] = $this->createDeliveryAssignment($rider->riderProfile);
        $shipment = $assignment->shipment;
        $shipment->update(['logistics_center_id' => $rider->riderProfile->logistics_center_id]);
        $shipment->sellerOrder->update(['grand_total' => '125.50']);
        $collection = CodCollection::create([
            'shipment_id' => $shipment->id, 'seller_order_id' => $shipment->seller_order_id,
            'order_id' => $order->id, 'rider_profile_id' => $rider->riderProfile->id,
            'logistics_center_id' => $rider->riderProfile->logistics_center_id,
            'expected_amount' => '125.50', 'status' => 'PENDING',
        ]);
        $this->withToken($this->issueToken($rider));
        $endpoint = '/api/v1/rider/assignments/'.$assignment->id;
        $payload = ['action' => 'delivery_success', 'receiver_name' => 'Receiver',
            'collected_amount' => '125.50', 'scan_method' => 'BARCODE'];
        $this->postJson($endpoint, $payload + ['_method' => 'PATCH', 'scanned_code' => 'WRONG',
            'proof_file' => UploadedFile::fake()->image('wrong.jpg')])->assertUnprocessable();
        $this->assertDatabaseHas('shipments', ['id' => $shipment->id, 'current_status' => 'OUT_FOR_DELIVERY']);
        $this->postJson($endpoint, $payload + ['_method' => 'PATCH', 'scanned_code' => $shipment->tracking_number,
            'proof_file' => UploadedFile::fake()->image('proof.jpg')])
            ->assertOk()->assertJsonPath('shipment_status', 'DELIVERED');
        $this->assertDatabaseHas('rider_assignments', ['id' => $assignment->id, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('cod_collections', ['id' => $collection->id, 'status' => 'COLLECTED', 'collected_amount' => '125.50']);
        $this->getJson('/api/v1/rider/earnings')->assertOk()->assertJsonPath('data.rider.name', $rider->name);
        $this->getJson('/api/v1/rider/remittances')->assertOk()->assertJsonPath('data.collections.0.id', $collection->id);
        $remittance = ['collection_ids' => [$collection->id], 'submitted_amount' => '125.50', 'notes' => 'Cash handed to center'];
        $receipt = $this->postJson('/api/v1/rider/remittances', $remittance)
            ->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->json('data.id');
        $this->postJson('/api/v1/rider/remittances', $remittance)->assertCreated()->assertJsonPath('data.id', $receipt);
        $this->assertDatabaseCount('rider_remittances', 1);
        $this->assertDatabaseHas('cod_collections', ['id' => $collection->id, 'status' => 'REMITTED']);
        $this->getJson('/api/v1/rider/remittances')->assertOk()
            ->assertJsonPath('data.collections', [])->assertJsonPath('data.remittances.0.verified_amount', null);
        $this->postJson('/api/v1/rider/remittances', ['collection_ids' => [$collection->id], 'submitted_amount' => '99.00'])
            ->assertUnprocessable();
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
