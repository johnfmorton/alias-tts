<?php

namespace App\Http\Middleware;

use App\Models\AppEvent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-party page-view analytics for the admin panel — the app's answer to
 * "which screens get used" without loading any third-party script inside the
 * logged-in app. Records one app_events row per full-page HTML GET; the
 * filters exclude everything that isn't a human looking at a screen: the
 * constant JSON status polls (jobs, chunks, voice clips), POSTs, redirects,
 * and audio/zip downloads (GETs, but not text/html).
 */
class RecordPageView
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->ajax()
            && $response->getStatusCode() === 200
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && ($route = $request->route()?->getName())
        ) {
            AppEvent::record(AppEvent::PAGE_VIEW, $request->user()?->id, AppEvent::SOURCE_STUDIO, [
                'route' => $route,
            ]);
        }

        return $response;
    }
}
