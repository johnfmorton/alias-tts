<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\Credit\CreditService;
use App\Support\OpenAiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-generation credit gate for the /v1 GENERATING endpoints only, keyed to
 * the API key OWNER's prepaid balance (see CreditService; NULL = unlimited).
 * Runs after ValidateApiKey (which resolves the key) and alongside the hourly
 * RateLimitApiRequests — the rate limit brakes burst abuse, this caps total
 * spend. Poll/audio/project routes are never gated, so finished audio stays
 * downloadable at $0. Known trade-off: the gate sits in front of the
 * SpeechService cache lookup, so an out-of-credit key also loses free cache
 * replays — acceptable, since the same request re-succeeds once credit is
 * added.
 */
class EnsureCreditAvailable
{
    public function __construct(private readonly CreditService $credit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey instanceof ApiKey || $this->credit->canSpend($apiKey->user)) {
            return $next($request);
        }

        $message = CreditService::OUT_OF_CREDIT_MESSAGE;

        // Real OpenAI reports an exhausted balance as 429 insufficient_quota,
        // so OpenAI-dialect clients (and their SDK retry logic) see exactly
        // the shape they already understand.
        if ($request->routeIs('openai.*')) {
            return OpenAiError::json($message, 429, type: 'insufficient_quota', code: 'insufficient_quota');
        }

        return response()->json([
            'detail' => ['message' => $message, 'status' => 402],
        ], 402);
    }
}
