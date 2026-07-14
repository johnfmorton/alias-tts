<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\SpeechService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /** A super-admin user (the panel pages the recovery tests hit are admin-only). */
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

        // A successful audio project is named by its text snippet alone — the "API"
        // badge carries the provenance, so there is no "API generation:" prefix
        // (unlike the "API failure:" auto-name a failed generation keeps).
        $this->assertSame('Hello world, this is a short test of the', $project->title);

        // The finished generation is carried across, not left for the admin to
        // regenerate: every chunk is Completed with its raw audio on disk...
        $chunks = $project->chunks()->get();
        $this->assertTrue($chunks->isNotEmpty());
        $this->assertTrue(
            $chunks->every(fn ($c) => $c->status === ChunkStatus::Completed),
            'every retained chunk is generated, not pending',
        );
        foreach ($chunks as $chunk) {
            $this->assertNotNull($chunk->audio_path);
            Storage::disk('local')->assertExists($chunk->audio_path);
        }

        // ...and the project's final file IS the audio the API returned (Ready,
        // byte-for-byte), so the panel doesn't show a "draft" final.
        $this->assertSame(ProjectStatus::Ready, $project->status);
        $this->assertNotNull($project->final_audio_path);
        Storage::disk('local')->assertExists($project->final_audio_path);
        $this->assertSame(
            Storage::disk('local')->get($speech->audio_path),
            Storage::disk('local')->get($project->final_audio_path),
            'the project final is the same concatenated audio /v1 returned',
        );
    }

    public function test_a_job_retry_does_not_duplicate_the_recovery_project(): void
    {
        config(['tts.api_project_mode' => 'on_error']);
        $this->failTheProvider();
        $voice = $this->voice();

        try {
            $this->generate($voice);
        } catch (RuntimeException) {
        }

        $speech = Speech::firstWhere('status', SpeechStatus::Failed);
        $this->assertSame(1, TtsProject::where('source_speech_id', $speech->id)->count());

        // An async job retry re-runs process() on the same record — it must not
        // create a second recovery project.
        try {
            app(SpeechService::class)->process($speech);
        } catch (RuntimeException) {
        }

        $this->assertSame(1, TtsProject::where('source_speech_id', $speech->id)->count(), 'no duplicate on retry');
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

        // A failed generation keeps the "API failure: <snippet>" auto-name so it
        // reads as needing attention (dismissing the banner strips this prefix).
        $this->assertSame('API failure: Hello world, this is a short test of the', $project->title);
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
        // A plain panel URL, never a magic-login token (the /v1 caller isn't an admin).
        $this->assertStringContainsString('/admin/studio/projects/', (string) $res->json('detail.recovery_url'));
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
        $this->assertStringContainsString('/admin/studio/projects/', (string) $res->json('recovery_url'));
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

        // Tier-1 discovery: the list badges it, the page explains it. The
        // project is ownerless, so it sits outside the SuperAdmin's default
        // own-projects scope — widen to all owners.
        $this->actingAs($this->admin())
            ->get(route('admin.studio.index', ['owner' => 'all']))
            ->assertOk()
            ->assertSee('API failure');

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('Recovered from a failed API generation')
            ->assertSee('CUDA error: device-side assert triggered');
    }

    public function test_dismiss_clears_the_failure_flag_and_strips_the_auto_title(): void
    {
        $voice = Voice::create(['slug' => 'v3', 'name' => 'V3']);
        $project = TtsProject::create([
            'voice_id' => $voice->id,
            'title' => 'API failure: Hello world recovery',
            'origin' => 'api_failure',
            'failure_reason' => 'CUDA error: device-side assert triggered',
            'failed_chunk_index' => 0,
            'expires_at' => now()->addDay(),
            'source_text' => 'Hello.',
            'normalized_text' => 'Hello.',
            'status' => ProjectStatus::Draft,
            'model_id' => config('tts.default_model_id'),
            'output_format' => config('tts.default_output_format'),
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.dismiss-failure', $project))
            ->assertOk()
            ->assertJson(['ok' => true, 'title' => 'Hello world recovery']);

        $project->refresh();
        $this->assertNull($project->origin, 'no longer an api_failure project');
        $this->assertNull($project->failure_reason);
        $this->assertNull($project->failed_chunk_index);
        $this->assertNull($project->expires_at, 'TTL cleared so the prune leaves it alone');
        $this->assertSame('Hello world recovery', $project->title, 'auto "API failure: " prefix stripped');

        // The index no longer badges it and the page no longer explains it.
        $this->actingAs($this->admin())
            ->get(route('admin.studio.index', ['owner' => 'all']))
            ->assertOk()
            ->assertDontSee('API failure');

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertDontSee('Recovered from a failed API generation');
    }

    public function test_dismiss_keeps_a_hand_edited_title(): void
    {
        $voice = Voice::create(['slug' => 'v4', 'name' => 'V4']);
        $project = TtsProject::create([
            'voice_id' => $voice->id,
            'title' => 'My important narration',
            'origin' => 'api_failure',
            'failure_reason' => 'boom',
            'source_text' => 'Hello.',
            'normalized_text' => 'Hello.',
            'status' => ProjectStatus::Draft,
            'model_id' => config('tts.default_model_id'),
            'output_format' => config('tts.default_output_format'),
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.dismiss-failure', $project))
            ->assertOk()
            ->assertJson(['title' => 'My important narration']);

        $this->assertNull($project->refresh()->origin);
        $this->assertSame('My important narration', $project->title);
    }

    private function recoveryProject(Voice $voice, ?Carbon $expiresAt, string $origin = 'api_failure'): TtsProject
    {
        $project = app(ProjectService::class)->createFromText(
            'Recovery', $voice, 'A sentence to chunk.', [],
            config('tts.default_model_id'), config('tts.default_output_format'), null,
        );
        $project->update(['origin' => $origin, 'expires_at' => $expiresAt]);

        return $project;
    }

    public function test_prune_removes_only_expired_untouched_recovery_projects(): void
    {
        $voice = Voice::create(['slug' => 'pv', 'name' => 'PV']);

        $expired = $this->recoveryProject($voice, now()->subDay());            // expired + untouched -> pruned
        $touched = $this->recoveryProject($voice, now()->subDay());            // expired but worked on -> kept
        $touched->chunks()->first()->update(['audio_path' => 'p/x.wav', 'status' => ChunkStatus::Completed]);
        $fresh = $this->recoveryProject($voice, now()->addDay());              // not yet expired -> kept
        $always = $this->recoveryProject($voice, null, 'api');                 // always-mode (no TTL) -> kept

        $this->artisan('projects:prune-recovery')->assertExitCode(0);

        $this->assertNull(TtsProject::find($expired->id), 'expired + untouched is pruned');
        $this->assertNotNull(TtsProject::find($touched->id), 'a started repair is kept');
        $this->assertNotNull(TtsProject::find($fresh->id), 'not-yet-expired is kept');
        $this->assertNotNull(TtsProject::find($always->id), 'always-mode (no TTL) is kept');
    }
}
