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
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\SellerPickupBatchService;
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
        $centerAddress = Address::create([
            'user_id' => $centerUser->id,
            'label' => 'Sorting Center',
            'recipient_name' => $centerUser->name,
            'contact_number' => $centerUser->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Sorting Center Street',
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'address_id' => $centerAddress->id,
            'code' => 'E2E-CENTER',
            'business_name' => 'E2E Sorting Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $centerUser->id,
            'approved_at' => now(),
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'E2E-AREA',
            'name' => 'E2E Delivery Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
        ]);

        $category = Category::create(['name' => 'E2E Category', 'slug' => 'e2e-category', 'is_active' => true]);
        $sellerAddress = Address::create([
            'user_id' => $sellerUser->id,
            'label' => 'Business',
            'recipient_name' => $sellerUser->name,
            'contact_number' => $sellerUser->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Seller Pickup Street',
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'E2E Store',
            'status' => 'ACTIVE',
        ]);
        $product = Product::create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'E2E Parcel Product',
            'slug' => 'e2e-parcel-product',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'E2E-PARCEL-SKU',
            'price' => 100,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
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
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => '1 Test Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'E2E-SELLER-ORDER-001',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'PLACED',
            'item_subtotal' => 100,
            'grand_total' => 100,
        ]);
        $sellerOrder->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'original_unit_price' => 100,
            'unit_price' => 100,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 100,
        ]);
        $workflow->sellerTransition($sellerOrder, 'confirm', $sellerUser);
        $workflow->sellerTransition($sellerOrder, 'prepare', $sellerUser);
        $workflow->sellerTransition($sellerOrder, 'pack', $sellerUser);
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
        $batches = app(SellerPickupBatchService::class);
        $pickupBatch = $batches->create($centerUser, $center, $pickupRider->id, [$shipment->id]);
        $batches->accept($pickupRiderUser, $pickupBatch);
        $pickupBatch = $batches->start($pickupRiderUser, $pickupBatch->fresh());
        $batches->scan($pickupRiderUser, $pickupBatch, $shipment->tracking_number, 'BARCODE');
        $batches->finishSellerPickup($pickupRiderUser, $pickupBatch->fresh());

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
        $localTrips = app(LocalRiderTripService::class);
        $deliveryTrip = $localTrips->createTrip($centerUser, $deliveryRider, $area, 'DELIVERY');
        $deliveryAssignment = $workflow->assignRider($shipment->fresh(), $deliveryRider, 'DELIVERY', $centerUser);
        $localTrips->addAssignment($centerUser, $deliveryTrip, $deliveryAssignment);
        $tripItem = $deliveryTrip->items()->sole();
        $localTrips->loadTrip($centerUser, $deliveryTrip, [$tripItem->id => $shipment->tracking_number]);
        $workflow->riderTransition($deliveryAssignment, 'accept', $deliveryRiderUser);
        $workflow->riderTransition($deliveryAssignment, 'start', $deliveryRiderUser);
        $this->actingAs($deliveryRiderUser)
            ->post(route('rider.shipments.transition', $deliveryAssignment), [
                '_method' => 'PATCH',
                'action' => 'delivery_success',
                'scanned_code' => $shipment->tracking_number,
                'scan_method' => 'BARCODE',
                'collected_amount' => '100.00',
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
            'approved_parcel_capacity' => 10,
            'plate_number' => 'E2E-PLATE-'.uniqid(),
            'drivers_license_number' => 'E2E-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
    }
}
