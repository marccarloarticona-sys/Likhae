<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderAvailabilityApiController extends Controller
{
    public function update(Request $request, LogisticsSchemaReadiness $schema): JsonResponse
    {
        if (! $schema->riderAvailabilityReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Rider availability is temporarily unavailable until the rider availability schema is installed.',
            ], 503);
        }

        $data = $request->validate([
            'is_online' => ['required', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);

        $rider = $this->localRider($request);
        $isOnline = (bool) $data['is_online'];
        $hasCoordinates = isset($data['latitude'], $data['longitude']);

        $rider->update([
            'is_online' => $isOnline,
            'availability_latitude' => $isOnline && $hasCoordinates ? $data['latitude'] : null,
            'availability_longitude' => $isOnline && $hasCoordinates ? $data['longitude'] : null,
            'availability_location_updated_at' => $isOnline && $hasCoordinates ? now() : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $isOnline ? 'You are now online and available for assignments.' : 'You are now offline.',
            'data' => [
                'is_online' => (bool) $rider->is_online,
                'availability_latitude' => $rider->availability_latitude,
                'availability_longitude' => $rider->availability_longitude,
                'updated_at' => $rider->availability_location_updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function updateLocation(Request $request, LogisticsSchemaReadiness $schema): JsonResponse
    {
        if (! $schema->riderAvailabilityReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Rider availability location is temporarily unavailable until the rider availability schema is installed.',
            ], 503);
        }

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $rider = $this->localRider($request);
        abort_unless(
            $rider->is_online && in_array($rider->status, ['ACTIVE', 'WARNED'], true),
            409,
            'Go online before updating dispatch location.',
        );

        $rider->update([
            'availability_latitude' => $data['latitude'],
            'availability_longitude' => $data['longitude'],
            'availability_location_updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'latitude' => (float) $rider->availability_latitude,
                'longitude' => (float) $rider->availability_longitude,
                'updated_at' => $rider->availability_location_updated_at?->toIso8601String(),
            ],
        ]);
    }

    private function localRider(Request $request)
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
                && $user->status === User::STATUS_ACTIVE
                && $user->isAccountType(User::TYPE_RIDER),
            403,
            'Active rider account access is required.',
        );

        $rider = $user->riderProfile;
        abort_unless(
            $rider
                && in_array($rider->status, ['ACTIVE', 'WARNED'], true)
                && RiderVehicleType::isLocalBarangay($rider->vehicle_type),
            403,
            'Availability controls are only available to active motorcycle and tricycle riders.',
        );

        return $rider;
    }
}
