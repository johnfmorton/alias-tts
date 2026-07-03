<?php

namespace App\Console\Commands;

use App\Models\Speech;
use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
use App\Services\SpeechService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Delete expired generated audio. Each Speech row carries an `expires_at`
 * (set from tts.ttl_hours); once past it the cached audio is never reused, so
 * this removes both the database row and the stored file. Because storage goes
 * through Laravel's disk abstraction, this cleans the configured disk whether
 * that's `local` or `s3`. Wire it to the scheduler (see routes/console.php).
 *
 * `--orphans` additionally sweeps files under the speech storage path
 * (tts.storage_path) that no database row references — leftovers from crashed
 * jobs or rows removed outside the app. The sweep never leaves that prefix, so
 * voices/, avatars/, genblaze/, and anything else sharing the disk are never
 * touched, and it skips files younger than `--orphan-age` hours so an
 * in-flight generation that has written its file but not yet committed its row
 * is safe.
 */
class SpeechCleanup extends Command
{
    protected $signature = 'speech:cleanup
                            {--dry-run : Report what would be deleted without deleting anything}
                            {--before= : Cutoff date/time (anything strtotime understands); defaults to now}
                            {--orphans : Also delete files under the speech storage path that no database row references}
                            {--orphan-age=24 : Minimum age in hours before an unreferenced file counts as an orphan}';

    protected $description = 'Delete expired generated audio (DB rows + files on the configured disk, including S3); --orphans also sweeps unreferenced files';

    public function handle(SpeechService $speech): int
    {
        $cutoff = $this->option('before') ? Carbon::parse($this->option('before')) : Carbon::now();
        $dryRun = (bool) $this->option('dry-run');
        $diskName = (string) config('tts.storage_disk');
        $disk = Storage::disk($diskName);

        $this->cleanExpired($speech, $disk, $diskName, $cutoff, $dryRun);

        if ($this->option('orphans')) {
            $this->sweepOrphans($disk, $diskName, $dryRun);
        }

        return self::SUCCESS;
    }

    private function cleanExpired(SpeechService $speech, Filesystem $disk, string $diskName, Carbon $cutoff, bool $dryRun): void
    {
        $query = Speech::where('expires_at', '<', $cutoff);

        if ((clone $query)->count() === 0) {
            $this->info("No expired speech records before {$cutoff->toDateTimeString()} (disk \"{$diskName}\").");

            return;
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
    }

    private function sweepOrphans(Filesystem $disk, string $diskName, bool $dryRun): void
    {
        $root = trim((string) config('tts.storage_path', 'speech'), '/');
        $minAgeHours = max(0, (float) $this->option('orphan-age'));
        $modifiedBefore = Carbon::now()->subHours($minAgeHours)->getTimestamp();

        $known = $this->referencedPaths();

        $orphans = 0;
        $bytes = 0;
        $sparedFresh = 0;

        foreach ($disk->allFiles($root) as $file) {
            if (isset($known[$file])) {
                continue;
            }

            if ((int) $disk->lastModified($file) > $modifiedBefore) {
                $sparedFresh++;

                continue;
            }

            $bytes += (int) $disk->size($file);

            if (! $dryRun) {
                $disk->delete($file);
            }

            $orphans++;
            $this->line(($dryRun ? 'Would delete orphan: ' : 'Deleted orphan: ').$file, verbosity: 'v');
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$orphans} orphaned file(s) under \"{$root}/\" on disk \"{$diskName}\".");
        $this->table(['Metric', 'Value'], [
            ['Orphaned files', $orphans],
            ['Approx. bytes freed', number_format($bytes)],
            ['Spared (younger than '.$minAgeHours.'h)', $sparedFresh],
            ['Mode', $dryRun ? 'dry-run (nothing deleted)' : 'deleted'],
        ]);
    }

    /**
     * Every file path some database row still points at, as a set. Anything
     * under the speech storage path NOT in here has no owner. Sealed audio is
     * listed separately from the final because sealing snapshots the bytes to
     * their own immutable file.
     *
     * @return array<string, true>
     */
    private function referencedPaths(): array
    {
        $known = [];

        $sources = [
            Speech::query()->whereNotNull('audio_path')->toBase()->select('audio_path'),
            TtsChunk::query()->whereNotNull('audio_path')->toBase()->select('audio_path'),
            TtsChunkTake::query()->toBase()->select('audio_path'),
            TtsProject::query()->whereNotNull('final_audio_path')->toBase()->select('final_audio_path as audio_path'),
            TtsProject::query()->whereNotNull('sealed_audio_path')->toBase()->select('sealed_audio_path as audio_path'),
        ];

        foreach ($sources as $query) {
            foreach ($query->cursor() as $row) {
                $known[ltrim((string) $row->audio_path, '/')] = true;
            }
        }

        return $known;
    }
}
