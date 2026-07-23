<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $sqlitePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.storage_disk' => 'local']);
        Storage::fake('local');

        // A real, file-backed sqlite database to dump — the default test DB is
        // `:memory:` inside a transaction, where a snapshot can't be taken.
        $this->sqlitePath = rtrim(sys_get_temp_dir(), '/').'/tts-backup-src-'.bin2hex(random_bytes(6)).'.sqlite';
        $pdo = new \PDO('sqlite:'.$this->sqlitePath);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('hello')");
        $pdo = null;

        config(['database.connections.backup_src' => [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
    }

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== '' && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    // ---- db:backup ----------------------------------------------------------

    public function test_it_writes_a_dated_dump_to_the_storage_disk(): void
    {
        CarbonImmutable::setTestNow('2026-07-23 09:00:00');

        $this->artisan('db:backup', ['--connection' => 'backup_src'])->assertExitCode(0);

        Storage::disk('local')->assertExists('db_backup/2026/07/23/backup-23JUL2026-1.sqlite');
        $this->assertNotSame('', Storage::disk('local')->get('db_backup/2026/07/23/backup-23JUL2026-1.sqlite'));

        CarbonImmutable::setTestNow();
    }

    public function test_same_day_dumps_increment_the_sequence(): void
    {
        CarbonImmutable::setTestNow('2026-07-23 09:00:00');

        $this->artisan('db:backup', ['--connection' => 'backup_src'])->assertExitCode(0);
        $this->artisan('db:backup', ['--connection' => 'backup_src'])->assertExitCode(0);
        $this->artisan('db:backup', ['--connection' => 'backup_src'])->assertExitCode(0);

        foreach ([1, 2, 3] as $n) {
            Storage::disk('local')->assertExists("db_backup/2026/07/23/backup-23JUL2026-{$n}.sqlite");
        }

        CarbonImmutable::setTestNow();
    }

    public function test_compress_writes_a_gzip_file(): void
    {
        CarbonImmutable::setTestNow('2026-07-23 09:00:00');

        $this->artisan('db:backup', ['--connection' => 'backup_src', '--compress' => true])->assertExitCode(0);

        $path = 'db_backup/2026/07/23/backup-23JUL2026-1.sqlite.gz';
        Storage::disk('local')->assertExists($path);
        // gzip magic bytes
        $this->assertSame("\x1f\x8b", substr(Storage::disk('local')->get($path), 0, 2));

        CarbonImmutable::setTestNow();
    }

    // ---- db:prune-backups (retention bands) ---------------------------------

    /** Write a backup at a logical date, with a controllable upload time. */
    private function makeBackup(string $date, int $seq, int $mtime): string
    {
        $carbon = CarbonImmutable::parse($date);
        $token = strtoupper($carbon->format('dMY'));
        $path = 'db_backup/'.$carbon->format('Y/m/d')."/backup-{$token}-{$seq}.sql";

        Storage::disk('local')->put($path, "SQL-{$seq}");
        touch(Storage::disk('local')->path($path), $mtime);

        return $path;
    }

    public function test_it_keeps_everything_in_the_last_30_days(): void
    {
        $a = $this->makeBackup('2026-07-23', 1, 100);
        $b = $this->makeBackup('2026-07-23', 2, 200);
        $c = $this->makeBackup('2026-07-10', 1, 300);

        $this->artisan('db:prune-backups', ['--before' => '2026-07-23'])->assertExitCode(0);

        foreach ([$a, $b, $c] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_between_30_and_90_days_keeps_only_the_oldest_per_day(): void
    {
        $now = '2026-07-23';
        // 40 days old → daily-thin band. Oldest (smallest mtime) survives.
        $oldest = $this->makeBackup('2026-06-13', 1, 100);
        $mid = $this->makeBackup('2026-06-13', 2, 200);
        $newest = $this->makeBackup('2026-06-13', 3, 300);

        // A different day in the same band keeps its own oldest.
        $otherDay = $this->makeBackup('2026-06-14', 1, 100);

        $this->artisan('db:prune-backups', ['--before' => $now])->assertExitCode(0);

        Storage::disk('local')->assertExists($oldest);
        Storage::disk('local')->assertExists($otherDay);
        Storage::disk('local')->assertMissing($mid);
        Storage::disk('local')->assertMissing($newest);
    }

    public function test_daily_thin_breaks_mtime_ties_by_sequence(): void
    {
        // Same upload time → the lower sequence number is treated as oldest.
        $keep = $this->makeBackup('2026-06-13', 1, 500);
        $drop = $this->makeBackup('2026-06-13', 2, 500);

        $this->artisan('db:prune-backups', ['--before' => '2026-07-23'])->assertExitCode(0);

        Storage::disk('local')->assertExists($keep);
        Storage::disk('local')->assertMissing($drop);
    }

    public function test_between_90_days_and_12_months_keeps_only_the_oldest_per_month(): void
    {
        $now = '2026-07-23';
        // ~5 months old → monthly-thin band. Only the single oldest of the whole
        // month survives, across every day in it.
        $oldest = $this->makeBackup('2026-02-03', 1, 100);
        $sameDayLater = $this->makeBackup('2026-02-03', 2, 150);
        $laterDay = $this->makeBackup('2026-02-20', 1, 200);

        // A different month keeps its own oldest.
        $otherMonth = $this->makeBackup('2026-03-10', 1, 100);

        $this->artisan('db:prune-backups', ['--before' => $now])->assertExitCode(0);

        Storage::disk('local')->assertExists($oldest);
        Storage::disk('local')->assertExists($otherMonth);
        Storage::disk('local')->assertMissing($sameDayLater);
        Storage::disk('local')->assertMissing($laterDay);
    }

    public function test_older_than_12_months_is_deleted(): void
    {
        $ancient = $this->makeBackup('2025-01-01', 1, 100);

        $this->artisan('db:prune-backups', ['--before' => '2026-07-23'])->assertExitCode(0);

        Storage::disk('local')->assertMissing($ancient);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $drop = $this->makeBackup('2026-06-13', 2, 200);
        $this->makeBackup('2026-06-13', 1, 100);

        $this->artisan('db:prune-backups', ['--before' => '2026-07-23', '--dry-run' => true])
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($drop);
    }

    public function test_it_ignores_files_that_do_not_match_the_backup_layout(): void
    {
        Storage::disk('local')->put('db_backup/README.txt', 'hello');
        Storage::disk('local')->put('db_backup/2026/07/notafile.sql', 'stray');

        $this->artisan('db:prune-backups', ['--before' => '2026-07-23'])->assertExitCode(0);

        Storage::disk('local')->assertExists('db_backup/README.txt');
        Storage::disk('local')->assertExists('db_backup/2026/07/notafile.sql');
    }
}
