<?php

namespace App\Console\Commands;

use App\Enums\ProjectJobStatus;
use App\Models\TtsProjectJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Prune finished background runs off the Jobs page. Completed/failed/cancelled
 * rows are pure history and accumulate forever without a cap (one project
 * collected 50+ in its first week), so two independent rules bound them: drop
 * runs older than tts.jobs_keep_days, and keep at most tts.jobs_keep_per_user
 * newest runs per owner. Active (queued/running) runs are never touched — the
 * worker owns those rows. Wire it to the scheduler (routes/console.php).
 */
class ProjectJobsPrune extends Command
{
    protected $signature = 'jobs:prune {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete finished background runs past the retention window or per-user cap';

    public function handle(): int
    {
        $days = (int) config('tts.jobs_keep_days', 7);
        $perUser = (int) config('tts.jobs_keep_per_user', 100);

        $ids = collect();

        // Age rule. The Jobs page sorts by created_at (its "Started" column),
        // so age runs by the same clock the user sees there.
        if ($days > 0) {
            $ids = $ids->merge(
                $this->finished()->where('created_at', '<', now()->subDays($days))->pluck('id'),
            );
        }

        // Per-owner cap. Owners are the user_id buckets the Jobs page scopes
        // by; NULL (an owner deleted since) is a bucket of its own.
        if ($perUser > 0) {
            $owners = $this->finished()->distinct()->pluck('user_id');
            foreach ($owners as $owner) {
                $bucket = fn (): Builder => $this->finished()->when(
                    $owner === null,
                    fn (Builder $q) => $q->whereNull('user_id'),
                    fn (Builder $q) => $q->where('user_id', $owner),
                );
                // UUIDv7 ids are time-ordered — the id tiebreak keeps same-second
                // runs deterministic.
                $keep = $bucket()->orderByDesc('created_at')->orderByDesc('id')->limit($perUser)->pluck('id');
                $ids = $ids->merge($bucket()->whereNotIn('id', $keep)->pluck('id'));
            }
        }

        $ids = $ids->unique()->values();

        if ($this->option('dry-run')) {
            $this->info("{$ids->count()} finished run(s) would be pruned.");

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($ids->chunk(500) as $chunk) {
            $deleted += TtsProjectJob::whereKey($chunk->all())->delete();
        }

        $this->info("Pruned {$deleted} finished run(s).");

        return self::SUCCESS;
    }

    /** @return Builder<TtsProjectJob> Finished (terminal) runs only — never active ones. */
    private function finished(): Builder
    {
        return TtsProjectJob::query()->whereNotIn('status', [
            ProjectJobStatus::Queued->value,
            ProjectJobStatus::Running->value,
        ]);
    }
}
