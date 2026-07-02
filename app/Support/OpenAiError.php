<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Builds an OpenAI-shaped error envelope:
 *
 *   { "error": { "message": ..., "type": ..., "param": ..., "code": ... } }
 *
 * Used by the OpenAI-compatible TTS surface (POST /v1/audio/speech) — and by the
 * shared auth / rate-limit middleware when it is serving an `openai.*` route — so
 * a client written against OpenAI's SDK surfaces a clean error. The ElevenLabs
 * surface keeps its own {"detail":{...}} shape.
 */
class OpenAiError
{
    public static function json(
        string $message,
        int $status,
        ?string $type = null,
        ?string $param = null,
        ?string $code = null,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => $type ?? self::typeFor($status),
                'param' => $param,
                'code' => $code,
            ],
        ], $status);
    }

    private static function typeFor(int $status): string
    {
        return match (true) {
            $status === 429 => 'rate_limit_error',
            $status >= 500 => 'api_error',
            default => 'invalid_request_error',
        };
    }
}
