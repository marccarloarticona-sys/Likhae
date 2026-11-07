<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = config('app.supported_locales', ['en' => 'English']);
        $preferredLocale = data_get($request->user()?->notification_preferences, 'language');
        $locale = is_string($preferredLocale) && array_key_exists($preferredLocale, $supportedLocales)
            ? $preferredLocale
            : 'en';

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }
}
