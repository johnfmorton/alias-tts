<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\Settings\SettingsManager;
use App\Support\OpenAiError;
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
            return $this->error($request, 'An API key is required. Provide it in the xi-api-key header.', 401);
        }

        $apiKey = ApiKey::where('key', $apiKeyHeader)->first();

        if (! $apiKey) {
            return $this->error($request, 'The provided API key is not valid.', 401);
        }

        if (! $apiKey->is_active) {
            return $this->error($request, 'This API key has been deactivated.', 403);
        }

        // A deactivated (suspended) account takes its keys down with it — the
        // Users screen promises "their API keys stop working". Ownerless keys
        // (user_id NULL) pass; there is no account to deactivate.
        if ($apiKey->user?->isSuspended()) {
            return $this->error($request, 'This API key belongs to a deactivated account.', 403);
        }

        $request->attributes->set('api_key', $apiKey);

        // The request runs under the key OWNER's saved settings (ASR QA,
        // api_project_mode, …) — settings are per-user, never instance-wide.
        app(SettingsManager::class)->applyForUser($apiKey->user_id);

        return $next($request);
    }

    /**
     * Errors use the ElevenLabs shape {"detail":{"message":...}} so clients such
     * as the Bespoken Craft plugin surface a clean message — except on the
     * OpenAI-compatible surface (`openai.*` routes), which gets the OpenAI shape.
     */
    private function error(Request $request, string $message, int $status): Response
    {
        if ($request->routeIs('openai.*')) {
            return OpenAiError::json($message, $status, code: $status === 401 ? 'invalid_api_key' : null);
        }

        return response()->json(['detail' => ['message' => $message, 'status' => $status]], $status);
    }
}
