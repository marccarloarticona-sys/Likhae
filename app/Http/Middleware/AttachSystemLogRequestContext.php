<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachSystemLogRequestContext
{
    private const REQUEST_ID_ATTRIBUTE = 'system_monitoring_log_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $context = self::contextFor($request);
        Log::withContext($context);

        try {
            return $next($request);
        } finally {
            Log::withoutContext(array_keys($context));
        }
    }

    /** @return array<string, string|null> */
    public static function contextFor(Request $request): array
    {
        $route = $request->route();
        if (! $route instanceof Route && ! $request->server->get('REQUEST_URI')) {
            return [];
        }

        $requestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid();
            $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);
        }

        $routeUri = $route instanceof Route ? $route->uri() : null;
        $routeName = $route instanceof Route ? $route->getName() : null;

        return [
            'system_monitoring_request_id' => $requestId,
            'system_monitoring_request_method' => self::safeMethod($request->method()),
            'system_monitoring_request_path' => self::safePath($request, $routeUri),
            'system_monitoring_route_name' => self::safeRouteName($routeName),
        ];
    }

    private static function safeMethod(string $method): string
    {
        $method = strtoupper($method);

        return in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)
            ? $method
            : 'OTHER';
    }

    private static function safePath(Request $request, ?string $routeUri): string
    {
        if (is_string($routeUri)) {
            $path = '/'.ltrim($routeUri, '/');
        } else {
            $segments = array_map(static function (string $segment): string {
                if ($segment === '') {
                    return '';
                }
                if (ctype_digit($segment) || Str::isUuid($segment) || str_contains($segment, '@') || strlen($segment) > 48) {
                    return '{value}';
                }

                return preg_replace('/[^A-Za-z0-9._~-]/', '', $segment) ?: '{value}';
            }, explode('/', trim($request->path(), '/')));
            $path = '/'.implode('/', $segments);
        }

        $path = preg_replace('/[^A-Za-z0-9._~{}\/:?-]/', '', $path) ?: '/';

        return mb_substr($path, 0, 255);
    }

    private static function safeRouteName(?string $routeName): ?string
    {
        return is_string($routeName) && preg_match('/^[A-Za-z0-9_.:-]{1,180}$/', $routeName) === 1
            ? $routeName
            : null;
    }
}
