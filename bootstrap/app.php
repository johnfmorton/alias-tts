<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ValidateInternalSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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

            // Password-protected control panel.
            Route::middleware(['web', 'auth', EnsureUserIsAdmin::class])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        // Already-authenticated users hitting a guest route (e.g. /login) go to the dashboard.
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1/*'),
        );
    })->create();
