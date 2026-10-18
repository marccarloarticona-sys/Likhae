<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentWorkflowGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbatched_pickup_cannot_bypass_seller_specific_assignment(): void
    {
        [$user, $shipment, $assignment] = $this->pickupFixture();

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->riderTransition($assignment, 'pickup_complete', $user, [
            'scanned_code' => $shipment->tracking_number,
        ]);
    }

    public function test_started_assignment_cannot_be_rejected(): void
    {
        [$user, $shipment, $assignment] = $this->pickupFixture('IN_PROGRESS');

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->riderTransition($assignment, 'reject', $user, ['reason' => 'too late']);
    }

    public function test_pickup_cannot_start_without_a_seller_specific_batch(): void
    {
        [$user, $shipment, $assignment] = $this->pickupFixture('ACCEPTED');

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->riderTransition($assignment, 'start', $user);
    }

    public function test_stale_rider_accept_request_returns_a_validation_error_instead_of_a_server_error(): void
    {
        [$user, $shipment, $assignment] = $this->pickupFixture('ACCEPTED');

        $this->actingAs($user)
            ->patch(route('rider.shipments.transition', $assignment), ['action' => 'accept'])
            ->assertRedirect()
            ->assertSessionHasErrors('action');
    }

    public function test_center_receipt_requires_tracking_code_and_correct_center(): void
    {
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'code' => 'CENTER-RECEIVE-TEST',
            'business_name' => 'Receive Test Center',
            'status' => 'ACTIVE',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $this->sellerOrder()->id,
            'tracking_number' => 'TRACK-RECEIVE-TEST',
            'logistics_center_id' => $center->id,
            'destination_province_code' => 'P1',
            'destination_province_name' => 'Province',
            'destination_municipality_code' => 'M1',
            'destination_municipality_name' => 'Municipality',
            'destination_barangay_code' => 'B1',
            'destination_barangay_name' => 'Barangay',
            'current_status' => 'PICKED_UP',
        ]);

        try {
            app(ShipmentWorkflowService::class)->receiveAtCenter($shipment, $center, $centerUser, '');
            $this->fail('Center receipt should require a matching scan.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('shipments', ['id' => $shipment->id, 'current_status' => 'PICKED_UP']);
        }
    }

    public function test_sorting_rejects_an_area_when_address_codes_differ(): void
    {
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'code' => 'CENTER-SORT-NAME-TEST',
            'business_name' => 'Sorting Name Test Center',
            'status' => 'ACTIVE',
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'AREA-SAN-MIGUEL',
            'name' => 'Barangay San Miguel - Pila Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403422000',
            'municipality_name' => 'Pila',
            'barangay_code' => '0403422015',
            'barangay_name' => 'San Miguel',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $this->sellerOrder()->id,
            'tracking_number' => 'TRACK-SORT-NAME-TEST',
            'logistics_center_id' => $center->id,
            'destination_province_code' => 'LAG',
            'destination_province_name' => 'Laguna',
            'destination_municipality_code' => 'PILA',
            'destination_municipality_name' => 'Pila',
            'destination_barangay_code' => 'SAN-MIGUEL',
            'destination_barangay_name' => 'San Miguel',
            'current_status' => 'AT_SORTING_CENTER',
        ]);

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->sortShipment($shipment, $center, $centerUser, $area);
    }

    public function test_delivery_assignment_rejects_a_rider_from_the_wrong_service_area(): void
    {
        [$center, $centerUser, $shipment, $area] = $this->deliveryAssignmentFixture();
        $wrongArea = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'AREA-WRONG-'.uniqid(),
            'name' => 'Wrong Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $wrongArea->id,
            'province_code' => 'WRONG-P',
            'province_name' => 'Target Province',
            'municipality_code' => 'WRONG-M',
            'municipality_name' => 'Target Municipality',
            'barangay_code' => 'WRONG-B',
            'barangay_name' => 'Target Barangay',
        ]);
        $rider = $this->riderFor($center, 'ACTIVE');
        RiderAreaAssignment::create([
            'rider_profile_id' => $rider->id,
            'service_area_id' => $wrongArea->id,
            'assigned_by_user_id' => $centerUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->assignRider($shipment, $rider, 'DELIVERY', $centerUser);
    }

    public function test_delivery_assignment_rejects_an_unavailable_rider_even_when_area_matches(): void
    {
        [$center, $centerUser, $shipment, $area] = $this->deliveryAssignmentFixture();
        $rider = $this->riderFor($center, 'SUSPENDED');
        RiderAreaAssignment::create([
            'rider_profile_id' => $rider->id,
            'service_area_id' => $area->id,
            'assigned_by_user_id' => $centerUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->assignRider($shipment, $rider, 'DELIVERY', $centerUser);
    }

    public function test_delivery_assignment_rejects_an_active_rider_who_is_offline(): void
    {
        [$center, $centerUser, $shipment] = $this->deliveryAssignmentFixture();
        $rider = $this->riderFor($center, 'ACTIVE');
        $rider->update(['is_online' => false]);

        try {
            app(ShipmentWorkflowService::class)->assignRider($shipment, $rider, 'DELIVERY', $centerUser);
            $this->fail('An offline rider must not receive a delivery assignment.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The rider must be online to receive a local assignment.', $exception->getMessage());
        }
    }

    public function test_delivery_assignment_does_not_trust_a_matching_service_area_id_when_codes_differ(): void
    {
        [$center, $centerUser, $shipment, $area] = $this->deliveryAssignmentFixture();
        $rider = $this->riderFor($center, 'ACTIVE');
        RiderAreaAssignment::create([
            'rider_profile_id' => $rider->id,
            'service_area_id' => $area->id,
            'assigned_by_user_id' => $centerUser->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $shipment->update([
            'destination_province_code' => 'DIFFERENT-P',
            'destination_municipality_code' => 'DIFFERENT-M',
            'destination_barangay_code' => 'DIFFERENT-B',
        ]);

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->assignRider($shipment->fresh(), $rider, 'DELIVERY', $centerUser);
    }

    public function test_pickup_assignment_rejects_a_shipment_without_a_covered_seller_address(): void
    {
        [$riderUser, $shipment] = $this->pickupFixture('ASSIGNED');
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = $shipment->logisticsCenter;
        $rider = RiderProfile::query()->whereKey($shipment->riderAssignments()->first()->rider_profile_id)->firstOrFail();
        $shipment->riderAssignments()->delete();

        $this->expectException(\RuntimeException::class);
        app(ShipmentWorkflowService::class)->assignRider($shipment, $rider, 'PICKUP', $centerUser);
    }

    private function pickupFixture(string $assignmentStatus = 'IN_PROGRESS'): array
    {
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'CENTER-PICKUP-'.uniqid(),
            'business_name' => 'Pickup Test Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'PICKUP-'.uniqid(),
            'drivers_license_number' => 'LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
            'is_online' => true,
        ]);
        $sellerOrder = $this->sellerOrder();
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'TRACK-PICKUP-'.uniqid(),
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
            'status' => $assignmentStatus,
            'assigned_at' => now(),
            'started_at' => now(),
        ]);

        return [$riderUser, $shipment, $assignment];
    }

    private function deliveryAssignmentFixture(): array
    {
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'code' => 'CENTER-DELIVERY-'.uniqid(),
            'business_name' => 'Delivery Test Center',
            'status' => 'ACTIVE',
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'AREA-DELIVERY-'.uniqid(),
            'name' => 'Delivery Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => 'TARGET-P',
            'province_name' => 'Target Province',
            'municipality_code' => 'TARGET-M',
            'municipality_name' => 'Target Municipality',
            'barangay_code' => 'TARGET-B',
            'barangay_name' => 'Target Barangay',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $this->sellerOrder()->id,
            'tracking_number' => 'TRACK-DELIVERY-'.uniqid(),
            'logistics_center_id' => $center->id,
            'service_area_id' => $area->id,
            'destination_province_code' => 'TARGET-P',
            'destination_province_name' => 'Target Province',
            'destination_municipality_code' => 'TARGET-M',
            'destination_municipality_name' => 'Target Municipality',
            'destination_barangay_code' => 'TARGET-B',
            'destination_barangay_name' => 'Target Barangay',
            'current_status' => 'SORTED',
        ]);

        return [$center, $centerUser, $shipment, $area];
    }

    private function riderFor(LogisticsCenter $center, string $status): RiderProfile
    {
        $riderUser = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => $status,
        ]);

        return RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'DELIVERY-'.uniqid(),
            'drivers_license_number' => 'DELIVERY-LICENSE-'.uniqid(),
            'status' => $status,
            'is_online' => $status === 'ACTIVE',
        ]);
    }

    private function sellerOrder(): SellerOrder
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create([
            'name' => 'Test category',
            'slug' => 'test-category-'.uniqid(),
            'is_active' => true,
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Test seller',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'ORDER-'.uniqid(),
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
        ]);

        return SellerOrder::create([
            'seller_order_number' => 'SELLER-ORDER-'.uniqid(),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
        ]);
    }
}
