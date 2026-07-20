<?php

namespace App\Services\Pronunciation;

/**
 * Applies the writer's approved pronunciation dictionary to text arriving on the
 * /v1 API surface — gated by the per-user `tts.pronunciation.apply_to_api`
 * setting (default OFF). The /v1 endpoints are otherwise a faithful
 * ElevenLabs/OpenAI passthrough (the Bespoken plugin substitutes upstream, per
 * {@see PronunciationApiController}), so this is the ONE place the server opts a
 * direct API caller into respelling.
 *
 * Only the /v1 controllers use this, so reading the (already per-user-overlaid)
 * config here is correct — unlike the shared ProjectService/SpeechService, which
 * must never config-read a per-user setting because Studio and /v1 funnel
 * through them and would cross-contaminate.
 */
class ApiPronunciation
{
    public function __construct(
        private PronunciationDictionary $dictionary,
        private PronunciationSubstituter $substituter,
    ) {}

    /**
     * The approved substitution map to apply on the API path, or [] when the
     * toggle is off — for callers that take a map and apply it themselves (e.g.
     * {@see ProjectService::createFromText}, which respells the chunks while
     * keeping `source_text` verbatim).
     *
     * @return list<array{term: string, phonetic: string, match_mode: string}>
     */
    public function mapFor(?int $userId): array
    {
        return config('tts.pronunciation.apply_to_api', false)
            ? $this->dictionary->approvedMap($userId)
            : [];
    }

    /**
     * Respell $text with the toggle-gated dictionary for the synthesis endpoints,
     * which hand raw text straight to the provider (no createFromText map hook).
     * A no-op when the toggle is off or the writer has no approved terms.
     */
    public function apply(?int $userId, string $text): string
    {
        $map = $this->mapFor($userId);

        return $map === [] ? $text : $this->substituter->apply($text, $map)['text'];
    }
}
