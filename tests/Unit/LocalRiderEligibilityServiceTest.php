<?php

namespace Tests\Unit;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\Fulfillment\LocalRiderEligibilityService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class LocalRiderEligibilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn () => Http::response([], 500));
    }

    public function test_active_local_rider_can_cover_one_barangay_owned_by_the_center(): void
    {
        $service = new LocalRiderEligibilityService;
        $center = $this->center();
        $area = $this->area([
            'province_code' => '0403400000',
            'municipality_code' => '0403419000',
            'barangay_code' => '0403419001',
        ]);
        $rider = $this->rider('motorcycle');

        $location = $service->assertRiderCanCoverArea($center, $rider, $area);

        $this->assertSame('0403419001', $location->barangay_code);
    }

    public function test_local_assignment_rejects_foreign_or_ambiguous_area_and_non_local_vehicle(): void
    {
        $service = new LocalRiderEligibilityService;
        $center = $this->center();
        $rider = $this->rider('motorcycle');

        $this->expectException(RuntimeException::class);
        $service->assertRiderCanCoverArea($center, $rider, $this->area([
            'province_code' => '0403400000',
            'municipality_code' => '0403419000',
            'barangay_code' => '0403419001',
        ], [
            ['province_code' => '0403400000', 'municipality_code' => '0403419000', 'barangay_code' => '0403419002'],
        ]));
    }

    public function test_local_assignment_rejects_inter_logistics_vehicle(): void
    {
        $service = new LocalRiderEligibilityService;

        $this->expectException(RuntimeException::class);
        $service->assertRiderCanCoverArea($this->center(), $this->rider('truck'), $this->area([
            'province_code' => '0403400000',
            'municipality_code' => '0403419000',
            'barangay_code' => '0403419001',
        ]));
    }

    private function center(): LogisticsCenter
    {
        $address = (new Address)->forceFill([
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403419000',
            'municipality_name' => 'Pagsanjan',
        ]);
        $center = new LogisticsCenter;
        $center->setRawAttributes([
            'id' => 10,
            'status' => 'ACTIVE',
            'approved_by_user_id' => 1,
            'approved_at' => '2026-01-01 00:00:00',
        ]);
        $center->setRelation('address', $address);

        return $center;
    }

    private function area(array $location, array $additional = []): ServiceArea
    {
        $area = (new ServiceArea)->forceFill(['id' => 20, 'logistics_center_id' => 10, 'is_active' => true]);
        $locations = collect(array_merge([$location], $additional))
            ->map(fn (array $attributes) => (new ServiceAreaLocation)->forceFill($attributes));
        $area->setRelation('locations', $locations);

        return $area;
    }

    private function rider(string $vehicleType): RiderProfile
    {
        $user = (new User)->forceFill([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $rider = (new RiderProfile)->forceFill([
            'logistics_center_id' => 10,
            'status' => 'ACTIVE',
            'vehicle_type' => $vehicleType,
        ]);
        $rider->setRelation('user', $user);

        return $rider;
    }
}
