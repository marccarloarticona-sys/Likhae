<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Admin\AdminUserSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackAdminManagedUserSessions
{
    public function __construct(private readonly AdminUserSessionManager $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->session()->getId();
        if ($this->sessions->isRevoked($sessionId)) {
            $user = $request->user();
            $accountType = $user?->account_type;
            if ($accountType === null && ($revokedUserId = $this->sessions->revokedUserId($sessionId)) !== null) {
                $accountType = User::query()->whereKey($revokedUserId)->value('account_type');
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 'session_suspended',
                    'message' => 'Your session was suspended by an administrator. Please sign in again.',
                ], 401);
            }

            $loginRoute = in_array(strtoupper((string) $accountType), [User::TYPE_LOGISTICS, User::TYPE_RIDER], true)
                ? 'logistics.login'
                : 'login';

            return redirect()->route($loginRoute)
                ->with('status', 'Your session was suspended by an administrator. Please sign in again.');
        }

        $response = $next($request);
        $user = $request->user();
        if ($user instanceof User && config('session.driver') === 'file') {
            $request->attributes->set('admin_session_tracking', [
                'id' => $request->session()->getId(),
                'user_id' => (int) $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $tracking = $request->attributes->get('admin_session_tracking');
        if (! is_array($tracking)) {
            return;
        }

        $this->sessions->recordFileSession(
            (string) ($tracking['id'] ?? ''),
            (int) ($tracking['user_id'] ?? 0),
            isset($tracking['ip_address']) ? (string) $tracking['ip_address'] : null,
            isset($tracking['user_agent']) ? (string) $tracking['user_agent'] : null,
        );
    }
}
