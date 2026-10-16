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
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\SellerPickupBatchService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SellerPickupBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $centerUser;
    private LogisticsCenter $center;
    private ServiceArea $area;
    private SellerProfile $seller;
    private RiderProfile $rider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $centerAddress = $this->address($this->centerUser, 'Sorting Center');
        $this->center = LogisticsCenter::create([
            'owner_user_id' => $this->centerUser->id,
            'address_id' => $centerAddress->id,
            'code' => 'BATCH-CENTER-'.uniqid(),
            'business_name' => 'Pila Logistics Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $this->centerUser->id,
            'approved_at' => now(),
        ]);
        $this->area = ServiceArea::create([
            'logistics_center_id' => $this->center->id,
            'code' => 'BATCH-AREA-'.uniqid(),
            'name' => 'San Pablo · San Miguel',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create(['service_area_id' => $this->area->id] + $this->geo());

        $this->seller = $this->newSeller('Batch Seller');
        $this->rider = $this->newRider();
    }

    public function test_01_one_ready_parcel_can_form_a_pickup_assignment(): void
    {
        try {
            $this->createBatch([]);
            $this->fail('An empty pickup assignment must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('rider_local_trips', 0);
        }

        $trip = $this->createBatch([$this->readyShipment($this->seller)]);

        $this->assertSame(1, $trip->items->count());
        $this->assertSame($this->seller->id, $trip->seller_profile_id);
    }

    public function test_02_ten_ready_parcels_fit_one_pickup_assignment(): void
    {
        $shipments = $this->readyShipments($this->seller, 10);
        $trip = $this->createBatch($shipments);

        $this->assertSame(10, $trip->items->count());
        $this->assertSame(10, $trip->approved_parcel_capacity);
    }

    public function test_03_server_rejects_eleven_parcels(): void
    {
        $this->expectException(RuntimeException::class);
        $this->createBatch($this->readyShipments($this->seller, 11));
    }

    public function test_04_rider_approved_capacity_limits_batch_below_ten(): void
    {
        $this->rider->update(['approved_parcel_capacity' => 6]);

        $this->expectException(RuntimeException::class);
        $this->createBatch($this->readyShipments($this->seller, 7));
    }

    public function test_05_batch_rejects_shipments_from_two_sellers(): void
    {
        $otherSeller = $this->newSeller('Other Seller');

        $this->expectException(RuntimeException::class);
        $this->createBatch([
            $this->readyShipment($this->seller),
            $this->readyShipment($otherSeller),
        ]);
    }

    public function test_06_two_sellers_in_one_barangay_get_separate_pickup_assignments(): void
    {
        $otherSeller = $this->newSeller('Neighbor Seller');
        $first = $this->createBatch([$this->readyShipment($this->seller)]);
        $second = $this->createBatch([$this->readyShipment($otherSeller)]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($this->seller->id, $first->seller_profile_id);
        $this->assertSame($otherSeller->id, $second->seller_profile_id);
    }

    public function test_07_shipment_in_an_active_pickup_batch_cannot_be_assigned_twice(): void
    {
        $shipment = $this->readyShipment($this->seller);
        $this->createBatch([$shipment]);

        $this->expectException(RuntimeException::class);
        $this->createBatch([$shipment]);
    }

    public function test_08_inter_logistics_vehicle_cannot_receive_seller_pickup(): void
    {
        foreach (['van', 'truck'] as $vehicle) {
            try {
                $this->createBatch([$this->readyShipment($this->seller)], $this->newRider($vehicle));
                $this->fail('An inter-logistics vehicle cannot receive a seller pickup assignment.');
            } catch (RuntimeException) {
                $this->assertDatabaseMissing('rider_local_trips', ['trip_type' => 'PICKUP', 'seller_profile_id' => $this->seller->id]);
            }
        }
    }

    public function test_09_motorcycle_and_tricycle_riders_with_matching_area_are_eligible(): void
    {
        foreach (['motorcycle', 'tricycle'] as $vehicle) {
            $rider = $this->newRider($vehicle);
            $trip = $this->createBatch([$this->readyShipment($this->seller)], $rider);
            $this->assertSame($rider->id, $trip->rider_profile_id);
        }
    }

    public function test_10_rider_scan_verifies_a_valid_assigned_waybill(): void
    {
        $shipment = $this->readyShipment($this->seller);
        [$trip, $riderUser] = $this->startBatch([$shipment]);
        $result = app(SellerPickupBatchService::class)->scan($riderUser, $trip, $shipment->tracking_number, 'BARCODE');

        $this->assertFalse($result['already_verified']);
        $this->assertSame('PICKED_UP', $shipment->fresh()->current_status);
        $this->assertDatabaseHas('parcel_scans', [
            'shipment_id' => $shipment->id,
            'scan_type' => 'PICKUP_COLLECTED',
            'scanned_code' => $shipment->tracking_number,
            'result' => 'SUCCESS',
        ]);
    }

    public function test_11_scan_of_a_waybill_outside_the_seller_batch_is_rejected(): void
    {
        $inside = $this->readyShipment($this->seller);
        $outside = $this->readyShipment($this->newSeller('Outside Batch Seller'));
        [$trip, $riderUser] = $this->startBatch([$inside]);

        try {
            app(SellerPickupBatchService::class)->scan($riderUser, $trip, $outside->tracking_number, 'MANUAL');
            $this->fail('A waybill outside the current batch must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Tracking number does not match this job.', $exception->getMessage());
            $this->assertSame('READY_FOR_PICKUP', $outside->fresh()->current_status);
            $this->assertDatabaseMissing('parcel_scans', ['shipment_id' => $outside->id, 'scan_type' => 'PICKUP_COLLECTED']);
        }
    }

    public function test_12_duplicate_rider_scan_does_not_add_another_completion_event(): void
    {
        $shipment = $this->readyShipment($this->seller);
        [$trip, $riderUser] = $this->startBatch([$shipment]);
        $service = app(SellerPickupBatchService::class);
        $service->scan($riderUser, $trip, $shipment->tracking_number, 'QR');
        $result = $service->scan($riderUser, $trip->fresh(), $shipment->tracking_number, 'QR');

        $this->assertTrue($result['already_verified']);
        $this->assertSame(1, $shipment->scans()->where('scan_type', 'PICKUP_COLLECTED')->count());
        $this->assertSame(1, $shipment->events()->where('status', 'PICKED_UP')->count());
    }

    public function test_13_batch_cannot_finish_while_one_of_seven_parcels_is_unresolved(): void
    {
        $shipments = $this->readyShipments($this->seller, 7);
        [$trip, $riderUser] = $this->startBatch($shipments);
        $service = app(SellerPickupBatchService::class);
        foreach (array_slice($shipments, 0, 6) as $shipment) {
            $service->scan($riderUser, $trip, $shipment->tracking_number, 'BARCODE');
        }

        $this->expectException(RuntimeException::class);
        $service->finishSellerPickup($riderUser, $trip->fresh());
    }

    public function test_14_scanning_all_seven_parcels_moves_each_to_picked_up(): void
    {
        $shipments = $this->readyShipments($this->seller, 7);
        [$trip, $riderUser] = $this->startBatch($shipments);
        $service = app(SellerPickupBatchService::class);
        foreach ($shipments as $shipment) {
            $service->scan($riderUser, $trip, $shipment->tracking_number, 'BARCODE');
        }
        $service->finishSellerPickup($riderUser, $trip->fresh());

        foreach ($shipments as $shipment) {
            $this->assertSame('PICKED_UP', $shipment->fresh()->current_status);
        }
    }

    public function test_15_collected_parcel_stays_picked_up_until_hub_intake(): void
    {
        $shipment = $this->readyShipment($this->seller);
        [$trip, $riderUser] = $this->startBatch([$shipment]);
        app(SellerPickupBatchService::class)->scan($riderUser, $trip, $shipment->tracking_number, 'BARCODE');
        app(SellerPickupBatchService::class)->finishSellerPickup($riderUser, $trip->fresh());

        $this->assertSame('PICKED_UP', $shipment->fresh()->current_status);
        $this->assertDatabaseMissing('shipment_events', ['shipment_id' => $shipment->id, 'status' => 'AT_SORTING_CENTER']);
    }

    public function test_16_logistics_scan_records_center_receive_and_moves_one_parcel_to_sorting_center(): void
    {
        [$trip, $riderUser, $shipments] = $this->collectedBatch(2);
        app(ShipmentWorkflowService::class)->receiveAtCenter($shipments[0], $this->center, $this->centerUser, $shipments[0]->tracking_number, 'BARCODE');

        $this->assertSame('AT_SORTING_CENTER', $shipments[0]->fresh()->current_status);
        $this->assertDatabaseHas('parcel_scans', [
            'shipment_id' => $shipments[0]->id,
            'scan_type' => 'CENTER_RECEIVE',
            'scanned_code' => $shipments[0]->tracking_number,
            'result' => 'SUCCESS',
        ]);
    }

    public function test_17_unscanned_remaining_parcel_stays_picked_up(): void
    {
        [, , $shipments] = $this->collectedBatch(2);
        app(ShipmentWorkflowService::class)->receiveAtCenter($shipments[0], $this->center, $this->centerUser, $shipments[0]->tracking_number, 'BARCODE');

        $this->assertSame('PICKED_UP', $shipments[1]->fresh()->current_status);
        $this->assertDatabaseMissing('parcel_scans', ['shipment_id' => $shipments[1]->id, 'scan_type' => 'CENTER_RECEIVE']);
    }

    public function test_18_duplicate_logistics_receive_does_not_duplicate_scan_or_transition(): void
    {
        [, , $shipments] = $this->collectedBatch(1);
        $workflow = app(ShipmentWorkflowService::class);
        $workflow->receiveAtCenter($shipments[0], $this->center, $this->centerUser, $shipments[0]->tracking_number, 'BARCODE');
        $workflow->receiveAtCenter($shipments[0]->fresh(), $this->center, $this->centerUser, $shipments[0]->tracking_number, 'BARCODE');

        $this->assertSame(1, $shipments[0]->scans()->where('scan_type', 'CENTER_RECEIVE')->count());
        $this->assertSame(1, $shipments[0]->events()->where('status', 'AT_SORTING_CENTER')->count());
    }

    public function test_19_batch_completes_only_after_all_collected_parcels_reach_the_center(): void
    {
        [$trip, , $shipments] = $this->collectedBatch(2);
        $workflow = app(ShipmentWorkflowService::class);
        $workflow->receiveAtCenter($shipments[0], $this->center, $this->centerUser, $shipments[0]->tracking_number, 'BARCODE');
        $this->assertSame(RiderLocalTrip::EN_ROUTE_TO_HUB, $trip->fresh()->status);
        $workflow->receiveAtCenter($shipments[1], $this->center, $this->centerUser, $shipments[1]->tracking_number, 'BARCODE');

        $this->assertSame(RiderLocalTrip::COMPLETED, $trip->fresh()->status);
    }

    public function test_20_only_received_parcels_appear_in_the_sorting_queue(): void
    {
        [, , $shipments] = $this->collectedBatch(2);
        app(ShipmentWorkflowService::class)->receiveAtCenter($shipments[0], $this->center, $this->centerUser, $shipments[0]->tracking_number, 'BARCODE');
        $response = $this->actingAs($this->centerUser)->get(route('logistics.sorting'));
        $response->assertOk();
        $queuedIds = $response->viewData('shipments')->getCollection()->pluck('id')->all();

        $this->assertContains($shipments[0]->id, $queuedIds);
        $this->assertNotContains($shipments[1]->id, $queuedIds);
    }

    private function collectedBatch(int $count): array
    {
        $shipments = $this->readyShipments($this->seller, $count);
        [$trip, $riderUser] = $this->startBatch($shipments);
        $service = app(SellerPickupBatchService::class);
        foreach ($shipments as $shipment) {
            $service->scan($riderUser, $trip, $shipment->tracking_number, 'BARCODE');
        }
        $trip = $service->finishSellerPickup($riderUser, $trip->fresh());

        return [$trip, $riderUser, $shipments];
    }

    private function startBatch(array $shipments, ?RiderProfile $rider = null): array
    {
        $rider ??= $this->rider;
        $trip = $this->createBatch($shipments, $rider);
        $riderUser = $rider->user;
        $service = app(SellerPickupBatchService::class);
        $service->accept($riderUser, $trip);
        $trip = $service->start($riderUser, $trip->fresh());

        return [$trip, $riderUser];
    }

    private function createBatch(array $shipments, ?RiderProfile $rider = null): RiderLocalTrip
    {
        return app(SellerPickupBatchService::class)->create(
            $this->centerUser,
            $this->center,
            (int) ($rider ?? $this->rider)->id,
            array_map(fn (Shipment $shipment): int => (int) $shipment->id, $shipments),
        );
    }

    private function readyShipments(SellerProfile $seller, int $count): array
    {
        return array_map(fn (int $number): Shipment => $this->readyShipment($seller), range(1, $count));
    }

    private function readyShipment(SellerProfile $seller): Shipment
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $order = Order::create([
            'order_number' => 'BATCH-ORDER-'.uniqid(),
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'grand_total' => 100,
            'placed_at' => now(),
        ]);
        OrderAddress::create(['order_id' => $order->id] + $this->geo() + [
            'recipient_name' => $buyer->name,
            'contact_number' => $buyer->contact_number,
            'street_address' => 'Buyer Delivery Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'BATCH-SELLER-ORDER-'.uniqid(),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
            'grand_total' => 100,
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'BATCH-TRACK-'.uniqid(),
            'logistics_center_id' => $this->center->id,
            'service_area_id' => $this->area->id,
            'destination_province_code' => $this->geo()['province_code'],
            'destination_province_name' => $this->geo()['province_name'],
            'destination_municipality_code' => $this->geo()['municipality_code'],
            'destination_municipality_name' => $this->geo()['municipality_name'],
            'destination_barangay_code' => $this->geo()['barangay_code'],
            'destination_barangay_name' => $this->geo()['barangay_name'],
            'current_status' => 'READY_FOR_PICKUP',
        ]);
        PickupRequest::create([
            'shipment_id' => $shipment->id,
            'requested_by_user_id' => $seller->user_id,
            'status' => 'PENDING',
            'requested_at' => now(),
            'notes' => 'LOGISTICS_PICKUP:Seller marked parcel ready for pickup.',
        ]);

        return $shipment;
    }

    private function newSeller(string $name): SellerProfile
    {
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $address = $this->address($sellerUser, 'Seller Pickup Street');
        $category = Category::create([
            'name' => 'Batch category '.uniqid(),
            'slug' => 'batch-category-'.uniqid(),
            'is_active' => true,
        ]);

        return SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $address->id,
            'business_name' => $name,
            'status' => 'ACTIVE',
        ]);
    }

    private function newRider(string $vehicle = 'motorcycle'): RiderProfile
    {
        $user = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $rider = RiderProfile::create([
            'user_id' => $user->id,
            'logistics_center_id' => $this->center->id,
            'vehicle_type' => $vehicle,
            'approved_parcel_capacity' => 10,
            'plate_number' => strtoupper(substr($vehicle, 0, 3)).'-'.uniqid(),
            'drivers_license_number' => 'BATCH-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        RiderAreaAssignment::create([
            'rider_profile_id' => $rider->id,
            'service_area_id' => $this->area->id,
            'assigned_by_user_id' => $this->centerUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        return $rider;
    }

    private function address(User $user, string $street): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'label' => $street,
            'recipient_name' => $user->name,
            'contact_number' => $user->contact_number,
            ...$this->geo(),
            'street_address' => $street,
        ]);
    }

    private function geo(): array
    {
        return [
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
        ];
    }
}
