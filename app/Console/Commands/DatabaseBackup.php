<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Dump the database and store it on the app's configured storage disk (local,
 * or s3/B2 in production) alongside the audio it already keeps there. Backups
 * land under a top-level `db_backup/` prefix — a sibling of speech/ and voices/,
 * OUTSIDE tts.storage_path so `speech:cleanup --orphans` never touches them —
 * organised by date:
 *
 *     db_backup/2026/07/23/backup-23JUL2026-1.sql
 *     db_backup/2026/07/23/backup-23JUL2026-2.sql
 *
 * The trailing number increments per day, so several dumps a day are fine (see
 * `db:prune-backups` for the matching retention thinning).
 *
 * mysql/mariadb dumps stream through mysqldump/mariadb-dump; sqlite uses a
 * consistent `VACUUM INTO` snapshot; pgsql uses pg_dump. Because storage goes
 * through Laravel's disk abstraction, the same command works on local disks and
 * on S3/B2. Wire it to the scheduler in routes/console.php.
 */
class DatabaseBackup extends Command
{
    protected $signature = 'db:backup
                            {--connection= : Database connection to back up (defaults to the app default)}
                            {--disk= : Storage disk to write to (defaults to tts.storage_disk)}
                            {--compress : gzip the dump (writes a .gz file)}';

    protected $description = 'Dump the database to the storage disk under db_backup/YYYY/MM/DD/ (local or S3/B2)';

