<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route to SuperAdmins only. The control panel at large is open to any
 * signed-in, active user (see EnsureAccountIsActive); this narrower gate protects
 * the admin-only surface — today the Users screen, tomorrow audit log / server config.
 */
class EnsureUserIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            abort(403, 'SuperAdmin access required.');
        }

        return $next($request);
    }
}
