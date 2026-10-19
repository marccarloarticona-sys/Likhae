<?php

use App\Http\Middleware\EnsureWorkspaceRole;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\PreventPrivatePageCache;
use App\Http\Middleware\EnsureRiderOperationalType;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway and Cloudflare terminate TLS before forwarding to Laravel.
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [EnsureActiveAccount::class, PreventPrivatePageCache::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => route(
            $request->is('logistics/*', 'rider', 'rider/*') ? 'logistics.login' : 'login'
        ));
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->workspaceRoute()));
        $middleware->alias([
            'role' => EnsureWorkspaceRole::class,
            'workspace.role' => EnsureWorkspaceRole::class,
            'seller.approved' => \App\Http\Middleware\EnsureApprovedSeller::class,
            'provider.approved' => \App\Http\Middleware\EnsureApprovedProvider::class,
            'rider.active' => \App\Http\Middleware\EnsureActiveRider::class,
            'rider.operational' => EnsureRiderOperationalType::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |--------------------------------------------------------------------------
        | API JSON Exceptions
        |--------------------------------------------------------------------------
        |
        | API requests should return JSON errors instead of Laravel HTML error
        | pages. This is required for the Flutter mobile application.
        |
        */

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) =>
                $request->is('api/*') ||
                $request->expectsJson(),
        );

        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $message = 'The selected upload is too large. Images must be 20 MB or less each, and videos must be 200 MB or less.';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message], 413);
            }

            return redirect()->back()
                ->with('upload_error', $message)
                ->with('show_error_modal', true);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->routeIs('admin.products.ai.rescan') || $request->expectsJson()) {
                return null;
            }

            $product = $request->route('product');
            if (! $product) {
                return null;
            }

            $retryAfter = max(1, (int) ($exception->getHeaders()['Retry-After'] ?? 60));
            $timeUnit = $retryAfter === 1 ? 'second' : 'seconds';

            return redirect()->route('admin.products.show', ['product' => $product])
                ->withErrors([
                    'moderation' => "Too many rescan requests. Please wait {$retryAfter} {$timeUnit} before trying again.",
                ])
                ->header('Retry-After', (string) $retryAfter);
        });
    })
    ->create();
