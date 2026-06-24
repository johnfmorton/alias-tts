<?php

namespace App\Services\Health;

use App\Enums\HealthStatus;
use App\Jobs\QueueHeartbeatJob;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use App\Providers\AppServiceProvider;
use App\Services\Asr\AsrClient;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The single source of truth for "is this service wired up correctly": PHP,
 * database, ffmpeg, the storage disk (local or S3), the inference provider +
 * token, the queue, the cleanup schedule, and app config. Returns structured
 * results so any surface — the `tts:doctor` CLI, the admin health page, a JSON
 * uptime probe — renders the same checks instead of reimplementing them.
 */
class HealthReport
{
    /** Cache key the per-minute scheduler heartbeat writes (see routes/console.php). */
    public const SCHEDULER_HEARTBEAT_KEY = 'tts:scheduler:heartbeat';

    /** Cache key a running queue:work stamps on each loop (see AppServiceProvider). */
    public const QUEUE_WORKER_HEARTBEAT_KEY = 'tts:queue:worker-heartbeat';

    /** A heartbeat older than this (seconds) means cron isn't running every minute. */
    private const SCHEDULER_HEARTBEAT_STALE = 120;

    /** No worker-loop heartbeat within this many seconds means no worker is running. */
    private const QUEUE_WORKER_HEARTBEAT_STALE = 90;

    /** A job sitting unprocessed this long (seconds) means no worker is draining it. */
    private const QUEUE_BACKLOG_STALE = 120;

    /** @var array<int, HealthCheckResult> */
    private array $results = [];

    /** When true, checks may make live calls (validate the token, probe the queue). */
    private bool $deep = false;

    /**
     * Run every check and return the results.
     *
     * @return array<int, HealthCheckResult>
     */
    public function run(bool $deep = false): array
    {
        $this->deep = $deep;
        $this->results = [];

        // Runtime
        $this->checkPhp();
        // Data stores
        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkCache();
        // Media pipeline
        $this->checkFfmpeg();
        $this->checkStorage();
        $this->checkDisk();
        // Inference + async
        $this->checkProvider();
        $this->checkAsr();
        $this->checkQueue();
        $this->checkQueueTiming();
        $this->checkFailedJobs();
        $this->checkScheduler();
        $this->checkCleanup();
        // Content the API needs to be usable
        $this->checkVoices();
        $this->checkApiKeys();
        // App config
        $this->checkApp();
        $this->checkAppUrl();

        return $this->results;
    }

    private function add(string $key, HealthStatus $status, string $label, string $detail, ?string $helpUrl = null): void
    {
        $this->results[] = new HealthCheckResult($key, $status, $label, $detail, $helpUrl);
    }

