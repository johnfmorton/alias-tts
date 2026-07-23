<?php

namespace App\Services\Tts;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Runs the catalog of Chatterbox models (classic and Turbo) on Replicate.
 *
 * Which model a call uses comes from the RESERVED settings key `model`
 * (absent = classic chatterbox; see ModelCatalog::stamp()). Each catalog
 * entry (config `tts.models`) carries its own slug, pinned version, input
 * field names, knob dialect, and input cap; the transport below — retries,
 * throttling, polling — is shared by all of them.
 *
 * Uses the predictions API with `Prefer: wait` for a near-synchronous call,
 * and polls as a fallback if the prediction is still processing when the wait
 * window closes.
 *
 * Replicate enforces a burst rate limit on prediction creation (e.g. "6/min,
 * burst 1"), returning HTTP 429 with a `retry_after` hint. Because a long
 * article fans out into many short Chatterbox calls in quick succession, every
 * Replicate request is wrapped in {@see self::sendWithRetry()} so a throttled
 * call slows generation down (honoring `retry_after`) instead of failing the
 * whole article. Set `min_request_gap_ms` to space calls out proactively.
 *
 * Separately, a prediction can come back `failed` with a TRANSIENT GPU fault
 * (e.g. "CUDA error: device-side assert triggered" on a flaky worker). Those are
 * re-rolled up to `predict_max_retries` times via {@see self::predictWithFailureRetry()};
 * deterministic failures (bad input) fail fast.
 *
 * NOTE: confirm the exact model slug and input field names from the model's
 * schema page (config: tts.providers.replicate.text_field / reference_field).
 */
class ReplicateChatterboxProvider implements TtsProvider
{
    private const BASE = 'https://api.replicate.com/v1';

    /** Wall-clock of the last prediction creation, for proactive spacing. */
    private ?float $lastPredictionAt = null;

    public function __construct(
        private array $config,
        private int $timeout = 300,
        private array $models = [],
    ) {}

    public function outputContainer(?string $model = null): string
    {
        return $this->modelConfig($model)['output_container'] ?? 'wav';
    }

    /**
     * The catalog entry for a model key. Unknown/absent keys — and a provider
     * constructed without a catalog (direct construction in tests) — fall back
     * to the classic chatterbox shape built from the transport config's legacy
     * keys, so old call sites behave exactly as before.
     *
     * @return array<string, mixed>
     */
    private function modelConfig(?string $model): array
    {
        if ($model !== null && isset($this->models[$model])) {
            return $this->models[$model];
        }

        return $this->models[ModelCatalog::DEFAULT] ?? [
            'model' => $this->config['model'] ?? 'resemble-ai/chatterbox',
            'version' => $this->config['version'] ?? '',
            'text_field' => $this->config['text_field'] ?? 'prompt',
            'reference_field' => $this->config['reference_field'] ?? 'audio_prompt',
            'output_container' => $this->config['output_container'] ?? 'wav',
            'max_input_chars' => 0,
            'knobs' => 'chatterbox',
        ];
    }

    public function synthesize(string $text, ?string $referenceAudio, array $settings): string
    {
        $token = $this->config['token'] ?? null;
        if (! $token) {
            throw new RuntimeException('REPLICATE_API_TOKEN is not configured.');
        }

        $modelKey = isset($settings['model']) ? (string) $settings['model'] : null;
        $model = $this->modelConfig($modelKey);

        // Fail fast on a per-model input cap (turbo: 500 chars) BEFORE any HTTP
        // call, so an oversized chunk never spends credit.
        $maxChars = max(0, (int) ($model['max_input_chars'] ?? 0));
        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            throw new RuntimeException(sprintf(
                '%s accepts at most %d characters per call; this chunk is %d. Split the chunk or switch its voice to a model without the cap.',
                $model['label'] ?? ($modelKey ?? 'The model'),
                $maxChars,
                mb_strlen($text),
            ));
        }

        // Engines that don't render [laugh]-style sound tags would read them
        // aloud as words — strip the known ones from THEIR payloads only (the
        // chunk text itself is never touched).
        if (! ($model['supports_tags'] ?? false)) {
            $text = ParalinguisticTags::strip($text);
        }

        $input = [
            ($model['text_field'] ?? 'prompt') => $text,
        ];

        if ($referenceAudio !== null) {
            $input[$model['reference_field'] ?? 'audio_prompt'] = $this->toDataUri($referenceAudio);
        }

        if (($model['knobs'] ?? 'chatterbox') === 'turbo') {
            // Turbo's dialect: temperature + sampling knobs, no cfg/exaggeration.
            // ChatterboxTurboTuning owns the mapping (incl. EL stability →
            // temperature); EL style/similarity_boost are accepted and ignored.
            $input += ChatterboxTurboTuning::resolveNative($settings);

            // A clip-less turbo voice speaks through a built-in voice. Replicate
            // ignores `voice` whenever reference_audio is present, so only send
            // it when there is no clip.
            if ($referenceAudio === null) {
                $input['voice'] = (string) ($settings['voice_preset'] ?? 'Andy');
            }
        } elseif (($model['knobs'] ?? 'chatterbox') === 'qwen3-tts') {
            // Qwen validates its input, so the payload holds schema keys ONLY:
            // Qwen3TtsTuning emits just language/style_instruction (defaults by
            // omission), and none of the chatterbox numeric knobs apply.
            $input += Qwen3TtsTuning::resolveNative($settings);

            if ($referenceAudio !== null) {
                // NAMING TRAP: qwen's 'voice_clone' clones from the clip;
                // 'custom_voice' is its PRESET-speaker mode, not cloning.
                $input['mode'] = 'voice_clone';

                // The clip's transcript (stamped from voices.settings by
                // ModelCatalog::stamp) sharpens clone fidelity when present.
                $referenceText = trim((string) ($settings['reference_text'] ?? ''));
                if ($referenceText !== '') {
                    $input['reference_text'] = $referenceText;
                }
            } else {
                $input['mode'] = 'custom_voice';
                $input['speaker'] = (string) ($settings['voice_preset'] ?? 'Serena');
            }
        } else {
            // Resolve Chatterbox's native knobs. Native keys (cfg_weight/exaggeration)
            // win when present (the Studio speaks native); otherwise they're derived
            // from the ElevenLabs-style 0..1 knobs the public /v1 API speaks
            // (stability/style), keeping the EL defaults aligned to Chatterbox defaults.
            // ChatterboxTuning is the single source of truth for this mapping.
            $native = ChatterboxTuning::resolveNative($settings);
            $input['cfg_weight'] = $native['cfg_weight'];      // [0.2, 1.0]: higher = steadier pacing
            $input['exaggeration'] = $native['exaggeration'];  // [0.25, 2.0]: higher = more animated

            // Native-only sampling temperature (no ElevenLabs twin). Defaults to the
            // model's own 0.8, so an EL-only caller that never sets it is unaffected.
            $input['temperature'] = ChatterboxTuning::clampTemperature(
                (float) ($settings['temperature'] ?? ChatterboxTuning::TEMPERATURE_DEFAULT),
            );
        }

        // Pin the seed when provided so a saved take can name the exact seed it
        // rendered at; otherwise the model draws a fresh random seed each call
        // (classic treats 0 as random, turbo treats absent as random — omitting
        // when unpinned satisfies both). Engines whose schema has no seed input
        // (qwen) never receive one — an unknown key fails input validation.
        // NOTE: even a pinned seed is NOT bit-reproducible on Replicate's
        // shared GPUs — it biases the draw, it doesn't freeze it (see
        // docs/STUDIO-TUNING.md).
        if (isset($settings['seed']) && ($model['supports_seed'] ?? true)) {
            $input['seed'] = (int) $settings['seed'];
        }

        $prediction = $this->predictWithFailureRetry($token, $input, $model);

        $output = $prediction['output'] ?? null;
        $url = is_array($output) ? ($output[0] ?? null) : $output;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Replicate returned no audio output.');
        }

        $audio = $this->sendWithRetry(
            fn () => Http::withToken($token)->timeout($this->timeout)->get($url),
            'audio download',
        );

        return $audio->body();
    }

    private function createPrediction(string $token, array $input, array $model): array
    {
        $this->respectRequestGap();

        $response = $this->sendWithRetry(function () use ($token, $input, $model) {
            $http = Http::withToken($token)
                ->timeout($this->timeout)
                ->withHeaders(['Prefer' => 'wait']);

            // Pinned version -> /predictions with `version`; otherwise the model endpoint.
            if (! empty($model['version'])) {
                return $http->post(self::BASE.'/predictions', [
                    'version' => $model['version'],
                    'input' => $input,
                ]);
            }

            $slug = $model['model'] ?? 'resemble-ai/chatterbox';

            return $http->post(self::BASE."/models/{$slug}/predictions", [
                'input' => $input,
            ]);
        }, 'prediction request');

        return $response->json();
    }

    /**
     * Create a prediction and wait for it; if it fails with a TRANSIENT GPU fault
     * (a flaky worker — see {@see self::isTransientFailure()}), recreate it up to
     * `predict_max_retries` times with the same backoff as the 429 path, bounded
     * by the per-request timeout. Non-transient failures throw at once (fail fast,
     * no wasted credit). Returns the succeeded prediction.
     */
    private function predictWithFailureRetry(string $token, array $input, array $model): array
    {
        $maxRetries = max(0, (int) ($this->config['predict_max_retries'] ?? 2));
        $baseMs = max(0, (int) ($this->config['retry_base_ms'] ?? 1000));
        $capMs = max($baseMs, (int) ($this->config['retry_max_ms'] ?? 30000));
        $deadline = time() + $this->timeout;

        $attempt = 0;
        while (true) {
            $prediction = $this->awaitCompletion($token, $this->createPrediction($token, $input, $model));

            if (($prediction['status'] ?? '') === 'succeeded') {
                return $prediction;
            }

            $err = $prediction['error'] ?? 'unknown error';
            $err = is_string($err) ? $err : json_encode($err);
            $status = $prediction['status'] ?? 'failed';

            if ($attempt >= $maxRetries || ! $this->isTransientFailure($err)) {
                throw new RuntimeException("Replicate prediction {$status}: {$err}");
            }

            $delayMs = (int) min($capMs, $baseMs * (2 ** $attempt));
            if (time() + (int) ceil($delayMs / 1000) >= $deadline) {
                throw new RuntimeException("Replicate prediction {$status} (transient); retry budget exhausted: {$err}");
            }

            Sleep::for($delayMs)->milliseconds();
            $attempt++;
        }
    }

    /**
     * Whether a failed prediction's error looks like a transient GPU/infra fault
     * (worth re-rolling) rather than a deterministic input problem. Matches the
     * Chatterbox failures observed on Replicate's shared GPUs — CUDA device-side
     * asserts and out-of-memory chief among them.
     */
    private function isTransientFailure(string $error): bool
    {
        return (bool) preg_match(
            '/cuda|device-side assert|out of memory|\boom\b|cudnn|nccl|gpu|internal error|please try again|service unavailable|\b50[234]\b/i',
            $error,
        );
    }

    /**
     * Poll a prediction until it reaches a terminal state, returning it as-is
     * (succeeded OR failed — the caller decides how to handle a failure). Throws
     * only when polling outlives the per-request timeout.
     */
    private function awaitCompletion(string $token, array $prediction): array
    {
        $deadline = time() + $this->timeout;

        while (in_array($prediction['status'] ?? '', ['starting', 'processing'], true)) {
            if (time() >= $deadline) {
                throw new RuntimeException('Replicate prediction timed out.');
            }

            Sleep::for(750)->milliseconds();

            $get = $prediction['urls']['get'] ?? (self::BASE.'/predictions/'.($prediction['id'] ?? ''));
            $response = $this->sendWithRetry(
                fn () => Http::withToken($token)->timeout($this->timeout)->get($get),
                'prediction polling',
            );
            $prediction = $response->json();
        }

        return $prediction;
    }

    /**
     * Send a Replicate request, retrying when it throttles us (HTTP 429).
     *
     * On 429 we honor Replicate's `retry_after` hint (JSON body, then the
     * Retry-After header, both in seconds) and fall back to exponential
     * backoff. Bounded by `max_retries` and the per-request timeout so a stuck
     * call can't outlive the synchronous budget. Non-429 failures throw at once.
     *
     * @param  callable():Response  $send
     */
    private function sendWithRetry(callable $send, string $context): Response
    {
        $maxRetries = max(0, (int) ($this->config['max_retries'] ?? 5));
        $baseMs = max(0, (int) ($this->config['retry_base_ms'] ?? 1000));
        $capMs = max($baseMs, (int) ($this->config['retry_max_ms'] ?? 30000));
        $deadline = time() + $this->timeout;

        $attempt = 0;
        while (true) {
            $response = $send();

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() !== 429 || $attempt >= $maxRetries) {
                throw new RuntimeException(
                    "Replicate {$context} failed (HTTP {$response->status()}): ".$response->body(),
                );
            }

            $delayMs = $this->retryDelayMs($response, $attempt, $baseMs, $capMs);

            // Never sleep past the deadline — give up cleanly instead of hanging.
            if (time() + (int) ceil($delayMs / 1000) >= $deadline) {
                throw new RuntimeException(
                    "Replicate {$context} throttled (HTTP 429); retry budget exhausted: ".$response->body(),
                );
            }

            Sleep::for($delayMs)->milliseconds();
            $attempt++;
        }
    }

    /**
     * Backoff delay (ms) for a 429: prefer Replicate's `retry_after` hint, else
     * exponential backoff (base, 2x, 4x, …). Capped so one wait can't dominate.
     */
    private function retryDelayMs(Response $response, int $attempt, int $baseMs, int $capMs): int
    {
        $hintSeconds = $this->retryAfterSeconds($response);
        if ($hintSeconds !== null) {
            return (int) min($capMs, max(0, (int) round($hintSeconds * 1000)));
        }

        return (int) min($capMs, $baseMs * (2 ** $attempt));
    }

    /**
     * Replicate signals throttling with `retry_after` in the JSON body and may
     * also set a Retry-After header; both are in seconds. Returns null if absent.
     */
    private function retryAfterSeconds(Response $response): ?float
    {
        $body = $response->json();
        if (is_array($body) && isset($body['retry_after']) && is_numeric($body['retry_after'])) {
            return (float) $body['retry_after'];
        }

        $header = $response->header('Retry-After');
        if (is_numeric($header)) {
            return (float) $header;
        }

        return null;
    }

    /**
     * Proactively space out prediction creations to respect Replicate's burst
     * limit. Disabled by default (min_request_gap_ms = 0, relying on reactive
     * 429 retry); set it to ~10000 to stay under a 6/min limit up front.
     */
    private function respectRequestGap(): void
    {
        $gapMs = max(0, (int) ($this->config['min_request_gap_ms'] ?? 0));
        if ($gapMs === 0) {
            return;
        }

        if ($this->lastPredictionAt !== null) {
            $remainingMs = $gapMs - ((microtime(true) - $this->lastPredictionAt) * 1000);
            if ($remainingMs > 0) {
                Sleep::for((int) ceil($remainingMs))->milliseconds();
            }
        }

        $this->lastPredictionAt = microtime(true);
    }

    private function toDataUri(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Could not read reference audio at {$path}.");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'mp3' => 'audio/mpeg',
            'm4a', 'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            default => 'audio/wav',
        };

        return "data:{$mime};base64,".base64_encode($bytes);
    }
}
