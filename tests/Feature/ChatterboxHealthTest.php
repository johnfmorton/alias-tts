<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * tts:chatterbox:health must give a green/red answer about the local sidecar
 * (reachability + per-engine load state), warn when the sidecar is reachable
 * but not the active provider, and with --deep prove a full synthesis
 * round-trip returns WAV bytes.
 */
class ChatterboxHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin the sidecar URL so the Http fakes below match regardless of any
        // TTS_LOCAL_CHATTERBOX_URL in the developer's .env (a stray real URL
        // would send these tests to a live sidecar).
        config(['tts.providers.local.url' => 'http://127.0.0.1:8766']);
    }

    private function healthyBody(): array
    {
        return [
            'status' => 'ok',
            'device' => 'cpu',
            'python' => '3.10.14',
            'torch' => '2.6.0',
            'chatterbox_tts' => '0.1.7',
            'busy' => false,
            'models' => [
                'chatterbox' => ['loaded' => false, 'error' => null, 'load_seconds' => null],
                'chatterbox-turbo' => ['loaded' => true, 'error' => null, 'load_seconds' => 8.6],
            ],
        ];
    }

    public function test_a_healthy_sidecar_reports_ok_and_engine_states(): void
    {
        config(['tts.provider' => 'local']);
        Http::fake(['127.0.0.1:8766/health' => Http::response($this->healthyBody())]);

        $this->artisan('tts:chatterbox:health')
            ->expectsOutputToContain('OK — device cpu')
            ->expectsOutputToContain('not loaded (lazy — loads on first use)')
            ->expectsOutputToContain('loaded (8.6s)')
            ->assertExitCode(0);
    }

    public function test_an_unreachable_sidecar_fails(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->artisan('tts:chatterbox:health')->assertExitCode(1);
    }

    public function test_warns_when_the_sidecar_is_not_the_active_provider(): void
    {
        config(['tts.provider' => 'replicate']);
        Http::fake(['127.0.0.1:8766/health' => Http::response($this->healthyBody())]);

        $this->artisan('tts:chatterbox:health')
            ->expectsOutputToContain("TTS_PROVIDER is 'replicate'")
            ->assertExitCode(0);
    }

    public function test_deep_synthesizes_a_phrase_through_the_sidecar(): void
    {
        config(['tts.provider' => 'local']);
        Http::fake([
            '127.0.0.1:8766/health' => Http::response($this->healthyBody()),
            '127.0.0.1:8766/synthesize' => Http::response('RIFF-fake-wav-bytes', 200),
        ]);

        $this->artisan('tts:chatterbox:health --deep')
            ->expectsOutputToContain('Self-test PASSED')
            ->assertExitCode(0);
    }

    public function test_deep_fails_when_synthesis_does_not_return_wav(): void
    {
        config(['tts.provider' => 'local']);
        Http::fake([
            '127.0.0.1:8766/health' => Http::response($this->healthyBody()),
            '127.0.0.1:8766/synthesize' => Http::response('not-audio', 200),
        ]);

        $this->artisan('tts:chatterbox:health --deep')->assertExitCode(1);
    }
}
