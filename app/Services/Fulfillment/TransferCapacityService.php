<?php

namespace App\Services\Fulfillment;

use App\Models\Rider\RiderProfile;
use App\Support\RiderVehicleType;
use Illuminate\Support\Collection;
use RuntimeException;

final class TransferCapacityService
{
    /** @param Collection<int, array{weight_kg: mixed, volume_liters: mixed}>|array<int, array{weight_kg: mixed, volume_liters: mixed}> $parcels */
    public function assertCapacity(RiderProfile $driver, Collection|array $parcels): void
    {
        if (! RiderVehicleType::isInterLogistics($driver->vehicle_type)) {
            throw new RuntimeException('Only an approved van, closed van, or truck may carry an inter-logistics manifest.');
        }
        if ($driver->status !== 'ACTIVE' || $driver->user?->account_type !== 'RIDER') {
            throw new RuntimeException('The assigned inter-logistics driver is not active and authorized.');
        }

        $parcelRows = $parcels instanceof Collection ? $parcels->values()->all() : array_values($parcels);
        $parcelCapacity = $driver->approved_parcel_capacity;
        $weightCapacity = $driver->approved_weight_capacity_kg;
        $volumeCapacity = $driver->approved_volume_capacity_liters;
        if ($parcelCapacity === null || $weightCapacity === null || $volumeCapacity === null
            || (int) $parcelCapacity < 1 || (float) $weightCapacity <= 0 || (float) $volumeCapacity <= 0) {
            throw new RuntimeException('The driver has no complete approved parcel, weight, and volume capacity.');
        }
        $vehicleParcelLimit = RiderVehicleType::interLogisticsParcelLimit($driver->vehicle_type);
        $approvedParcelLimit = min((int) $parcelCapacity, $vehicleParcelLimit);
        if (count($parcelRows) > $approvedParcelLimit) {
            throw new RuntimeException('The manifest exceeds the driver\'s approved parcel capacity.');
        }

        $weight = 0.0;
        $volume = 0.0;
        foreach ($parcelRows as $parcel) {
            if (! array_key_exists('weight_kg', $parcel) || $parcel['weight_kg'] === null
                || ! array_key_exists('volume_liters', $parcel) || $parcel['volume_liters'] === null) {
                throw new RuntimeException('The manifest cannot be sealed because parcel weight or volume is missing.');
            }
            $parcelWeight = (float) $parcel['weight_kg'];
            $parcelVolume = (float) $parcel['volume_liters'];
            if ($parcelWeight <= 0 || $parcelVolume <= 0) {
                throw new RuntimeException('The manifest cannot be sealed because parcel weight and volume must be positive.');
            }
            $weight += $parcelWeight;
            $volume += $parcelVolume;
        }
        if ($weight > (float) $weightCapacity || $volume > (float) $volumeCapacity) {
            throw new RuntimeException('The manifest exceeds the driver\'s approved weight or volume capacity.');
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
}
