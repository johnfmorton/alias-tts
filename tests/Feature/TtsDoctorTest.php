<?php

namespace Tests\Feature;

use App\Services\Health\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TtsDoctorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    public function test_doctor_passes_with_a_healthy_local_setup(): void
    {
        // fake provider + faked local storage + sqlite + ffmpeg in the runner:
        // every check is PASS/WARN, none FAIL.
        $this->artisan('tts:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('Summary');
    }

    public function test_doctor_fails_when_replicate_token_is_missing(): void
    {
        config([
            'tts.provider' => 'replicate',
            'tts.providers.replicate.token' => null,
        ]);

        $this->artisan('tts:doctor')->assertExitCode(1);
    }

    public function test_scheduler_passes_when_the_heartbeat_is_fresh(): void
    {
        Cache::put(HealthReport::SCHEDULER_HEARTBEAT_KEY, now()->getTimestamp());

        $this->artisan('tts:doctor')
            ->assertExitCode(0)
            ->expectsOutputToContain('cron is running');
    }

    public function test_scheduler_fails_when_the_heartbeat_is_stale(): void
    {
        // A beat exists but is older than the staleness window — cron stopped.
        Cache::put(HealthReport::SCHEDULER_HEARTBEAT_KEY, now()->subSeconds(600)->getTimestamp());

        $this->artisan('tts:doctor')
            ->assertExitCode(1)
            ->expectsOutputToContain('heartbeat is stale');
    }

    public function test_scheduler_fails_in_production_without_a_heartbeat(): void
    {
        // No cron has stamped a heartbeat — a hard fail in production only.
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('tts:doctor')
            ->assertExitCode(1)
            ->expectsOutputToContain('no heartbeat seen');
    }

    public function test_queue_fails_with_a_stale_database_backlog(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subSeconds(600)->getTimestamp(),
            'created_at' => now()->subSeconds(600)->getTimestamp(),
        ]);

        $this->artisan('tts:doctor')
            ->assertExitCode(1)
            ->expectsOutputToContain('unprocessed');
    }

    public function test_deep_queue_probe_fails_when_no_worker_drains_it(): void
    {
        config([
            'queue.default' => 'database',
            'tts.doctor_queue_probe_timeout' => 1,
        ]);

        // The probe job is enqueued to the database queue but nothing runs it.
        $this->artisan('tts:doctor --deep')
            ->assertExitCode(1)
            ->expectsOutputToContain('no worker processed it');
    }
}
