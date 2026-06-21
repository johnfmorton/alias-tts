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
 */
class ChunkQualityScorer
{
    public function score(string $sourceText, array $transcript): ChunkQualityVerdict
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

        $trimAtMs = in_array('TAIL', $problems, true)
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
        );
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
