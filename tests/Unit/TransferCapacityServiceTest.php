<?php

namespace Tests\Unit;

use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\Fulfillment\TransferCapacityService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TransferCapacityServiceTest extends TestCase
{
    public function test_inter_manifest_requires_complete_approved_capacity(): void
    {
        $driver = $this->driver(['approved_parcel_capacity' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('complete approved parcel, weight, and volume capacity');

        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => 1, 'volume_liters' => 1],
        ]);
    }

    public function test_inter_manifest_rejects_unknown_physical_parcel_data(): void
    {
        $driver = $this->driver([
            'approved_parcel_capacity' => 2,
            'approved_weight_capacity_kg' => 10,
            'approved_volume_capacity_liters' => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('weight or volume is missing');

        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => null, 'volume_liters' => 1],
        ]);
    }

    public function test_inter_manifest_rejects_nonpositive_physical_parcel_data(): void
    {
        $driver = $this->driver([
            'approved_parcel_capacity' => 2,
            'approved_weight_capacity_kg' => 10,
            'approved_volume_capacity_liters' => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be positive');

        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => -1, 'volume_liters' => 1],
        ]);
    }

    public function test_inter_manifest_enforces_count_weight_and_volume(): void
    {
        $driver = $this->driver([
            'approved_parcel_capacity' => 2,
            'approved_weight_capacity_kg' => 10,
            'approved_volume_capacity_liters' => 10,
        ]);

        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => 4, 'volume_liters' => 3],
            ['weight_kg' => 5, 'volume_liters' => 6],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('weight or volume capacity');
        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => 6, 'volume_liters' => 3],
            ['weight_kg' => 5, 'volume_liters' => 6],
        ]);
    }

    public function test_manifest_exact_count_weight_and_volume_limits_are_accepted(): void
    {
        $driver = $this->driver([
            'approved_parcel_capacity' => 2,
            'approved_weight_capacity_kg' => 10,
            'approved_volume_capacity_liters' => 10,
        ]);

        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => 4, 'volume_liters' => 3],
            ['weight_kg' => 6, 'volume_liters' => 7],
        ]);

        $this->assertTrue(true);
    }

    public function test_fractional_weight_and_volume_exact_boundaries_are_accepted_without_float_overflow(): void
    {
        $driver = $this->driver([
            'approved_parcel_capacity' => 2,
            'approved_weight_capacity_kg' => '0.300',
            'approved_volume_capacity_liters' => '0.300',
        ]);

        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => '0.100', 'volume_liters' => '0.100'],
            ['weight_kg' => '0.200', 'volume_liters' => '0.200'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('weight or volume capacity');
        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => '0.100', 'volume_liters' => '0.100'],
            ['weight_kg' => '0.201', 'volume_liters' => '0.200'],
        ]);
    }

    public function test_manifest_rejects_parcel_count_above_the_approved_limit(): void
    {
        $driver = $this->driver([
            'approved_parcel_capacity' => 1,
            'approved_weight_capacity_kg' => 10,
            'approved_volume_capacity_liters' => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parcel capacity');
        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => 1, 'volume_liters' => 1],
            ['weight_kg' => 1, 'volume_liters' => 1],
        ]);
    }

    public function test_manifest_rejects_volume_above_the_approved_limit(): void
    {
        $driver = $this->driver([
            'approved_parcel_capacity' => 2,
            'approved_weight_capacity_kg' => 10,
            'approved_volume_capacity_liters' => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('weight or volume capacity');
        (new TransferCapacityService)->assertCapacity($driver, [
            ['weight_kg' => 1, 'volume_liters' => 5],
            ['weight_kg' => 1, 'volume_liters' => 6],
        ]);
    }

    public function test_local_trip_limit_is_ten_parcels(): void
    {
        (new TransferCapacityService)->assertLocalParcelLimit(10);

        $this->expectException(RuntimeException::class);
        (new TransferCapacityService)->assertLocalParcelLimit(11);
    }

    /** @param array<string, mixed> $attributes */
    private function driver(array $attributes): RiderProfile
    {
        $driver = new RiderProfile([
            'vehicle_type' => 'van',
            'status' => 'ACTIVE',
            ...$attributes,
        ]);
        $driver->setRelation('user', new User(['account_type' => 'RIDER', 'status' => 'ACTIVE']));

        return $driver;
    }
}
