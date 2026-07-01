<?php

namespace App\Http\Controllers;

use App\Enums\SpeechStatus;
use App\Exceptions\SpeechGenerationException;
use App\Http\Requests\TextToSpeechRequest;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\SpeechService;
use App\Services\Tts\VoiceSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
 *
 * A Bespoken-specific async extension lets long text exceed the synchronous
 * ~300s ceiling:
 *
 *   POST /v1/text-to-speech/{voice_id}/jobs  -> 202 { id, status, status_url, audio_url }
 *   GET  /v1/text-to-speech/jobs/{id}        -> { id, status, ... }
 *   GET  /v1/text-to-speech/jobs/{id}/audio  -> audio bytes once completed
 */
class TextToSpeechController extends Controller
{
    public function __construct(
        private SpeechService $speechService,
        private VoiceSettingsResolver $settingsResolver,
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
                settings: $this->settingsResolver->resolve($voice, $request->voiceSettingOverrides()),
                modelId: $request->modelId(),
                outputFormat: $request->outputFormat(),
                seed: $request->seed(),
                forceRefresh: $request->boolean('force_refresh'),
            );
        } catch (SpeechGenerationException $e) {
            report($e);

            return $this->error('Speech generation failed: '.$e->getMessage(), 502, $this->recoveryUrl($e->recoveryProject));
        } catch (Throwable $e) {
            report($e);

            return $this->error('Speech generation failed: '.$e->getMessage(), 502);
        }

        return $this->audioResponse($speech);
    }

    /**
     * Async: queue generation and return immediately with a poll URL. Returns
     * 200 if the result is already cached/complete, otherwise 202 Accepted.
     */
    public function queue(TextToSpeechRequest $request, string $voice_id): Response
    {
        $apiKey = $request->attributes->get('api_key');

        $voice = Voice::resolve($voice_id);
        if (! $voice) {
            return $this->error("A voice with voice_id '{$voice_id}' could not be found.", 404);
        }

        try {
            $speech = $this->speechService->queueSynthesis(
                apiKey: $apiKey,
                voice: $voice,
                text: $request->input('text'),
                settings: $this->settingsResolver->resolve($voice, $request->voiceSettingOverrides()),
                modelId: $request->modelId(),
                outputFormat: $request->outputFormat(),
                seed: $request->seed(),
                forceRefresh: $request->boolean('force_refresh'),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error('Could not queue speech generation: '.$e->getMessage(), 502);
        }

        // Reflect any inline completion (e.g. QUEUE_CONNECTION=sync, where the
        // job runs during dispatch) so the response status is accurate.
        $speech->refresh();

        $code = $speech->isCompleted() ? Response::HTTP_OK : Response::HTTP_ACCEPTED;

        return response()->json($this->jobPayload($speech), $code)
            ->header('request-id', $speech->id)
            ->header('x-cache', $speech->wasRecentlyCreated ? 'MISS' : 'HIT');
    }

    /**
     * Async: poll a job's status. Scoped to the calling API key.
     */
    public function status(Request $request, string $id): Response
    {
        $speech = $this->findOwnedSpeech($request, $id);
        if (! $speech) {
            return $this->error("A speech job with id '{$id}' could not be found.", 404);
        }

        return response()->json($this->jobPayload($speech));
    }

    /**
     * Async: download the generated audio once the job has completed.
     */
    public function audio(Request $request, string $id): Response
    {
        $speech = $this->findOwnedSpeech($request, $id);
        if (! $speech) {
            return $this->error("A speech job with id '{$id}' could not be found.", 404);
        }

        if ($speech->status === SpeechStatus::Failed) {
            return $this->error('Speech generation failed: '.($speech->error_message ?: 'unknown error'), 502, $this->recoveryUrlForSpeech($speech));
        }

        if (! $speech->isCompleted() || ! $speech->audio_path) {
            return $this->error('Audio is not ready yet; the job is still processing.', 409);
        }

        if (! Storage::disk(config('tts.storage_disk'))->exists($speech->audio_path)) {
            return $this->error('The generated audio is no longer available.', 410);
        }

        return $this->audioResponse($speech);
    }

    private function findOwnedSpeech(Request $request, string $id): ?Speech
    {
        $apiKey = $request->attributes->get('api_key');
        if (! $apiKey instanceof ApiKey) {
            return null;
        }

        return Speech::query()
            ->where('id', $id)
            ->where('api_key_id', $apiKey->id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(Speech $speech): array
    {
        return [
            'id' => $speech->id,
            'status' => $speech->status->value,
            'characters' => $speech->characters,
            'model_id' => $speech->model_id,
            'created_at' => $speech->created_at?->toIso8601String(),
            'status_url' => route('tts.jobs.status', ['id' => $speech->id]),
            'audio_url' => route('tts.jobs.audio', ['id' => $speech->id]),
            'error' => $speech->status === SpeechStatus::Failed ? $speech->error_message : null,
            'recovery_url' => $this->recoveryUrlForSpeech($speech),
        ];
    }

    private function audioResponse(Speech $speech): Response
    {
        $bytes = $this->speechService->audioBytes($speech);

        return response($bytes, 200)
            ->header('Content-Type', $speech->mime_type ?: 'audio/mpeg')
            ->header('Content-Disposition', 'inline; filename="'.$speech->id.'.'.pathinfo($speech->audio_path, PATHINFO_EXTENSION).'"')
            ->header('request-id', $speech->id)
            ->header('x-cache', $speech->wasRecentlyCreated ? 'MISS' : 'HIT');
    }

    private function error(string $message, int $status, ?string $recoveryUrl = null): Response
    {
        $detail = ['message' => $message, 'status' => $status];
        if ($recoveryUrl !== null) {
            $detail['recovery_url'] = $recoveryUrl;
        }

        return response()->json(['detail' => $detail], $status);
    }

    /** The panel URL for a failed speech's recovery project, if any. */
    private function recoveryUrlForSpeech(Speech $speech): ?string
    {
        if ($speech->status !== SpeechStatus::Failed) {
            return null;
        }

        return $this->recoveryUrl(
            TtsProject::where('source_speech_id', $speech->id)->where('origin', 'api_failure')->first()
        );
    }

    /**
     * A plain panel URL for the recovery project — NOT a magic-login link. The
     * /v1 audience holds an api key, not admin rights, so it must never be
     * auto-elevated to the admin user; an admin opens the project from the Studio
     * panel badge after a normal login. This is just the pointer (no token, so
     * nothing secret rides the response body or default request logs).
     */
    private function recoveryUrl(?TtsProject $project): ?string
    {
        return $project ? route('admin.studio.projects.show', $project) : null;
    }
}
