<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The control-panel gate for every signed-in user. Runs after `auth`, so a user is
 * present. Two jobs:
 *  1. Bounce suspended accounts back to the login screen (a SuperAdmin can suspend a
 *     user mid-session; this ends their access on the next request).
 *  2. Keep `last_active_at` fresh for the Users screen's presence column — throttled
 *     to once every few minutes and written without bumping `updated_at`.
 */
class EnsureAccountIsActive
{
    /** How stale presence may get before we rewrite it. */
    private const PRESENCE_TTL_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been suspended.']);
        }

        if ($user && (! $user->last_active_at || $user->last_active_at->lt(now()->subMinutes(self::PRESENCE_TTL_MINUTES)))) {
            $user->forceFill(['last_active_at' => now()]);
            $user->timestamps = false;
            $user->saveQuietly();
        }

        return $next($request);
    }
}
