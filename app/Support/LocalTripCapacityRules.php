<?php

namespace App\Support;

use RuntimeException;

final class LocalTripCapacityRules
{
    public const MAX_PARCELS_PER_TRIP = 10;

    public static function approvedCapacity(?int $approvedCapacity): int
    {
        if ($approvedCapacity === null || $approvedCapacity < 1) {
            throw new RuntimeException('The rider has no positive approved local-trip parcel capacity.');
        }

        return min(self::MAX_PARCELS_PER_TRIP, $approvedCapacity);
    }

    public static function assertAvailable(int $loadedCount, int $capacity): void
    {
        if ($loadedCount >= $capacity) {
            throw new RuntimeException('The local trip has reached its approved parcel capacity. Create or use another trip.');
        }
    }
}
