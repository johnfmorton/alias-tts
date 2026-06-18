<?php

namespace App\Console\Commands;

use App\Models\Speech;
use App\Services\SpeechService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete expired generated audio. Each Speech row carries an `expires_at`
 * (set from tts.ttl_hours); once past it the cached audio is never reused, so
 * this removes both the database row and the stored file. Because storage goes
 * through Laravel's disk abstraction, this cleans the configured disk whether
 * that's `local` or `s3`. Wire it to the scheduler (see routes/console.php).
 */
class SpeechCleanup extends Command
{
    protected $signature = 'speech:cleanup
                            {--dry-run : Report what would be deleted without deleting anything}
                            {--before= : Cutoff date/time (anything strtotime understands); defaults to now}';

    protected $description = 'Delete expired generated audio (DB rows + files on the configured disk, including S3)';

    public function handle(SpeechService $speech): int
    {
        $cutoff = $this->option('before') ? Carbon::parse($this->option('before')) : Carbon::now();
        $dryRun = (bool) $this->option('dry-run');
        $diskName = (string) config('tts.storage_disk');
        $disk = Storage::disk($diskName);

        $query = Speech::where('expires_at', '<', $cutoff);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("No expired speech records before {$cutoff->toDateTimeString()} (disk \"{$diskName}\").");

            return self::SUCCESS;
        }

        $processed = 0;
        $bytes = 0;

        // chunkById is delete-safe: it pages by id, so removing the current rows
        // doesn't disturb iteration.
        $query->orderBy('id')->chunkById(200, function ($rows) use (&$processed, &$bytes, $dryRun, $speech, $disk) {
            foreach ($rows as $row) {
                if ($row->audio_path && $disk->exists($row->audio_path)) {
                    $bytes += (int) $disk->size($row->audio_path);
                }

                if (! $dryRun) {
                    $speech->deleteSpeech($row);
                }

                $processed++;
            }
        });

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$processed} expired speech record(s) on disk \"{$diskName}\".");
        $this->table(['Metric', 'Value'], [
            ['Records', $processed],
            ['Approx. bytes freed', number_format($bytes)],
            ['Cutoff', $cutoff->toDateTimeString()],
            ['Mode', $dryRun ? 'dry-run (nothing deleted)' : 'deleted'],
        ]);

        return self::SUCCESS;
    }
}
