<?php

namespace App\Http\Controllers;

use App\Models\Admin\Announcement;
use App\Models\Admin\Notification;
use App\Models\User;
use App\Services\Notifications\AnnouncementDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $layout = match ($user->account_type) {
            'ADMIN' => 'layouts.admin.app',
            'SELLER' => 'layouts.seller.app',
            'RIDER' => 'layouts.rider.app',
            'LOGISTICS' => 'layouts.logistics.app',
            default => 'layouts.buyer.app',
        };

        return view('notifications.index', [
            'layout' => $layout,
            'unreadCount' => $user->notifications()->whereNull('read_at')->count(),
            'notifications' => $user->notifications()
                ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', ['ANNOUNCEMENT'])
                ->latest('created_at')
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    public function dismissAnnouncement(Request $request, Notification $notification): RedirectResponse|JsonResponse
    {
        $ownedNotification = $this->ownedAnnouncementNotification($request, $notification);
        $ownedNotification->forceFill(['read_at' => $ownedNotification->read_at ?? now()])->save();

        if ($request->expectsJson()) {
            return response()->json(['dismissed' => true]);
        }

        return redirect($this->safeReturnPath($request));
    }

    public function openAnnouncement(
        Request $request,
        Notification $notification,
        AnnouncementDestination $destinations,
    ): RedirectResponse {
        $ownedNotification = $this->ownedAnnouncementNotification($request, $notification);
        $announcement = Announcement::query()->findOrFail($ownedNotification->reference_id);
        $user = $request->user();

        abort_unless(
            $announcement->is_active
                && $announcement->published_at
                && $announcement->published_at->lessThanOrEqualTo(now())
                && (! $announcement->expires_at || $announcement->expires_at->greaterThan(now()))
                && $announcement->show_popup
                && $this->audienceAllows($announcement->target_audience ?? 'ALL', $user->account_type),
            404,
        );

        $destination = $destinations->resolve($announcement->cta_url);
        abort_unless(filled($announcement->cta_label) && $destination, 404);

        $ownedNotification->forceFill(['read_at' => $ownedNotification->read_at ?? now()])->save();

        return redirect()->to($destination);
    }

    private function ownedAnnouncementNotification(Request $request, Notification $notification): Notification
    {
        return $request->user()->notifications()
            ->whereKey($notification->getKey())
            ->where('type', 'ANNOUNCEMENT')
            ->where('reference_type', Announcement::class)
            ->firstOrFail();
    }

    private function audienceAllows(string $audience, string $accountType): bool
    {
        $allowedAudiences = match ($accountType) {
            User::TYPE_BUYER => ['ALL', 'BUYERS'],
            User::TYPE_SELLER => ['ALL', 'SELLERS'],
            User::TYPE_LOGISTICS => ['ALL', 'LOGISTICS', 'LOGISTICS_CENTERS'],
            User::TYPE_RIDER => ['ALL', 'LOGISTICS', 'RIDERS'],
            default => [],
        };

        return in_array(strtoupper($audience), $allowedAudiences, true);
    }

    private function safeReturnPath(Request $request): string
    {
        $path = trim((string) $request->input('return_to'));
        $parts = parse_url($path);
        $decodedPath = rawurldecode(is_array($parts) ? (string) ($parts['path'] ?? '') : '');

        if (! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || (is_array($parts) && (isset($parts['scheme']) || isset($parts['host'])))
            || str_starts_with($decodedPath, '//')
            || str_contains($decodedPath, '\\')) {
            return route($request->user()->workspaceRoute());
        }

        return $path;
    }
}
