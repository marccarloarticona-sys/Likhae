<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\SellerPickupBatchService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagsanjanPickupIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const PAGSANJAN = [
        'province_code' => '0403400000', 'province_name' => 'Laguna',
        'municipality_code' => '0403419000', 'municipality_name' => 'Pagsanjan',
        'barangay_code' => '0403419001', 'barangay_name' => 'Anibong',
    ];

    private const PILA = [
        'province_code' => '0403400000', 'province_name' => 'Laguna',
        'municipality_code' => '0403422000', 'municipality_name' => 'Pila',
        'barangay_code' => '0403422015', 'barangay_name' => 'San Miguel',
    ];

    public function test_each_scanned_pagsanjan_pickup_parcel_is_independently_received_at_the_hub(): void
    {
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $centerAddress = $this->address($centerUser, self::PAGSANJAN, 'Pagsanjan Hub Road');
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'address_id' => $centerAddress->id,
            'code' => 'PAGSANJAN-INTAKE-'.uniqid(),
            'business_name' => 'Pagsanjan Logistics',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $centerUser->id,
            'approved_at' => now(),
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'PAGSANJAN-PICKUP-'.uniqid(),
            'name' => 'Anibong Seller Pickup Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create(['service_area_id' => $area->id, ...self::PAGSANJAN]);

        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $sellerAddress = $this->address($sellerUser, self::PAGSANJAN, 'Seller Street');
        $category = Category::create(['name' => 'Pagsanjan Intake', 'slug' => 'pagsanjan-intake', 'is_active' => true]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'Pagsanjan Intake Seller',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'approved_parcel_capacity' => 10,
            'plate_number' => 'PAGSANJAN-PICKUP-'.uniqid(),
            'drivers_license_number' => 'PAGSANJAN-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        RiderAreaAssignment::create([
            'rider_profile_id' => $rider->id,
            'service_area_id' => $area->id,
            'assigned_by_user_id' => $centerUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $shipments = [];
        for ($i = 1; $i <= 2; $i++) {
            $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
            $order = Order::create([
                'order_number' => 'PAGSANJAN-INTAKE-ORDER-'.uniqid(),
                'buyer_user_id' => $buyer->id,
                'status' => 'PLACED',
                'grand_total' => 100,
                'placed_at' => now(),
            ]);
            OrderAddress::create([
                'order_id' => $order->id,
                'recipient_name' => $buyer->name,
                'contact_number' => $buyer->contact_number,
                ...self::PILA,
                'street_address' => 'Pila Street '.$i,
            ]);
            $sellerOrder = SellerOrder::create([
                'seller_order_number' => 'PAGSANJAN-INTAKE-SO-'.uniqid(),
                'order_id' => $order->id,
                'seller_profile_id' => $seller->id,
                'status' => 'READY_FOR_PICKUP',
                'grand_total' => 100,
            ]);
            $shipment = Shipment::create([
                'seller_order_id' => $sellerOrder->id,
                'tracking_number' => 'PAGSANJAN-INTAKE-'.uniqid(),
                'logistics_center_id' => $center->id,
                'origin_logistics_center_id' => $center->id,
                'destination_municipality_code' => self::PILA['municipality_code'],
                'destination_province_code' => self::PILA['province_code'],
                'destination_province_name' => self::PILA['province_name'],
                'destination_municipality_name' => self::PILA['municipality_name'],
                'destination_barangay_code' => self::PILA['barangay_code'],
                'destination_barangay_name' => self::PILA['barangay_name'],
                'current_status' => 'READY_FOR_PICKUP',
            ]);
            PickupRequest::create([
                'shipment_id' => $shipment->id,
                'requested_by_user_id' => $sellerUser->id,
                'status' => 'PENDING',
                'requested_at' => now(),
                'notes' => 'LOGISTICS_PICKUP:Seller marked parcel ready for pickup.',
            ]);
            $shipments[] = $shipment;
        }

        $batches = app(SellerPickupBatchService::class);
        $trip = $batches->create($centerUser, $center, $rider->id, array_map(fn (Shipment $shipment) => $shipment->id, $shipments));
        $batches->accept($riderUser, $trip);
        $trip = $batches->start($riderUser, $trip->fresh());
        foreach ($shipments as $shipment) {
            $batches->scan($riderUser, $trip, $shipment->tracking_number, 'BARCODE');
        }
        $batches->finishSellerPickup($riderUser, $trip->fresh());

        foreach ($shipments as $shipment) {
            app(ShipmentWorkflowService::class)->receiveAtCenter(
                $shipment->fresh(),
                $center,
                $centerUser,
                $shipment->tracking_number,
                'BARCODE',
            );
            $this->assertDatabaseHas('shipments', [
                'id' => $shipment->id,
                'logistics_center_id' => $center->id,
                'current_status' => 'AT_SORTING_CENTER',
            ]);
            $this->assertDatabaseHas('parcel_scans', [
                'shipment_id' => $shipment->id,
                'scan_type' => 'CENTER_RECEIVE',
                'scanned_code' => $shipment->tracking_number,
                'result' => 'SUCCESS',
            ]);
        }
    }

    private function address(User $user, array $codes, string $street): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'label' => 'Test',
            'recipient_name' => $user->name,
            'contact_number' => $user->contact_number,
            ...$codes,
            'street_address' => $street,
        ]);
    }
}