    public function handle(): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('database.default'));
        $config = config("database.connections.{$connectionName}");

        if (! is_array($config)) {
            $this->error("Unknown database connection \"{$connectionName}\".");

            return self::FAILURE;
        }

        $driver = (string) ($config['driver'] ?? '');
        $compress = (bool) $this->option('compress');
        $diskName = (string) ($this->option('disk') ?: config('tts.storage_disk'));
        $disk = Storage::disk($diskName);

        $now = CarbonImmutable::now();
        $rawExt = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $destExt = $compress ? $rawExt.'.gz' : $rawExt;

        $dir = $this->destinationDir($now);
        $destPath = $dir.'/'.$this->nextFilename($disk, $dir, $now, $destExt);

        $plainTmp = $this->tempPath($rawExt);
        $uploadTmp = $plainTmp;
        $bytes = 0;

        try {
            $this->line("Dumping \"{$connectionName}\" ({$driver}) …");

            match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($driver, $config, $plainTmp),
                'pgsql' => $this->dumpPgsql($config, $plainTmp),
                'sqlite' => $this->dumpSqlite($connectionName, $config, $plainTmp),
                default => throw new \RuntimeException("Unsupported database driver \"{$driver}\" for db:backup."),
            };

            if ($compress) {
                $uploadTmp = $this->tempPath($rawExt.'.gz');
                $this->gzipFile($plainTmp, $uploadTmp);
                @unlink($plainTmp);
            }

            $bytes = (int) (@filesize($uploadTmp) ?: 0);

            $stream = fopen($uploadTmp, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Could not reopen the dump for upload.');
            }

            $ok = $disk->writeStream($destPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($ok === false) {
                $this->error("Failed to write the backup to disk \"{$diskName}\".");

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            foreach (array_unique([$plainTmp, $uploadTmp]) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $this->info("Backed up to \"{$destPath}\" on disk \"{$diskName}\" (".number_format($bytes).' bytes).');

        return self::SUCCESS;
    }

    private function destinationDir(CarbonInterface $now): string
    {
        $root = trim((string) config('tts.backup.path', 'db_backup'), '/');

        return $root.'/'.$now->format('Y/m/d');
    }

    /**
     * Next `backup-DDMONYYYY-N.<ext>` for the day. N is one past the highest
     * number already present so same-day dumps never collide.
     */
    private function nextFilename(Filesystem $disk, string $dir, CarbonInterface $now, string $ext): string
    {
        $token = strtoupper($now->format('dMY')); // 23JUL2026
        $max = 0;

        foreach ($disk->files($dir) as $path) {
            if (preg_match('/^backup-'.preg_quote($token, '/').'-(\d+)\./', basename($path), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return "backup-{$token}-".($max + 1).".{$ext}";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpMysql(string $driver, array $config, string $tmp): void
    {
        $candidates = $driver === 'mariadb'
            ? ['mariadb-dump', 'mysqldump']
            : ['mysqldump', 'mariadb-dump'];

        $binary = $this->resolveBinary(config('tts.backup.mysqldump_path'), $candidates);

        // Credentials go in a 0600 defaults-extra-file so they never appear in
        // the process list or in a ProcessFailedException message.
        $defaults = $this->writeMysqlDefaultsFile($config);

        try {
            $command = [
                $binary,
                '--defaults-extra-file='.$defaults, // must be the first argument
                '--single-transaction',
                '--quick',
                '--no-tablespaces',
                '--routines',
                '--triggers',
                '--default-character-set='.($config['charset'] ?? 'utf8mb4'),
            ];

            if (empty($config['unix_socket'])) {
                $host = $config['host'] ?? '127.0.0.1';
                $command[] = '--host='.(is_array($host) ? ($host[0] ?? '127.0.0.1') : $host);
                $command[] = '--port='.($config['port'] ?? 3306);
            }

            $command[] = (string) ($config['database'] ?? '');

            $this->runToFile($command, $tmp);
        } finally {
            @unlink($defaults);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpPgsql(array $config, string $tmp): void
    {
        $binary = $this->resolveBinary(config('tts.backup.pg_dump_path'), ['pg_dump']);

        $host = $config['host'] ?? '127.0.0.1';
        $command = [
            $binary,
            '--host='.(is_array($host) ? ($host[0] ?? '127.0.0.1') : $host),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? 'postgres'),
            '--no-owner',
            '--no-privileges',
            (string) ($config['database'] ?? ''),
        ];

        $this->runToFile($command, $tmp, ['PGPASSWORD' => (string) ($config['password'] ?? '')]);
    }

    /**
     * A consistent snapshot of the sqlite database. For the usual file-backed
     * database we checkpoint the WAL and copy the file — safe even from inside a
     * transaction. For a non-file database (`:memory:`) we fall back to VACUUM
     * INTO, which needs a fresh target and cannot run inside a transaction.
     *
     * @param  array<string, mixed>  $config
     */
    private function dumpSqlite(string $connectionName, array $config, string $tmp): void
    {
        $database = (string) ($config['database'] ?? '');

        if ($database !== '' && $database !== ':memory:' && is_file($database)) {
            try {
                DB::connection($connectionName)->getPdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable) {
                // Not every journal mode supports a checkpoint; copy regardless.
            }

            if (! @copy($database, $tmp)) {
                throw new \RuntimeException("Could not copy the sqlite database at {$database}.");
            }

            return;
        }

        if (is_file($tmp)) {
            @unlink($tmp);
        }

        DB::connection($connectionName)
            ->getPdo()
            ->exec("VACUUM INTO '".str_replace("'", "''", $tmp)."'");
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>|null  $env
     */
    private function runToFile(array $command, string $tmp, ?array $env = null): void
    {
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open temp file {$tmp} for writing.");
        }

        $timeout = (int) config('tts.backup.timeout', 3600);
        $process = new Process($command, null, $env);
        $process->setTimeout($timeout > 0 ? $timeout : null);

        try {
            $process->run(function (string $type, string $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeMysqlDefaultsFile(array $config): string
    {
        $path = $this->tempPath('cnf');

        $lines = ['[client]'];
        $lines[] = 'user="'.addcslashes((string) ($config['username'] ?? 'root'), '"\\').'"';
        $lines[] = 'password="'.addcslashes((string) ($config['password'] ?? ''), '"\\').'"';
        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket="'.addcslashes((string) $config['unix_socket'], '"\\').'"';
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function resolveBinary(?string $configured, array $candidates): string
    {
        if (is_string($configured) && $configured !== '') {
            if (! is_executable($configured)) {
                throw new \RuntimeException("Configured dump binary \"{$configured}\" is not executable.");
            }

            return $configured;
        }

        $extraDirs = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/usr/local/mysql/bin'];
        $finder = new ExecutableFinder;

        foreach ($candidates as $name) {
            $found = $finder->find($name, null, $extraDirs);
            if ($found !== null) {
                return $found;
            }
        }

        throw new \RuntimeException(
            'Could not find '.implode(' or ', $candidates).' on PATH. '
            .'Set an absolute path via TTS_MYSQLDUMP_PATH / TTS_PG_DUMP_PATH.'
        );
    }

    private function gzipFile(string $src, string $dst): void
    {
        $in = fopen($src, 'rb');
        $out = gzopen($dst, 'wb9');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Could not open the dump for compression.');
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, 262144);
                if ($chunk === false) {
                    throw new \RuntimeException('Read error while compressing the dump.');
                }
                gzwrite($out, $chunk);
            }
        } finally {
            gzclose($out);
            fclose($in);
        }
    }

    private function tempPath(string $ext): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/tts-db-backup-'.bin2hex(random_bytes(8)).'.'.$ext;
    }
}
