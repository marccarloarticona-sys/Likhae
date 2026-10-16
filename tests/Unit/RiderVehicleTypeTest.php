<?php

namespace Tests\Unit;

use App\Support\RiderVehicleType;
use PHPUnit\Framework\TestCase;

class RiderVehicleTypeTest extends TestCase
{
    public function test_registration_values_include_required_vehicle_types_and_legacy_car(): void
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

    public function test_vehicle_roles_distinguish_local_transfer_and_legacy_values(): void
    {
        $this->assertSame(RiderVehicleType::ROLE_LOCAL_BARANGAY, RiderVehicleType::role('motorcycle'));
        $this->assertSame(RiderVehicleType::ROLE_LOCAL_BARANGAY, RiderVehicleType::role('tricycle'));
        $this->assertSame(RiderVehicleType::ROLE_INTER_LOGISTICS, RiderVehicleType::role('closed_van'));
        $this->assertSame(RiderVehicleType::ROLE_INTER_LOGISTICS, RiderVehicleType::role('Truck'));
        $this->assertSame(RiderVehicleType::ROLE_LEGACY, RiderVehicleType::role('Car'));
    }

    public function test_local_assignment_values_are_motorcycle_and_tricycle_only(): void
    {
        $this->assertSame(['motorcycle', 'tricycle'], RiderVehicleType::localValues());
    }

    public function test_inter_logistics_assignment_values_exclude_local_and_legacy_vehicles(): void
    {
        $this->assertSame(['van', 'closed_van', 'truck'], RiderVehicleType::interLogisticsValues());
        $this->assertTrue(RiderVehicleType::isInterLogistics('Van'));
        $this->assertTrue(RiderVehicleType::isInterLogistics('closed van'));
        $this->assertTrue(RiderVehicleType::isInterLogistics('truck'));
        $this->assertFalse(RiderVehicleType::isInterLogistics('motorcycle'));
        $this->assertFalse(RiderVehicleType::isInterLogistics('tricycle'));
        $this->assertFalse(RiderVehicleType::isInterLogistics('car'));
    }
}
