<?php

namespace App\Console\Commands;

use App\Models\TtsProject;
use App\Services\ProjectService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Prune auto-created recovery projects (origin=api_failure, from
 * tts.api_project_mode=on_error) that have expired without anyone touching them
 * — i.e. no chunk was ever generated. Once an admin starts repairing one (any
 * chunk has audio) it's kept regardless of TTL. 'always'-mode (origin=api) and
 * panel-made projects have no expires_at and are never pruned. There is no other
 * project cleanup, so without this they'd accumulate. Wire it to the scheduler
 * (see routes/console.php).
 */
class PruneRecoveryProjects extends Command
{
    protected $signature = 'projects:prune-recovery
                            {--dry-run : Report what would be deleted without deleting anything}
                            {--before= : Cutoff (anything strtotime understands); defaults to now}';

    protected $description = 'Delete expired, untouched API-failure recovery projects (DB rows + stored audio)';

    public function handle(ProjectService $projects): int
    {
        $cutoff = $this->option('before') ? Carbon::parse($this->option('before')) : Carbon::now();
        $dryRun = (bool) $this->option('dry-run');

        $query = TtsProject::query()
            ->where('origin', 'api_failure')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoff)
            // "Untouched": no chunk was ever generated. Once an admin has started
            // repairing it (any chunk holds audio), keep it regardless of TTL.
            ->whereDoesntHave('chunks', fn ($q) => $q->whereNotNull('audio_path'));

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info("No expired, untouched recovery projects before {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $processed = 0;

        // chunkById is delete-safe: paging by id means removing rows mid-iteration
        // doesn't disturb the cursor.
        $query->orderBy('id')->chunkById(200, function ($rows) use (&$processed, $dryRun, $projects) {
            foreach ($rows as $project) {
                if (! $dryRun) {
                    $projects->deleteProject($project);
                }

                $processed++;
            }
        });

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$processed} expired, untouched recovery project(s).");

        return self::SUCCESS;
    }
}
