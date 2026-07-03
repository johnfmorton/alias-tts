<?php

namespace Tests\Feature;

use App\Enums\HealthStatus;
use App\Models\ApiKey;
use App\Models\Voice;
use App\Services\Health\HealthCheckResult;
use App\Services\Health\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    public function test_it_returns_a_structured_result_per_check(): void
    {
        $results = app(HealthReport::class)->run();

        $this->assertContainsOnlyInstancesOf(HealthCheckResult::class, $results);

        // Stable keys the CLI, health page, and JSON probe all target. The
        // queue is sync in tests, so the database-only `queue_timing` check is
        // intentionally absent here (see test_queue_timing_*).
        $keys = array_map(fn (HealthCheckResult $r) => $r->key, $results);
        $this->assertEqualsCanonicalizing([
            'php_version', 'php_extensions', 'database', 'migrations', 'cache',
            'ffmpeg', 'storage', 'disk', 'provider', 'asr', 'genblaze', 'pronunciation', 'queue', 'failed_jobs',
            'scheduler', 'cleanup', 'voices', 'api_keys', 'app_key', 'debug', 'app_url',
        ], $keys);
    }

    public function test_a_healthy_setup_has_no_failures(): void
    {
        $results = app(HealthReport::class)->run();

        $this->assertEmpty(array_filter($results, fn (HealthCheckResult $r) => $r->isFailure()));
    }

    public function test_a_result_serializes_to_an_array(): void
    {
        $results = app(HealthReport::class)->run();
        $provider = collect($results)->firstWhere('key', 'provider');

        // Fake provider is a deliberate WARN, not a hard failure.
        $this->assertSame(HealthStatus::Warn, $provider->status);
        $this->assertSame([
            'key' => 'provider',
            'status' => 'WARN',
            'label' => 'Provider',
            'detail' => 'fake — returns silent placeholder audio (set TTS_PROVIDER=replicate for real voices)',
            'help_url' => null,
        ], $provider->toArray());
    }

    public function test_failed_jobs_warns_when_a_job_has_failed(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $failed = $this->resultFor('failed_jobs');

        $this->assertSame(HealthStatus::Warn, $failed->status);
        $this->assertStringContainsString('1 failed', $failed->detail);
    }

    public function test_voices_and_api_keys_pass_once_configured(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);
        ApiKey::generate('k', 100);

        $this->assertSame(HealthStatus::Pass, $this->resultFor('voices')->status);
        $this->assertSame(HealthStatus::Pass, $this->resultFor('api_keys')->status);
    }

    public function test_queue_passes_when_a_worker_heartbeat_is_fresh(): void
    {
        config(['queue.default' => 'database']);
        Cache::put(HealthReport::QUEUE_WORKER_HEARTBEAT_KEY, now()->getTimestamp());

        $queue = $this->resultFor('queue');

        $this->assertSame(HealthStatus::Pass, $queue->status);
        $this->assertStringContainsString('draining the queue', $queue->detail);
    }

    public function test_queue_warns_locally_without_a_worker_heartbeat(): void
    {
        // No heartbeat, no jobs, non-production: a missing worker is expected locally.
        config(['queue.default' => 'database']);

        $this->assertSame(HealthStatus::Warn, $this->resultFor('queue')->status);
    }

    public function test_queue_fails_in_production_without_a_worker(): void
    {
        config(['queue.default' => 'database']);
        app()->detectEnvironment(fn () => 'production');

        $queue = $this->resultFor('queue');

        $this->assertSame(HealthStatus::Fail, $queue->status);
        $this->assertStringContainsString('worker', $queue->detail);
    }

    public function test_queue_timing_warns_when_retry_after_is_too_short(): void
    {
        // The default now derives safely from TTS_ASYNC_TIMEOUT, so set a short
        // retry_after explicitly to exercise the WARN path.
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);

        $timing = $this->resultFor('queue_timing');

        $this->assertSame(HealthStatus::Warn, $timing->status);
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER', $timing->detail);
    }

    public function test_queue_timing_passes_when_retry_after_exceeds_job_timeout(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 2000,
            'tts.async_timeout' => 1800,
        ]);

        $this->assertSame(HealthStatus::Pass, $this->resultFor('queue_timing')->status);
    }

    public function test_genblaze_warns_with_setup_steps_when_not_configured(): void
    {
        // Genblaze is integral, so an absent runner is a strident WARN (not a
        // cheerful pass) that names the exact env var and links the setup guide.
        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Warn, $genblaze->status);
        $this->assertStringContainsString('TTS_GENBLAZE_RUNNER_URL=http://127.0.0.1:8800', $genblaze->detail);
        $this->assertNotNull($genblaze->helpUrl); // renders the "Setup guide" link on the Health page
    }

    public function test_pronunciation_warns_loudly_with_the_fix_when_the_runner_is_down(): void
    {
        config(['tts.pronunciation.enabled' => true]); // on, but no runner configured in the test env

        $p = $this->resultFor('pronunciation');

        $this->assertSame(HealthStatus::Warn, $p->status);
        $this->assertStringContainsString('silently skipped', $p->detail);          // strident
        $this->assertStringContainsString('TTS_GENBLAZE_RUNNER_URL', $p->detail);    // the fix
        $this->assertStringContainsString('TTS_PRONUNCIATION_ENABLED=false', $p->detail); // the off-switch
        $this->assertNotNull($p->helpUrl);
    }

    public function test_genblaze_fails_when_the_configured_runner_is_unreachable(): void
    {
        config(['tts.genblaze.runner_url' => 'http://runner.test']);
        Http::fake(['runner.test/health' => Http::response([], 500)]);

        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Fail, $genblaze->status);
        $this->assertStringContainsString("isn't responding", $genblaze->detail);
    }

    public function test_genblaze_fails_when_the_internal_secret_is_empty(): void
    {
        config(['tts.genblaze.runner_url' => 'http://runner.test', 'tts.internal.secret' => '']);
        Http::fake(['runner.test/health' => Http::response($this->runnerHealth())]);

        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Fail, $genblaze->status);
        $this->assertStringContainsString('TTS_INTERNAL_SECRET', $genblaze->detail);
    }

    public function test_genblaze_fails_on_a_storage_root_mismatch(): void
    {
        config([
            'tts.genblaze.runner_url' => 'http://runner.test',
            'tts.internal.secret' => 's3cret',
            'filesystems.disks.s3.root' => 'mimic',
        ]);
        Http::fake(['runner.test/health' => Http::response($this->runnerHealth(['storage_root' => null]))]);

        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Fail, $genblaze->status);
        $this->assertStringContainsString('storage root mismatch', $genblaze->detail);
        $this->assertStringContainsString('"mimic/"', $genblaze->detail);
    }

    public function test_genblaze_fails_when_the_app_has_a_root_but_the_runner_predates_it(): void
    {
        config([
            'tts.genblaze.runner_url' => 'http://runner.test',
            'tts.internal.secret' => 's3cret',
            'filesystems.disks.s3.root' => 'mimic',
        ]);
        // An old runner's /health has no storage_root key at all.
        $body = $this->runnerHealth();
        unset($body['storage_root']);
        Http::fake(['runner.test/health' => Http::response($body)]);

        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Fail, $genblaze->status);
        $this->assertStringContainsString('predates storage-root support', $genblaze->detail);
    }

    public function test_genblaze_warns_when_callbacks_target_a_different_app_url(): void
    {
        config([
            'tts.genblaze.runner_url' => 'http://runner.test',
            'tts.internal.secret' => 's3cret',
            'app.url' => 'https://tts.example.com',
        ]);
        Http::fake(['runner.test/health' => Http::response($this->runnerHealth(['mimic' => 'https://other.example.com']))]);

        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Warn, $genblaze->status);
        $this->assertStringContainsString('MIMIC_BASE_URL', $genblaze->detail);
    }

    public function test_genblaze_warns_without_a_b2_sink(): void
    {
        config([
            'tts.genblaze.runner_url' => 'http://runner.test',
            'tts.internal.secret' => 's3cret',
            'app.url' => 'https://tts.example.com',
        ]);
        Http::fake(['runner.test/health' => Http::response($this->runnerHealth(['b2' => false]))]);

        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Warn, $genblaze->status);
        $this->assertStringContainsString('B2_BUCKET', $genblaze->detail);
    }

    public function test_genblaze_passes_when_everything_agrees(): void
    {
        config([
            'tts.genblaze.runner_url' => 'http://runner.test',
            'tts.internal.secret' => 's3cret',
            'app.url' => 'https://tts.example.com',
            'filesystems.disks.s3.root' => 'mimic',
        ]);
        Http::fake(['runner.test/health' => Http::response($this->runnerHealth(['storage_root' => 'mimic']))]);

        $genblaze = $this->resultFor('genblaze');

        $this->assertSame(HealthStatus::Pass, $genblaze->status);
        $this->assertStringContainsString('storage root "mimic/"', $genblaze->detail);
    }

    /** A healthy runner /health payload; override fields per scenario. */
    private function runnerHealth(array $overrides = []): array
    {
        return array_merge([
            'status' => 'ok',
            'mimic' => 'https://tts.example.com',
            'b2' => true,
            'storage_root' => null,
            'pronounce' => [],
        ], $overrides);
    }

    private function resultFor(string $key): HealthCheckResult
    {
        return collect(app(HealthReport::class)->run())->firstWhere('key', $key);
    }
}
