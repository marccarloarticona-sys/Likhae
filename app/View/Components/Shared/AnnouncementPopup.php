<?php

namespace App\View\Components\Shared;

use App\Models\Admin\Announcement;
use App\Models\Admin\Notification;
use App\Models\User;
use App\Services\Notifications\AnnouncementDestination;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AnnouncementPopup extends Component
{
    public function __construct(private readonly AnnouncementDestination $destinations)
    {
    }

    public function render(): View
    {
        $user = request()->user();
        $accountType = $user?->account_type;
        $audiences = match ($accountType) {
            User::TYPE_BUYER => ['ALL', 'BUYERS'],
            User::TYPE_SELLER => ['ALL', 'SELLERS'],
            User::TYPE_LOGISTICS => ['ALL', 'LOGISTICS', 'LOGISTICS_CENTERS'],
            User::TYPE_RIDER => ['ALL', 'LOGISTICS', 'RIDERS'],
            default => [],
        };

        $announcement = null;
        $notification = null;
        $imageUrl = null;
        $ctaUrl = null;

        if ($user
            && $user->status === User::STATUS_ACTIVE
            && $audiences !== []
            && $this->popupSchemaReady()) {
            $query = Announcement::query()
                ->published()
                ->where('show_popup', true)
                ->whereExists(function ($query) use ($user): void {
                    $query->selectRaw('1')
                        ->from('notifications')
                        ->whereColumn('notifications.reference_id', 'announcements.id')
                        ->where('notifications.user_id', $user->id)
                        ->where('notifications.type', 'ANNOUNCEMENT')
                        ->where('notifications.reference_type', Announcement::class)
                        ->whereNull('notifications.read_at');
                });

            if (Schema::hasColumn('announcements', 'target_audience')) {
                $query->whereIn('target_audience', $audiences);
            }

            $announcement = $query
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->first();

            if ($announcement) {
                $notification = Notification::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'ANNOUNCEMENT')
                    ->where('reference_type', Announcement::class)
                    ->where('reference_id', $announcement->id)
                    ->whereNull('read_at')
                    ->latest('id')
                    ->first();

                $disk = Storage::disk('public');
                if ($announcement->image_path && $disk->exists($announcement->image_path)) {
                    $imageUrl = $disk->url($announcement->image_path);
                }

                $ctaUrl = $this->destinations->resolve($announcement->cta_url);
                if (! filled($announcement->cta_label) || ! $ctaUrl) {
                    $ctaUrl = null;
                }
            }
        }

        return view('components.shared.announcement-popup', [
            'announcement' => $announcement,
            'notification' => $notification,
            'imageUrl' => $imageUrl,
            'ctaUrl' => $ctaUrl,
            'themeColor' => $announcement?->safe_theme_color,
        ]);
    }

    private function popupSchemaReady(): bool
    {
        foreach (['image_path', 'image_alt', 'theme_color', 'cta_label', 'cta_url', 'show_popup'] as $column) {
            if (! Schema::hasColumn('announcements', $column)) {
                return false;
            }
        }

        return true;
    }
}
