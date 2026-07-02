<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenAiSpeechRequest;
use App\Models\Speech;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\SpeechService;
use App\Services\Tts\VoiceSettingsResolver;
use App\Support\OpenAiError;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * OpenAI-compatible text-to-speech endpoint — a thin adapter over the SAME
 * {@see SpeechService} the ElevenLabs surface uses, so an app written against
 * OpenAI's `POST /v1/audio/speech` works against Bespoken by only swapping the
 * base URL.
 *
 *   POST /v1/audio/speech
 *   header: Authorization: Bearer <bespoken api key>   (xi-api-key also works)
 *   body:   { model, input, voice, response_format?, speed?, instructions? }
 *
 * Translation to Bespoken's synthesis call:
 *   input           -> text
 *   voice           -> a Bespoken voice SLUG (passthrough; OpenAI's fixed preset
 *                      names like "alloy" can be mapped to a chosen voice via
 *                      config('tts.openai_voice_aliases'))
 *   response_format -> an ElevenLabs output_format token (mp3/wav/pcm)
 *   model           -> ignored; the configured provider decides the model
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
        $voice = Voice::resolveFor($this->voiceSlug($request->voiceName()), $apiKey?->user_id);
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
            );
        } catch (Throwable $e) {
            report($e);

            return OpenAiError::json('Speech generation failed: '.$e->getMessage(), 502);
        }

        return $this->audioResponse($speech);
    }

    /**
     * Map an OpenAI `voice` value to a Bespoken voice slug. Passthrough by default
     * (treat it as a slug); an operator can map OpenAI's fixed preset names to
     * their own voices via config('tts.openai_voice_aliases').
     */
    private function voiceSlug(string $voice): string
    {
        $aliases = (array) config('tts.openai_voice_aliases', []);

        return (string) ($aliases[strtolower($voice)] ?? $voice);
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
