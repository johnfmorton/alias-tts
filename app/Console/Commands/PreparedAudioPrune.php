<?php

namespace App\Console\Commands;

use App\Services\Audio\PreparedChunkCache;
use Illuminate\Console\Command;

/**
 * Prune the local prepared-chunk cache ({@see PreparedChunkCache}). Every hit
 * touches its file, so anything untouched for tts.prepared_cache.days belongs
 * to a take no build has stitched in that long — a superseded take, a deleted
 * project, or audio prepared under since-changed trim settings. Deleting is
 * always safe: a later build just prepares the chunk again. Wire it to the
 * scheduler (routes/console.php).
 */
class PreparedAudioPrune extends Command
{
    protected $signature = 'audio:prune-prepared
                            {--dry-run : Report what would be deleted without deleting anything}
                            {--all : Empty the cache regardless of age}';

    protected $description = 'Delete prepared chunk audio unused past the retention window';

    public function handle(PreparedChunkCache $cache): int
    {
        $days = $this->option('all') ? 0 : (int) config('tts.prepared_cache.days', 14);
        $dryRun = (bool) $this->option('dry-run');

        $result = $cache->prune($days, $dryRun);

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info(sprintf(
            '%s %d prepared file(s) (%s bytes) under %s.',
            $verb,
            $result['files'],
            number_format($result['bytes']),
            $cache->root(),
        ));

        return self::SUCCESS;
    }
}
