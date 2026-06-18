<?php

namespace App\Console\Commands;

use App\Enums\HealthStatus;
use App\Services\Health\HealthCheckResult;
use App\Services\Health\HealthReport;
use Illuminate\Console\Command;

/**
 * CLI view over {@see HealthReport}: confirms the service is configured
 * correctly (PHP, database, ffmpeg, storage, provider, queue, scheduler, app
 * config), prints PASS / WARN / FAIL per check, and exits non-zero if anything
 * FAILs — so it's usable in CI or a deploy script. The checks themselves live
 * in HealthReport so the admin health page runs the exact same logic.
 */
class TtsDoctor extends Command
{
    protected $signature = 'tts:doctor {--deep : Also make live calls (validate the Replicate token, probe the queue)}';

    protected $description = 'Check that the TTS service is configured correctly (ffmpeg, storage, provider, queue, scheduler)';

    public function handle(HealthReport $report): int
    {
        $results = $report->run((bool) $this->option('deep'));

        $this->renderResults($results);

        $fails = count(array_filter($results, fn (HealthCheckResult $r) => $r->isFailure()));

        return $fails > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, HealthCheckResult>  $results
     */
    private function renderResults(array $results): void
    {
        $this->newLine();

        foreach ($results as $result) {
            $badge = match ($result->status) {
                HealthStatus::Pass => '<fg=black;bg=green> PASS </>',
                HealthStatus::Warn => '<fg=black;bg=yellow> WARN </>',
                HealthStatus::Fail => '<fg=white;bg=red> FAIL </>',
            };

            $this->line("{$badge} <options=bold>{$result->label}</> — {$result->detail}");
        }

        $count = fn (HealthStatus $s) => count(array_filter($results, fn (HealthCheckResult $r) => $r->status === $s));

        $this->newLine();
        $this->line(sprintf(
            'Summary: <fg=green>%d pass</>, <fg=yellow>%d warn</>, <fg=red>%d fail</>',
            $count(HealthStatus::Pass),
            $count(HealthStatus::Warn),
            $count(HealthStatus::Fail),
        ));
    }
}
