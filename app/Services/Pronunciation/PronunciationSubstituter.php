<?php

namespace App\Services\Pronunciation;

use App\Services\TextNormalizer;

/**
 * Applies a pronunciation dictionary to text: a find-and-replace that rewrites
 * each known `term` to its ASCII `phonetic` respelling BEFORE the text reaches
 * the TTS engine (e.g. "DDEV" => "dee dev"). Pure (no DB/IO) so it is trivially
 * testable and reusable on any code path.
 *
 * Semantics (see docs/ALIAS-PRONUNCIATION-PREPROCESSOR.md §5):
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
 *  - Dash-insensitive: every dash-like code point is folded together, so a term
 *    stored with one dash still matches text spelled with another (see
 *    {@see DASH_CLASS}). Only the dash positions are loosened; the rest of the
 *    term stays a literal, and non-matching text is never rewritten.
 *  - Replaces every occurrence.
 *
 * ASCII respelling only — the open-source Chatterbox model takes plain `prompt`
 * text (no SSML/<phoneme>/IPA), and {@see TextNormalizer} strips
 * "<…>" before this ever runs.
 */
class PronunciationSubstituter
{
    /**
     * Every dash-like code point folded together for matching: ASCII hyphen-minus
     * (U+002D), Unicode hyphen (U+2010), non-breaking hyphen (U+2011), figure
     * dash (U+2012), en dash (U+2013), em dash (U+2014), horizontal bar (U+2015)
     * and the minus sign (U+2212). LLM-suggested terms routinely carry a
     * typographic en dash ("SHA–256") that a writer's text spells with a plain
     * hyphen ("SHA-256"); without this fold the exact match would never fire.
     */
    private const DASH_CLASS = '[-\x{2010}-\x{2015}\x{2212}]';

    /**
     * Fold every dash variant to a plain ASCII hyphen. Used to canonicalize both
     * the map keys and the matched surface so either side's dash choice lines up —
     * and reused by {@see PronunciationDictionary} to store terms canonically.
     */
    public static function normalizeDashes(string $value): string
    {
        return preg_replace('/'.self::DASH_CLASS.'/u', '-', $value) ?? $value;
    }

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
        // Keyed by the dash-normalized term so a stored "SHA–256" is found by a
        // "SHA-256" surface (and vice-versa); the *TermMap keeps the original term
        // for the applied list (what the review screen matches on).
        $exactMap = [];      // normalizeDashes(term)                => phonetic
        $exactTermMap = [];  // normalizeDashes(term)                => original term
        $ciMap = [];         // mb_strtolower(normalizeDashes(term)) => phonetic
        $ciTermMap = [];     // mb_strtolower(normalizeDashes(term)) => original term

        foreach ($entries as $entry) {
            $term = (string) ($entry['term'] ?? '');
            if ($term === '') {
                continue;
            }
            $phonetic = (string) ($entry['phonetic'] ?? '');
            $key = self::normalizeDashes($term);

            if (($entry['match_mode'] ?? 'case_sensitive') === 'case_insensitive') {
                $lower = mb_strtolower($key);
                $ciMap[$lower] = $phonetic;
                $ciTermMap[$lower] = $term;
                $fragment = '(?i:'.$this->fuzzyDashPattern($term).')';
            } else {
                $exactMap[$key] = $phonetic;
                $exactTermMap[$key] = $term;
                $fragment = $this->fuzzyDashPattern($term);
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
        $result = preg_replace_callback($pattern, function (array $m) use (&$applied, $exactMap, $exactTermMap, $ciMap, $ciTermMap) {
            // Fold the matched surface's dash to line up with the normalized keys;
            // the replacement text and everything else is left exactly as written.
            $key = self::normalizeDashes($m[1]);

            if (array_key_exists($key, $exactMap)) {
                $applied[$exactTermMap[$key]] = true;

                return $exactMap[$key];
            }

            $lower = mb_strtolower($key);
            if (array_key_exists($lower, $ciMap)) {
                $applied[$ciTermMap[$lower]] = true;

                return $ciMap[$lower];
            }

            return $m[1]; // unreachable: every alternative lives in one of the maps
        }, $text);

        return [
            'text' => $result ?? $text,
            'applied' => array_keys($applied),
        ];
    }

    /**
     * A regex for $term whose every dash matches any dash variant while every
     * other character stays a literal. Built by splitting on the dash class and
     * quoting each run between dashes, so only the dash positions are loosened —
     * a term without a dash yields exactly preg_quote($term), unchanged behavior.
     */
    private function fuzzyDashPattern(string $term): string
    {
        $parts = preg_split('/'.self::DASH_CLASS.'/u', $term) ?: [$term];

        return implode(self::DASH_CLASS, array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            $parts,
        ));
    }
}
