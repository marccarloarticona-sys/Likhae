<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderAddress;
use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderAssignmentLocation;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Maps\MapDataService;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MapboxComponentTest extends TestCase
{
    public function test_shared_map_component_renders_safe_controls_and_marker_data(): void
    {
        config(['services.geoapify.key' => 'geoapify-test-key']);

        $html = Blade::render('<x-shared.mapbox :markers="$markers" />', [
            'markers' => [[
                'id' => 'destination-1',
                'kind' => 'destination',
                'title' => 'Delivery destination',
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'popup' => 'Manila',
            ]],
        ]);

        $this->assertStringContainsString('data-likhae-map', $html);
        $this->assertStringContainsString('Use my location', $html);
        $this->assertStringContainsString('Open full map', $html);
        $this->assertStringContainsString('geoapify-test-key', $html);
        $this->assertStringContainsString('destination-1', $html);
        $this->assertStringNotContainsString('data-map-navigation-panel', $html);
    }

    public function test_rider_navigation_map_renders_eta_distance_and_destination_panel(): void
    {
        config(['services.geoapify.key' => 'geoapify-test-key']);

        $html = Blade::render('<x-shared.mapbox :markers="$markers" navigation />', [
            'markers' => [],
        ]);

        $this->assertStringContainsString('data-map-navigation="true"', $html);
        $this->assertStringContainsString('data-map-navigation-eta', $html);
        $this->assertStringContainsString('data-map-navigation-distance', $html);
        $this->assertStringContainsString('data-map-navigation-arrival', $html);
        $this->assertStringContainsString('data-map-navigation-eta>—</strong>', $html);
        $this->assertStringNotContainsString('Unavailable</strong>', $html);
        $this->assertStringContainsString('function navigationMetricPlaceholder', file_get_contents(resource_path('js/shared/mapbox.js')));
        $this->assertStringContainsString('#800000', file_get_contents(resource_path('css/shared/mapbox.css')));
    }

    public function test_address_pin_component_exposes_explicit_coordinate_confirmation_fields(): void
    {
        config(['services.geoapify.key' => 'geoapify-test-key']);

        $html = Blade::render('<form><x-shared.address-pin /></form>');

        $this->assertStringContainsString('data-address-pin', $html);
        $this->assertStringContainsString('data-address-confirm', $html);
        $this->assertStringContainsString('name="latitude"', $html);
        $this->assertStringContainsString('name="longitude"', $html);
        $this->assertStringContainsString('geoapify-test-key', $html);
    }

    public function test_map_data_service_uses_order_destination_coordinates_without_customer_details(): void
    {
        $address = new OrderAddress([
            'barangay_name' => 'Barangay Test',
            'municipality_name' => 'Test City',
            'province_name' => 'Test Province',
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'recipient_name' => 'Private Customer',
        ]);
        $order = new Order();
        $order->setRelation('address', $address);
        $sellerOrder = new SellerOrder();
        $sellerOrder->setRelation('order', $order);
        $shipment = new Shipment();
        $shipment->setRelation('sellerOrder', $sellerOrder);
        $shipment->setRelation('riderAssignments', collect());
        $shipment->setRelation('logisticsCenter', null);

        $markers = app(MapDataService::class)->forShipment($shipment);

        $this->assertCount(1, $markers);
        $this->assertSame(14.5995, $markers[0]['latitude']);
        $this->assertSame(120.9842, $markers[0]['longitude']);
        $this->assertArrayNotHasKey('recipient_name', $markers[0]);
        $this->assertArrayNotHasKey('navigation_destination', $markers[0]);
        $this->assertStringNotContainsString('Private Customer', json_encode($markers));
    }

    public function test_rider_map_exposes_location_update_endpoint_before_first_gps_coordinate(): void
    {
        $riderUser = new User(['account_type' => User::TYPE_RIDER]);
        $riderUser->id = 701;
        $rider = new RiderProfile(['user_id' => $riderUser->id]);
        $rider->id = 801;
        $rider->setRelation('user', $riderUser);

        $assignment = new RiderAssignment([
            'shipment_id' => 901,
            'rider_profile_id' => $rider->id,
            'assignment_type' => RiderAssignment::TYPE_PICKUP,
            'status' => 'ACCEPTED',
        ]);
        $assignment->id = 1001;
        $assignment->setRelation('riderProfile', $rider);
        $assignment->setRelation('liveLocation', null);

        $address = new OrderAddress([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'barangay_name' => 'Barangay Test',
            'municipality_name' => 'Test City',
            'province_name' => 'Test Province',
        ]);
        $order = new Order();
        $order->setRelation('address', $address);
        $sellerOrder = new SellerOrder();
        $sellerOrder->setRelation('order', $order);
        $sellerOrder->setRelation('sellerProfile', null);

        $shipment = new Shipment([
            'tracking_number' => 'TRACK-901',
            'current_status' => 'READY_FOR_PICKUP',
        ]);
        $shipment->id = 901;
        $shipment->setRelation('sellerOrder', $sellerOrder);
        $shipment->setRelation('riderAssignments', collect([$assignment]));
        $shipment->setRelation('logisticsCenter', null);
        $shipment->setRelation('serviceArea', null);

        $this->actingAs($riderUser);
        $markers = app(MapDataService::class)->forShipment($shipment);
        $marker = collect($markers)->firstWhere('id', 'rider-assignment-1001');

        $this->assertNotNull($marker);
        $this->assertArrayNotHasKey('latitude', $marker);
        $this->assertSame(route('rider.assignments.location', ['assignment' => 1001]), $marker['location_update_endpoint']);
    }

    public function test_accepted_delivery_map_routes_to_the_saved_order_destination(): void
    {
        $riderUser = new User(['account_type' => User::TYPE_RIDER]);
        $riderUser->id = 702;
        $rider = new RiderProfile(['user_id' => $riderUser->id]);
        $rider->id = 802;
        $rider->setRelation('user', $riderUser);

        $assignment = new RiderAssignment([
            'shipment_id' => 902,
            'rider_profile_id' => $rider->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY,
            'status' => 'ACCEPTED',
        ]);
        $assignment->id = 1002;
        $assignment->setRelation('riderProfile', $rider);
        $assignment->setRelation('liveLocation', null);

        $buyerAddress = new OrderAddress([
            'recipient_name' => 'Navigation Buyer',
            'latitude' => 14.6101,
            'longitude' => 120.9901,
            'barangay_name' => 'Buyer Barangay',
            'municipality_name' => 'Buyer City',
            'province_name' => 'Buyer Province',
        ]);
        $order = new Order();
        $order->setRelation('address', $buyerAddress);
        $seller = new SellerProfile();
        $seller->setRelation('businessAddress', new OrderAddress([
            'latitude' => 14.5801,
            'longitude' => 120.9701,
        ]));
        $sellerOrder = new SellerOrder();
        $sellerOrder->setRelation('order', $order);
        $sellerOrder->setRelation('sellerProfile', $seller);

        $shipment = new Shipment([
            'tracking_number' => 'TRACK-902',
            'current_status' => 'ASSIGNED_TO_RIDER',
        ]);
        $shipment->id = 902;
        $shipment->setRelation('sellerOrder', $sellerOrder);
        $shipment->setRelation('riderAssignments', collect([$assignment]));
        $shipment->setRelation('logisticsCenter', null);
        $shipment->setRelation('serviceArea', null);

        $this->actingAs($riderUser);
        $service = app(MapDataService::class);
        $markers = $service->forShipment($shipment, true, true);
        $tracking = $service->trackingState($shipment, $riderUser);
        $riderMarker = collect($markers)->firstWhere('id', 'rider-assignment-1002');

        $this->assertSame('buyer', $tracking['active_destination_kind']);
        $this->assertSame(1002, $tracking['active_assignment_id']);
        $this->assertSame(14.6101, $tracking['destination']['latitude']);
        $this->assertSame(120.9901, $tracking['destination']['longitude']);
        $this->assertNotNull($riderMarker);
        $this->assertSame('buyer', $riderMarker['active_destination_kind']);
        $this->assertSame(route('rider.assignments.location', ['assignment' => 1002]), $riderMarker['location_update_endpoint']);
        $buyerMarker = collect($markers)->firstWhere('target_kind', 'buyer');
        $this->assertSame(14.6101, $buyerMarker['latitude']);
        $this->assertSame('Navigation Buyer', $buyerMarker['navigation_destination']['name']);
        $this->assertStringContainsString('Buyer City', $buyerMarker['navigation_destination']['address']);
    }

    public function test_pickup_navigation_switches_from_seller_to_logistics_center_coordinates(): void
    {
        $riderUser = new User(['account_type' => User::TYPE_RIDER]);
        $riderUser->id = 703;
        $rider = new RiderProfile(['user_id' => $riderUser->id]);
        $rider->id = 803;
        $rider->setRelation('user', $riderUser);

        $sellerAddress = new Address([
            'street_address' => 'Seller Street',
            'barangay_name' => 'Seller Barangay',
            'municipality_name' => 'Seller City',
            'province_name' => 'Seller Province',
            'latitude' => 14.5801,
            'longitude' => 120.9701,
        ]);
        $seller = new SellerProfile(['business_name' => 'Seller Navigation Store']);
        $seller->setRelation('businessAddress', $sellerAddress);
        $sellerOrder = new SellerOrder();
        $sellerOrder->setRelation('sellerProfile', $seller);
        $sellerOrder->setRelation('order', (new Order())->setRelation('address', new OrderAddress([
            'latitude' => 14.6101,
            'longitude' => 120.9901,
        ])));

        $centerAddress = new Address([
            'street_address' => 'Sorting Center Road',
            'barangay_name' => 'Center Barangay',
            'municipality_name' => 'Center City',
            'province_name' => 'Center Province',
            'latitude' => 14.5901,
            'longitude' => 120.9801,
        ]);
        $center = new LogisticsCenter(['business_name' => 'Central Sorting Center']);
        $center->id = 901;
        $center->setRelation('address', $centerAddress);

        $assignment = new RiderAssignment([
            'shipment_id' => 903,
            'rider_profile_id' => $rider->id,
            'assignment_type' => RiderAssignment::TYPE_PICKUP,
            'status' => 'IN_PROGRESS',
        ]);
        $assignment->id = 1003;
        $assignment->setRelation('riderProfile', $rider);
        $assignment->setRelation('liveLocation', new RiderAssignmentLocation([
            'latitude' => 14.5701,
            'longitude' => 120.9601,
            'recorded_at' => now(),
        ]));

        $shipment = new Shipment([
            'tracking_number' => 'TRACK-903',
            'current_status' => 'READY_FOR_PICKUP',
        ]);
        $shipment->id = 903;
        $shipment->setRelation('sellerOrder', $sellerOrder);
        $shipment->setRelation('riderAssignments', collect([$assignment]));
        $shipment->setRelation('logisticsCenter', $center);
        $shipment->setRelation('serviceArea', null);

        $this->actingAs($riderUser);
        $service = app(MapDataService::class);
        $markers = $service->forShipment($shipment, true, true);
        $sellerMarker = collect($markers)->firstWhere('target_kind', 'seller');

        $this->assertSame('Seller Navigation Store', $sellerMarker['navigation_destination']['name']);
        $this->assertStringContainsString('Seller Street', $sellerMarker['navigation_destination']['address']);

        $assignment->status = 'COMPLETED';
        $shipment->current_status = 'PICKED_UP';
        $markers = $service->forShipment($shipment, true, true);
        $centerMarker = collect($markers)->firstWhere('target_kind', 'logistics');

        $this->assertSame('Central Sorting Center', $centerMarker['navigation_destination']['name']);
        $this->assertStringContainsString('Sorting Center Road', $centerMarker['navigation_destination']['address']);
    }
}
