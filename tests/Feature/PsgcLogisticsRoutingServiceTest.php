<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Seller\Category;
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
            $this->assertStringContainsString('not active, approved, or authorized', strtolower((string) $exception->errors()['address_id'][0]));
        }
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
}
