<?php

namespace App\Services\Notifications;

use App\Models\Admin\Announcement;
use App\Models\Admin\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AnnouncementNotificationPublisher
{
    public function publish(Announcement $announcement): int
    {
        if (! $announcement->is_active
            || ! $announcement->published_at
            || $announcement->published_at->isFuture()
            || ($announcement->expires_at && $announcement->expires_at->lessThanOrEqualTo(now()))) {
            return 0;
        }

        $audienceTargetingReady = Schema::hasColumn('announcements', 'target_audience');
        $audience = $audienceTargetingReady
            ? strtoupper((string) ($announcement->target_audience ?? 'ALL'))
            : 'ALL';
        $accountTypes = match ($audience) {
            'ALL' => null,
            'BUYERS' => [User::TYPE_BUYER],
            'SELLERS' => [User::TYPE_SELLER],
            'LOGISTICS' => [User::TYPE_LOGISTICS, User::TYPE_RIDER],
            default => [],
        };

        if ($accountTypes === []) {
            return 0;
        }

        $createdCount = 0;

        User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->when($accountTypes !== null, fn ($query) => $query->whereIn('account_type', $accountTypes))
            ->whereNotExists(function ($query) use ($announcement): void {
                $query->selectRaw('1')
                    ->from('notifications')
                    ->whereColumn('notifications.user_id', 'users.id')
                    ->where('notifications.type', 'ANNOUNCEMENT')
                    ->where('notifications.reference_type', Announcement::class)
                    ->where('notifications.reference_id', $announcement->id);
            })
            ->chunkById(100, function ($users) use ($announcement, &$createdCount): void {
                foreach ($users as $user) {
                    $notification = Notification::query()->firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'type' => 'ANNOUNCEMENT',
                            'reference_type' => Announcement::class,
                            'reference_id' => $announcement->id,
                        ],
                        [
                            'title' => $announcement->title,
                            'message' => $announcement->body,
                            'action_url' => route('notifications.index'),
                        ],
                    );

                    if ($notification->wasRecentlyCreated) {
                        $createdCount++;
                    }
                }
            });

        return $createdCount;
    }
}
