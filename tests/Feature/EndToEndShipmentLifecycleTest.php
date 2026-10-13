<?php

namespace Tests\Feature;

use App\Models\Admin\CommissionTransaction;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EndToEndShipmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_completes_from_seller_through_pickup_sorting_delivery_and_buyer_receipt(): void
    {
        $this->withoutVite();
        Storage::fake('public');
        $workflow = app(ShipmentWorkflowService::class);

        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'code' => 'E2E-CENTER',
            'business_name' => 'E2E Sorting Center',
            'status' => 'ACTIVE',
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'E2E-AREA',
            'name' => 'E2E Delivery Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => 'P-E2E',
            'province_name' => 'Test Province',
            'municipality_code' => 'M-E2E',
            'municipality_name' => 'Test Municipality',
            'barangay_code' => 'B-E2E',
            'barangay_name' => 'Test Barangay',
        ]);

        $category = Category::create(['name' => 'E2E Category', 'slug' => 'e2e-category', 'is_active' => true]);
        $sellerAddress = Address::create([
            'user_id' => $sellerUser->id,
            'label' => 'Business',
            'recipient_name' => $sellerUser->name,
            'contact_number' => $sellerUser->contact_number,
            'province_code' => 'P-E2E',
            'province_name' => 'Test Province',
            'municipality_code' => 'M-E2E',
            'municipality_name' => 'Test Municipality',
            'barangay_code' => 'B-E2E',
            'barangay_name' => 'Test Barangay',
            'street_address' => 'Seller Pickup Street',
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'E2E Store',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'E2E-ORDER-001',
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'grand_total' => 100,
            'placed_at' => now(),
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $buyer->name,
            'contact_number' => $buyer->contact_number,
            'province_code' => 'P-E2E',
            'province_name' => 'Test Province',
            'municipality_code' => 'M-E2E',
            'municipality_name' => 'Test Municipality',
            'barangay_code' => 'B-E2E',
            'barangay_name' => 'Test Barangay',
            'street_address' => '1 Test Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'E2E-SELLER-ORDER-001',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'PLACED',
            'grand_total' => 100,
        ]);
        $workflow->sellerTransition($sellerOrder, 'confirm', $sellerUser);
        $workflow->sellerTransition($sellerOrder, 'prepare', $sellerUser);
        $workflow->sellerTransition($sellerOrder, 'ready', $sellerUser);
        $shipment = $sellerOrder->shipment()->firstOrFail();
        $pickupRequest = $shipment->pickupRequests()->firstOrFail();

        $this->actingAs($centerUser)
            ->get(route('logistics.pickups'))
            ->assertOk()
            ->assertSee($shipment->tracking_number);

        $workflow->approvePickup($pickupRequest, $centerUser);

        $pickupRiderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $pickupRider = $this->rider($pickupRiderUser, $center);
        RiderAreaAssignment::create([
            'rider_profile_id' => $pickupRider->id,
            'service_area_id' => $area->id,
            'assigned_by_user_id' => $centerUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $pickupAssignment = $workflow->assignRider($shipment, $pickupRider, 'PICKUP', $centerUser);
        $workflow->riderTransition($pickupAssignment, 'accept', $pickupRiderUser);
        $workflow->riderTransition($pickupAssignment, 'start', $pickupRiderUser);
        $workflow->riderTransition($pickupAssignment, 'pickup_complete', $pickupRiderUser, [
            'scan_method' => 'BARCODE',
            'scanned_code' => $shipment->tracking_number,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Cancel Order');
        $this->actingAs($buyer)
            ->post(route('buyer.orders.cancel', $order))
            ->assertStatus(409);

        $workflow->receiveAtCenter($shipment->fresh(), $center, $centerUser, $shipment->tracking_number, 'BARCODE');
        $workflow->sortShipment($shipment->fresh(), $center, $centerUser, $area);

        $deliveryRiderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $deliveryRider = $this->rider($deliveryRiderUser, $center);
        RiderAreaAssignment::create([
            'rider_profile_id' => $deliveryRider->id,
            'service_area_id' => $area->id,
            'assigned_by_user_id' => $centerUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $deliveryAssignment = $workflow->assignRider($shipment->fresh(), $deliveryRider, 'DELIVERY', $centerUser);
        $workflow->riderTransition($deliveryAssignment, 'accept', $deliveryRiderUser);
        $workflow->riderTransition($deliveryAssignment, 'start', $deliveryRiderUser);
        $this->actingAs($deliveryRiderUser)
            ->post(route('rider.shipments.transition', $deliveryAssignment), [
                '_method' => 'PATCH',
                'action' => 'delivery_success',
                'proof_file' => UploadedFile::fake()->image('e2e-proof.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Rider assignment updated.');

        $deliveryProof = $shipment->deliveryAttempts()->where('status', 'DELIVERED')->firstOrFail();
        Storage::disk('public')->assertExists($deliveryProof->proof_path);

        $this->assertSame('PROCESSING', $order->fresh()->status);
        $this->actingAs($buyer)
            ->get(route('buyer.orders', ['status' => 'DELIVERED']))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Delivered');
        $processingResponse = $this->actingAs($buyer)
            ->get(route('buyer.orders', ['status' => 'PROCESSING']))
            ->assertOk();
        $this->assertFalse($processingResponse->viewData('orders')->getCollection()->contains('id', $order->id));

        $this->actingAs($buyer)
            ->post(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])
            ->assertRedirect();

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame('COMPLETED', $shipment->fresh()->current_status);
        $this->assertDatabaseHas('parcel_scans', [
            'shipment_id' => $shipment->id,
            'scan_type' => 'PICKUP_COLLECTED',
            'scanned_code' => $shipment->tracking_number,
            'result' => 'SUCCESS',
        ]);
        $this->assertDatabaseHas('parcel_scans', [
            'shipment_id' => $shipment->id,
            'scan_type' => 'CENTER_RECEIVE',
            'scanned_code' => $shipment->tracking_number,
            'result' => 'SUCCESS',
        ]);
        $eventStatuses = $shipment->events()->pluck('status')->all();
        $this->assertSame(['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKED_UP', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED'], array_values(array_unique($eventStatuses)));
        $this->assertEquals(10.0, (float) CommissionTransaction::where('seller_order_id', $sellerOrder->id)->value('commission_amount'));
    }

    private function rider(User $user, LogisticsCenter $center): RiderProfile
    {
        return RiderProfile::create([
            'user_id' => $user->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'E2E-PLATE-'.uniqid(),
            'drivers_license_number' => 'E2E-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
    }
}
