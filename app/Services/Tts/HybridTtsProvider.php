<?php

namespace App\Services\Tts;

/**
 * The TTS_PROVIDER=local driver: routes each synthesize call by the engine's
 * `local_capable` catalog flag — Chatterbox engines to the local sidecar,
 * engines the sidecar can't run (qwen) to Replicate. One voice catalog, two
 * backends, chosen per call, so a project can mix a local chatterbox voice
 * with a Replicate-only qwen voice chunk by chunk.
 *
 * The remote leg still needs REPLICATE_API_TOKEN; without it a qwen render
 * fails with the provider's own clear token error while chatterbox voices
 * keep rendering locally, untouched.
 */
class HybridTtsProvider implements TtsProvider
{
    /** @param array<string, array<string, mixed>> $models */
    public function __construct(
        private TtsProvider $local,
        private TtsProvider $remote,
        private array $models = [],
    ) {}

    public function synthesize(string $text, ?string $referenceAudio, array $settings): string
    {
        $modelKey = isset($settings['model']) ? (string) $settings['model'] : null;

        return $this->providerFor($modelKey)->synthesize($text, $referenceAudio, $settings);
    }

    public function outputContainer(?string $model = null): string
    {
        return $this->providerFor($model)->outputContainer($model);
    }

    /**
     * Unknown/absent keys fall back to the default (chatterbox) entry — the
     * same resolution both concrete providers apply — so a stale voices.model
     * value routes local, never to a surprise paid render.
     */
    private function providerFor(?string $modelKey): TtsProvider
    {
        $model = ($modelKey !== null && isset($this->models[$modelKey]))
            ? $this->models[$modelKey]
            : ($this->models[ModelCatalog::DEFAULT] ?? []);

        return ($model['local_capable'] ?? true) ? $this->local : $this->remote;
    }
}
