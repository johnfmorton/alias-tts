<?php

namespace App\Services\Asr;

/**
 * The result of {@see ChunkRemediator::remediate()}: the final audio bytes to
 * keep, the verdict for that take (null when the sidecar dropped mid-loop and
 * the take couldn't be re-scored), what was done, and the details needed to
 * annotate a persisted asr_report.
 */
final class RemediationOutcome
{
    /**
     * @param  list<string>  $fixedProblems  the problem code(s) on the flagged take that this
     *                                       fix resolved (empty when nothing was applied)
     */
    public function __construct(
        public readonly string $bytes,
        public readonly ?ChunkQualityVerdict $verdict,
        public readonly string $action,        // none|kept|rerolled|rerolled_unrecovered|trimmed|trim_failed|unscored
        public readonly int $rerollAttempts = 0,
        public readonly ?int $trimmedToMs = null,
        public readonly array $fixedProblems = [],
    ) {}

    /**
     * Fields to merge into a persisted asr_report so the surface (Studio / health)
     * shows what action was taken.
     *
     * @return array<string, mixed>
     */
    public function reportExtra(): array
    {
        $extra = ['action' => $this->action];
        if ($this->rerollAttempts > 0) {
            $extra['reroll_attempts'] = $this->rerollAttempts;
        }
        if ($this->trimmedToMs !== null) {
            $extra['trimmed_to_ms'] = $this->trimmedToMs;
        }
        // A recovered re-roll persists the NEW (clean) take's verdict, so its own
        // `problems` are empty — record what the fix actually resolved so the QA
        // badge can still name the original defect ("fixed a possible cut-off").
        if ($this->fixedProblems !== []) {
            $extra['fixed_problems'] = array_values($this->fixedProblems);
        }

        return $extra;
    }
}