    private function checkPhp(): void
    {
        PHP_VERSION_ID >= 80300
            ? $this->add('php_version', HealthStatus::Pass, 'PHP version', PHP_VERSION)
            : $this->add('php_version', HealthStatus::Fail, 'PHP version', PHP_VERSION.' (need >= 8.3)');

        $missing = array_values(array_filter(
            ['curl', 'zip', 'fileinfo'],
            fn ($ext) => ! extension_loaded($ext),
        ));

        $missing === []
            ? $this->add('php_extensions', HealthStatus::Pass, 'PHP extensions', 'curl, zip, fileinfo')
            : $this->add('php_extensions', HealthStatus::Fail, 'PHP extensions', 'missing: '.implode(', ', $missing));
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();

            DB::getSchemaBuilder()->hasTable('speeches')
                ? $this->add('database', HealthStatus::Pass, 'Database', 'connected; migrations present')
                : $this->add('database', HealthStatus::Fail, 'Database', 'connected but tables missing — run `php artisan migrate`');
        } catch (Throwable $e) {
            $this->add('database', HealthStatus::Fail, 'Database', 'cannot connect: '.$e->getMessage());
        }
    }

    private function checkFfmpeg(): void
    {
        $bin = (string) config('tts.ffmpeg_path', 'ffmpeg');

        try {
            $process = new Process([$bin, '-version']);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->add('ffmpeg', HealthStatus::Fail, 'ffmpeg', "`{$bin}` failed — install ffmpeg (e.g. `apt install ffmpeg`) or set TTS_FFMPEG_PATH");

                return;
            }

            $output = $process->getOutput();
            $banner = trim((string) strtok($output, "\n")) ?: 'ffmpeg';
            $version = self::parseFfmpegVersion($output);
            $min = (string) config('tts.ffmpeg_min_version', '8.1.2');

            // Gate on the MagicYUV "PixelSmash" fix (CVE-2026-8461), first shipped
            // in ffmpeg 8.1.2. A git/"N-…" build we can't parse only warns.
            if ($version === null) {
                $this->add('ffmpeg', HealthStatus::Warn, 'ffmpeg', "{$banner} — could not determine the version; ensure it is >= {$min} (fixes the \"PixelSmash\" MagicYUV flaw, CVE-2026-8461)");
            } elseif (version_compare($version, $min, '<')) {
                $this->add('ffmpeg', HealthStatus::Fail, 'ffmpeg', "{$banner} — older than {$min}, which fixes the \"PixelSmash\" MagicYUV flaw (CVE-2026-8461). Upgrade ffmpeg, or if your distro backported the fix set TTS_FFMPEG_MIN_VERSION to your version.");
            } else {
                $this->add('ffmpeg', HealthStatus::Pass, 'ffmpeg', $banner);
            }
        } catch (Throwable $e) {
            $this->add('ffmpeg', HealthStatus::Fail, 'ffmpeg', "could not run `{$bin}`: ".$e->getMessage());
        }
    }

    /**
     * Extract the upstream version (e.g. "8.1.2") from `ffmpeg -version` output,
     * or null when it can't be determined — e.g. a git "N-…" build. Public and
     * static so the PixelSmash version gate can be unit-tested without ffmpeg.
     */
    public static function parseFfmpegVersion(string $versionOutput): ?string
    {
        return preg_match('/^ffmpeg version n?(\d+(?:\.\d+){0,2})/im', $versionOutput, $m) === 1
            ? $m[1]
            : null;
    }

    private function checkStorage(): void
    {
        $diskName = (string) config('tts.storage_disk');
        $probe = trim((string) config('tts.storage_path', 'speech'), '/').'/.doctor-'.uniqid().'.txt';

        try {
            $disk = Storage::disk($diskName);
            $disk->put($probe, 'ok');
            $readBack = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            $readBack
                ? $this->add('storage', HealthStatus::Pass, "Storage [{$diskName}]", 'write / read / delete OK')
                : $this->add('storage', HealthStatus::Fail, "Storage [{$diskName}]", 'wrote a probe file but could not read it back');
        } catch (Throwable $e) {
            $this->add('storage', HealthStatus::Fail, "Storage [{$diskName}]", 'not usable: '.$e->getMessage());
        }
    }

    private function checkProvider(): void
    {
        $provider = (string) config('tts.provider');

        if ($provider === 'fake') {
            $this->add('provider', HealthStatus::Warn, 'Provider', 'fake — returns silent placeholder audio (set TTS_PROVIDER=replicate for real voices)');

            return;
        }

        if ($provider !== 'replicate') {
            $this->add('provider', HealthStatus::Warn, 'Provider', "unknown provider '{$provider}'");

            return;
        }

        $token = config('tts.providers.replicate.token');
        if (! $token) {
            $this->add('provider', HealthStatus::Fail, 'Provider [replicate]', 'REPLICATE_API_TOKEN is not set');

            return;
        }

        if (! $this->deep) {
            $this->add('provider', HealthStatus::Pass, 'Provider [replicate]', 'token set (run with --deep to validate it live)');

            return;
        }

        try {
            $response = Http::withToken($token)->timeout(15)->get('https://api.replicate.com/v1/account');

            $response->successful()
                ? $this->add('provider', HealthStatus::Pass, 'Provider [replicate]', 'token valid (account: '.($response->json('username') ?? '?').')')
                : $this->add('provider', HealthStatus::Fail, 'Provider [replicate]', 'token rejected by Replicate (HTTP '.$response->status().')');
        } catch (Throwable $e) {
            $this->add('provider', HealthStatus::Warn, 'Provider [replicate]', 'could not reach Replicate: '.$e->getMessage());
        }
    }

    /**
     * The optional Whisper ASR sidecar (transcript QA of generated chunks). Off
     * by default; when enabled, pings the sidecar's /health so the page and
     * `tts:doctor` confirm the daemon is up and the model is loaded. The deeper
     * transcription self-test lives in `php artisan tts:asr:health --deep`.
     */
    private function checkAsr(): void
    {
        $docs = (string) config('tts.asr.docs_url') ?: null;

        if (! (bool) config('tts.asr.enabled', false)) {
            $this->add('asr', HealthStatus::Pass, 'ASR transcript QA', 'disabled — set TTS_ASR_ENABLED=true to transcribe and quality-check generated chunks.', $docs);

            return;
        }

        $url = (string) config('tts.asr.url');
        $health = app(AsrClient::class)->health();

        if (! $health['reachable']) {
            // Keep this actionable: the raw cURL error is noise here (almost always
            // "connection refused" = the daemon isn't running). The setup guide is
            // linked below; `tts:asr:health` shows the underlying connection error.
            $this->add('asr', HealthStatus::Fail, 'ASR transcript QA', "The tts-asr sidecar at {$url} isn't responding — install and start the daemon (or fix TTS_ASR_URL). Run `php artisan tts:asr:health` for the connection error.", $docs);

            return;
        }

        $body = $health['body'];
        if (($body['status'] ?? '') !== 'ok') {
            $this->add('asr', HealthStatus::Fail, 'ASR transcript QA', 'The sidecar is running but its model did not load ('.($body['error'] ?? 'unknown').') — check the daemon log and available memory.', $docs);

            return;
        }

        $detail = sprintf(
            'sidecar up at %s (model %s, faster-whisper %s, %dms) — verify transcription with `php artisan tts:asr:health --deep`',
            $url,
            $body['model'] ?? '?',
            $body['faster_whisper_version'] ?? '?',
            $health['latency_ms'] ?? 0,
        );
        $this->add('asr', HealthStatus::Pass, 'ASR transcript QA', $detail);
    }

    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->add('queue', HealthStatus::Warn, 'Queue', 'sync — async jobs run inline during the request (fine for short text; long async text needs a real worker)');

            return;
        }

        // Active: dispatch a probe and confirm a worker actually drains it. Only
        // under --deep, since it costs a real round-trip through the queue.
        if ($this->deep) {
            $this->probeQueue($connection);

            return;
        }

        // Passive worker liveness via the heartbeat a running queue:work stamps
        // on each loop — so this reflects whether a worker is actually alive, not
        // just that the connection is configured.
        $age = $this->workerHeartbeatAge();
        if ($age !== null && $age <= self::QUEUE_WORKER_HEARTBEAT_STALE) {
            $this->add('queue', HealthStatus::Pass, 'Queue', "a `queue:work` worker is draining the queue (last heartbeat {$age}s ago)");

            return;
        }

        // A worker busy on a single long job doesn't loop, so it stops stamping —
        // a currently-reserved job means a worker has it.
        if ($this->reservedJobCount() > 0) {
            $this->add('queue', HealthStatus::Pass, 'Queue', 'a `queue:work` worker is processing a job');

            return;
        }

        // No heartbeat and nothing reserved: the worker isn't running.
        $backlog = $this->queueBacklog();
        if ($backlog > 0) {
            $this->add('queue', HealthStatus::Fail, 'Queue', "{$backlog} job(s) unprocessed and no worker heartbeat — start a `queue:work` worker");

            return;
        }

        app()->environment('production')
            ? $this->add('queue', HealthStatus::Fail, 'Queue', 'no `queue:work` worker heartbeat — async generation will not run; start a worker (and confirm CACHE_STORE is shared, not array)')
            : $this->add('queue', HealthStatus::Warn, 'Queue', 'no `queue:work` worker heartbeat (expected unless a worker runs locally); run live checks for an active probe');
    }

    /**
     * Seconds since a running queue:work last stamped its heartbeat, or null if
     * there's no (readable) beat. The worker stamps this on each loop and on job
     * pickup; see {@see AppServiceProvider}.
     */
    private function workerHeartbeatAge(): ?int
    {
        try {
            $beat = Cache::get(self::QUEUE_WORKER_HEARTBEAT_KEY);
        } catch (Throwable) {
            return null;
        }

        return $beat === null ? null : max(0, now()->getTimestamp() - (int) $beat);
    }

    /** Jobs currently reserved (claimed by a worker) within the async job window. */
    private function reservedJobCount(): int
    {
        try {
            $connection = (string) config('queue.default');

            if (config("queue.connections.{$connection}.driver") !== 'database') {
                return 0;
            }

            $table = (string) config("queue.connections.{$connection}.table", 'jobs');

            if (! DB::getSchemaBuilder()->hasTable($table)) {
                return 0;
            }

            return DB::table($table)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '>=', now()->subSeconds((int) config('tts.async_timeout', 1800))->getTimestamp())
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Whether a queue worker appears to be alive right now — a fresh loop
     * heartbeat or a job it's actively processing. Used to fail the long live
     * test fast instead of enqueuing a job that hangs.
     */
    public function queueWorkerActive(): bool
    {
        if (config('queue.default') === 'sync') {
            return true; // jobs run inline; no worker needed
        }

        $age = $this->workerHeartbeatAge();

        return ($age !== null && $age <= self::QUEUE_WORKER_HEARTBEAT_STALE) || $this->reservedJobCount() > 0;
    }

    /**
     * Count jobs that have been available (not reserved) longer than the stale
     * threshold — i.e. a backlog no worker is consuming. Returns 0 when there's
     * nothing to measure or the driver isn't the database queue.
     */
    private function queueBacklog(): int
    {
        try {
            $connection = (string) config('queue.default');

            if (config("queue.connections.{$connection}.driver") !== 'database') {
                return 0;
            }

            $table = (string) config("queue.connections.{$connection}.table", 'jobs');

            if (! DB::getSchemaBuilder()->hasTable($table)) {
                return 0;
            }

            return DB::table($table)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->subSeconds(self::QUEUE_BACKLOG_STALE)->getTimestamp())
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function probeQueue(string $connection): void
    {
        $token = uniqid('doctor-', true);
        $key = QueueHeartbeatJob::cacheKey($token);
        $timeout = max(1, (int) config('tts.doctor_queue_probe_timeout', 10));

        try {
            QueueHeartbeatJob::dispatch($token);
        } catch (Throwable $e) {
            $this->add('queue', HealthStatus::Fail, 'Queue', 'could not dispatch a probe job: '.$e->getMessage());

            return;
        }

        try {
            $deadline = microtime(true) + $timeout;
            while (microtime(true) < $deadline) {
                if (Cache::get($key) === $token) {
                    Cache::forget($key);
                    $this->add('queue', HealthStatus::Pass, 'Queue', "worker drained a probe job (connection \"{$connection}\")");

                    return;
                }

                usleep(250_000);
            }

            Cache::forget($key);
        } catch (Throwable $e) {
            $this->add('queue', HealthStatus::Warn, 'Queue', 'dispatched a probe job but could not read the result (cache unreachable): '.$e->getMessage());

            return;
        }

        $this->add('queue', HealthStatus::Fail, 'Queue', "dispatched a probe job but no worker processed it within {$timeout}s — start a `queue:work` worker");
    }

    private function checkScheduler(): void
    {
        try {
            $scheduled = array_filter(
                $this->scheduledEvents(),
                fn ($event) => str_contains((string) ($event->command ?? ''), 'speech:cleanup'),
            );

            if ($scheduled === []) {
                $this->add('scheduler', HealthStatus::Warn, 'Scheduler', 'speech:cleanup is not scheduled; expired audio will accumulate');

                return;
            }
        } catch (Throwable $e) {
            $this->add('scheduler', HealthStatus::Warn, 'Scheduler', 'could not introspect the schedule: '.$e->getMessage());

            return;
        }

        // speech:cleanup is registered; now confirm cron is actually firing
        // `schedule:run` by reading the per-minute heartbeat (routes/console.php).
        try {
            $beat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        } catch (Throwable $e) {
            $this->add('scheduler', HealthStatus::Warn, 'Scheduler', 'speech:cleanup is scheduled; could not read the heartbeat (cache unreachable): '.$e->getMessage());

            return;
        }

        if ($beat === null) {
            // No beat yet. In production that means cron isn't running; locally
            // it's expected (developers rarely run cron), so don't hard-fail.
            app()->environment('production')
                ? $this->add('scheduler', HealthStatus::Fail, 'Scheduler', 'speech:cleanup is scheduled but no heartbeat seen — ensure cron runs `php artisan schedule:run` every minute (if just added, wait a minute and re-run)')
                : $this->add('scheduler', HealthStatus::Warn, 'Scheduler', 'speech:cleanup is scheduled; no heartbeat yet (expected unless cron runs `schedule:run` locally)');

            return;
        }

        $age = max(0, now()->getTimestamp() - (int) $beat);

        $age <= self::SCHEDULER_HEARTBEAT_STALE
            ? $this->add('scheduler', HealthStatus::Pass, 'Scheduler', "cron is running (last heartbeat {$age}s ago); speech:cleanup scheduled")
            : $this->add('scheduler', HealthStatus::Fail, 'Scheduler', "scheduler heartbeat is stale ({$age}s old) — cron has stopped running `php artisan schedule:run`");
    }

    /**
     * The schedule is defined in routes/console.php, which the framework only
     * loads in the console context. During an HTTP request (the admin health
     * page) the Schedule is therefore empty, so we boot the console kernel to
     * register it — falling back to requiring the routes file directly.
     *
     * @return array<int, Event>
     */
    private function scheduledEvents(): array
    {
        $schedule = app(Schedule::class);

        if ($schedule->events() === []) {
            try {
                app(ConsoleKernel::class)->bootstrap();
            } catch (Throwable) {
                // Fall through to loading the routes file directly.
            }

            if ($schedule->events() === [] && is_file(base_path('routes/console.php'))) {
                require base_path('routes/console.php');
            }
        }

        return $schedule->events();
    }

    private function checkApp(): void
    {
        config('app.key')
            ? $this->add('app_key', HealthStatus::Pass, 'App key', 'set')
            : $this->add('app_key', HealthStatus::Fail, 'App key', 'APP_KEY is missing — run `php artisan key:generate`');

        if (app()->environment('production') && config('app.debug')) {
            $this->add('debug', HealthStatus::Fail, 'Debug mode', 'APP_DEBUG=true in production — set it to false');
        } else {
            $this->add('debug', HealthStatus::Pass, 'Debug mode', app()->environment().' / debug='.(config('app.debug') ? 'true' : 'false'));
        }
    }

    private function checkMigrations(): void
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                $this->add('migrations', HealthStatus::Warn, 'Migrations', 'migration repository not found — run `php artisan migrate`');

                return;
            }

            $ran = $migrator->getRepository()->getRan();
            $paths = array_unique(array_merge($migrator->paths(), [database_path('migrations')]));
            $pending = array_diff(array_keys($migrator->getMigrationFiles($paths)), $ran);

            $pending === []
                ? $this->add('migrations', HealthStatus::Pass, 'Migrations', 'up to date')
                : $this->add('migrations', HealthStatus::Fail, 'Migrations', count($pending).' pending — run `php artisan migrate`');
        } catch (Throwable $e) {
            $this->add('migrations', HealthStatus::Warn, 'Migrations', 'could not determine status: '.$e->getMessage());
        }
    }

    private function checkCache(): void
    {
        $store = (string) config('cache.default');

        if ($store === 'array') {
            $this->add('cache', HealthStatus::Warn, 'Cache store', 'array — not shared across processes; the scheduler/queue liveness checks need a persistent store (set CACHE_STORE to file, redis, or database)');

            return;
        }

        if ($store === 'database') {
            try {
                $table = (string) config('cache.stores.database.table', 'cache');

                DB::getSchemaBuilder()->hasTable($table)
                    ? $this->add('cache', HealthStatus::Pass, 'Cache store', "database (table \"{$table}\")")
                    : $this->add('cache', HealthStatus::Warn, 'Cache store', "database cache table \"{$table}\" missing — run `php artisan migrate`");
            } catch (Throwable $e) {
                $this->add('cache', HealthStatus::Warn, 'Cache store', 'could not check the cache table: '.$e->getMessage());
            }

            return;
        }

        $this->add('cache', HealthStatus::Pass, 'Cache store', $store);
    }

    private function checkDisk(): void
    {
        $diskName = (string) config('tts.storage_disk');
        $driver = (string) config("filesystems.disks.{$diskName}.driver");

        if ($driver !== 'local') {
            $this->add('disk', HealthStatus::Pass, 'Disk space', "disk \"{$diskName}\" ({$driver}) — free space not tracked for object storage");

            return;
        }

        try {
            $root = (string) config("filesystems.disks.{$diskName}.root", storage_path('app'));
            $free = @disk_free_space($root);
            $total = @disk_total_space($root);

            if ($free === false || $total === false || $total <= 0) {
                $this->add('disk', HealthStatus::Warn, 'Disk space', "could not read free space for {$root}");

                return;
            }

            $pct = $free / $total * 100;
            $detail = sprintf('%s free of %s (%d%%) on %s', $this->bytes($free), $this->bytes($total), (int) round($pct), $root);

            // Generated audio accumulates; flag before the disk actually fills.
            ($pct < 10 || $free < 1_073_741_824)
                ? $this->add('disk', HealthStatus::Warn, 'Disk space', $detail.' — running low')
                : $this->add('disk', HealthStatus::Pass, 'Disk space', $detail);
        } catch (Throwable $e) {
            $this->add('disk', HealthStatus::Warn, 'Disk space', 'could not read disk space: '.$e->getMessage());
        }
    }

    /**
     * The database queue releases a job back for retry after `retry_after`
     * seconds; if that's shorter than a long async job's timeout, the job can
     * be re-dispatched while still running. Single-worker setups dodge it, but
     * it's a double-generation bug the moment a second worker exists.
     */
    private function checkQueueTiming(): void
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return;
        }

        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 90);
        $jobTimeout = (int) config('tts.async_timeout', 1800);

        $retryAfter > $jobTimeout
            ? $this->add('queue_timing', HealthStatus::Pass, 'Queue timing', "retry_after {$retryAfter}s > async job timeout {$jobTimeout}s")
            : $this->add('queue_timing', HealthStatus::Warn, 'Queue timing', "retry_after ({$retryAfter}s) ≤ async job timeout ({$jobTimeout}s) — a long job can be released and double-run; set DB_QUEUE_RETRY_AFTER > TTS_ASYNC_TIMEOUT");
    }

    private function checkFailedJobs(): void
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                return;
            }

            $count = DB::table('failed_jobs')->count();

            $count === 0
                ? $this->add('failed_jobs', HealthStatus::Pass, 'Failed jobs', 'none')
                : $this->add('failed_jobs', HealthStatus::Warn, 'Failed jobs', "{$count} failed — inspect with `php artisan queue:failed`, retry with `queue:retry all`");
        } catch (Throwable $e) {
            $this->add('failed_jobs', HealthStatus::Warn, 'Failed jobs', 'could not read failed_jobs: '.$e->getMessage());
        }
    }

    private function checkCleanup(): void
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('speeches')) {
                return;
            }

            // speech:cleanup runs daily; allow a full cycle past expiry before
            // flagging, so the normal up-to-24h lag doesn't read as a problem.
            $stale = Speech::where('expires_at', '<', now()->subHours(25))->count();

            $stale > 0
                ? $this->add('cleanup', HealthStatus::Warn, 'Expired audio', "{$stale} generation(s) >25h past expiry not cleaned up — is the scheduler running speech:cleanup?")
                : $this->add('cleanup', HealthStatus::Pass, 'Expired audio', 'no expired audio pending cleanup');
        } catch (Throwable $e) {
            $this->add('cleanup', HealthStatus::Warn, 'Expired audio', 'could not check expired audio: '.$e->getMessage());
        }
    }

    private function checkVoices(): void
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('voices')) {
                return;
            }

            $count = Voice::count();

            $count > 0
                ? $this->add('voices', HealthStatus::Pass, 'Voices', "{$count} configured")
                : $this->add('voices', HealthStatus::Warn, 'Voices', 'no voices configured — add at least one to generate speech');
        } catch (Throwable $e) {
            $this->add('voices', HealthStatus::Warn, 'Voices', 'could not read voices: '.$e->getMessage());
        }
    }

    private function checkApiKeys(): void
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('api_keys')) {
                return;
            }

            $active = ApiKey::where('is_active', true)->count();

            $active > 0
                ? $this->add('api_keys', HealthStatus::Pass, 'API keys', "{$active} active")
                : $this->add('api_keys', HealthStatus::Warn, 'API keys', 'no active API key — the API cannot be called; create one in the panel');
        } catch (Throwable $e) {
            $this->add('api_keys', HealthStatus::Warn, 'API keys', 'could not read API keys: '.$e->getMessage());
        }
    }

    private function checkAppUrl(): void
    {
        $url = (string) config('app.url');

        if (app()->environment('production')) {
            if ($url === '' || str_contains($url, 'localhost') || str_contains($url, '127.0.0.1')) {
                $this->add('app_url', HealthStatus::Fail, 'App URL', "APP_URL is \"{$url}\" in production — set it to the public URL (the plugin's connection details are built from it)");

                return;
            }

            if (! str_starts_with($url, 'https://')) {
                $this->add('app_url', HealthStatus::Warn, 'App URL', "{$url} — not https");

                return;
            }
        }

        $this->add('app_url', HealthStatus::Pass, 'App URL', $url !== '' ? $url : '(not set)');
    }

    private function bytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $i = 0;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
