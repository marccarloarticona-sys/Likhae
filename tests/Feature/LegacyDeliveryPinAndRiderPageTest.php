<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Finance\CodCollection;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderAssignmentLocation;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Maps\MapDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyDeliveryPinAndRiderPageTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_buyer_can_confirm_only_the_missing_pin_on_their_active_order_snapshot(): void
    {
        [$buyer, $order, $snapshot] = $this->deliveryFixture();
        $accountAddress = Address::create([
            'user_id' => $buyer->id,
            'label' => 'Home',
            'recipient_name' => 'Current account recipient',
            'contact_number' => '09170000000',
            'province_code' => 'P-ACCOUNT',
            'province_name' => 'Account Province',
            'municipality_code' => 'M-ACCOUNT',
            'municipality_name' => 'Account Municipality',
            'barangay_code' => 'B-ACCOUNT',
            'barangay_name' => 'Account Barangay',
            'street_address' => 'Current account street',
            'latitude' => '14.5000000',
            'longitude' => '120.5000000',
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Delivery pin required')
            ->assertSee('Confirm the exact delivery location for this existing order')
            ->assertSee('delivery-pin-form', false);

        $this->post(route('buyer.orders.delivery-pin', $order), [
            'latitude' => '14.1234567',
            'longitude' => '120.7654321',
        ])->assertRedirect(route('buyer.orders.show', $order));

        $snapshot->refresh();
        $this->assertSame('14.1234567', $snapshot->latitude);
        $this->assertSame('120.7654321', $snapshot->longitude);
        $this->assertSame('Saved historical street', $snapshot->street_address);
        $this->assertSame('Saved Barangay', $snapshot->barangay_name);
        $this->assertSame('Saved Municipality', $snapshot->municipality_name);
        $this->assertSame('Saved Province', $snapshot->province_name);
        $this->assertSame('Historical recipient', $snapshot->recipient_name);
        $this->assertSame('09171112222', $snapshot->contact_number);
        $this->assertSame('Current account street', $accountAddress->fresh()->street_address);
        $this->assertSame('14.5000000', $accountAddress->fresh()->latitude);
    }

    public function test_pin_confirmation_rejects_other_buyers_and_terminal_or_already_pinned_orders(): void
    {
        [$owner, $order, $snapshot] = $this->deliveryFixture();
        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);

        $this->actingAs($otherBuyer)
            ->post(route('buyer.orders.delivery-pin', $order), ['latitude' => '14.2', 'longitude' => '120.8'])
            ->assertForbidden();
        $this->assertNull($snapshot->fresh()->latitude);

        $this->actingAs($owner)
            ->post(route('buyer.orders.delivery-pin', $order), ['latitude' => '14.2', 'longitude' => '120.8'])
            ->assertRedirect();
        $pinnedOrder = $this->deliveryFixture('pinned');
        [$pinnedOwner, $pinned, $pinnedSnapshot] = $pinnedOrder;
        $this->actingAs($pinnedOwner)
            ->post(route('buyer.orders.delivery-pin', $pinned), ['latitude' => '14.3', 'longitude' => '120.9'])
            ->assertStatus(409);
        $this->assertSame('14.0000000', $pinnedSnapshot->fresh()->latitude);

        [$deliveredBuyer, $deliveredOrder, $deliveredSnapshot, $deliveredShipment] = $this->deliveryFixture();
        $deliveredShipment->update(['current_status' => 'DELIVERED']);
        $this->actingAs($deliveredBuyer)
            ->post(route('buyer.orders.delivery-pin', $deliveredOrder), ['latitude' => '14.3', 'longitude' => '120.9'])
            ->assertStatus(409);
        $this->assertNull($deliveredSnapshot->fresh()->latitude);
    }

    public function test_local_assigned_rider_sees_pin_warning_until_the_saved_order_pin_exists(): void
    {
        [, , $snapshot, $shipment, $assignment, $riderUser] = $this->deliveryFixture('missing', 'motorcycle');

        $this->actingAs($riderUser)
            ->get(route('rider.deliveries.show', $assignment))
            ->assertOk()
            ->assertSee('DELIVERY PIN REQUIRED')
            ->assertSee('Navigation will become available once the Buyer confirms the delivery pin.')
            ->assertSee('No route, distance, or ETA is available yet.')
            ->assertSee('Message Buyer')
            ->assertDontSee('&quot;target_kind&quot;:&quot;buyer&quot;', false);

        $snapshot->update(['latitude' => '14.2000000', 'longitude' => '120.8000000']);
        $this->get(route('rider.deliveries.show', $assignment))
            ->assertOk()
            ->assertDontSee('DELIVERY PIN REQUIRED')
            ->assertSee('OUT FOR DELIVERY')
            ->assertSee('IN PROGRESS')
            ->assertSee('&quot;target_kind&quot;:&quot;buyer&quot;', false);
    }

    public function test_only_assigned_local_rider_can_open_final_mile_details(): void
    {
        [, , , , $assignment] = $this->deliveryFixture();
        $otherLocalRider = $this->rider('tricycle');
        $interLogisticsRider = $this->rider('van');

        $this->actingAs($otherLocalRider)
            ->get(route('rider.deliveries.show', $assignment))
            ->assertForbidden();

        $this->patch(route('rider.shipments.transition', $assignment), ['action' => 'delivery_success'])
            ->assertForbidden();

        $this->actingAs($interLogisticsRider)
            ->get(route('rider.deliveries.show', $assignment))
            ->assertForbidden();

        $this->patch(route('rider.shipments.transition', $assignment), ['action' => 'delivery_success'])
            ->assertForbidden();
    }

    public function test_delivery_requires_cash_and_proof_then_buyer_sees_proof_and_confirms_receipt(): void
    {
        [$buyer, $order, , $shipment, $assignment, $riderUser] = $this->deliveryFixture('pinned');
        Storage::fake('public');
        CodCollection::create([
            'shipment_id' => $shipment->id,
            'seller_order_id' => $shipment->seller_order_id,
            'order_id' => $order->id,
            'rider_profile_id' => $assignment->rider_profile_id,
            'logistics_center_id' => $shipment->logistics_center_id,
            'expected_amount' => '100.00',
            'status' => CodCollection::PENDING,
        ]);

        $this->actingAs($riderUser)
            ->patch(route('rider.shipments.transition', $assignment), ['action' => 'delivery_success'])
            ->assertRedirect()
            ->assertSessionHasErrors(['collected_amount', 'proof_file']);
        $this->assertSame('OUT_FOR_DELIVERY', $shipment->fresh()->current_status);

        $this->patch(route('rider.shipments.transition', $assignment), [
            'action' => 'delivery_success',
            'collected_amount' => '95.00',
            'proof_file' => UploadedFile::fake()->image('delivery-proof.jpg'),
        ])->assertRedirect()->assertSessionHasErrors('scanned_code');
        $this->assertSame('OUT_FOR_DELIVERY', $shipment->fresh()->current_status);

        $this->get(route('rider.deliveries.scan', [
            'assignment' => $assignment,
            'tracking' => $shipment->tracking_number,
            'scan_method' => 'MANUAL',
        ]))->assertRedirect(route('rider.deliveries.show', $assignment));

        $this->patch(route('rider.shipments.transition', $assignment), [
            'action' => 'delivery_success',
            'scanned_code' => $shipment->tracking_number,
            'scan_method' => 'MANUAL',
            'collected_amount' => '95.00',
            'proof_file' => UploadedFile::fake()->image('delivery-proof.jpg'),
        ])->assertRedirect()->assertSessionHas('status', 'Rider assignment updated.');

        $shipment->refresh();
        $assignment->refresh();
        $proof = DeliveryAttempt::query()->where('shipment_id', $shipment->id)->where('status', 'DELIVERED')->firstOrFail();
        $this->assertSame('DELIVERED', $shipment->current_status);
        $this->assertSame('COMPLETED', $assignment->status);
        $this->assertNotEmpty($proof->proof_path);
        Storage::disk('public')->assertExists($proof->proof_path);
        $this->assertDatabaseHas('cod_collections', [
            'shipment_id' => $shipment->id,
            'expected_amount' => '100.00',
            'collected_amount' => '95.00',
            'status' => CodCollection::DISCREPANCY,
            'rider_profile_id' => $assignment->rider_profile_id,
        ]);
        $this->assertSame('PROCESSING', $order->fresh()->status, 'Rider delivery must not complete the Buyer order.');

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Proof of Delivery')
            ->assertSee('Order Received');

        $this->post(route('buyer.orders.received', $order), ['confirm_receipt' => '1'])->assertRedirect();
        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame('COMPLETED', $shipment->fresh()->current_status);
    }

    public function test_rider_map_marker_distinguishes_fresh_and_stale_gps(): void
    {
        $assignment = new RiderAssignment([
            'shipment_id' => 91,
            'rider_profile_id' => 92,
            'assignment_type' => 'DELIVERY',
            'status' => 'IN_PROGRESS',
        ]);
        $assignment->id = 93;

        $assignment->setRelation('liveLocation', new RiderAssignmentLocation([
            'latitude' => 14.2,
            'longitude' => 120.8,
            'recorded_at' => now(),
        ]));
        $fresh = app(MapDataService::class)->assignmentMarker($assignment);
        $this->assertSame('live', $fresh['location_state']);
        $this->assertSame('Current rider location', $fresh['title']);

        $assignment->setRelation('liveLocation', new RiderAssignmentLocation([
            'latitude' => 14.2,
            'longitude' => 120.8,
            'recorded_at' => now()->subMinutes(3),
        ]));
        $stale = app(MapDataService::class)->assignmentMarker($assignment);
        $this->assertSame('stale', $stale['location_state']);
        $this->assertSame('Last known rider location', $stale['title']);
    }

    private function deliveryFixture(string $pin = 'missing', string $vehicle = 'motorcycle'): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create([
            'name' => 'Pin Test '.$this->sequence,
            'slug' => 'pin-test-'.$this->sequence++,
            'is_active' => true,
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Pin Test Seller',
            'status' => 'ACTIVE',
        ]);
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'PIN-CENTER-'.$this->sequence,
            'business_name' => 'Pin Test Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => $vehicle,
            'plate_number' => 'PIN-PLATE-'.$this->sequence,
            'drivers_license_number' => 'PIN-LICENSE-'.$this->sequence,
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'PIN-ORDER-'.$this->sequence,
            'buyer_user_id' => $buyer->id,
            'status' => 'PROCESSING',
            'grand_total' => 100,
            'placed_at' => now(),
        ]);
        $snapshot = OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => 'Historical recipient',
            'contact_number' => '09171112222',
            'province_code' => 'P-SAVED',
            'province_name' => 'Saved Province',
            'municipality_code' => 'M-SAVED',
            'municipality_name' => 'Saved Municipality',
            'barangay_code' => 'B-SAVED',
            'barangay_name' => 'Saved Barangay',
            'street_address' => 'Saved historical street',
            'latitude' => $pin === 'pinned' ? '14.0000000' : null,
            'longitude' => $pin === 'pinned' ? '120.0000000' : null,
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'PIN-SELLER-'.$this->sequence,
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
            'grand_total' => 100,
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'logistics_center_id' => $center->id,
            'tracking_number' => 'PIN-TRACK-'.$this->sequence,
            'destination_province_code' => 'P-SAVED',
            'destination_province_name' => 'Saved Province',
            'destination_municipality_code' => 'M-SAVED',
            'destination_municipality_name' => 'Saved Municipality',
            'destination_barangay_code' => 'B-SAVED',
            'destination_barangay_name' => 'Saved Barangay',
            'current_status' => 'OUT_FOR_DELIVERY',
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => 'DELIVERY',
            'status' => 'IN_PROGRESS',
        ]);

        return [$buyer, $order, $snapshot, $shipment, $assignment, $riderUser];
    }

    private function rider(string $vehicle): User
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'PIN-OTHER-CENTER-'.$this->sequence,
            'business_name' => 'Other Rider Center',
            'status' => 'ACTIVE',
        ]);
        $user = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);
        RiderProfile::create([
            'user_id' => $user->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => $vehicle,
            'plate_number' => 'OTHER-PLATE-'.$this->sequence,
            'drivers_license_number' => 'OTHER-LICENSE-'.$this->sequence++,
            'status' => 'ACTIVE',
        ]);

        return $user;
    }
}
