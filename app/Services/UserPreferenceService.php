<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserPreferenceService
{
    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function merge(User $user, array $changes, array $defaults = []): array
    {
        return DB::transaction(function () use ($user, $changes, $defaults): array {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $preferences = array_merge($defaults, (array) $lockedUser->notification_preferences, $changes);
            $lockedUser->forceFill(['notification_preferences' => $preferences])->save();

            return $preferences;
        });
    }
}
