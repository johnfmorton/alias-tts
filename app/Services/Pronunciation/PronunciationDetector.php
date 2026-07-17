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
     * @param  bool  $force  Skip the global on/off toggle and always run — used by
     *                       the judge-facing Genblaze page, which surfaces this
     *                       Genblaze CHAT step as a first-class part of the demo.
     * @return array{available: bool, substitutions: list<array<string, mixed>>, provenance?: mixed, error?: ?string}
     */
    public function detect(string $text, ?int $userId = null, bool $force = false): array
    {
        if (! $force && ! config('tts.pronunciation.enabled', false)) {
            return ['available' => false, 'substitutions' => []];
        }

        $result = $this->runner->detectPronunciation(
            text: $text,
            knownTerms: $this->dictionary->knownTerms($userId),
            provider: (string) config('tts.pronunciation.llm_provider', 'replicate'),
            model: config('tts.pronunciation.model'),
            temperature: (float) config('tts.pronunciation.temperature', 0.2),
        );
        $result['substitutions'] = $this->dedupe($result['substitutions'] ?? []);

        return $result;
    }

    /**
     * The runner dedupes too, but models sometimes list a term once per
     * occurrence and older runners predate that fix — so every consumer
     * (review screen, Inspector panel, Genblaze runs) gets each term exactly
     * once from here. Case-insensitive on `term`; keeps the highest-confidence
     * row in the position where the term first appeared; drops rows with an
     * empty term or phonetic.
     *
     * @param  list<array<string, mixed>>  $substitutions
     * @return list<array<string, mixed>>
     */
    private function dedupe(array $substitutions): array
    {
        $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
        $firstSeen = [];
        $kept = [];

        foreach ($substitutions as $s) {
            $term = trim((string) ($s['term'] ?? ''));
            if ($term === '' || trim((string) ($s['phonetic'] ?? '')) === '') {
                continue;
            }

            $key = mb_strtolower($term);
            if (! array_key_exists($key, $firstSeen)) {
                $firstSeen[$key] = count($kept);
                $kept[] = $s;

                continue;
            }

            $held = $rank[$kept[$firstSeen[$key]]['confidence'] ?? ''] ?? 0;
            if (($rank[$s['confidence'] ?? ''] ?? 0) > $held) {
                $kept[$firstSeen[$key]] = $s;
            }
        }

        return $kept;
    }
}
