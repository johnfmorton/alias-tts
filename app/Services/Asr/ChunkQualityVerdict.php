<?php

namespace App\Services\Asr;

/**
 * The outcome of scoring one generated chunk against its source text via the
 * ASR round-trip. `problems` is a list drawn from:
 *
 *   TRUNC      transcript did not reach the end of the script (missing content)
 *   TAIL       audio continues well past the last recognized word (junk tail)
 *   PAUSE      a large silent gap between two recognized words (mid-stream pause)
 *   NOSPEECH   no words recognized at all
 *   TAILNOISE  a loud tail too short to trip TAIL (energy-based; lossless-trimmed)
 *   BNDNOISE   a tonal hum filling a punctuation-boundary gap (energy-based; re-rolled)
 *
 * `ok` is true only when `problems` is empty. `trimAtMs` is the suggested cut
 * point (ms) for a TAIL/TAILNOISE trim, derived from the last word end; null
 * otherwise. `tailPeakDbfs` is the measured tail loudness (null when no audio
 * features were available); `boundaryNoise` describes the offending boundary gap
 * when BNDNOISE fired.
 */
final class ChunkQualityVerdict
{
    /**
     * @param  list<string>  $problems
     * @param  array{gap_s: float, dbfs: float, zcr_hz: float, after: string}|null  $boundaryNoise
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $problems,
        public readonly float $score,
        public readonly float $trailS,
        public readonly float $maxGapS,
        public readonly float $tailCov,
        public readonly int $wordCount,
        public readonly ?int $trimAtMs,
        public readonly ?float $tailPeakDbfs = null,
        public readonly ?array $boundaryNoise = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'ok' => $this->ok,
            'problems' => $this->problems,
            'score' => $this->score,
            'trail_s' => $this->trailS,
            'max_gap_s' => $this->maxGapS,
            'tail_cov' => $this->tailCov,
            'word_count' => $this->wordCount,
            'trim_at_ms' => $this->trimAtMs,
        ];

        // Additive: only present when energy features were measured / a hum fired,
        // so existing reports/badges are unaffected.
        if ($this->tailPeakDbfs !== null) {
            $out['tail_peak_dbfs'] = $this->tailPeakDbfs;
        }
        if ($this->boundaryNoise !== null) {
            $out['boundary_noise'] = $this->boundaryNoise;
        }

        return $out;
    }
}
