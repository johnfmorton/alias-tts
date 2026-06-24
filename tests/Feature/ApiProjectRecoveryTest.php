<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\SpeechService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * tts.api_project_mode: whether a /v1 generation also creates an editable Studio
 * project. Exercised at the SpeechService level (the shared sync/async chokepoint).
 */
class ApiProjectRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function voice(): Voice
    {
        return Voice::create(['slug' => 'v', 'name' => 'V']);
    }

    /** Replace the provider with one that throws on every synthesize call. */
    private function failTheProvider(): void
    {
        $this->app->instance(TtsProvider::class, new class extends FakeTtsProvider
        {
            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                throw new RuntimeException('Replicate prediction failed: CUDA error: device-side assert triggered');
            }
        });
    }

    private function generate(Voice $voice): Speech
    {
        return app(SpeechService::class)->synthesize(
            apiKey: ApiKey::generate('test', 100),
            voice: $voice,
            text: 'Hello world, this is a short test of the recovery path.',
            settings: [],
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
        );
    }

    public function test_never_mode_creates_no_project(): void
    {
        config(['tts.api_project_mode' => 'never']);

        $this->generate($this->voice());

        $this->assertSame(0, TtsProject::count());
    }

    public function test_on_error_creates_nothing_on_success(): void
    {
        config(['tts.api_project_mode' => 'on_error']);

        $this->generate($this->voice());

        $this->assertSame(0, TtsProject::count());
    }

    public function test_always_mode_creates_a_kept_project_on_success(): void
    {
        config(['tts.api_project_mode' => 'always']);

        $speech = $this->generate($this->voice());

        $project = TtsProject::firstWhere('source_speech_id', $speech->id);
        $this->assertNotNull($project);
        $this->assertSame('api', $project->origin);
        $this->assertNull($project->failure_reason);
        $this->assertNull($project->expires_at, 'always-mode projects are kept, not auto-pruned');
        $this->assertTrue($project->chunks()->exists());
    }

    public function test_on_error_creates_a_recovery_project_and_still_rethrows(): void
    {
        config(['tts.api_project_mode' => 'on_error']);
        $this->failTheProvider();
        $voice = $this->voice();

        try {
            $this->generate($voice);
            $this->fail('generation should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CUDA', $e->getMessage());
        }

        $project = TtsProject::first();
        $this->assertNotNull($project);
        $this->assertSame('api_failure', $project->origin);
        $this->assertStringContainsString('CUDA', (string) $project->failure_reason);
        $this->assertSame(0, $project->failed_chunk_index, 'single-segment text fails at index 0');
        $this->assertNotNull($project->expires_at, 'recovery projects carry a TTL for the prune');
        $this->assertNotNull($project->source_speech_id);
    }
}
