<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveRider
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user?->isAccountType(User::TYPE_RIDER)
            && $user->riderProfile()->whereIn('status', ['ACTIVE', 'WARNED'])->exists(),
            403,
            'An active rider account is required.',
        );

        return $next($request);
    }
}
