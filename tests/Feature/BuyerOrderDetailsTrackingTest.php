<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Buyer\Payment;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerOrderDetailsTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_order_details_show_real_timeline_and_only_their_successful_delivery_proof(): void
    {
        $this->withoutVite();
        Storage::fake('public');

        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create(['name' => 'Tracking Test', 'slug' => 'tracking-test', 'is_active' => true]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Tracking Test Store',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'TRACKING-ORDER-001',
            'buyer_user_id' => $buyer->id,
            'status' => 'PROCESSING',
            'grand_total' => 100,
            'placed_at' => now(),
        ]);
        Payment::create([
            'order_id' => $order->id,
            'method' => 'COD',
            'provider' => 'COD',
            'amount' => 100,
            'status' => 'PENDING',
            'initiated_at' => now(),
        ]);
        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $buyer->name,
            'contact_number' => '09170000000',
            'province_code' => 'P-TEST',
            'province_name' => 'Test Province',
            'municipality_code' => 'M-TEST',
            'municipality_name' => 'Test Municipality',
            'barangay_code' => 'B-TEST',
            'barangay_name' => 'Test Barangay',
            'street_address' => '1 Saved Order Street',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'TRACKING-SELLER-001',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
            'grand_total' => 100,
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'TRACKING-PARCEL-001',
            'destination_province_code' => 'P-TEST',
            'destination_province_name' => 'Test Province',
            'destination_municipality_code' => 'M-TEST',
            'destination_municipality_name' => 'Test Municipality',
            'destination_barangay_code' => 'B-TEST',
            'destination_barangay_name' => 'Test Barangay',
            'current_status' => 'SORTED',
        ]);
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => 'PLACED',
            'notes' => 'Order placed.',
            'occurred_at' => now()->subHour(),
        ]);
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => 'SORTED',
            'notes' => 'Sorted by destination.',
            'occurred_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Live Delivery Map')
            ->assertSee('Tracking Timeline')
            ->assertSee('Sorted by destination')
            ->assertSee('Pending')
            ->assertSee('1 Saved Order Street')
            ->assertSee('Cash on Delivery (COD)')
            ->assertSee('data-map-live="false"', false)
            ->assertDontSee('Proof of Delivery');

        $centerUser = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $adminUser = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerUser->id,
            'code' => 'TRACKING-CENTER',
            'business_name' => 'Tracking Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $adminUser->id,
            'approved_at' => now(),
        ]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'TRACK-PLATE-'.uniqid(),
            'drivers_license_number' => 'TRACK-LICENSE-'.uniqid(),
            'status' => 'ACTIVE',
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => 'DELIVERY',
            'status' => 'COMPLETED',
            'completed_at' => now(),
        ]);
        $shipment->update(['current_status' => 'OUT_FOR_DELIVERY']);
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => 'OUT_FOR_DELIVERY',
            'notes' => 'Rider is on the way.',
            'occurred_at' => now()->subMinutes(2),
        ]);
        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('data-map-live="true"', false)
            ->assertDontSee('Proof of Delivery');

        Storage::disk('public')->put('delivery-proofs/failed.jpg', 'failed-attempt');
        Storage::disk('public')->put('delivery-proofs/older-delivered.jpg', 'older-successful-delivery');
        Storage::disk('public')->put('delivery-proofs/delivered.jpg', 'successful-delivery');
        DeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'rider_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'status' => 'FAILED',
            'failure_reason' => 'Recipient unavailable.',
            'proof_path' => 'delivery-proofs/failed.jpg',
            'attempted_at' => now()->subMinutes(10),
        ]);
        DeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'rider_assignment_id' => $assignment->id,
            'attempt_number' => 2,
            'status' => 'DELIVERED',
            'proof_path' => 'delivery-proofs/older-delivered.jpg',
            'attempted_at' => now()->subMinutes(5),
        ]);
        $deliveredAttempt = DeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'rider_assignment_id' => $assignment->id,
            'attempt_number' => 3,
            'status' => 'DELIVERED',
            'proof_path' => 'delivery-proofs/delivered.jpg',
            'attempted_at' => now(),
        ]);
        $shipment->update(['current_status' => 'DELIVERED']);
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => 'DELIVERED',
            'notes' => 'Delivery rider delivered the parcel.',
            'occurred_at' => now(),
        ]);

        $proofUrl = route('delivery-events.proof', $deliveredAttempt);
        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('data-proof-of-delivery', false)
            ->assertSee('Proof of Delivery')
            ->assertSee('View Full Photo')
            ->assertSee('href="'.$proofUrl.'" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('Order Received')
            ->assertSee('data-map-live="false"', false)
            ->assertDontSee('src="'.$proofUrl.'"', false)
            ->assertDontSee('alt="Proof of delivery for tracking '.$shipment->tracking_number.'"', false)
            ->assertSee($proofUrl, false)
            ->assertDontSee('delivery-proofs/delivered.jpg')
            ->assertDontSee('delivery-proofs/older-delivered.jpg')
            ->assertDontSee('delivery-proofs/failed.jpg');

        $secondSellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $secondSeller = SellerProfile::create([
            'user_id' => $secondSellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Second Tracking Store',
            'status' => 'ACTIVE',
        ]);
        $secondSellerOrder = SellerOrder::create([
            'seller_order_number' => 'TRACKING-SELLER-002',
            'order_id' => $order->id,
            'seller_profile_id' => $secondSeller->id,
            'status' => 'READY_FOR_PICKUP',
            'grand_total' => 50,
        ]);
        $secondShipment = Shipment::create([
            'seller_order_id' => $secondSellerOrder->id,
            'tracking_number' => 'TRACKING-PARCEL-002',
            'destination_province_code' => 'P-TEST',
            'destination_province_name' => 'Test Province',
            'destination_municipality_code' => 'M-TEST',
            'destination_municipality_name' => 'Test Municipality',
            'destination_barangay_code' => 'B-TEST',
            'destination_barangay_name' => 'Test Barangay',
            'current_status' => 'SORTED',
        ]);
        ShipmentEvent::create([
            'shipment_id' => $secondShipment->id,
            'status' => 'PLACED',
            'notes' => 'Order placed.',
            'occurred_at' => now()->subHour(),
        ]);
        ShipmentEvent::create([
            'shipment_id' => $secondShipment->id,
            'status' => 'SORTED',
            'notes' => 'Sorted by destination.',
            'occurred_at' => now()->subMinutes(20),
        ]);
        $multipleSellerDetails = $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee($shipment->tracking_number)
            ->assertSee($secondShipment->tracking_number)
            ->assertSee('data-proof-of-delivery', false)
            ->assertDontSee('Proof of Delivery for tracking '.$secondShipment->tracking_number);
        $this->assertSame(1, substr_count($multipleSellerDetails->getContent(), 'data-proof-of-delivery'));

        $secondShipment->update(['current_status' => 'DELIVERED']);
        ShipmentEvent::create([
            'shipment_id' => $secondShipment->id,
            'status' => 'DELIVERED',
            'notes' => 'Delivered without a saved proof file.',
            'occurred_at' => now(),
        ]);
        $secondAssignment = RiderAssignment::create([
            'shipment_id' => $secondShipment->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => 'DELIVERY',
            'status' => 'COMPLETED',
            'completed_at' => now(),
        ]);
        DeliveryAttempt::create([
            'shipment_id' => $secondShipment->id,
            'rider_assignment_id' => $secondAssignment->id,
            'attempt_number' => 1,
            'status' => 'DELIVERED',
            'proof_path' => 'delivery-proofs/missing-file.jpg',
            'attempted_at' => now(),
        ]);
        $missingProofDetails = $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee($shipment->tracking_number)
            ->assertSee($secondShipment->tracking_number)
            ->assertDontSee('delivery-proofs/missing-file.jpg');
        $this->assertSame(1, substr_count($missingProofDetails->getContent(), 'data-proof-of-delivery'));

        $this->get($proofUrl)->assertOk();
        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $this->actingAs($otherBuyer)->get($proofUrl)->assertForbidden();
        auth()->logout();
        $this->get($proofUrl)->assertRedirect();

        $secondShipment->update(['current_status' => 'COMPLETED']);
        ShipmentEvent::create([
            'shipment_id' => $secondShipment->id,
            'status' => 'COMPLETED',
            'notes' => 'Buyer confirmed receipt.',
            'occurred_at' => now(),
        ]);
        $shipment->update(['current_status' => 'COMPLETED']);
        $order->update(['status' => 'COMPLETED', 'completed_at' => now()]);
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'status' => 'COMPLETED',
            'notes' => 'Buyer confirmed receipt.',
            'occurred_at' => now(),
        ]);
        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Proof of Delivery')
            ->assertSee('View Full Photo')
            ->assertSee('data-map-live="false"', false)
            ->assertSee('Buyer confirmed receipt');
    }
}
