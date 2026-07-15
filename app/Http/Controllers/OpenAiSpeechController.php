<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenAiSpeechRequest;
use App\Models\Speech;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\SpeechService;
use App\Services\Tts\ModelCatalog;
use App\Services\Tts\VoiceSettingsResolver;
use App\Support\OpenAiError;
use App\Support\VoiceAliases;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * OpenAI-compatible text-to-speech endpoint — a thin adapter over the SAME
 * {@see SpeechService} the ElevenLabs surface uses, so an app written against
 * OpenAI's `POST /v1/audio/speech` works against Alias by only swapping the
 * base URL.
 *
 *   POST /v1/audio/speech
 *   header: Authorization: Bearer <alias api key>   (xi-api-key also works)
 *   body:   { model, input, voice, response_format?, speed?, instructions? }
 *
 * Translation to Alias's synthesis call:
 *   input           -> text
 *   voice           -> a Alias voice SLUG (passthrough; OpenAI's fixed preset
 *                      names like "alloy" can be mapped to a chosen voice via
 *                      config('tts.openai_voice_aliases') — the ElevenLabs
 *                      surface has a sibling map, tts.elevenlabs_voice_aliases;
 *                      see {@see VoiceAliases})
 *   response_format -> an ElevenLabs output_format token (mp3/wav/pcm)
 *   model           -> an engine override when it names a catalog model
 *                      ('chatterbox' / 'chatterbox-turbo') or an operator alias
 *                      (config('tts.openai_model_aliases')); any other value —
 *                      including OpenAI's own 'tts-1' — is ignored and the
 *                      voice's engine decides
 *   speed / instructions -> accepted for compatibility, not yet applied
 *
 * Success -> raw audio bytes. Error -> OpenAI shape { "error": { ... } }.
 * See docs/OPENAI-COMPAT.md.
 */
class OpenAiSpeechController extends Controller
{
    /**
     * OpenAI `response_format` -> ElevenLabs `output_format` token. Limited to
     * what {@see AudioConverter} produces today; opus/aac/flac
     * are rejected at validation. NOTE: `pcm` yields a WAV container (audio/wav),
     * not OpenAI's raw headerless 24 kHz PCM — documented in docs/OPENAI-COMPAT.md.
     */
    private const FORMAT_MAP = [
        'mp3' => 'mp3_44100_128',
        'wav' => 'wav_44100',
        'pcm' => 'pcm_24000',
    ];

    public function __construct(
        private SpeechService $speechService,
        private VoiceSettingsResolver $settingsResolver,
    ) {}

    public function store(OpenAiSpeechRequest $request): Response
    {
        $apiKey = $request->attributes->get('api_key');

        // Scoped to the key owner's voices (+ shared built-ins), exactly like the
        // ElevenLabs path — one user's key can never generate with another's voice.
        $voice = Voice::resolveFor(VoiceAliases::openAi($request->voiceName()), $apiKey?->user_id);
        if (! $voice) {
            return OpenAiError::json(
                "The voice '{$request->voiceName()}' could not be found.",
                404,
                param: 'voice',
                code: 'voice_not_found',
            );
        }

        try {
            $speech = $this->speechService->synthesize(
                apiKey: $apiKey,
                voice: $voice,
                text: $request->text(),
                settings: $this->settingsResolver->resolve($voice),
                modelId: (string) config('tts.default_model_id'),
                outputFormat: self::FORMAT_MAP[$request->responseFormat()],
                engine: $this->engineOverride($request->modelName()),
            );
        } catch (Throwable $e) {
            report($e);

            return OpenAiError::json('Speech generation failed: '.$e->getMessage(), 502);
        }

        return $this->audioResponse($speech);
    }

    /**
     * Map the request's `model` to an engine override: an exact catalog key
     * wins, then the operator's alias map (empty by default — a stock client's
     * 'tts-1' must never silently switch every voice's engine). Anything else
     * -> null, meaning the voice's own engine decides (the long-standing
     * ignore-fallback; never an error).
     */
    private function engineOverride(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }

        if (ModelCatalog::isKnown($model)) {
            return $model;
        }

        $aliases = (array) config('tts.openai_model_aliases', []);
        $alias = $aliases[strtolower($model)] ?? null;

        return ModelCatalog::isKnown($alias) ? $alias : null;
    }

    private function audioResponse(Speech $speech): Response
    {
        $bytes = $this->speechService->audioBytes($speech);

        return response($bytes, 200)
            ->header('Content-Type', $speech->mime_type ?: 'audio/mpeg')
            ->header('Content-Disposition', 'inline; filename="'.$speech->id.'.'.pathinfo($speech->audio_path, PATHINFO_EXTENSION).'"')
            ->header('x-request-id', $speech->id)
            ->header('x-cache', $speech->wasRecentlyCreated ? 'MISS' : 'HIT');
    }
}
