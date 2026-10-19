<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MessagePresenceController extends Controller
{
    public function touch(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isActive(), 403);

        $presenceKey = 'message-presence:user:'.$user->id;
        if (! (bool) data_get($user->notification_preferences, 'show_online_status', true)) {
            Cache::store('database')->forget($presenceKey);

            return response()->json(['success' => true, 'online' => false])
                ->header('Cache-Control', 'no-store, private');
        }

        Cache::store('database')->put(
            $presenceKey,
            true,
            // Leave room for browser timer throttling in background tabs.
            now()->addSeconds(120),
        );

        return response()->json(['success' => true, 'online' => true])
            ->header('Cache-Control', 'no-store, private');
    }
}
