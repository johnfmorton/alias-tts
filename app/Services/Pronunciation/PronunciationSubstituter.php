<?php

namespace App\Services\Pronunciation;

use App\Services\TextNormalizer;

/**
 * Applies a pronunciation dictionary to text: a find-and-replace that rewrites
 * each known `term` to its ASCII `phonetic` respelling BEFORE the text reaches
 * the TTS engine (e.g. "DDEV" => "dee dev"). Pure (no DB/IO) so it is trivially
 * testable and reusable on any code path.
 *
 * Semantics (see docs/MIMIC-PRONUNCIATION-PREPROCESSOR.md §5):
 *  - Longest term first, so "PostgreSQL" wins before a bare "SQL" could match
 *    inside it.
 *  - Word-boundary aware via (?<!\w)…(?!\w) lookarounds, so "SQL" inside
 *    "NoSQLDB" is left alone. These lookarounds subsume the spec's separate
 *    "\b vs custom boundary" cases: they also correctly match symbol-edged terms
 *    like ".env" or "C#" (where a plain \b fails) yet refuse to match them when
 *    glued to a word character (e.g. the ".env" inside "foo.env").
 *  - One simultaneous pass (a single alternation), so a replacement is never
 *    re-scanned and substitutions cannot cascade into one another.
 *  - Per-entry case sensitivity: a `case_insensitive` entry matches
 *    DDEV/ddev/Ddev via an inline (?i:…) group; the default is exact-case.
 *  - Replaces every occurrence.
 *
 * ASCII respelling only — the open-source Chatterbox model takes plain `prompt`
 * text (no SSML/<phoneme>/IPA), and {@see TextNormalizer} strips
 * "<…>" before this ever runs.
 */
class PronunciationSubstituter
{
    /**
     * @param  list<array{term: string, phonetic: string, match_mode?: string}>  $entries
     * @return array{text: string, applied: list<string>}
     */
    public function apply(string $text, array $entries): array
    {
        // Longest term first so the alternation prefers "PostgreSQL" over "SQL".
        usort($entries, fn ($a, $b) => mb_strlen((string) $b['term']) <=> mb_strlen((string) $a['term']));

        $fragments = [];
        $seen = [];
        $exactMap = [];   // term (exact case)   => phonetic
        $ciMap = [];      // mb_strtolower(term) => phonetic
        $ciTermMap = [];  // mb_strtolower(term) => original term (for the applied list)

        foreach ($entries as $entry) {
            $term = (string) ($entry['term'] ?? '');
            if ($term === '') {
                continue;
            }
            $phonetic = (string) ($entry['phonetic'] ?? '');
            $quoted = preg_quote($term, '/');

            if (($entry['match_mode'] ?? 'case_sensitive') === 'case_insensitive') {
                $lower = mb_strtolower($term);
                $ciMap[$lower] = $phonetic;
                $ciTermMap[$lower] = $term;
                $fragment = '(?i:'.$quoted.')';
            } else {
                $exactMap[$term] = $phonetic;
                $fragment = $quoted;
            }

            if (! isset($seen[$fragment])) {
                $seen[$fragment] = true;
                $fragments[] = $fragment;
            }
        }

        if ($fragments === []) {
            return ['text' => $text, 'applied' => []];
        }

        // No global /i flag — case sensitivity is per-fragment via (?i:…).
        $pattern = '/(?<!\w)('.implode('|', $fragments).')(?!\w)/u';

        $applied = [];
        $result = preg_replace_callback($pattern, function (array $m) use (&$applied, $exactMap, $ciMap, $ciTermMap) {
            $surface = $m[1];

            if (array_key_exists($surface, $exactMap)) {
                $applied[$surface] = true;

                return $exactMap[$surface];
            }

            $lower = mb_strtolower($surface);
            if (array_key_exists($lower, $ciMap)) {
                $applied[$ciTermMap[$lower]] = true;

                return $ciMap[$lower];
            }

            return $surface; // unreachable: every alternative lives in one of the maps
        }, $text);

        return [
            'text' => $result ?? $text,
            'applied' => array_keys($applied),
        ];
    }
}
