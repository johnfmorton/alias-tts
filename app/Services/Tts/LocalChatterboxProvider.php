<?php

namespace App\Services\Tts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runs the catalog of Chatterbox models (classic and Turbo) on a LOCAL
 * inference sidecar (chatterbox-sidecar/) instead of Replicate — a
 * development driver selected with TTS_PROVIDER=local. See
 * docs/CHATTERBOX-LOCAL.md for the sidecar setup.
 *
 * The contract is deliberately simple and synchronous: one multipart POST to
 * /synthesize returns raw WAV bytes — no prediction/polling dance and no
 * retry logic (local failures are deterministic; a failure should surface,
 * not be re-rolled). The same catalog entries drive both providers, so the
 * per-model input caps and tag stripping behave exactly as on Replicate.
 *
 * Differences from Replicate, by design:
 *  - Turbo's named preset voices (Aaron…Walter) are a Replicate deployment
 *    feature; a clip-less turbo voice speaks in the model's single built-in
 *    voice locally.
 *  - The sidecar generates one request at a time; concurrent chunk requests
 *    queue, so the timeout must cover queue wait as well as generation.
 */
class LocalChatterboxProvider implements TtsProvider
{
    public function __construct(
        private array $config,
        private int $timeout = 300,
        private array $models = [],
    ) {}

    public function outputContainer(?string $model = null): string
    {
        // The sidecar always emits WAV regardless of engine.
        return 'wav';
    }

    /**
     * The catalog entry for a model key; unknown/absent keys fall back to the
     * classic chatterbox entry (mirrors ReplicateChatterboxProvider).
     *
     * @return array<string, mixed>
     */
    private function modelConfig(?string $model): array
    {
        if ($model !== null && isset($this->models[$model])) {
            return $this->models[$model];
        }

        return $this->models[ModelCatalog::DEFAULT] ?? [
            'label' => 'Chatterbox',
            'max_input_chars' => 0,
            'knobs' => 'chatterbox',
        ];
    }

    public function synthesize(string $text, ?string $referenceAudio, array $settings): string
    {
        $modelKey = isset($settings['model']) ? (string) $settings['model'] : ModelCatalog::DEFAULT;
        $model = $this->modelConfig($modelKey);

        // Fail fast on a per-model input cap (turbo: 500 chars) BEFORE any HTTP
        // call — same guarantee as the Replicate driver.
        $maxChars = max(0, (int) ($model['max_input_chars'] ?? 0));
        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            throw new RuntimeException(sprintf(
                '%s accepts at most %d characters per call; this chunk is %d. Split the chunk or switch its voice to a model without the cap.',
                $model['label'] ?? $modelKey,
                $maxChars,
                mb_strlen($text),
            ));
        }

        // Engines that don't render [laugh]-style sound tags would read them
        // aloud as words — strip the known ones from THEIR payloads only.
        if (! ($model['supports_tags'] ?? false)) {
            $text = ParalinguisticTags::strip($text);
        }

        $fields = [
            'text' => $text,
            'model' => $modelKey,
        ];

        if (($model['knobs'] ?? 'chatterbox') === 'turbo') {
            $fields += ChatterboxTurboTuning::resolveNative($settings);
            // No `voice` preset field: the sidecar has no named presets — a
            // clip-less turbo request uses the model's built-in voice.
        } else {
            $native = ChatterboxTuning::resolveNative($settings);
            $fields['cfg_weight'] = $native['cfg_weight'];
            $fields['exaggeration'] = $native['exaggeration'];
            $fields['temperature'] = ChatterboxTuning::clampTemperature(
                (float) ($settings['temperature'] ?? ChatterboxTuning::TEMPERATURE_DEFAULT),
            );
        }

        // A pinned seed is honored via torch.manual_seed on the sidecar. Local
        // single-threaded CPU inference makes the pin far more reproducible
        // than Replicate's shared GPUs.
        if (isset($settings['seed'])) {
            $fields['seed'] = (int) $settings['seed'];
        }

        $url = rtrim((string) ($this->config['url'] ?? 'http://127.0.0.1:8766'), '/');

        $request = Http::timeout($this->timeout)->connectTimeout(5)->asMultipart();

        if ($referenceAudio !== null) {
            $bytes = @file_get_contents($referenceAudio);
            if ($bytes === false) {
                throw new RuntimeException("Could not read reference audio at {$referenceAudio}.");
            }
            $request = $request->attach('reference', $bytes, basename($referenceAudio));
        }

        try {
            $response = $request->post($url.'/synthesize', $fields);
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                "Local Chatterbox sidecar unreachable at {$url} — is it running? See docs/CHATTERBOX-LOCAL.md.",
                previous: $e,
            );
        }

        if ($response->status() === 503) {
            $detail = $response->json('error') ?? trim($response->body());

            throw new RuntimeException(
                "Local Chatterbox sidecar could not serve the '{$modelKey}' model: {$detail} "
                .'(a cold model downloads ~3.8GB on first use — see docs/CHATTERBOX-LOCAL.md).',
            );
        }

        if (! $response->successful()) {
            $detail = $response->json('error') ?? trim($response->body());

            throw new RuntimeException(
                "Local Chatterbox sidecar error (HTTP {$response->status()}): {$detail}",
            );
        }

        $audio = $response->body();
        if ($audio === '') {
            throw new RuntimeException('Local Chatterbox sidecar returned no audio.');
        }

        return $audio;
    }
}
