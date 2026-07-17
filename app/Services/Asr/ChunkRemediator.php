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

            // Sound-tag awareness (tag-stripped expected text + suppressed
            // duration/energy signals on tagged chunks) lives INSIDE the
            // scorer, so the raw source text goes through as-is.
            return $this->scorer->score($sourceText, $transcript, $this->energyFeatures($bytes, $transcript));
        } catch (Throwable $e) {
            Log::warning('ASR QA failed', ['label' => $label, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Per-chunk audio energy features for the scorer's TAILNOISE / BNDNOISE
     * signals, measured from the same bytes that were transcribed and aligned to
     * the transcript word timings. Returns null on any failure so the scorer
     * simply falls back to the duration-only signals.
     *
     * @param  array{duration?: float, words?: array<int, array<string, mixed>>}  $transcript
     * @return array{speech_dbfs: float|null, tail_peak_dbfs: float|null, gaps: array<int, array{dur_s: float, mean_dbfs: float, zcr_hz: float}>}|null
     */
    private function energyFeatures(string $bytes, array $transcript): ?array
    {
        $words = array_values((array) ($transcript['words'] ?? []));
        if ($words === []) {
            return null;
        }

        try {
            return $this->converter->analyzeChunkEnergy($bytes, $words, (float) ($transcript['duration'] ?? 0.0));
        } catch (Throwable $e) {
            Log::warning('ASR energy analysis failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Problems whose only fix is a fresh take: missing/garbled content
     * (TRUNC/PAUSE/NOSPEECH) and a mid-stream boundary hum (BNDNOISE) — none can
     * be trimmed because the defect sits inside the speech.
     */
    private const REROLL_PROBLEMS = ['TRUNC', 'PAUSE', 'NOSPEECH', 'BNDNOISE'];

    /** Problems that are junk strictly after the speech, so a precise trim fixes them. */
    private const TRIMMABLE_PROBLEMS = ['TAIL', 'TAILNOISE'];

    /**
     * Remediate a flagged take (action=auto). For re-roll-class problems
     * ({@see REROLL_PROBLEMS}) re-roll via $resynthesize up to max_rerolls,
     * keeping the best-coverage take and stopping on the first clean one; when
     * every candidate scores at or below the flagged take the outcome is
     * 'kept' (original bytes, nothing to apply). For a trim-only take
     * (TAIL/TAILNOISE), precise-trim at the ASR speech end (no re-roll). A
     * re-rolled take that ends up trim-only is trimmed too.
     *
     * When $allowReroll is false (a MANUAL re-roll — the user asked for exactly one
     * fresh take) re-rolling is suppressed: a junk tail is still precise-trimmed
     * (lossless, strictly after the speech), but a defect that would need a fresh
     * take is left untouched (action='none').
     *
     * @param  callable(): string  $resynthesize  produces a fresh take (fresh seed)
     */
    public function remediate(string $sourceText, string $bytes, ChunkQualityVerdict $verdict, callable $resynthesize, string $label = 'chunk', bool $allowReroll = true): RemediationOutcome
    {
        $needsReroll = array_intersect(self::REROLL_PROBLEMS, $verdict->problems) !== [];

        if (! $needsReroll) {
            // Not ok and not reroll-class ⇒ a trimmable tail (TAIL/TAILNOISE) only.
            return $this->trim($bytes, $verdict, 0);
        }

        if (! $allowReroll) {
            // Manual re-roll: never auto-re-roll again. The defect sits inside the
            // speech (not a trimmable tail), so keep the take the user asked for.
            return new RemediationOutcome($bytes, $verdict, 'none');
        }

        $bestBytes = $bytes;
        $bestVerdict = $verdict;
        $recovered = false;
        $improved = false;
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
                $improved = true;
            }
            $recovered = $candidateVerdict->ok;
        }

        // No candidate beat the flagged take — keep-best fell back to the
        // original bytes. A distinct action so callers know nothing changed:
        // recording "the remediated take" would just duplicate the flagged one.
        if (! $improved) {
            return new RemediationOutcome($bytes, $verdict, 'kept', $attempts);
        }

        // If the winning take's only remaining problems are junk tails, trim them.
        if (! $bestVerdict->ok
            && array_diff($bestVerdict->problems, self::TRIMMABLE_PROBLEMS) === []
            && $bestVerdict->trimAtMs !== null) {
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
