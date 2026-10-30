<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && ! $user->isActive()) {
            if ($user->pendingRegistrationApplication()
                && $request->routeIs(
                    'login',
                    'login.store',
                    'logistics.login',
                    'logistics.login.store',
                    'google.callback',
                    'registration.status',
                    'registration.documents.resubmit',
                    'policies.accept',
                    'logout',
                )) {
                return $next($request);
            }

            $message = $user->inactiveMessage();

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route(
                $request->is('logistics/*', 'rider', 'rider/*') ? 'logistics.login' : 'login'
            )->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
