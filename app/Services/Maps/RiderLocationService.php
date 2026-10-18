<?php

namespace App\Services\Maps;

use App\Events\RiderLocationUpdated;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderAssignmentLocation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RiderLocationService
{
    private const MAX_ACCURACY_METERS = 250;

    private const MAX_TIMESTAMP_SKEW_SECONDS = 120;

    public function update(
        RiderAssignment $assignment,
        User $user,
        float $latitude,
        float $longitude,
        float $accuracy,
        CarbonInterface $recordedAt,
    ): RiderAssignmentLocation
    {
        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages([
                'latitude' => 'Latitude and longitude must be finite coordinates within their valid ranges.',
                'longitude' => 'Latitude and longitude must be finite coordinates within their valid ranges.',
            ]);
        }

        if (! is_finite($accuracy) || $accuracy < 0 || $accuracy > self::MAX_ACCURACY_METERS) {
            throw ValidationException::withMessages([
                'accuracy' => 'The device GPS accuracy is too poor for live tracking.',
            ]);
        }

        $now = now();
        if (abs($recordedAt->diffInSeconds($now, false)) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            throw ValidationException::withMessages([
                'recorded_at' => 'The device location timestamp is stale or invalid.',
            ]);
        }

        $assignment->loadMissing('riderProfile', 'shipment');
        abort_unless(
            $user->isActive()
            && in_array($user->riderProfile?->status, ['ACTIVE', 'WARNED'], true)
            && (int) $assignment->riderProfile?->user_id === (int) $user->id,
            403,
            'An active rider account with ownership of this assignment is required.',
        );

        $shipment = $assignment->shipment;
        $allowed = in_array($assignment->status, ['ACCEPTED', 'IN_PROGRESS'], true)
            && (($assignment->assignment_type === RiderAssignment::TYPE_PICKUP && in_array($shipment?->current_status, ['PICKUP_ASSIGNED', 'READY_FOR_PICKUP'], true))
                || ($assignment->assignment_type === RiderAssignment::TYPE_DELIVERY
                    && in_array($shipment?->current_status, ['ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY'], true)))
            || ($assignment->assignment_type === RiderAssignment::TYPE_PICKUP
                && $assignment->status === 'COMPLETED'
                && $shipment?->current_status === 'PICKED_UP');

        if (! $allowed) {
            abort(409, 'Live location is only accepted while the rider is traveling to the seller, sorting center, or buyer delivery address.');
        }

        $latest = $assignment->liveLocation()->first();
        if ($latest?->recorded_at && $recordedAt->lt($latest->recorded_at)) {
            throw ValidationException::withMessages([
                'recorded_at' => 'This location update is older than the latest accepted rider location.',
            ]);
        }

        $location = $assignment->liveLocation()->updateOrCreate([], [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'recorded_at' => $recordedAt,
        ]);

        // Persisted device GPS is the source of truth. A realtime outage must
        // not make an otherwise accepted GPS update appear to have failed.
        try {
            RiderLocationUpdated::dispatch($location->load('riderAssignment.shipment'));
        } catch (Throwable $exception) {
            Log::warning('Rider GPS location was saved but its realtime broadcast failed.', [
                'assignment_id' => $assignment->id,
                'shipment_id' => $assignment->shipment_id,
                'exception' => $exception,
            ]);
        }

        return $location;
    }
}
