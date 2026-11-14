<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            if ($mode === 'optional') {
                return $next($request);
            }

            return response()->json(['success' => false, 'message' => 'A bearer token is required.'], 401);
        }

        $user = User::query()
            ->where('api_token_hash', hash('sha256', $token))
            ->where('api_token_expires_at', '>', now())
            ->first();

        if (! $user || ! $user->isActive()) {
            return response()->json(['success' => false, 'message' => 'The bearer token is invalid or expired.'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
