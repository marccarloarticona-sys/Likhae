<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class RiderAvailabilityService
{
    public function markOfflineOnLogout(?User $user): void
    {
        if (! $user || $user->account_type !== User::TYPE_RIDER || ! Schema::hasTable('rider_profiles')) {
            return;
        }

        $rider = $user->riderProfile;
        if (! $rider || ! Schema::hasColumn('rider_profiles', 'is_online')) {
            return;
        }

        $attributes = ['is_online' => false];
        foreach (['availability_latitude', 'availability_longitude', 'availability_location_updated_at'] as $column) {
            if (Schema::hasColumn('rider_profiles', $column)) {
                $attributes[$column] = null;
            }
        }

        $rider->forceFill($attributes)->save();
    }
}
