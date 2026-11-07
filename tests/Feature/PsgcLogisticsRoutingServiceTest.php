<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Order;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\LogisticsCoverageRoute;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\PsgcLogisticsRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PsgcLogisticsRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PAGSANJAN = [
        'province_code' => '0403400000',
        'province_name' => 'Laguna',
        'municipality_code' => '0403419000',
        'municipality_name' => 'Pagsanjan',
        'barangay_code' => '0403419001',
        'barangay_name' => 'Anibong',
    ];

    private const PILA = [
        'province_code' => '0403400000',
        'province_name' => 'Laguna',
        'municipality_code' => '0403422000',
        'municipality_name' => 'Pila',
        'barangay_code' => '0403422015',
        'barangay_name' => 'San Miguel',
    ];

    public function test_canonical_pagsanjan_to_pila_addresses_resolve_their_unique_authorized_centers(): void
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->routingFixture();

        $resolved = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);

        $this->assertSame($origin->id, $resolved['origin']->id);
        $this->assertSame($destination->id, $resolved['destination']->id);
        $this->assertSame(self::PILA['barangay_code'], $resolved['destination_area']->barangay_code);
    }

    public function test_multiple_pagsanjan_centers_fail_closed_even_when_barangay_is_covered(): void
    {
        [$seller, $buyerAddress] = $this->routingFixture();
        $this->centerFor('Pagsanjan Logistics Duplicate', '0403419000', 'Pagsanjan', '0403419001', 'Anibong');
        $this->assertAmbiguous($seller, $buyerAddress);
    }

    public function test_no_eligible_pagsanjan_center_returns_a_controlled_failure(): void
    {
        [$seller, $buyerAddress, $origin] = $this->routingFixture();
        $origin->delete();

        try {
            app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);
            $this->fail('Missing seller-origin routing must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no active logistics hub', strtolower((string) $exception->errors()['address_id'][0]));
        }
    }

    public function test_multiple_pila_centers_fail_closed_even_when_barangay_is_covered(): void
    {
        [$seller, $buyerAddress] = $this->routingFixture();
        $this->centerFor('Pila Logistics Duplicate', '0403422000', 'Pila', '0403422015', 'San Miguel');
        $this->assertAmbiguous($seller, $buyerAddress);
    }

    public function test_exact_barangay_coverage_selects_the_only_matching_center_in_a_municipality(): void
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->routingFixture();
        $this->centerFor('Pila Other Barangay', '0403422000', 'Pila', '0403422016', 'Other Barangay');

        $resolved = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);

        $this->assertSame($origin->id, $resolved['origin']->id);
        $this->assertSame($destination->id, $resolved['destination']->id);
    }

    public function test_manual_routing_accepts_only_selected_approved_hubs_with_exact_barangay_coverage(): void
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->routingFixture();
        $this->centerFor('Pagsanjan Logistics Duplicate', '0403419000', 'Pagsanjan', '0403419001', 'Anibong');

        $resolved = app(PsgcLogisticsRoutingService::class)->resolveWithCenters($seller, $buyerAddress, $origin->id, $destination->id);

        $this->assertSame($origin->id, $resolved['origin']->id);
        $this->assertSame($destination->id, $resolved['destination']->id);
        $this->assertSame(self::PILA['barangay_code'], $resolved['destination_area']->barangay_code);
    }

    public function test_manual_routing_rejects_hub_that_does_not_match_selected_municipality(): void
    {
        [$seller, $buyerAddress, , $destination] = $this->routingFixture();

        try {
            app(PsgcLogisticsRoutingService::class)->resolveWithCenters($seller, $buyerAddress, $destination->id, $destination->id);
            $this->fail('Manually selected hubs must match the saved address PSGC.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('seller pickup barangay psgc is not covered', strtolower((string) $exception->errors()['address_id'][0]));
        }
    }

    public function test_exact_primary_psgc_route_stays_first_when_smart_fallback_is_disabled(): void
    {
        config(['likhae.smart_nearby_logistics_fallback' => false]);
        [$seller, $buyerAddress, $origin, $destination] = $this->routingFixture();

        $resolved = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);

        $this->assertSame('PRIMARY', $resolved['coverage_type']);
        $this->assertNull($resolved['coverage_route']);
        $this->assertSame($origin->id, $resolved['origin']->id);
        $this->assertSame($destination->id, $resolved['destination']->id);
    }

    public function test_explicit_approved_secondary_route_serves_an_uncovered_barangay(): void
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->fallbackFixture();
        $this->coverageRoute($origin, $destination, $buyerAddress, LogisticsCoverageRoute::TYPE_SECONDARY);

        $resolved = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);

        $this->assertSame($origin->id, $resolved['origin']->id);
        $this->assertSame($destination->id, $resolved['destination']->id);
        $this->assertSame(LogisticsCoverageRoute::TYPE_SECONDARY, $resolved['coverage_type']);
        $this->assertInstanceOf(LogisticsCoverageRoute::class, $resolved['coverage_route']);
        $this->assertSame('0403422016', $resolved['destination_area']->barangay_code);
        $this->assertNotNull($resolved['service_date']);
        $this->assertNotNull($resolved['estimated_delivery_at']);
    }

    public function test_unapproved_or_inactive_nearby_route_does_not_make_address_serviceable(): void
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->fallbackFixture();
        $this->coverageRoute($origin, $destination, $buyerAddress, LogisticsCoverageRoute::TYPE_EXTENDED, active: false);

        try {
            app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);
            $this->fail('An inactive route must not authorize delivery based on hub proximity.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no approved secondary or extended', strtolower((string) $exception->errors()['address_id'][0]));
        }
    }

    public function test_multiple_approved_routes_are_ranked_deterministically(): void
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->fallbackFixture();
        $this->coverageRoute($origin, $destination, $buyerAddress, LogisticsCoverageRoute::TYPE_SECONDARY);
        $otherDestination = $this->centerFor('Calamba Backup Hub', '0403405000', 'City of Calamba', '0403405001', 'Bagong Kalsada');
        $this->coverageRoute($origin, $otherDestination, $buyerAddress, LogisticsCoverageRoute::TYPE_EXTENDED);

        $first = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);
        $second = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);

        $this->assertSame($destination->id, $first['destination']->id);
        $this->assertSame($first['coverage_route']->id, $second['coverage_route']->id);
    }

    public function test_full_route_capacity_uses_another_valid_route(): void
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->fallbackFixture();
        $fullRoute = $this->coverageRoute($origin, $destination, $buyerAddress, LogisticsCoverageRoute::TYPE_SECONDARY, capacity: 1);
        $initialDecision = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);
        $fullRoute->update(['valid_until' => $initialDecision['estimated_delivery_at']]);
        $this->reserveRouteCapacity($seller, $buyerAddress, $fullRoute, $initialDecision['service_date']->toDateString());

        $otherDestination = $this->centerFor('Calamba Capacity Backup', '0403405000', 'City of Calamba', '0403405001', 'Bagong Kalsada');
        $availableRoute = $this->coverageRoute($origin, $otherDestination, $buyerAddress, LogisticsCoverageRoute::TYPE_EXTENDED);

        $resolved = app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);

        $this->assertSame($availableRoute->id, $resolved['coverage_route']->id);
    }

    /** @return array{0: SellerProfile, 1: Address, 2: LogisticsCenter, 3: LogisticsCenter} */
    private function routingFixture(): array
    {
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $category = Category::create(['name' => 'Routing Test', 'slug' => 'routing-test', 'is_active' => true]);
        $sellerAddress = $this->address($sellerUser, self::PAGSANJAN, 'Seller pickup address');
        $buyerAddress = $this->address($buyer, self::PILA, 'Buyer delivery address');
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $sellerAddress->id,
            'business_name' => 'Pagsanjan Routing Test Seller',
            'status' => 'ACTIVE',
        ]);
        $origin = $this->centerFor('Pagsanjan Logistics', '0403419000', 'Pagsanjan', '0403419001', 'Anibong');
        $destination = $this->centerFor('Pila Logistics', '0403422000', 'Pila', '0403422015', 'San Miguel');

        return [$seller, $buyerAddress, $origin, $destination];
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

    private function centerFor(string $name, string $municipalityCode, string $municipalityName, string $barangayCode, string $barangayName): LogisticsCenter
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $codes = [
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => $municipalityCode,
            'municipality_name' => $municipalityName,
            'barangay_code' => $barangayCode,
            'barangay_name' => $barangayName,
        ];
        $address = $this->address($owner, $codes, $name.' address');
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'address_id' => $address->id,
            'code' => strtoupper(str_replace(' ', '-', $name)).'-'.uniqid(),
            'business_name' => $name,
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'AREA-'.uniqid(),
            'name' => $name.' coverage',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create(['service_area_id' => $area->id, ...$codes]);

        return $center;
    }

    private function assertAmbiguous(SellerProfile $seller, Address $buyerAddress): void
    {
        try {
            app(PsgcLogisticsRoutingService::class)->resolve($seller, $buyerAddress);
            $this->fail('Ambiguous routing must never guess a hub.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ambiguous', strtolower((string) $exception->errors()['address_id'][0]));
        }
    }

    /** @return array{0: SellerProfile, 1: Address, 2: LogisticsCenter, 3: LogisticsCenter} */
    private function fallbackFixture(): array
    {
        [$seller, $buyerAddress, $origin, $destination] = $this->routingFixture();
        $buyerAddress->forceFill([
            'barangay_code' => '0403422016',
            'barangay_name' => 'Santa Clara Norte',
        ])->save();
        config(['likhae.smart_nearby_logistics_fallback' => true]);

        return [$seller, $buyerAddress, $origin, $destination];
    }

    private function coverageRoute(
        LogisticsCenter $origin,
        LogisticsCenter $destination,
        Address $buyerAddress,
        string $coverageType,
        bool $active = true,
        int $capacity = 10,
    ): LogisticsCoverageRoute {
        $area = ServiceArea::create([
            'logistics_center_id' => $destination->id,
            'code' => 'EXT-'.strtoupper(uniqid()),
            'name' => 'Approved '.$coverageType.' coverage',
            'is_active' => true,
        ]);
        $location = ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => $buyerAddress->province_code,
            'province_name' => $buyerAddress->province_name,
            'municipality_code' => $buyerAddress->municipality_code,
            'municipality_name' => $buyerAddress->municipality_name,
            'barangay_code' => $buyerAddress->barangay_code,
            'barangay_name' => $buyerAddress->barangay_name,
            'coverage_type' => $coverageType,
        ]);
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN, 'status' => User::STATUS_ACTIVE]);

        return LogisticsCoverageRoute::query()->create([
            'origin_logistics_center_id' => $origin->id,
            'destination_service_area_location_id' => $location->id,
            'coverage_type' => $coverageType,
            'shipment_type' => LogisticsCoverageRoute::SHIPMENT_MARKETPLACE,
            'is_active' => $active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(30),
            'max_parcels_per_day' => $capacity,
            'estimated_sla_hours' => 36,
            'minimum_pickup_lead_hours' => 24,
            'dispatch_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'dispatch_cutoff_time' => '23:59:00',
            'created_by_user_id' => $admin->id,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'updated_by_user_id' => $admin->id,
        ]);
    }

    private function reserveRouteCapacity(
        SellerProfile $seller,
        Address $buyerAddress,
        LogisticsCoverageRoute $route,
        string $serviceDate,
    ): Shipment {
        $buyer = User::query()->findOrFail($buyerAddress->user_id);
        $order = Order::query()->create([
            'order_number' => 'ROUTE-CAPACITY-'.strtoupper(uniqid()),
            'buyer_user_id' => $buyer->id,
            'currency' => 'PHP',
            'status' => 'PLACED',
            'payment_status' => 'PENDING',
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'ROUTE-CAPACITY-SO-'.strtoupper(uniqid()),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'PLACED',
            'item_subtotal' => 1,
            'shipping_fee' => 1,
            'grand_total' => 2,
        ]);

        return Shipment::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'ROUTE-CAPACITY-'.strtoupper(uniqid()),
            'smart_coverage_route_id' => $route->id,
            'smart_coverage_service_date' => $serviceDate,
            'destination_province_code' => $buyerAddress->province_code,
            'destination_province_name' => $buyerAddress->province_name,
            'destination_municipality_code' => $buyerAddress->municipality_code,
            'destination_municipality_name' => $buyerAddress->municipality_name,
            'destination_barangay_code' => $buyerAddress->barangay_code,
            'destination_barangay_name' => $buyerAddress->barangay_name,
            'current_status' => 'PLACED',
        ]);
    }
}
