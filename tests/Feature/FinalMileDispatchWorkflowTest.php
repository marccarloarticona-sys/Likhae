<?php

namespace Tests\Feature;

use App\Models\Admin\Dispute;
use App\Models\Admin\DisputeEvidence;
use App\Models\Buyer\Address;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\FinalMileDispatchService;
use App\Services\Fulfillment\LocalRiderTripService;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FinalMileDispatchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const SAN_MIGUEL = [
        'province_code' => '0403400000', 'province_name' => 'Laguna',
        'municipality_code' => '0403422000', 'municipality_name' => 'Pila',
        'barangay_code' => '0403422015', 'barangay_name' => 'San Miguel',
    ];

    private const APLAYA = [
        'province_code' => '0403400000', 'province_name' => 'Laguna',
        'municipality_code' => '0403422000', 'municipality_name' => 'Pila',
        'barangay_code' => '0403422001', 'barangay_name' => 'Aplaya',
    ];

    public function test_three_san_miguel_parcels_share_a_trip_and_delivery_outcomes_remain_per_parcel(): void
    {
        $fixture = $this->fixture();
        $dispatch = app(FinalMileDispatchService::class);
        $shipments = [
            $this->shipment($fixture, self::SAN_MIGUEL, 'Street 1'),
            $this->shipment($fixture, self::SAN_MIGUEL, 'Street 99'),
            $this->shipment($fixture, self::SAN_MIGUEL, 'Market Road'),
        ];

        $trip = $dispatch->dispatch($fixture['centerUser'], $fixture['center'], $shipments[0], $fixture['rider']);
        foreach (array_slice($shipments, 1) as $shipment) {
            $dispatch->dispatch($fixture['centerUser'], $fixture['center'], $shipment, $fixture['rider'], $trip->id);
        }
        $this->assertSame(3, $trip->items()->count());
        $this->assertSame(3, $trip->approved_parcel_capacity);
        $this->assertSame(
            ['Street 1', 'Street 99', 'Market Road'],
            $trip->items()->with('riderAssignment.shipment.sellerOrder.order.address')->get()
                ->map(fn (RiderLocalTripItem $item) => $item->riderAssignment->shipment->sellerOrder->order->address->street_address)
                ->all(),
        );

        $otherBarangayShipment = $this->shipment($fixture, self::APLAYA, 'Aplaya Road');
        $this->assertRuntimeFailure(
            fn () => $dispatch->dispatch($fixture['centerUser'], $fixture['center'], $otherBarangayShipment, $fixture['rider'], $trip->id),
            'PSGC barangay does not match the trip barangay',
        );
        $this->assertSame(3, $trip->items()->count());

        $overCapacity = $this->shipment($fixture, self::SAN_MIGUEL, 'Overflow Street');
        $this->assertRuntimeFailure(
            fn () => $dispatch->dispatch($fixture['centerUser'], $fixture['center'], $overCapacity, $fixture['rider'], $trip->id),
            'reached its approved parcel capacity',
        );
        $this->assertSame(3, $trip->items()->count());

        $tripItems = $trip->items()->with('riderAssignment.shipment')->orderBy('id')->get();
        $codes = $tripItems->mapWithKeys(fn (RiderLocalTripItem $item): array => [$item->id => $item->riderAssignment->shipment->tracking_number])->all();
        $trips = app(LocalRiderTripService::class);
        $trip = $trips->loadTrip($fixture['centerUser'], $trip, $codes);
        $workflow = app(ShipmentWorkflowService::class);
        foreach ($tripItems as $item) {
            $workflow->riderTransition($item->riderAssignment, 'accept', $fixture['riderUser']);
            $workflow->riderTransition($item->riderAssignment->fresh(), 'start', $fixture['riderUser']);
        }

        $workflow->riderTransition($tripItems[0]->riderAssignment->fresh(), 'delivery_success', $fixture['riderUser'], [
            'scanned_code' => $tripItems[0]->riderAssignment->shipment->tracking_number,
            'scan_method' => 'MANUAL',
            'proof_path' => 'proofs/san-miguel-one.jpg',
            'collected_amount' => '100.00',
        ]);
        $workflow->riderTransition($tripItems[1]->riderAssignment->fresh(), 'delivery_failed', $fixture['riderUser'], [
            'failure_reason' => 'Buyer unavailable at the address.',
        ]);

        $this->assertSame('DELIVERED', $shipments[0]->fresh()->current_status);
        $this->assertSame('DELIVERY_FAILED', $shipments[1]->fresh()->current_status);
        $this->assertSame('OUT_FOR_DELIVERY', $shipments[2]->fresh()->current_status);
        $this->assertDatabaseHas('delivery_attempts', ['shipment_id' => $shipments[0]->id, 'status' => 'DELIVERED']);
        $this->assertDatabaseHas('delivery_attempts', ['shipment_id' => $shipments[1]->id, 'status' => 'FAILED']);
        $this->assertDatabaseMissing('delivery_attempts', ['shipment_id' => $shipments[2]->id]);
    }

    public function test_third_failed_attempt_starts_return_review_without_falsely_marking_return_custody_complete(): void
    {
        $fixture = $this->fixture();
        $shipment = $this->shipment($fixture, self::SAN_MIGUEL, 'Third Attempt Road');
        $assignment = $this->startDelivery($fixture, $shipment);
        $workflow = app(ShipmentWorkflowService::class);

        foreach ([1, 2, 3] as $attemptNumber) {
            if ($attemptNumber > 1) {
                $returnWorkflow = ShipmentReturnWorkflow::query()->where('shipment_id', $shipment->id)->firstOrFail();
                $returns = app(\App\Services\Fulfillment\ShipmentReturnWorkflowService::class);
                $returns->handoffToCenterAsOriginalRider($returnWorkflow, $fixture['riderUser'], 'MANUAL', $shipment->tracking_number);
                $returns->receiveAtCenter($returnWorkflow->fresh(), $fixture['centerUser'], 'MANUAL', $shipment->tracking_number);
            }
            if ($assignment->status === 'ACCEPTED') {
                $workflow->riderTransition($assignment, 'start', $fixture['riderUser']);
            }
            $workflow->riderTransition($assignment->fresh(), 'delivery_failed', $fixture['riderUser'], [
                'failure_reason' => 'Buyer unavailable at the address.',
                'attempt_status' => 'RESCHEDULED',
                'next_attempt_at' => now()->addDay(),
            ]);
            $assignment = $assignment->fresh();
            if ($attemptNumber < 3) {
                $this->assertSame('ACCEPTED', $assignment->status);
            }
        }

        $this->assertSame('COMPLETED', $assignment->fresh()->status);
        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        $this->assertSame(3, $shipment->deliveryAttempts()->count());
        $this->assertSame('FAILED', $shipment->deliveryAttempts()->latest('attempt_number')->value('status'));
        $this->assertTrue($shipment->events()->where('notes', 'like', 'RETURN_TO_SELLER_INITIATED:%')->exists());
        $this->assertDatabaseMissing('delivery_attempts', ['shipment_id' => $shipment->id, 'status' => 'RETURNED']);
    }

    public function test_buyer_refusal_starts_return_review_on_first_attempt(): void
    {
        $fixture = $this->fixture();
        $shipment = $this->shipment($fixture, self::SAN_MIGUEL, 'Buyer Refusal Road');
        $assignment = $this->startDelivery($fixture, $shipment);
        app(ShipmentWorkflowService::class)->riderTransition($assignment, 'start', $fixture['riderUser']);

        app(ShipmentWorkflowService::class)->riderTransition($assignment->fresh(), 'delivery_failed', $fixture['riderUser'], [
            'failure_reason' => 'Buyer refused parcel: recipient declined delivery.',
        ]);

        $this->assertSame('DELIVERY_FAILED', $shipment->fresh()->current_status);
        $this->assertSame('FAILED', $shipment->deliveryAttempts()->sole()->status);
        $this->assertTrue($shipment->events()->where('notes', 'like', 'RETURN_TO_SELLER_INITIATED:%')->exists());
    }

    public function test_logistics_manual_status_override_cannot_mark_a_parcel_delivered(): void
    {
        $fixture = $this->fixture();
        $shipment = $this->shipment($fixture, self::SAN_MIGUEL, 'Manual Override Road');
        $shipment->update(['current_status' => 'OUT_FOR_DELIVERY']);

        $this->actingAs($fixture['centerUser'])
            ->post(route('logistics.parcels.status-override', $shipment), [
                'status' => 'DELIVERED',
                'reason' => 'Buyer confirmed the package arrived.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('OUT_FOR_DELIVERY', $shipment->fresh()->current_status);
        $this->assertDatabaseMissing('shipment_events', [
            'shipment_id' => $shipment->id,
            'status' => 'DELIVERED',
        ]);
    }

    public function test_logistics_lost_exception_opens_a_claim_and_notifies_buyer_seller_and_admin(): void
    {
        $fixture = $this->fixture();
        $shipment = $this->shipment($fixture, self::SAN_MIGUEL, 'Lost Parcel Road');
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN, 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($fixture['centerUser'])
            ->post(route('logistics.parcels.exceptions.store', $shipment), [
                'exception_type' => 'LOST',
                'reason' => 'Parcel remained missing after the authorized hub search.',
            ])
            ->assertRedirect();

        $dispute = Dispute::query()->where('shipment_id', $shipment->id)->sole();
        $this->assertSame('SHIPMENT_LOST', $dispute->type);
        $this->assertSame('OPEN', $dispute->status);
        $this->assertSame('LOST', $shipment->fresh()->current_status);
        $this->assertDatabaseHas('shipment_events', ['shipment_id' => $shipment->id, 'status' => 'LOST']);
        $this->assertSame(1, $fixture['buyer']->notifications()->where('type', 'SHIPMENT_EXCEPTION')->count());
        $this->assertSame(1, $fixture['sellerUser']->notifications()->where('type', 'SHIPMENT_EXCEPTION')->count());
        $this->assertSame(1, $admin->notifications()->where('type', 'SHIPMENT_EXCEPTION')->count());
    }

    public function test_logistics_damaged_exception_requires_and_protects_photo_evidence(): void
    {
        Storage::fake('local');
        $fixture = $this->fixture();
        $shipment = $this->shipment($fixture, self::SAN_MIGUEL, 'Damaged Parcel Road');
        $route = route('logistics.parcels.exceptions.store', $shipment);
        $this->actingAs($fixture['centerUser'])
            ->post($route, [
                'exception_type' => 'DAMAGED',
                'reason' => 'Outer box is crushed and contents may be affected.',
            ])
            ->assertSessionHasErrors('photo');

        $this->post($route, [
            'exception_type' => 'DAMAGED',
            'reason' => 'Outer box is crushed and contents may be affected.',
            'photo' => UploadedFile::fake()->image('damage.jpg', 1200, 900),
        ])->assertRedirect();

        $dispute = Dispute::query()->where('shipment_id', $shipment->id)->sole();
        $evidence = DisputeEvidence::query()->where('dispute_id', $dispute->id)->sole();
        $this->assertSame('SHIPMENT_DAMAGED', $dispute->type);
        $this->assertSame('DAMAGED', $shipment->fresh()->current_status);
        $this->assertSame('local', $evidence->disk);
        $this->assertTrue($evidence->encrypted);
        Storage::disk('local')->assertExists($evidence->file_path);
    }

    private function startDelivery(array $fixture, Shipment $shipment): \App\Models\Rider\RiderAssignment
    {
        $trip = app(FinalMileDispatchService::class)->dispatch($fixture['centerUser'], $fixture['center'], $shipment, $fixture['rider']);
        $item = $trip->items()->with('riderAssignment')->sole();
        app(LocalRiderTripService::class)->loadTrip($fixture['centerUser'], $trip, [$item->id => $shipment->tracking_number]);
        $workflow = app(ShipmentWorkflowService::class);
        $workflow->riderTransition($item->riderAssignment, 'accept', $fixture['riderUser']);

        return $item->riderAssignment->fresh();
    }

    private function fixture(): array
    {
        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $category = Category::create(['name' => 'Final Mile Test', 'slug' => 'final-mile-test', 'is_active' => true]);
        $sellerAddress = $this->address($sellerUser, self::SAN_MIGUEL, 'Seller Road');
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'Final Mile Seller',
            'status' => 'ACTIVE',
        ]);
        $centerAddress = $this->address($centerUser, self::SAN_MIGUEL, 'Pila Logistics Road');
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'address_id' => $centerAddress->id,
            'code' => 'PILA-FINAL-MILE-'.uniqid(),
            'business_name' => 'Pila Logistics',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $centerUser->id,
            'approved_at' => now(),
        ]);
        $sanMiguelArea = $this->serviceArea($center, 'San Miguel', self::SAN_MIGUEL);
        $aplayArea = $this->serviceArea($center, 'Aplaya', self::APLAYA);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'approved_parcel_capacity' => 3,
            'plate_number' => 'PILA-TRIP-'.uniqid(),
            'drivers_license_number' => 'PILA-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
            'is_online' => true,
        ]);
        foreach ([$sanMiguelArea, $aplayArea] as $area) {
            RiderAreaAssignment::create([
                'rider_profile_id' => $rider->id,
                'service_area_id' => $area->id,
                'assigned_by_user_id' => $centerUser->id,
                'is_active' => true,
                'assigned_at' => now(),
            ]);
        }

        return compact('centerUser', 'riderUser', 'sellerUser', 'buyer', 'seller', 'center', 'rider', 'sanMiguelArea', 'aplayArea');
    }

    private function shipment(array $fixture, array $destination, string $street): Shipment
    {
        $order = Order::create([
            'order_number' => 'PILA-DELIVERY-'.uniqid(),
            'buyer_user_id' => $fixture['buyer']->id,
            'status' => 'PLACED',
            'grand_total' => 100,
            'placed_at' => now(),
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $fixture['buyer']->name,
            'contact_number' => $fixture['buyer']->contact_number,
            ...$destination,
            'street_address' => $street,
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'PILA-SO-'.uniqid(),
            'order_id' => $order->id,
            'seller_profile_id' => $fixture['seller']->id,
            'status' => 'PICKED_UP',
            'item_subtotal' => 100,
            'grand_total' => 100,
        ]);
        $area = $destination['barangay_code'] === self::SAN_MIGUEL['barangay_code']
            ? $fixture['sanMiguelArea']
            : $fixture['aplayArea'];

        return Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'PILA-TRACK-'.uniqid(),
            'logistics_center_id' => $fixture['center']->id,
            'origin_logistics_center_id' => $fixture['center']->id,
            'destination_logistics_center_id' => $fixture['center']->id,
            'service_area_id' => $area->id,
            'origin_province_code' => self::SAN_MIGUEL['province_code'],
            'origin_province_name' => self::SAN_MIGUEL['province_name'],
            'origin_municipality_code' => self::SAN_MIGUEL['municipality_code'],
            'origin_municipality_name' => self::SAN_MIGUEL['municipality_name'],
            'origin_barangay_code' => self::SAN_MIGUEL['barangay_code'],
            'origin_barangay_name' => self::SAN_MIGUEL['barangay_name'],
            'destination_province_code' => $destination['province_code'],
            'destination_province_name' => $destination['province_name'],
            'destination_municipality_code' => $destination['municipality_code'],
            'destination_municipality_name' => $destination['municipality_name'],
            'destination_barangay_code' => $destination['barangay_code'],
            'destination_barangay_name' => $destination['barangay_name'],
            'current_status' => 'SORTED',
        ]);
    }

    private function serviceArea(LogisticsCenter $center, string $name, array $codes): ServiceArea
    {
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => strtoupper(str_replace(' ', '-', $name)).'-'.uniqid(),
            'name' => $name.' Delivery Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create(['service_area_id' => $area->id, ...$codes]);

        return $area;
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

    private function assertRuntimeFailure(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected RuntimeException containing: '.$message);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
