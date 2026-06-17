<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // ElevenLabs uses the `xi-api-key` header. Accept it first for drop-in
        // compatibility, then fall back to `X-API-Key` / Bearer for our own tools.
        $apiKeyHeader = $request->header('xi-api-key')
            ?? $request->header('X-API-Key')
            ?? $request->bearerToken();

        if (! $apiKeyHeader) {
            return $this->error('An API key is required. Provide it in the xi-api-key header.', 401);
        }

        $apiKey = ApiKey::where('key', $apiKeyHeader)->first();

        if (! $apiKey) {
            return $this->error('The provided API key is not valid.', 401);
        }

        if (! $apiKey->is_active) {
            return $this->error('This API key has been deactivated.', 403);
        }

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    /**
     * Errors use the ElevenLabs shape {"detail":{"message":...}} so clients
     * such as the Bespoken Craft plugin surface a clean message.
     */
    private function error(string $message, int $status): Response
    {
        return response()->json(['detail' => ['message' => $message, 'status' => $status]], $status);
    }
}
