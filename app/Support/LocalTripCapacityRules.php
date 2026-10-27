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

    public static function effectiveCapacity(?int $tripCapacity, ?int $riderCapacity): int
    {
        return min(self::approvedCapacity($tripCapacity), self::approvedCapacity($riderCapacity));
    }

    public static function remainingCapacity(?int $approvedCapacity, int $activeParcelCount): int
    {
        if ($approvedCapacity === null || $approvedCapacity < 1) {
            return 0;
        }

        return max(0, self::approvedCapacity($approvedCapacity) - max(0, $activeParcelCount));
    }

    public static function assertCanAssign(int $activeParcelCount, int $additionalParcelCount, ?int $approvedCapacity): void
    {
        $remaining = self::remainingCapacity($approvedCapacity, $activeParcelCount);
        if ($additionalParcelCount < 1 || $additionalParcelCount > $remaining) {
            throw new RuntimeException("The rider has capacity for {$remaining} more parcel(s); this assignment needs {$additionalParcelCount}.");
        }
    }

    public static function assertAvailable(int $loadedCount, int $capacity): void
    {
        if ($loadedCount >= $capacity) {
            throw new RuntimeException('The local trip has reached its approved parcel capacity. Create or use another trip.');
        }
    }
}
