<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the /v1/internal/* primitive endpoints with a shared secret supplied
 * in the X-Internal-Secret header.
 *
 * These endpoints expose the raw pipeline stages to the Genblaze orchestrator —
 * a trusted, co-located service — so they sit behind a single rotating secret
 * rather than a per-client API key. When no secret is configured the surface is
 * disabled (503) so it can never be left open by accident.
 */
class ValidateInternalSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('tts.internal.secret', '');

        if ($secret === '') {
            return $this->error('The internal pipeline API is disabled (no secret configured).', 503);
        }

        $provided = (string) ($request->header('X-Internal-Secret') ?? '');

        if (! hash_equals($secret, $provided)) {
            return $this->error('A valid X-Internal-Secret header is required.', 403);
        }

        return $next($request);
    }

    private function error(string $message, int $status): Response
    {
        return response()->json(['detail' => ['message' => $message, 'status' => $status]], $status);
    }
}
