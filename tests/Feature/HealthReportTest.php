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
            'ffmpeg', 'storage', 'disk', 'provider', 'asr', 'queue', 'failed_jobs',
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

    private function resultFor(string $key): HealthCheckResult
    {
        return collect(app(HealthReport::class)->run())->firstWhere('key', $key);
    }
}
