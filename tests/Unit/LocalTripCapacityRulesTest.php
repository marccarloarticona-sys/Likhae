<?php

namespace Tests\Unit;

use App\Support\LocalTripCapacityRules;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalTripCapacityRulesTest extends TestCase
{
    public function test_local_capacity_is_lower_of_approved_capacity_and_ten(): void
    {
        $this->assertSame(5, LocalTripCapacityRules::approvedCapacity(5));
        $this->assertSame(10, LocalTripCapacityRules::approvedCapacity(18));
    }

    public function test_unknown_or_non_positive_capacity_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        LocalTripCapacityRules::approvedCapacity(null);
    }

    public function test_the_eleventh_parcel_is_rejected_for_a_ten_parcel_trip(): void
    {
        LocalTripCapacityRules::assertAvailable(9, 10);

        $this->expectException(RuntimeException::class);
        LocalTripCapacityRules::assertAvailable(10, 10);
    }

    public function test_a_second_trip_can_start_after_the_first_trip_is_full(): void
    {
        LocalTripCapacityRules::assertAvailable(0, 10);
        LocalTripCapacityRules::assertAvailable(8, 10);
        $this->assertSame(10, LocalTripCapacityRules::approvedCapacity(10));
    }
}
