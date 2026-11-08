<?php

namespace Tests\Feature;

use App\Events\RiderLocationUpdated;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderAssignmentLocation;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Maps\MapDataService;
use Tests\TestCase;

class TrackingRealtimeTest extends TestCase
{
    public function test_buyer_tracking_payload_contains_only_the_buyers_scoped_navigation_destination(): void
    {
        $buyer = new User([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $buyer->id = 1201;
        $rider = new RiderProfile([
            'user_id' => 1203,
            'status' => 'ACTIVE',
        ]);
        $rider->id = 1202;
        $assignment = new RiderAssignment([
            'shipment_id' => 1205,
            'rider_profile_id' => $rider->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY,
            'status' => 'IN_PROGRESS',
        ]);
        $assignment->id = 1204;
        $assignment->setRelation('riderProfile', $rider);
        $assignment->setRelation('liveLocation', new RiderAssignmentLocation([
            'latitude' => 14.58,
            'longitude' => 120.98,
            'recorded_at' => now(),
        ]));

        $address = new OrderAddress([
            'recipient_name' => 'Private Buyer',
            'street_address' => 'Saved Buyer Street',
            'barangay_name' => 'Buyer Barangay',
            'municipality_name' => 'Buyer City',
            'province_name' => 'Buyer Province',
            'latitude' => 14.60,
            'longitude' => 121.00,
        ]);
        $order = new Order(['buyer_user_id' => $buyer->id]);
        $order->setRelation('address', $address);
        $sellerOrder = new SellerOrder();
        $sellerOrder->setRelation('order', $order);
        $sellerOrder->setRelation('sellerProfile', new SellerProfile());

        $shipment = new Shipment([
            'tracking_number' => 'TRACK-REALTIME-1205',
            'current_status' => 'OUT_FOR_DELIVERY',
        ]);
        $shipment->id = 1205;
        $shipment->setRelation('sellerOrder', $sellerOrder);
        $shipment->setRelation('riderAssignments', collect([$assignment]));
        $shipment->setRelation('logisticsCenter', null);
        $shipment->setRelation('serviceArea', null);

        $markers = app(MapDataService::class)->forShipment($shipment, true, false, $buyer);
        $destination = collect($markers)->firstWhere('target_kind', 'buyer');

        $this->assertSame('Private Buyer', $destination['navigation_destination']['name']);
        $this->assertStringContainsString('Saved Buyer Street', $destination['navigation_destination']['address']);
        $this->assertArrayNotHasKey('navigation_destination', collect($markers)->firstWhere('kind', 'rider'));
    }

    public function test_buyer_can_see_assigned_delivery_rider_location_before_out_for_delivery(): void
    {
        $buyer = new User(['account_type' => User::TYPE_BUYER]);
        $buyer->id = 2201;
        $rider = new RiderProfile(['user_id' => 2202, 'status' => 'ACTIVE']);
        $rider->id = 2203;
        $assignment = new RiderAssignment([
            'shipment_id' => 2205,
            'rider_profile_id' => $rider->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY,
            'status' => 'ACCEPTED',
        ]);
        $assignment->id = 2204;
        $assignment->setRelation('riderProfile', $rider);
        $assignment->setRelation('liveLocation', new RiderAssignmentLocation([
            'latitude' => 14.58,
            'longitude' => 120.98,
            'recorded_at' => now(),
        ]));

        $order = new Order(['buyer_user_id' => $buyer->id]);
        $order->setRelation('address', null);
        $sellerOrder = new SellerOrder();
        $sellerOrder->setRelation('order', $order);
        $sellerOrder->setRelation('sellerProfile', null);
        $shipment = new Shipment([
            'tracking_number' => 'TRACK-ASSIGNED-2205',
            'current_status' => 'ASSIGNED_TO_RIDER',
        ]);
        $shipment->id = 2205;
        $shipment->setRelation('sellerOrder', $sellerOrder);
        $shipment->setRelation('riderAssignments', collect([$assignment]));
        $shipment->setRelation('transferLegs', collect());
        $shipment->setRelation('logisticsCenter', null);
        $shipment->setRelation('serviceArea', null);
        $shipment->setRelation('originLogisticsCenter', null);
        $shipment->setRelation('destinationLogisticsCenter', null);

        $markers = app(MapDataService::class)->forShipment($shipment, false, false, $buyer);
        $riderMarker = collect($markers)->firstWhere('kind', 'rider');

        $this->assertNotNull($riderMarker);
        $this->assertSame(14.58, $riderMarker['latitude']);
        $this->assertSame(120.98, $riderMarker['longitude']);
        $this->assertTrue($riderMarker['active_rider']);
    }

    public function test_rider_location_event_contains_current_assignment_coordinates(): void
    {
        $shipment = new Shipment(['current_status' => 'OUT_FOR_DELIVERY']);
        $shipment->id = 1301;
        $assignment = new RiderAssignment([
            'shipment_id' => $shipment->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY,
            'status' => 'IN_PROGRESS',
        ]);
        $assignment->id = 1302;
        $assignment->setRelation('shipment', $shipment);
        $location = new RiderAssignmentLocation([
            'rider_assignment_id' => $assignment->id,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'recorded_at' => now(),
        ]);
        $location->setRelation('riderAssignment', $assignment);

        $event = new RiderLocationUpdated($location);

        $this->assertSame('private-shipments.1301', $event->broadcastOn()[0]->name);
        $this->assertSame('rider.location.updated', $event->broadcastAs());
        $this->assertSame(1302, $event->broadcastWith()['assignment_id']);
        $this->assertSame(14.5995, $event->broadcastWith()['latitude']);
        $this->assertSame(120.9842, $event->broadcastWith()['longitude']);
    }
}
