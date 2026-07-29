<?php

use App\Http\Middleware\ApplyUserSettings;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RecordPageView;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateInternalSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // ElevenLabs-compatible routes, mounted at the root (no /api prefix).
            Route::group([], base_path('routes/v1.php'));

            // Stateless pipeline primitives for the Genblaze orchestrator.
            // Under /v1/internal/* so they inherit JSON error rendering; guarded
            // by a shared secret rather than an API key.
            Route::middleware([ValidateInternalSecret::class])
                ->prefix('v1/internal')
                ->name('internal.')
                ->group(base_path('routes/internal.php'));

            // Password-protected control panel. Open to any signed-in, active user;
            // the SuperAdmin-only surface (Users) is gated per-route inside admin.php.
            // ApplyUserSettings overlays the signed-in user's saved settings onto
            // config so the whole panel runs under THEIR preferences.
            Route::middleware(['web', 'auth', EnsureAccountIsActive::class, ApplyUserSettings::class, RecordPageView::class])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Honor X-Forwarded-* from a trusted proxy (Cloudflare / LB / nginx). The
        // subclass reads the trusted list from config at request time — safe under
        // a cached config, unlike reading env()/config() in this closure, which
        // runs before the framework loads configuration. See the middleware.
        $middleware->replace(
            BaseTrustProxies::class,
            TrustProxies::class,
        );

        $middleware->redirectGuestsTo(fn () => route('login'));
        // Already-authenticated users hitting a guest route (e.g. /login) land on
        // the dashboard.
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*'),
        );
    })->create();
