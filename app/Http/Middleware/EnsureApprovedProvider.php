<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedProvider
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user?->isAccountType(User::TYPE_LOGISTICS)
            && $user->logisticsCenter()->approvedActive()->exists(),
            403,
            'An active logistics center account is required.',
        );

        return $next($request);
    }
}
