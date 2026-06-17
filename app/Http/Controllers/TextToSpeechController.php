<?php

namespace App\Http\Controllers;

use App\Http\Requests\TextToSpeechRequest;
use App\Models\Voice;
use App\Services\SpeechService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ElevenLabs-compatible text-to-speech endpoint.
 *
 *   POST /v1/text-to-speech/{voice_id}
 *   header: xi-api-key: <key>
 *   body:   { text, model_id?, voice_settings?, output_format? }
 *
 * Success -> raw audio bytes (audio/mpeg by default).
 * Error   -> JSON { "detail": { "message": ... } } (matches ElevenLabs).
 */
class TextToSpeechController extends Controller
{
    public function __construct(
        private SpeechService $speechService,
    ) {}

    public function store(TextToSpeechRequest $request, string $voice_id): Response
    {
        $apiKey = $request->attributes->get('api_key');

        $voice = Voice::resolve($voice_id);
        if (! $voice) {
            return $this->error("A voice with voice_id '{$voice_id}' could not be found.", 404);
        }

        try {
            $speech = $this->speechService->synthesize(
                apiKey: $apiKey,
                voice: $voice,
                text: $request->input('text'),
                settings: $request->voiceSettings(),
                modelId: $request->modelId(),
                outputFormat: $request->outputFormat(),
                seed: $request->seed(),
                forceRefresh: $request->boolean('force_refresh'),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error('Speech generation failed: '.$e->getMessage(), 502);
        }

        $bytes = $this->speechService->audioBytes($speech);

        return response($bytes, 200)
            ->header('Content-Type', $speech->mime_type ?: 'audio/mpeg')
            ->header('Content-Disposition', 'inline; filename="'.$speech->id.'.'.pathinfo($speech->audio_path, PATHINFO_EXTENSION).'"')
            ->header('request-id', $speech->id)
            ->header('x-cache', $speech->wasRecentlyCreated ? 'MISS' : 'HIT');
    }

    private function error(string $message, int $status): Response
    {
        return response()->json([
            'detail' => ['message' => $message, 'status' => $status],
        ], $status);
    }
}
