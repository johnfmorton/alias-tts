<?php

namespace App\Services\Asr;

/**
 * Scores one generated chunk against its source text using a Whisper transcript
 * with word-level timestamps (from {@see AsrClient::transcribe()}). Pure: no
 * network, no DB — just the transcript payload + the source string, so it's
 * fully unit-testable from canned word arrays.
 *
 * Three signals separate good takes from Chatterbox's failure modes (thresholds
 * validated on labeled samples; see docs/ASR-SETUP.md):
 *
 *   trail_s   seconds of audio AFTER the last recognized word — catches tonal,
 *             speech-like, and "ghostly singing" tails the DSP trim misses.
 *   max_gap_s largest gap between consecutive words — catches mid-stream pauses.
 *   tail_cov  how far into the SOURCE the transcript reached (via word-level
 *             alignment) — catches TRUNCATION, which has NO acoustic artifact
 *             because the audio simply stops before the script ends.
 *
 * When per-chunk audio energy features are supplied ({@see AudioConverter::analyzeChunkEnergy},
 * wired in by {@see ChunkRemediator}), two further signals add extra scrutiny at
 * the zones where Chatterbox anomalies cluster — the tail and sentence/comma
 * boundaries — catching SHORT-but-LOUD junk the duration signals above miss:
 *
 *   TAILNOISE a loud "swoosh" right after the last word, too short to trip
 *             trail_s. Lossless-trimmed (the junk is entirely past the speech).
 *   BNDNOISE  a tonal "hum" filling a punctuation-boundary gap that is too short
 *             to trip max_gap_s. Mid-stream, so it can only be re-rolled.
 */
class ChunkQualityScorer
{
    /**
     * @param  array{speech_dbfs?: float|null, tail_peak_dbfs?: float|null, gaps?: array<int, array{dur_s: float, mean_dbfs: float, zcr_hz: float}>}|null  $audio
     *                                                                                                                                                             per-chunk energy features; null skips the energy signals (duration signals still apply)
     */
    public function score(string $sourceText, array $transcript, ?array $audio = null): ChunkQualityVerdict
    {
        $words = array_values((array) ($transcript['words'] ?? []));
        $duration = (float) ($transcript['duration'] ?? 0.0);

        // No recognized speech at all → garbage / truncation; always a problem.
        if ($words === []) {
            return new ChunkQualityVerdict(
                ok: false,
                problems: ['NOSPEECH'],
                score: 0.0,
                trailS: round($duration, 3),
                maxGapS: 0.0,
                tailCov: 0.0,
                wordCount: 0,
                trimAtMs: null,
            );
        }

        $trailSMax = (float) config('tts.asr.trail_s_max', 1.2);
        $gapSMax = (float) config('tts.asr.gap_s_max', 1.5);
        $tailCovMin = (float) config('tts.asr.tail_cov_min', 0.93);
        $guardMs = (int) config('tts.asr.trim_guard_ms', 80);

        // trailS: silence/junk after the last recognized word. Whisper does not
        // transcribe tones/hiss/most singing, so a long tail shows up here.
        $lastEnd = (float) ($words[count($words) - 1]['end'] ?? 0.0);
        $trailS = max(0.0, $duration - $lastEnd);

        // maxGap: the largest gap between two consecutive recognized words.
        $maxGap = 0.0;
        for ($i = 0, $n = count($words) - 1; $i < $n; $i++) {
            $gap = (float) ($words[$i + 1]['start'] ?? 0.0) - (float) ($words[$i]['end'] ?? 0.0);
            if ($gap > $maxGap) {
                $maxGap = $gap;
            }
        }

        $srcWords = $this->normalizeWords($sourceText);
        $hypWords = [];
        foreach ($words as $w) {
            foreach ($this->normalizeWords((string) ($w['word'] ?? '')) as $nw) {
                $hypWords[] = $nw;
            }
        }

        // Word-level alignment via longest common subsequence. `matched` is the
        // overall coverage; `lastSrcIdx` is the highest SOURCE index that has an
        // in-order match — if the END of the script never matches, the tail of
        // the source is missing (truncation).
        [$matched, $lastSrcIdx] = $this->align($srcWords, $hypWords);
        $srcCount = count($srcWords);
        $score = $srcCount === 0 ? 1.0 : $matched / $srcCount;
        $tailCov = $srcCount === 0 ? 1.0 : ($lastSrcIdx + 1) / $srcCount;

        $problems = [];
        if ($tailCov < $tailCovMin) {
            $problems[] = 'TRUNC';
        }
        if ($trailS > $trailSMax) {
            $problems[] = 'TAIL';
        }
        if ($maxGap > $gapSMax) {
            $problems[] = 'PAUSE';
        }

        // Energy-aware signals (only when audio features were supplied). They add
        // scrutiny at the tail and at punctuation boundaries — catching short,
        // loud junk the duration thresholds above sail past.
        $speechDbfs = $audio !== null && ($audio['speech_dbfs'] ?? null) !== null ? (float) $audio['speech_dbfs'] : null;
        $tailPeakDbfs = $audio !== null ? ($audio['tail_peak_dbfs'] ?? null) : null;
        if ($tailPeakDbfs !== null && $this->isTailNoise((float) $tailPeakDbfs, $speechDbfs)) {
            $problems[] = 'TAILNOISE';
        }

        $boundaryNoise = $this->detectBoundaryNoise($words, $audio);
        if ($boundaryNoise !== null) {
            $problems[] = 'BNDNOISE';
        }

        // TAIL and TAILNOISE are both junk strictly after the speech, so a precise
        // cut at the last word end (+ guard) removes them losslessly.
        $trimAtMs = array_intersect(['TAIL', 'TAILNOISE'], $problems) !== []
            ? (int) round(($lastEnd + $guardMs / 1000) * 1000)
            : null;

        return new ChunkQualityVerdict(
            ok: $problems === [],
            problems: $problems,
            score: round($score, 3),
            trailS: round($trailS, 3),
            maxGapS: round($maxGap, 3),
            tailCov: round($tailCov, 3),
            wordCount: count($words),
            trimAtMs: $trimAtMs,
            tailPeakDbfs: $tailPeakDbfs !== null ? round((float) $tailPeakDbfs, 1) : null,
            boundaryNoise: $boundaryNoise,
            speechDbfs: $speechDbfs !== null ? round($speechDbfs, 1) : null,
        );
    }

