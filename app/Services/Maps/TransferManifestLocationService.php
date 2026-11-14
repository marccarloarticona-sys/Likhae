<?php

namespace App\Services\Maps;

use App\Models\Logistics\TransferManifest;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\RiderVehicleType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class TransferManifestLocationService
{
    private const MAX_ACCURACY_METERS = 2500;

    private const MAX_TIMESTAMP_SKEW_SECONDS = 120;

    public function update(
        TransferManifest $manifest,
        User $user,
        float $latitude,
        float $longitude,
        float $accuracy,
        CarbonInterface $recordedAt,
    ): RiderProfile {
        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages([
                'latitude' => 'Latitude and longitude must be valid device coordinates.',
                'longitude' => 'Latitude and longitude must be valid device coordinates.',
            ]);
        }

        if (! is_finite($accuracy) || $accuracy < 0 || $accuracy > self::MAX_ACCURACY_METERS) {
            throw ValidationException::withMessages([
                'accuracy' => 'Device location accuracy is too low for live transfer tracking. Keep Location Services on and wait for a clearer fix.',
            ]);
        }

        if (abs($recordedAt->diffInSeconds(now(), false)) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            throw ValidationException::withMessages([
                'recorded_at' => 'The device location is stale. Wait for a fresh device fix and retry.',
            ]);
        }

        $manifest->loadMissing('assignedDriver');
        $driver = $manifest->assignedDriver;
        abort_unless(
            $user->isActive()
                && $driver instanceof RiderProfile
                && (int) $driver->user_id === (int) $user->id
                && in_array($driver->status, ['ACTIVE', 'WARNED'], true)
                && RiderVehicleType::isInterLogistics($driver->vehicle_type),
            403,
            'Only the active assigned inter-hub rider can share this transfer location.',
        );

        abort_unless(
            $manifest->status === TransferManifest::IN_TRANSIT && $manifest->driver_accepted_at,
            409,
            'Live transfer location is accepted only after the assigned rider starts the transfer.',
        );

        if ($manifest->departed_at && $recordedAt->lt($manifest->departed_at)) {
            throw ValidationException::withMessages([
                'recorded_at' => 'This device location was recorded before the transfer started. Waiting for a fresh location fix.',
            ]);
        }

        if ($driver->availability_location_updated_at
            && CarbonImmutable::parse($recordedAt)->lt($driver->availability_location_updated_at)) {
            throw ValidationException::withMessages([
                'recorded_at' => 'This location is older than the latest saved rider location.',
            ]);
        }

        $driver->update([
            'availability_latitude' => $latitude,
            'availability_longitude' => $longitude,
            'availability_location_updated_at' => $recordedAt,
        ]);

        return $driver->fresh();
    }
}
