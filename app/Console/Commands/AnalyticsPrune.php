<?php

namespace App\Console\Commands;

use App\Models\AppEvent;
use Illuminate\Console\Command;

/**
 * Prune first-party analytics rows (app_events) past the retention window.
 * Page views dominate the row count, and /admin/insights aggregates a 30-day
 * window — history beyond tts.analytics.retention_days is pure weight. The
 * volume/spend truth is never here (that's credit_transactions), so pruning
 * loses only old event detail. Wire it to the scheduler (routes/console.php).
 */
class AnalyticsPrune extends Command
{
    protected $signature = 'analytics:prune {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete app_events rows past the analytics retention window';

    public function handle(): int
    {
        $days = (int) config('tts.analytics.retention_days', 180);

        if ($days <= 0) {
            $this->info('Analytics retention is disabled (0 days) — keeping everything.');

            return self::SUCCESS;
        }

        $stale = AppEvent::where('created_at', '<', now()->subDays($days));

        if ($this->option('dry-run')) {
            $this->info($stale->count().' event(s) would be pruned.');

            return self::SUCCESS;
        }

        $deleted = 0;
        // Bounded deletes so a long-neglected table can't lock up in one statement.
        while (($batch = (clone $stale)->limit(500)->pluck('id'))->isNotEmpty()) {
            $deleted += AppEvent::whereKey($batch->all())->delete();
        }

        $this->info("Pruned {$deleted} event(s).");

        return self::SUCCESS;
    }
}
