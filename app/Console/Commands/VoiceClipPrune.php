<?php

namespace App\Console\Commands;

use App\Services\VoiceClipService;
use Illuminate\Console\Command;

/**
 * Prune expired prepared reference clips (rows + staged files under
 * tts.voice_clip_path). A prepared clip lives for tts.enhance.clip_ttl_hours;
 * one that's saved is consumed immediately (single-use), so this only clears
 * previews the user abandoned. Wire it to the scheduler (routes/console.php).
 */
class VoiceClipPrune extends Command
{
    protected $signature = 'voices:prune-clips {--dry-run : Report the expired count without deleting anything}';

    protected $description = 'Delete expired prepared reference clips (staging rows + files)';

    public function handle(VoiceClipService $clips): int
    {
        if ($this->option('dry-run')) {
            $count = \App\Models\VoiceClip::where('expires_at', '<=', now())->count();
            $this->info("{$count} expired prepared clip(s) would be pruned.");

            return self::SUCCESS;
        }

        $removed = $clips->pruneExpired();
        $this->info("Pruned {$removed} expired prepared clip(s).");

        return self::SUCCESS;
    }
}
