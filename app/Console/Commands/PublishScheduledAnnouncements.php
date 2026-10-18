<?php

namespace App\Console\Commands;

use App\Models\Admin\Announcement;
use App\Services\Notifications\AnnouncementNotificationPublisher;
use Illuminate\Console\Command;

class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'likhae:publish-announcements';

    protected $description = 'Publish active announcements to users when their publish time arrives.';

    public function handle(AnnouncementNotificationPublisher $publisher): int
    {
        $announcements = Announcement::query()
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        $count = 0;
        foreach ($announcements as $announcement) {
            $count += $publisher->publish($announcement);
        }

        $this->info("Announcement notifications delivered: {$count}");

        return self::SUCCESS;
    }
}
