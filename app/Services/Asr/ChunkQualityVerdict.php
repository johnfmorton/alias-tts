<?php

namespace App\Services\Asr;

/**
 * The outcome of scoring one generated chunk against its source text via the
 * ASR round-trip. `problems` is a list drawn from:
 *
 *   TRUNC     transcript did not reach the end of the script (missing content)
 *   TAIL      audio continues well past the last recognized word (junk tail)
 *   PAUSE     a large silent gap between two recognized words (mid-stream pause)
 *   NOSPEECH  no words recognized at all
 *
 * `ok` is true only when `problems` is empty. `trimAtMs` is the suggested cut
 * point (ms) for a TAIL trim, derived from the last word end; null otherwise.
 */
final class ChunkQualityVerdict
{
    /**
     * @param  list<string>  $problems
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
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'problems' => $this->problems,
            'score' => $this->score,
            'trail_s' => $this->trailS,
            'max_gap_s' => $this->maxGapS,
            'tail_cov' => $this->tailCov,
            'word_count' => $this->wordCount,
            'trim_at_ms' => $this->trimAtMs,
        ];
    }
}
