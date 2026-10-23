<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiPolicyAcceptance
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

        if ($request->routeIs('api.v1.auth.logout')) {
            return $next($request);
        }

        $fingerprint = $this->policies->fingerprintFor($user);
        $storedFingerprint = $user->api_token_policy_snapshot;
        if (! is_string($storedFingerprint)
            && $this->policies->applicablePolicies($user)->isEmpty()) {
            $user->forceFill(['api_token_policy_snapshot' => $fingerprint])->save();
            $storedFingerprint = $fingerprint;
        }

        if (! is_string($storedFingerprint) || ! hash_equals($fingerprint, $storedFingerprint)) {
            $user->forceFill([
                'api_token_hash' => null,
                'api_token_expires_at' => null,
                'api_token_policy_snapshot' => null,
            ])->save();

            return response()->json([
                'success' => false,
                'code' => 'policy_update_requires_login',
                'message' => 'A mandatory policy has changed. Sign in again to review and accept the current version.',
            ], 401);
        }

        if ($request->routeIs('api.v1.auth.me', 'api.v1.policies.accept')) {
            return $next($request);
        }

        $pending = $this->policies->pendingPolicies($user);
        if ($pending->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'code' => 'mandatory_policy_acceptance_required',
                'message' => 'Review and accept the current mandatory policies to continue.',
                'pending_policies' => $this->policies->serializePolicies($pending),
            ], 423);
        }

        return $next($request);
    }
}
