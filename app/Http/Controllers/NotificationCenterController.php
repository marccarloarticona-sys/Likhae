<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $layout = match ($user->account_type) {
            'ADMIN' => 'layouts.admin',
            'SELLER' => 'layouts.seller',
            'RIDER' => 'rider.app',
            'LOGISTICS' => 'Logistics.app',
            default => 'layouts.buyer',
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
}
