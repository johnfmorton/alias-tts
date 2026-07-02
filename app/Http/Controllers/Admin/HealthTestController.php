<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SpeechStatus;
use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use App\Services\Health\HealthReport;
use App\Services\SpeechService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Live, end-to-end provider tests for the admin health page. Unlike the
 * read-only checks in HealthReport, these make real (billable) generation
 * calls: the short test exercises the synchronous provider → audio path, and
 * the long test exercises async chunking, the queue worker, and concatenation
 * (dispatch + poll), so it doubles as a real queue-worker liveness test.
 */
class HealthTestController extends Controller
{
    use ServesRangedAudio;

    /** Short fixed text — one provider call, synchronous. */
    private const SHORT_TEXT = 'This is a short synchronous test of the text to speech provider.';

    public function __construct(
        private readonly SpeechService $speechService,
        private readonly HealthReport $health,
    ) {}

    public function short(Request $request): Response
    {
        $voice = $this->resolveVoice($request);
        if (! $voice) {
            return response()->json(['message' => 'No voice configured — add a voice first.'], 422);
        }

        try {
            $speech = $this->speechService->synthesize(
                apiKey: ApiKey::dashboardFor($request->user()->id),
                voice: $voice,
                text: self::SHORT_TEXT,
                settings: config('tts.default_voice_settings'),
                modelId: config('tts.default_model_id'),
                outputFormat: config('tts.default_output_format'),
                seed: null,
                forceRefresh: true, // always exercise the provider, never the cache
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Short test failed: '.$e->getMessage()], 502);
        }

        return response($this->speechService->audioBytes($speech), 200)
            ->header('Content-Type', $speech->mime_type ?: 'audio/mpeg');
    }

    public function long(Request $request): JsonResponse
    {
        $voice = $this->resolveVoice($request);
        if (! $voice) {
            return response()->json(['message' => 'No voice configured — add a voice first.'], 422);
        }

        // Fail fast instead of enqueuing a job that would hang (and linger in the
        // queue) when nothing is draining it.
        if (! $this->health->queueWorkerActive()) {
            return response()->json([
                'message' => 'No queue worker is running — start one (php artisan queue:work) before testing async generation.',
            ], 409);
        }

        try {
            $speech = $this->speechService->queueSynthesis(
                apiKey: ApiKey::dashboardFor($request->user()->id),
                voice: $voice,
                text: $this->longText(),
                settings: config('tts.default_voice_settings'),
                modelId: config('tts.default_model_id'),
                outputFormat: config('tts.default_output_format'),
                seed: null,
                forceRefresh: true,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not queue the long test: '.$e->getMessage()], 502);
        }

        // Reflect inline completion under QUEUE_CONNECTION=sync.
        $speech->refresh();

        return response()->json($this->payload($speech), $speech->isCompleted() ? 200 : 202);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $speech = $this->findTestSpeech($request, $id);
        if (! $speech) {
            return response()->json(['message' => 'Test job not found.'], 404);
        }

        return response()->json($this->payload($speech));
    }

    public function audio(Request $request, string $id): Response
    {
        $speech = $this->findTestSpeech($request, $id);
        if (! $speech) {
            return response()->json(['message' => 'Test job not found.'], 404);
        }

        if ($speech->status === SpeechStatus::Failed) {
            return response()->json(['message' => $speech->error_message ?: 'generation failed'], 502);
        }

        if (! $speech->isCompleted() || ! $speech->audio_path) {
            return response()->json(['message' => 'Audio is not ready yet.'], 409);
        }

        // Range-aware so the test player works in iOS Safari (see ServesRangedAudio).
        return $this->rangedAudio($this->speechService->audioBytes($speech), $speech->mime_type ?: 'audio/mpeg', $request);
    }

    private function resolveVoice(Request $request): ?Voice
    {
        if ($request->filled('voice')) {
            return Voice::resolveFor((string) $request->input('voice'), $request->user()->id);
        }

        return Voice::orderedFor($request->user()->id)->first();
    }

    /**
     * Test jobs run under the CLICKING user's own dashboard key
     * ({@see ApiKey::dashboardFor}), so these endpoints only ever serve that
     * user's own test speech — never arbitrary speech, and never another
     * user's tests.
     */
    private function findTestSpeech(Request $request, string $id): ?Speech
    {
        $key = ApiKey::where('name', 'dashboard')->where('user_id', $request->user()->id)->first();

        return $key
            ? Speech::query()->where('id', $id)->where('api_key_id', $key->id)->first()
            : null;
    }

    /** @return array<string, mixed> */
    private function payload(Speech $speech): array
    {
        return [
            'id' => $speech->id,
            'status' => $speech->status->value,
            'voice' => $speech->voice?->name,
            'characters' => $speech->characters,
            'status_url' => route('admin.health.test.status', ['id' => $speech->id]),
            'audio_url' => route('admin.health.test.audio', ['id' => $speech->id]),
            'error' => $speech->status === SpeechStatus::Failed ? $speech->error_message : null,
        ];
    }

    /**
     * Long enough to split into several chunks (chunk_chars defaults to 280), so
     * this exercises chunking + concatenation rather than a single provider call.
     */
    private function longText(): string
    {
        return trim(str_repeat(
            'This sentence is part of a longer passage used to confirm that long text is split into chunks, generated piece by piece, and concatenated back into one audio file. ',
            6,
        ));
    }
}
