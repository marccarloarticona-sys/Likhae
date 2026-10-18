<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\Waybill;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\SellerPickupBatchService;
use App\Services\Fulfillment\SellerPickupRequestService;
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

    public function test_21_seller_pickup_progress_uses_scans_and_is_seller_scoped(): void
    {
        $shipments = $this->readyShipments($this->seller, 3);
        [$trip, $riderUser] = $this->startBatch($shipments);
        $sellerUser = $this->seller->user;

        $initial = $this->actingAs($sellerUser)
            ->getJson(route('seller.orders.pickups.progress', $trip->id))
            ->assertOk()
            ->assertJsonPath('collected', 0)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('parcels.0.status', 'Waiting');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $sellerUser->id,
            'type' => 'SELLER_PICKUP_ASSIGNED',
            'reference_type' => 'SELLER_PICKUP_BATCH',
            'reference_id' => $trip->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $sellerUser->id,
            'type' => 'SELLER_PICKUP_STARTED',
            'reference_type' => 'SELLER_PICKUP_BATCH',
            'reference_id' => $trip->id,
        ]);

        app(SellerPickupBatchService::class)->scan($riderUser, $trip, $shipments[0]->tracking_number, 'BARCODE');
        $this->assertDatabaseHas('parcel_scans', [
            'shipment_id' => $shipments[0]->id,
            'scan_type' => 'PICKUP_COLLECTED',
            'scanned_code' => $shipments[0]->tracking_number,
            'result' => 'SUCCESS',
        ]);
        $this->assertSame(1, $shipments[0]->scans()->where('scan_type', 'PICKUP_COLLECTED')->count());

        $updated = $this->getJson(route('seller.orders.pickups.progress', $trip->id))
            ->assertOk()
            ->assertJsonPath('collected', 1)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('parcels.0.status', 'Collected');

        $this->assertSame($initial->json('parcels.0.tracking_number'), $shipments[0]->tracking_number);
        $this->assertSame($updated->json('parcels.0.tracking_number'), $shipments[0]->tracking_number);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $sellerUser->id,
            'type' => 'SELLER_SHIPMENT_PICKED_UP',
            'reference_type' => 'SELLER_SHIPMENT_STATUS',
            'reference_id' => $shipments[0]->id,
        ]);

        $service = app(SellerPickupBatchService::class);
        $assignmentIds = $trip->items()->pluck('rider_assignment_id')->all();
        $service->markNotCollected($riderUser, $trip, (int) $assignmentIds[1], 'Seller was unavailable');
        $service->markNotCollected($riderUser, $trip, (int) $assignmentIds[2], 'Parcel was not ready');
        $service->finishSellerPickup($riderUser, $trip->fresh());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $sellerUser->id,
            'type' => 'SELLER_PICKUP_COMPLETED',
            'reference_type' => 'SELLER_PICKUP_BATCH',
            'reference_id' => $trip->id,
            'message' => 'Pickup batch #P-'.$trip->id.' finished at 1 / 3 parcels collected.',
        ]);
        $this->actingAs($sellerUser)
            ->getJson(route('seller.orders.pickups.progress', $trip->id))
            ->assertOk()
            ->assertJsonPath('status', RiderLocalTrip::EN_ROUTE_TO_HUB)
            ->assertJsonPath('collected', 1)
            ->assertJsonPath('total', 3);
        $this->actingAs($this->newSeller('Progress Isolation Seller')->user)
            ->getJson(route('seller.orders.pickups.progress', $trip->id))
            ->assertNotFound();
    }

    public function test_22_seller_order_details_show_initial_pickup_progress_and_each_waybill(): void
    {
        $shipments = $this->readyShipments($this->seller, 2);
        $trip = $this->createBatch($shipments);
        $sellerOrder = $shipments[0]->sellerOrder;

        $this->actingAs($this->seller->user)
            ->get(route('seller.orders', ['mode' => 'show', 'order' => $sellerOrder->seller_order_number]))
            ->assertOk()
            ->assertSee('Pickup progress')
            ->assertSee('0 / 2 parcels collected')
            ->assertSee($shipments[0]->tracking_number)
            ->assertSee($shipments[1]->tracking_number)
            ->assertSee('Waiting');
    }

    public function test_23_only_the_assigned_rider_can_open_a_seller_pickup_batch(): void
    {
        $trip = $this->createBatch([$this->readyShipment($this->seller)]);
        $route = route('rider.pickups.batches.show', $trip->id);

        $this->actingAs($this->rider->user)->get($route)->assertOk();
        $this->actingAs($this->newRider('tricycle')->user)->get($route)->assertNotFound();
    }

    public function test_24_durable_seller_pickup_requests_accept_one_five_and_ten_parcels_across_orders(): void
    {
        foreach ([1, 5, 10] as $count) {
            $shipments = $this->requestReadyShipments($this->seller, $count);
            $pickup = $this->createDurableRequest($this->seller, $shipments);

            $this->assertSame($count, $pickup->memberships()->count());
            $this->assertSame($count, $pickup->memberships()->where('active_slot', true)->count());
            $this->assertSame($count, $pickup->memberships()->distinct('seller_order_id')->count('seller_order_id'));
        }
    }

    public function test_25_durable_request_rejects_zero_and_eleven_parcels(): void
    {
        $service = app(SellerPickupRequestService::class);
        try {
            $service->create($this->seller->user, (int) $this->seller->business_address_id, [], []);
            $this->fail('A zero-parcel request must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
        }

        $shipments = $this->requestReadyShipments($this->seller, 11);
        try {
            $this->createDurableRequest($this->seller, $shipments);
            $this->fail('An eleven-parcel request must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
            $this->assertDatabaseCount('seller_pickup_request_shipments', 0);
        }
    }

    public function test_26_durable_request_rejects_foreign_seller_cancelled_picked_and_mismatched_parcels(): void
    {
        $service = app(SellerPickupRequestService::class);
        $otherSeller = $this->newSeller('Pickup Foreign Seller');
        $foreignShipment = $this->requestReadyShipments($otherSeller, 1)[0];
        try {
            $this->createDurableRequest($this->seller, [$foreignShipment]);
            $this->fail('A foreign Seller parcel must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
        }

        $cancelled = $this->requestReadyShipments($this->seller, 1)[0];
        $cancelled->sellerOrder->update(['status' => 'CANCELLED']);
        try {
            $this->createDurableRequest($this->seller, [$cancelled]);
            $this->fail('A cancelled SellerOrder parcel must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
        }

        $picked = $this->requestReadyShipments($this->seller, 1)[0];
        $picked->update(['current_status' => 'PICKED_UP']);
        try {
            $this->createDurableRequest($this->seller, [$picked]);
            $this->fail('An already picked parcel must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
        }

        $ready = $this->requestReadyShipments($this->seller, 1)[0];
        try {
            $service->create($this->seller->user, (int) $this->seller->business_address_id, [$ready->id], [$ready->id => $ready->seller_order_id + 1]);
            $this->fail('A shipment paired with a different SellerOrder must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
        }
    }

    public function test_27_invalid_pickup_address_missing_waybill_and_one_bad_parcel_roll_back_everything(): void
    {
        $shipments = $this->requestReadyShipments($this->seller, 2);
        $shipments[1]->waybills()->delete();
        $service = app(SellerPickupRequestService::class);
        try {
            $this->createDurableRequest($this->seller, $shipments);
            $this->fail('A request with one parcel missing its waybill must roll back.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
            $this->assertDatabaseCount('seller_pickup_request_shipments', 0);
        }

        $ready = $this->requestReadyShipments($this->seller, 1)[0];
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $foreignAddress = $this->address($buyer, 'Not a seller business address');
        try {
            $service->create($this->seller->user, (int) $foreignAddress->id, [$ready->id], [$ready->id => $ready->seller_order_id]);
            $this->fail('An address not authorized to this Seller must be rejected.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 0);
            $this->assertDatabaseCount('seller_pickup_request_shipments', 0);
        }
    }

    public function test_28_duplicate_active_parcel_is_rejected_and_seller_can_create_request_from_logistics_page(): void
    {
        $shipments = $this->requestReadyShipments($this->seller, 2);
        $pickup = $this->createDurableRequest($this->seller, [$shipments[0]]);
        try {
            $this->createDurableRequest($this->seller, [$shipments[0], $shipments[1]]);
            $this->fail('An active membership must prevent duplicate pickup requests.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seller_pickup_requests', 1);
            $this->assertSame(1, $pickup->memberships()->where('active_slot', true)->count());
        }

        $this->actingAs($this->seller->user)->get(route('seller.logistics'))
            ->assertOk()
            ->assertSee('Request one pickup for 1–10 ready parcels')
            ->assertSee($shipments[1]->tracking_number)
            ->assertSee('Your pickup requests');

        $this->actingAs($this->seller->user)->post(route('seller.logistics.pickup-requests.store'), [
            'pickup_address_id' => $this->seller->business_address_id,
            'shipment_ids' => [$shipments[1]->id],
            'seller_order_ids' => [$shipments[1]->id => $shipments[1]->seller_order_id],
        ])->assertRedirect(route('seller.logistics'));
        $this->assertDatabaseCount('seller_pickup_requests', 2);
    }

    public function test_29_logistics_sees_one_request_with_all_parcels_and_reject_keeps_history(): void
    {
        $shipments = $this->requestReadyShipments($this->seller, 5);
        $pickup = $this->createDurableRequest($this->seller, $shipments);

        $response = $this->actingAs($this->centerUser)->get(route('logistics.pickups'));
        $response->assertOk()->assertSee('Seller Pickup Requests')->assertSee($this->seller->business_name);
        foreach ($shipments as $shipment) {
            $response->assertSee($shipment->tracking_number);
        }
        $this->assertCount(1, $response->viewData('sellerPickupRequests'));

        $this->actingAs($this->centerUser)->post(route('logistics.pickups.requests.reject', $pickup))
            ->assertRedirect();
        $this->assertDatabaseHas('seller_pickup_requests', ['id' => $pickup->id, 'status' => 'REJECTED']);
        $this->assertSame(0, $pickup->memberships()->where('active_slot', true)->count());
        $this->assertSame(5, $pickup->memberships()->count());
    }

    public function test_30_logistics_capacity_assignment_rider_scans_and_seller_progress_are_authoritative(): void
    {
        $shipments = $this->requestReadyShipments($this->seller, 5);
        $pickup = $this->createDurableRequest($this->seller, $shipments);
        $this->rider->update(['approved_parcel_capacity' => 4]);

        try {
            app(SellerPickupRequestService::class)->assign($this->centerUser, $this->center, $pickup->id, $this->rider->id);
            $this->fail('Logistics must not assign more parcels than the Rider approved capacity.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('rider_local_trips', 0);
            $this->assertDatabaseHas('seller_pickup_requests', ['id' => $pickup->id, 'status' => 'REQUESTED']);
        }

        $this->rider->update(['approved_parcel_capacity' => 10]);
        $this->actingAs($this->centerUser)->post(route('logistics.pickups.requests.assign', $pickup), [
            'rider_profile_id' => $this->rider->id,
        ])->assertRedirect();
        $pickup->refresh();
        $trip = $pickup->riderLocalTrip()->firstOrFail();
        $this->assertSame('ASSIGNED', $pickup->status);
        $this->assertSame($this->seller->id, $trip->seller_profile_id);
        $this->assertSame(5, $trip->items()->count());
        $this->assertSame(1, $this->seller->user->notifications()->where('type', 'SELLER_PICKUP_ASSIGNED')->count());

        $batchService = app(SellerPickupBatchService::class);
        $batchService->accept($this->rider->user, $trip);
        $this->assertSame('ACCEPTED', $pickup->fresh()->status);
        $trip = $batchService->start($this->rider->user, $trip->fresh());
        $this->assertSame('IN_PROGRESS', $pickup->fresh()->status);
        $this->assertSame(1, $this->seller->user->notifications()->where('type', 'SELLER_PICKUP_STARTED')->count());

        $this->actingAs($this->seller->user)
            ->getJson(route('seller.logistics.pickup-requests.progress', $pickup))
            ->assertOk()->assertJsonPath('collected', 0)->assertJsonPath('total', 5);

        $batchService->scan($this->rider->user, $trip, $shipments[0]->tracking_number, 'BARCODE');
        $this->assertDatabaseCount('parcel_scans', 1);
        $progress = $this->actingAs($this->seller->user)
            ->getJson(route('seller.logistics.pickup-requests.progress', $pickup))
            ->assertOk()->assertJsonPath('collected', 1)->assertJsonPath('total', 5);
        $this->assertSame('Collected', $progress->json('parcels.0.status'));
        $this->actingAs($this->centerUser)->get(route('logistics.pickups'))
            ->assertOk()->assertSee('1 / 5 collected');

        $duplicate = $batchService->scan($this->rider->user, $trip, $shipments[0]->tracking_number, 'BARCODE');
        $this->assertTrue($duplicate['already_verified']);
        $this->assertDatabaseCount('parcel_scans', 1);
        $this->actingAs($this->seller->user)->getJson(route('seller.logistics.pickup-requests.progress', $pickup))
            ->assertOk()->assertJsonPath('collected', 1)->assertJsonPath('total', 5);

        foreach (array_slice($shipments, 1) as $shipment) {
            $batchService->scan($this->rider->user, $trip, $shipment->tracking_number, 'BARCODE');
        }
        $this->getJson(route('seller.logistics.pickup-requests.progress', $pickup))
            ->assertOk()->assertJsonPath('collected', 5)->assertJsonPath('total', 5);
        $batchService->finishSellerPickup($this->rider->user, $trip->fresh());
        $this->assertSame('COMPLETED', $pickup->fresh()->status);
        $this->assertSame(0, $pickup->memberships()->where('active_slot', true)->count());
        $this->assertSame(5, $pickup->memberships()->whereNull('active_slot')->count());
        $this->actingAs($this->newSeller('Pickup Progress Isolation')->user)
            ->getJson(route('seller.logistics.pickup-requests.progress', $pickup))
            ->assertNotFound();
    }

    public function test_31_partial_pickup_preserves_uncollected_parcels_and_does_not_complete_request(): void
    {
        $shipments = $this->requestReadyShipments($this->seller, 10);
        $pickup = $this->createDurableRequest($this->seller, $shipments);
        $trip = app(SellerPickupRequestService::class)->assign($this->centerUser, $this->center, $pickup->id, $this->rider->id)->riderLocalTrip;
        $service = app(SellerPickupBatchService::class);
        $service->accept($this->rider->user, $trip);
        $trip = $service->start($this->rider->user, $trip->fresh());

        foreach (array_slice($shipments, 0, 8) as $shipment) {
            $service->scan($this->rider->user, $trip, $shipment->tracking_number, 'BARCODE');
        }
        foreach ($trip->items()->orderBy('id')->skip(8)->take(2)->get() as $item) {
            $service->markNotCollected($this->rider->user, $trip, (int) $item->rider_assignment_id, 'Seller parcel was not ready');
        }
        $service->finishSellerPickup($this->rider->user, $trip->fresh());

        $this->assertSame('PARTIALLY_COLLECTED', $pickup->fresh()->status);
        $this->assertSame(RiderLocalTrip::EN_ROUTE_TO_HUB, $trip->fresh()->status);
        $progress = app(SellerPickupRequestService::class)->progress($pickup->fresh(), (int) $this->seller->id);
        $this->assertSame(8, $progress['collected']);
        $this->assertSame(10, $progress['total']);
        $this->assertCount(2, $progress['parcels']->where('status', 'Not collected'));
        $this->assertSame(10, $pickup->memberships()->whereNull('active_slot')->count());
    }

    public function test_32_seller_cancellation_releases_slot_without_deleting_membership_history(): void
    {
        $shipment = $this->requestReadyShipments($this->seller, 1)[0];
        $pickup = $this->createDurableRequest($this->seller, [$shipment]);

        $this->actingAs($this->seller->user)
            ->post(route('seller.logistics.pickup-requests.cancel', $pickup))
            ->assertRedirect();

        $this->assertDatabaseHas('seller_pickup_requests', ['id' => $pickup->id, 'status' => 'CANCELLED']);
        $this->assertSame(0, $pickup->memberships()->where('active_slot', true)->count());
        $this->assertSame(1, $pickup->memberships()->count());

        $retry = $this->createDurableRequest($this->seller, [$shipment]);
        $this->assertSame(1, $retry->memberships()->where('active_slot', true)->count());
        $this->assertSame(2, $shipment->sellerPickupMemberships()->count());
    }

    public function test_33_cancelling_a_seller_order_cancels_its_active_request_transactionally(): void
    {
        $shipment = $this->requestReadyShipments($this->seller, 1)[0];
        $pickup = $this->createDurableRequest($this->seller, [$shipment]);
        $product = Product::create([
            'seller_profile_id' => $this->seller->id,
            'category_id' => $this->seller->primary_category_id,
            'name' => 'Cancellation test product '.uniqid(),
            'slug' => 'pickup-cancel-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PICKUP-CANCEL-'.uniqid(),
            'price' => 10,
            'stock' => 5,
            'is_default' => true,
            'is_active' => true,
        ]);
        $item = OrderItem::create([
            'seller_order_id' => $shipment->seller_order_id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'unit_price' => 10,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 10,
        ]);
        $shipment->update(['order_item_id' => $item->id]);

        app(ShipmentWorkflowService::class)->sellerTransition(
            $shipment->sellerOrder,
            'cancel',
            $this->seller->user,
            'Seller cancelled before pickup assignment',
        );

        $this->assertDatabaseHas('seller_pickup_requests', ['id' => $pickup->id, 'status' => 'CANCELLED']);
        $this->assertSame(0, $pickup->memberships()->where('active_slot', true)->count());
        $this->assertSame(1, $pickup->memberships()->count());
    }

    public function test_34_legacy_assignment_cannot_bypass_an_active_durable_pickup_request(): void
    {
        $shipment = $this->requestReadyShipments($this->seller, 1)[0];
        $pickup = $this->createDurableRequest($this->seller, [$shipment]);

        try {
            $this->createBatch([$shipment]);
            $this->fail('A legacy assignment must not bypass an active Seller pickup request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must be assigned through that request', $exception->getMessage());
        }
        $this->assertDatabaseCount('rider_local_trips', 0);
        $this->assertDatabaseCount('rider_assignments', 0);
        $this->assertDatabaseHas('seller_pickup_requests', ['id' => $pickup->id, 'status' => 'REQUESTED']);

        $assigned = app(SellerPickupRequestService::class)->assign(
            $this->centerUser,
            $this->center,
            (int) $pickup->id,
            (int) $this->rider->id,
        );
        $this->assertSame('ASSIGNED', $assigned->status);
        $this->assertDatabaseCount('rider_local_trips', 1);
        $this->assertDatabaseCount('rider_assignments', 1);
    }

    private function createDurableRequest(SellerProfile $seller, array $shipments): \App\Models\Seller\SellerPickupRequest
    {
        return app(SellerPickupRequestService::class)->create(
            $seller->user,
            (int) $seller->business_address_id,
            array_map(fn (Shipment $shipment): int => (int) $shipment->id, $shipments),
            array_combine(
                array_map(fn (Shipment $shipment): int => (int) $shipment->id, $shipments),
                array_map(fn (Shipment $shipment): int => (int) $shipment->seller_order_id, $shipments),
            ) ?: [],
        );
    }

    private function requestReadyShipments(SellerProfile $seller, int $count): array
    {
        $shipments = $this->readyShipments($seller, $count);
        foreach ($shipments as $shipment) {
            Waybill::create([
                'shipment_id' => $shipment->id,
                'format_version' => 1,
                'label_path' => 'waybills/'.$shipment->tracking_number.'.pdf',
                'generated_at' => now(),
            ]);
        }

        return $shipments;
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
            'is_online' => true,
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
