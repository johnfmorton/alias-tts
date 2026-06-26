<?php

namespace App\Services\Pronunciation;

use App\Services\Genblaze\GenblazeRunnerClient;

/**
 * Drives the LLM detection step: hands the runner the text plus the writer's
 * already-approved terms (so the model skips them), using the admin-selected
 * provider. The runner runs it as a Genblaze chat call; this service is the thin
 * PHP seam over {@see GenblazeRunnerClient}.
 *
 * Degrade-safe: returns ['available' => false] when the feature is off or the
 * runner/LLM is unavailable, so the new-project flow never blocks on it.
 */
class PronunciationDetector
{
    public function __construct(
        private readonly GenblazeRunnerClient $runner,
        private readonly PronunciationDictionary $dictionary,
    ) {}

    /**
     * @return array{available: bool, substitutions: list<array<string, mixed>>, provenance?: mixed, error?: ?string}
     */
    public function detect(string $text, ?int $userId = null): array
    {
        if (! config('tts.pronunciation.enabled', false)) {
            return ['available' => false, 'substitutions' => []];
        }

        return $this->runner->detectPronunciation(
            text: $text,
            knownTerms: $this->dictionary->knownTerms($userId),
            provider: (string) config('tts.pronunciation.llm_provider', 'replicate'),
            model: config('tts.pronunciation.model'),
            temperature: (float) config('tts.pronunciation.temperature', 0.2),
        );
    }
}
