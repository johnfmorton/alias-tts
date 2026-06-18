<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
