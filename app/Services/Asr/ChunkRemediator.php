<?php

namespace App\Services\Asr;

use App\Services\Audio\AudioConverter;
use App\Services\ProjectService;
use App\Services\SpeechService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared ASR remediation used by both generation paths (the editable-project
 * {@see ProjectService} and the synchronous/queued
 * {@see SpeechService}). Operates purely on audio bytes so each
 * caller can persist/log the result however it needs:
 *
 *   - {@see score()} transcribes bytes and scores them against the source text.
 *   - {@see remediate()} acts on a flagged verdict under action=auto: re-roll
 *     missing-content failures (keeping the best-coverage take) or precise-trim
 *     a junk tail.
 *
 * Everything degrades safely — a down sidecar yields a null verdict ("skip QA")
 * and a failed trim keeps the untrimmed bytes.
 */
class ChunkRemediator
{
    public function __construct(
        private AsrClient $asr,
        private ChunkQualityScorer $scorer,
        private AudioConverter $converter,
    ) {}

    /**
     * Transcribe + score bytes against the source text. Returns null (skip QA)
     * when the sidecar is unavailable or errors.
     */
    public function score(string $sourceText, string $bytes, string $label = 'chunk'): ?ChunkQualityVerdict
    {
        try {
            $transcript = $this->asr->transcribe($bytes, "{$label}.wav");
            if ($transcript === null) {
                return null;
            }

            return $this->scorer->score($sourceText, $transcript);
        } catch (Throwable $e) {
            Log::warning('ASR QA failed', ['label' => $label, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Remediate a flagged take (action=auto). For missing content
     * (TRUNC/PAUSE/NOSPEECH) re-roll via $resynthesize up to max_rerolls,
     * keeping the best-coverage take and stopping on the first clean one; for a
     * TAIL-only take, precise-trim at the ASR speech end (no re-roll). A re-rolled
     * take that ends up TAIL-only is trimmed too.
     *
     * @param  callable(): string  $resynthesize  produces a fresh take (fresh seed)
     */
    public function remediate(string $sourceText, string $bytes, ChunkQualityVerdict $verdict, callable $resynthesize, string $label = 'chunk'): RemediationOutcome
    {
        $needsReroll = array_intersect(['TRUNC', 'PAUSE', 'NOSPEECH'], $verdict->problems) !== [];

        if (! $needsReroll) {
            // Not ok and not reroll-class ⇒ TAIL only.
            return $this->trim($bytes, $verdict, 0);
        }

        $bestBytes = $bytes;
        $bestVerdict = $verdict;
        $recovered = false;
        $attempts = 0;
        $max = $this->asr->maxRerolls();

        for ($i = 1; $i <= $max && ! $recovered; $i++) {
            try {
                $candidate = $resynthesize();
            } catch (Throwable $e) {
                Log::warning('ASR auto re-roll synthesis failed', ['label' => $label, 'attempt' => $i, 'error' => $e->getMessage()]);
                break;
            }

            $attempts = $i;
            $candidateVerdict = $this->score($sourceText, $candidate, $label);

            // Can't score the take (sidecar dropped mid-loop): accept it and stop.
            if ($candidateVerdict === null) {
                Log::warning('ASR re-roll could not be scored (sidecar down); kept the new take', ['label' => $label]);

                return new RemediationOutcome($candidate, null, 'unscored', $i);
            }

            if ($candidateVerdict->ok || $candidateVerdict->score > $bestVerdict->score) {
                $bestBytes = $candidate;
                $bestVerdict = $candidateVerdict;
            }
            $recovered = $candidateVerdict->ok;
        }

        // If the winning take's only remaining problem is a junk tail, trim it.
        if (! $bestVerdict->ok && $bestVerdict->problems === ['TAIL'] && $bestVerdict->trimAtMs !== null) {
            return $this->trim($bestBytes, $bestVerdict, $attempts);
        }

        return new RemediationOutcome(
            $bestBytes,
            $bestVerdict,
            $recovered ? 'rerolled' : 'rerolled_unrecovered',
            $attempts,
        );
    }

    private function trim(string $bytes, ChunkQualityVerdict $verdict, int $attempts): RemediationOutcome
    {
        $trimmed = $this->converter->truncateToMs($bytes, (int) $verdict->trimAtMs);

        if ($trimmed === null) {
            return new RemediationOutcome($bytes, $verdict, 'trim_failed', $attempts);
        }

        return new RemediationOutcome($trimmed, $verdict, 'trimmed', $attempts, $verdict->trimAtMs);
    }
}