    /**
     * A loud tail is junk (TAILNOISE) only when it is BOTH above an absolute floor
     * AND louder than the chunk's OWN speech by a margin.
     *
     * The relative gate is the guard against a false positive that would DESTROY
     * content. Whisper systematically UNDER-times a soft final coda — a voiced
     * nasal like the "n" in "2019"→"nineteen" can even come back as a zero-duration
     * word — so the real, still-sounding word extends past the release window and
     * looks like a loud tail; trimming it then clips the word. But a word's own
     * coda is never louder than the word body, whereas a genuine swoosh artifact is
     * (the canonical bad take peaked ~9 dB ABOVE speech). Requiring the tail to
     * exceed the speech level spares the coda while still catching the swoosh.
     */
    private function isTailNoise(float $tailPeak, ?float $speech): bool
    {
        if ($tailPeak <= (float) config('tts.asr.tail_energy_dbfs_max', -38)) {
            return false; // absolutely quiet — never act (don't trim quiet chunks)
        }

        if ($speech === null) {
            return true; // no speech reference: fall back to the absolute floor
        }

        return $tailPeak > $speech + (float) config('tts.asr.tail_over_speech_db', 6);
    }

    /**
     * A tonal "hum" filling a gap that FOLLOWS sentence/clause punctuation: a
     * boundary gap whose inset core is both not-silent (mean dBFS above the
     * threshold) AND tonal/low-frequency (ZCR below the ceiling). Pure energy
     * cannot separate the hum from speech residue — it is genuinely quiet — but a
     * boundary gap should be near-silent, and a hum's low ZCR distinguishes it
     * from the broadband residue in a normal short gap. Returns the offending
     * gap's measurements, or null if none qualifies.
     *
     * @param  array<int, array<string, mixed>>  $words  transcript words (carry punctuation)
     * @param  array{gaps?: array<int, array{dur_s: float, mean_dbfs: float, zcr_hz: float}>}|null  $audio
     * @return array{gap_s: float, dbfs: float, zcr_hz: float, after: string}|null
     */
    private function detectBoundaryNoise(array $words, ?array $audio): ?array
    {
        $gaps = $audio['gaps'] ?? null;
        if (! is_array($gaps) || $gaps === []) {
            return null;
        }

        $energyMax = (float) config('tts.asr.boundary_energy_dbfs_max', -55);
        $zcrMax = (float) config('tts.asr.boundary_zcr_max_hz', 1500);

        foreach ($gaps as $i => $g) {
            $before = (string) ($words[$i]['word'] ?? '');
            if (! $this->endsAtBoundary($before)) {
                continue; // the gap is mid-clause, not a sentence/comma boundary
            }

            if ((float) $g['mean_dbfs'] > $energyMax && (float) $g['zcr_hz'] < $zcrMax) {
                return [
                    'gap_s' => (float) $g['dur_s'],
                    'dbfs' => (float) $g['mean_dbfs'],
                    'zcr_hz' => (float) $g['zcr_hz'],
                    'after' => trim($before),
                ];
            }
        }

        return null;
    }

    /** Whether a transcript word ends at a sentence/clause boundary (. , ; : ! ? …). */
    private function endsAtBoundary(string $word): bool
    {
        return preg_match('/[.,;:!?…]["\')\]]*\s*$/u', $word) === 1;
    }

    /**
     * Lowercase, drop punctuation (keep apostrophes), split on whitespace.
     *
     * @return list<string>
     */
    private function normalizeWords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = str_replace(['’', '‘', '`'], "'", $text);
        $text = preg_replace("/[^a-z0-9'\\s]/u", ' ', $text) ?? '';

        return array_values(array_filter(
            preg_split('/\s+/', trim($text)) ?: [],
            static fn (string $w): bool => $w !== '',
        ));
    }

    /**
     * Longest common subsequence over two word lists.
     *
     * @param  list<string>  $a  source words
     * @param  list<string>  $b  transcript words
     * @return array{0:int,1:int} [LCS length, highest source index in the LCS (or -1)]
     */
    private function align(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0) {
            return [0, -1];
        }

        // Word counts are per-chunk (tens of words), so a full DP table is cheap.
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        // Backtrack from (n, m); the first diagonal match we hit is the LAST word
        // of the LCS, so its source index is the highest one that matched in order.
        $lastSrcIdx = -1;
        $i = $n;
        $j = $m;
        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                $lastSrcIdx = $i - 1;
                break;
            }
            if ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return [$dp[$n][$m], $lastSrcIdx];
    }
}
