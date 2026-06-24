<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsProject;
use App\Models\User;
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

    /** A super-admin must exist for the magic-login recovery link to be minted. */
    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
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

    public function test_sync_failure_surfaces_a_recovery_url_in_the_error_detail(): void
    {
        config(['tts.api_project_mode' => 'on_error']);
        $this->admin();
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);
        $key = ApiKey::generate('test', 100);
        $this->failTheProvider();

        $res = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello world for recovery.']);

        $res->assertStatus(502);
        $this->assertStringContainsString('CUDA', (string) $res->json('detail.message'));
        $this->assertNotNull($res->json('detail.recovery_url'), 'a recovery link should be surfaced');
        $this->assertStringStartsWith('http', (string) $res->json('detail.recovery_url'));
        $this->assertSame('api_failure', TtsProject::first()?->origin);
    }

    public function test_never_mode_sync_failure_has_no_recovery_url(): void
    {
        config(['tts.api_project_mode' => 'never']);
        $this->admin();
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);
        $key = ApiKey::generate('test', 100);
        $this->failTheProvider();

        $res = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello.']);

        $res->assertStatus(502);
        $this->assertNull($res->json('detail.recovery_url'));
        $this->assertSame(0, TtsProject::count());
    }

    public function test_status_endpoint_surfaces_recovery_url_for_a_failed_job(): void
    {
        config(['tts.api_project_mode' => 'on_error']);
        $this->admin();
        $voice = Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);
        $key = ApiKey::generate('test', 100);
        $this->failTheProvider();

        try {
            app(SpeechService::class)->synthesize(
                apiKey: $key,
                voice: $voice,
                text: 'Hello async recovery.',
                settings: [],
                modelId: config('tts.default_model_id'),
                outputFormat: config('tts.default_output_format'),
            );
        } catch (RuntimeException) {
            // expected — the failed Speech + recovery project are now persisted
        }

        $speech = Speech::firstWhere('status', SpeechStatus::Failed);
        $this->assertNotNull($speech);

        $res = $this->withHeaders(['xi-api-key' => $key->key])
            ->getJson('/v1/text-to-speech/jobs/'.$speech->id);

        $res->assertOk()->assertJsonPath('status', 'failed');
        $this->assertNotNull($res->json('recovery_url'));
    }

    public function test_panel_badges_and_explains_an_api_failure_project(): void
    {
        $voice = Voice::create(['slug' => 'v2', 'name' => 'V2']);
        $project = TtsProject::create([
            'voice_id' => $voice->id,
            'title' => 'Recovered job',
            'origin' => 'api_failure',
            'failure_reason' => 'CUDA error: device-side assert triggered',
            'failed_chunk_index' => 0,
            'source_text' => 'Hello.',
            'normalized_text' => 'Hello.',
            'status' => ProjectStatus::Draft,
            'model_id' => config('tts.default_model_id'),
            'output_format' => config('tts.default_output_format'),
        ]);

        // Tier-1 discovery: the list badges it, the page explains it.
        $this->actingAs($this->admin())
            ->get(route('admin.studio.index'))
            ->assertOk()
            ->assertSee('API failure');

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('Recovered from a failed API generation')
            ->assertSee('CUDA error: device-side assert triggered');
    }
}
