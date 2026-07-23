<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Thin the database dumps written by `db:backup`, on a sliding retention curve
 * that keeps recent history dense and old history sparse:
 *
 *   - younger than 30 days      keep every dump
 *   - 30 to 90 days old         keep only the oldest dump on each day
 *   - 90 days to 12 months old  keep only the oldest dump in each month
 *   - older than 12 months      delete
 *
 * A dump's date comes from its directory (db_backup/YYYY/MM/DD/) — the source of
 * truth for when it was taken — and "oldest" within a day/month is decided by
 * upload time (with the filename's trailing number as a tiebreak), so this works
 * the same on a local disk or on S3/B2. Files that don't match the backup layout
 * are ignored, never deleted. Wire it to the scheduler after `db:backup`.
 */
class DatabaseBackupPrune extends Command
{
    protected $signature = 'db:prune-backups
                            {--dry-run : Report what would be deleted without deleting anything}
                            {--before= : Treat this date/time as "now" for age calculations (defaults to now)}
                            {--disk= : Storage disk to prune (defaults to tts.storage_disk)}';

    protected $description = 'Thin database backups: keep all <30d, daily 30–90d, monthly 90d–12mo, drop >12mo';

    public function handle(): int
    {
        $now = ($this->option('before')
            ? CarbonImmutable::parse((string) $this->option('before'))
            : CarbonImmutable::now())->startOfDay();

        $dryRun = (bool) $this->option('dry-run');
        $diskName = (string) ($this->option('disk') ?: config('tts.storage_disk'));
        $disk = Storage::disk($diskName);
        $root = trim((string) config('tts.backup.path', 'db_backup'), '/');

        // Band boundaries by the dump's own date.
        $keepAllCutoff = $now->subDays(30);   // >= this            → keep every dump
        $dailyCutoff = $now->subDays(90);     // [daily, keepAll)   → keep oldest per day
        $monthlyCutoff = $now->subMonths(12); // [monthly, daily)   → keep oldest per month
        // anything older than $monthlyCutoff → delete

        $files = $this->collect($disk, $root);

        if ($files === []) {
            $this->info("No backups found under \"{$root}/\" on disk \"{$diskName}\".");

            return self::SUCCESS;
        }

        $dailyGroups = [];   // 'Y-m-d' => [file, ...]
        $monthlyGroups = []; // 'Y-m'   => [file, ...]
        $deletions = [];
        $keptRecent = 0;

        foreach ($files as $file) {
            $date = $file['date'];

            if ($date->gte($keepAllCutoff)) {
                $keptRecent++;
            } elseif ($date->gte($dailyCutoff)) {
                $dailyGroups[$date->format('Y-m-d')][] = $file;
            } elseif ($date->gte($monthlyCutoff)) {
                $monthlyGroups[$date->format('Y-m')][] = $file;
            } else {
                $deletions[] = $file;
            }
        }

        [$dailyDeletes, $dailyKept] = $this->thin($dailyGroups);
        [$monthlyDeletes, $monthlyKept] = $this->thin($monthlyGroups);
        $deletions = array_merge($deletions, $dailyDeletes, $monthlyDeletes);

        $bytes = 0;
        foreach ($deletions as $file) {
            $bytes += $file['size'];
            if (! $dryRun) {
                $disk->delete($file['path']);
            }
            $this->line(($dryRun ? 'Would delete: ' : 'Deleted: ').$file['path'], verbosity: 'v');
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} ".count($deletions)." backup(s) under \"{$root}/\" on disk \"{$diskName}\".");
        $this->table(['Metric', 'Value'], [
            ['Backups scanned', count($files)],
            ['Kept (< 30 days, all)', $keptRecent],
            ['Kept (30–90 days, one per day)', $dailyKept],
            ['Kept (90 days–12 months, one per month)', $monthlyKept],
            ['Deleted', count($deletions)],
            ['Approx. bytes freed', number_format($bytes)],
            ['As of', $now->toDateString()],
            ['Mode', $dryRun ? 'dry-run (nothing deleted)' : 'deleted'],
        ]);

        return self::SUCCESS;
    }

    /**
     * In each day/month group keep the single oldest dump and mark the rest for
     * deletion. Oldest = earliest upload time, then lowest sequence number.
     *
     * @param  array<string, array<int, array{path:string,date:CarbonImmutable,mtime:int,seq:int,size:int}>>  $groups
     * @return array{0: array<int, array{path:string,date:CarbonImmutable,mtime:int,seq:int,size:int}>, 1: int}
     */
    private function thin(array $groups): array
    {
        $deletes = [];
        $kept = 0;

        foreach ($groups as $group) {
            usort($group, fn ($a, $b) => [$a['mtime'], $a['seq']] <=> [$b['mtime'], $b['seq']]);
            $kept++; // the first (oldest) survives
            $deletes = array_merge($deletes, array_slice($group, 1));
        }

        return [$deletes, $kept];
    }

    /**
     * Every backup file under the root, tagged with the date encoded in its
     * directory. Paths that don't match db_backup/YYYY/MM/DD/<file> are skipped.
     *
     * @return array<int, array{path:string,date:CarbonImmutable,mtime:int,seq:int,size:int}>
     */
    private function collect(Filesystem $disk, string $root): array
    {
        $files = [];

        foreach ($disk->allFiles($root) as $path) {
            $rel = ltrim(substr($path, strlen($root)), '/');
            $parts = explode('/', $rel);
            if (count($parts) < 4) {
                continue; // not YYYY/MM/DD/<file>
            }

            [$y, $m, $d] = [$parts[0], $parts[1], $parts[2]];
            if (! preg_match('/^\d{4}$/', $y) || ! preg_match('/^\d{2}$/', $m) || ! preg_match('/^\d{2}$/', $d)) {
                continue;
            }

            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', "{$y}-{$m}-{$d}");
            } catch (\Throwable) {
                continue;
            }
            // Reject impossible dates (e.g. 2026/13/40 overflowing) by round-trip.
            if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== "{$y}-{$m}-{$d}") {
                continue;
            }

            $seq = PHP_INT_MAX;
            if (preg_match('/-(\d+)\.[A-Za-z]/', basename($path), $mm)) {
                $seq = (int) $mm[1];
            }

            $files[] = [
                'path' => $path,
                'date' => $date,
                'mtime' => (int) $disk->lastModified($path),
                'seq' => $seq,
                'size' => (int) $disk->size($path),
            ];
        }

        return $files;
    }
}
