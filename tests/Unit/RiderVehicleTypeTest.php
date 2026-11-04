<?php

namespace Tests\Unit;

use App\Support\RiderVehicleType;
use PHPUnit\Framework\TestCase;

class RiderVehicleTypeTest extends TestCase
{
    public function test_registration_values_include_local_and_inter_logistics_vehicles(): void
    {
        $this->assertSame([
            'motorcycle',
            'tricycle',
            'van',
            'closed_van',
            'truck',
            'car',
        ], RiderVehicleType::acceptedValues());
    }

    public function test_vehicle_roles_distinguish_local_and_inter_logistics_values(): void
    {
        $this->assertSame(RiderVehicleType::ROLE_LOCAL_BARANGAY, RiderVehicleType::role('motorcycle'));
        $this->assertSame(RiderVehicleType::ROLE_LOCAL_BARANGAY, RiderVehicleType::role('tricycle'));
        $this->assertSame(RiderVehicleType::ROLE_INTER_LOGISTICS, RiderVehicleType::role('closed_van'));
        $this->assertSame(RiderVehicleType::ROLE_INTER_LOGISTICS, RiderVehicleType::role('Truck'));
        $this->assertSame(RiderVehicleType::ROLE_INTER_LOGISTICS, RiderVehicleType::role('Car'));
    }

    public function test_local_assignment_values_are_motorcycle_and_tricycle_only(): void
    {
        $this->assertSame(['motorcycle', 'tricycle'], RiderVehicleType::localValues());
    }

    public function test_inter_logistics_assignment_values_include_four_wheel_cars_but_exclude_local_vehicles(): void
    {
        $this->assertSame(['van', 'closed_van', 'truck', 'car'], RiderVehicleType::interLogisticsValues());
        $this->assertTrue(RiderVehicleType::isInterLogistics('Van'));
        $this->assertTrue(RiderVehicleType::isInterLogistics('closed van'));
        $this->assertTrue(RiderVehicleType::isInterLogistics('truck'));
        $this->assertFalse(RiderVehicleType::isInterLogistics('motorcycle'));
        $this->assertFalse(RiderVehicleType::isInterLogistics('tricycle'));
        $this->assertTrue(RiderVehicleType::isInterLogistics('car'));
        $this->assertSame(10, RiderVehicleType::interLogisticsParcelLimit('car'));
    }

    public function test_availability_support_is_limited_to_local_vehicles_and_vans(): void
    {
        $this->assertTrue(RiderVehicleType::supportsAvailability('motorcycle'));
        $this->assertTrue(RiderVehicleType::supportsAvailability('tricycle'));
        $this->assertTrue(RiderVehicleType::supportsAvailability('Van'));
        $this->assertTrue(RiderVehicleType::isVan('van'));
        $this->assertTrue(RiderVehicleType::isVan(' Van '));
        $this->assertFalse(RiderVehicleType::supportsAvailability('closed_van'));
        $this->assertFalse(RiderVehicleType::supportsAvailability('truck'));
        $this->assertFalse(RiderVehicleType::supportsAvailability('car'));
    }
}
