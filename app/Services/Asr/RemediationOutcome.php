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
    public function __construct(
        public readonly string $bytes,
        public readonly ?ChunkQualityVerdict $verdict,
        public readonly string $action,        // none|rerolled|rerolled_unrecovered|trimmed|trim_failed|unscored
        public readonly int $rerollAttempts = 0,
        public readonly ?int $trimmedToMs = null,
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

        return $extra;
    }
}
