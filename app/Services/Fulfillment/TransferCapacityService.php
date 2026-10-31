<?php

namespace App\Services\Fulfillment;

use App\Models\Rider\RiderProfile;
use App\Support\RiderVehicleType;
use Illuminate\Support\Collection;
use RuntimeException;

final class TransferCapacityService
{
    /** @param Collection<int, array{weight_kg: mixed}>|array<int, array{weight_kg: mixed}> $parcels */
    public function assertCapacity(RiderProfile $driver, Collection|array $parcels, bool $allowMissingWeights = false): void
    {
        if (! RiderVehicleType::isInterLogistics($driver->vehicle_type)) {
            throw new RuntimeException('Only an approved four-wheel inter-hub vehicle may carry an inter-logistics manifest.');
        }
        if (! in_array($driver->status, ['ACTIVE', 'WARNED'], true)
            || $driver->user?->account_type !== \App\Models\User::TYPE_RIDER
            || $driver->user?->status !== \App\Models\User::STATUS_ACTIVE) {
            throw new RuntimeException('The assigned inter-logistics driver is not active and authorized.');
        }

        $parcelRows = $parcels instanceof Collection ? $parcels->values()->all() : array_values($parcels);
        $parcelCapacity = $driver->approved_parcel_capacity;
        $weightCapacity = $driver->approved_weight_capacity_kg;
        if ($parcelCapacity === null || $weightCapacity === null
            || (int) $parcelCapacity < 1 || (float) $weightCapacity <= 0) {
            throw new RuntimeException('The driver has no complete approved parcel and weight capacity.');
        }
        $vehicleParcelLimit = RiderVehicleType::interLogisticsParcelLimit($driver->vehicle_type);
        $approvedParcelLimit = min((int) $parcelCapacity, $vehicleParcelLimit);
        if (count($parcelRows) > $approvedParcelLimit) {
            throw new RuntimeException('The manifest exceeds the driver\'s approved parcel capacity.');
        }

        $weight = 0;
        foreach ($parcelRows as $parcel) {
            $weightValue = $parcel['weight_kg'] ?? null;
            if ($weightValue === null || trim((string) $weightValue) === '') {
                if (! $allowMissingWeights) {
                    throw new RuntimeException('The manifest cannot be sealed because a parcel weight is missing.');
                }
            } else {
                $parcelWeight = $this->thousandths($weightValue);
                if ($parcelWeight <= 0) {
                    throw new RuntimeException('Parcel weight must be positive.');
                }
                $weight += $parcelWeight;
            }
        }
        if ($weight > $this->thousandths($weightCapacity)) {
            throw new RuntimeException('The manifest exceeds the driver\'s approved weight capacity.');
        }
    }

    public function assertLocalParcelLimit(int $parcelCount): void
    {
        // Kept as a stateless compatibility rule for unit callers. Individual
        // assignments must not call this; LocalRiderTripService counts only
        // queued/loaded physical items on one explicit trip.
        if ($parcelCount > 10) {
            throw new RuntimeException('A local motorcycle or tricycle trip cannot exceed 10 parcels.');
        }
    }

    private function thousandths(mixed $value): int
    {
        return (int) round((float) $value * 1000, 0, PHP_ROUND_HALF_UP);
    }
}
