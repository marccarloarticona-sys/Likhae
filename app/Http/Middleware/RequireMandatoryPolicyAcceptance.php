<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireMandatoryPolicyAcceptance
{
    public function __construct(private readonly PolicyAcceptanceService $policies)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->policies->schemaReady()) {
            return $next($request);
        }

        if ($request->routeIs('logout')) {
            return $next($request);
        }

        $fingerprint = $this->policies->fingerprintFor($user);
        $sessionFingerprint = $request->session()->get('policy_version_snapshot');

        if (! is_string($sessionFingerprint) && $fingerprint !== hash('sha256', '[]')) {
            return $this->requireFreshLogin($request, $user);
        }

        if (is_string($sessionFingerprint) && ! hash_equals($fingerprint, $sessionFingerprint)) {
            return $this->requireFreshLogin($request, $user);
        }

        if (! is_string($sessionFingerprint)) {
            $request->session()->put('policy_version_snapshot', $fingerprint);
        }

        if ($request->routeIs('policies.accept')) {
            return $next($request);
        }

        $pending = $this->policies->pendingPolicies($user);
        if ($pending->isEmpty()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'mandatory_policy_acceptance_required',
                'message' => 'Review and accept the current mandatory policies to continue.',
                'pending_policies' => $this->policies->serializePolicies($pending),
            ], 423);
        }

        $request->attributes->set('mandatory_policy_documents', $pending);

        $loginRoute = $user->isAccountType(User::TYPE_LOGISTICS, User::TYPE_RIDER)
            ? 'logistics.login'
            : 'login';

        if ($request->routeIs($loginRoute)) {
            return $next($request);
        }

        return redirect()->route($loginRoute);
    }

    private function requireFreshLogin(Request $request, User $user): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'policy_update_requires_login',
                'message' => 'A mandatory policy has changed. Sign in again to review and accept the current version.',
            ], 401);
        }

        $loginRoute = $user->isAccountType(User::TYPE_LOGISTICS, User::TYPE_RIDER)
            ? 'logistics.login'
            : 'login';

        return redirect()
            ->route($loginRoute)
            ->with('status', 'A mandatory policy has changed. Sign in again to review and accept the current version.');
    }
}
