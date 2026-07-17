<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Jobs\GenerateSpeechJob;
use App\Jobs\RunGenblazeJob;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\Credit\CreditService;
use App\Services\Genblaze\GenblazeRunStore;
use App\Services\ProjectService;
use App\Services\SpeechService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The out-of-credit gate: a drained balance blocks STARTING new generation
 * (dialect-correct errors on /v1, 402 JSON on panel endpoints, clean job
 * failures for queued work) while everything already generated stays fully
 * usable — playback, rebuild, downloads. NULL balance = unlimited and is
 * never gated.
 */
class CreditEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'cache.default' => 'array',
            'tts.models.chatterbox.cost_per_1k_chars' => 0.025,
        ]);
        Storage::fake('local');
    }

    private function userWithBalance(?int $micro): User
    {
        $user = User::factory()->create();
        $user->forceFill(['credit_balance_micro' => $micro])->save();

        return $user;
    }

    private function drained(): User
    {
        return $this->userWithBalance(0);
    }

    public function test_el_dialect_rejects_a_drained_key_with_402(): void
    {
        $key = ApiKey::generate('k', null, $this->drained()->id);
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello.'])
            ->assertStatus(402)
            ->assertJsonPath('detail.status', 402)
            ->assertJsonPath('detail.message', fn ($m) => str_contains((string) $m, 'out of credit'));
    }

    public function test_openai_dialect_rejects_with_429_insufficient_quota(): void
    {
        $key = ApiKey::generate('k', null, $this->drained()->id);
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);

        $this->withHeaders(['Authorization' => 'Bearer '.$key->key])
            ->postJson('/v1/audio/speech', ['model' => 'tts-1', 'voice' => 'my-voice', 'input' => 'Hello.'])
            ->assertStatus(429)
            ->assertJsonPath('error.type', 'insufficient_quota')
            ->assertJsonPath('error.code', 'insufficient_quota');
    }

    public function test_an_unlimited_owner_is_never_gated(): void
    {
        $key = ApiKey::generate('k', null, User::factory()->create()->id); // NULL balance
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello.'])
            ->assertStatus(200);
    }

    public function test_polls_and_audio_stay_available_after_the_balance_drains(): void
    {
        $owner = $this->userWithBalance(10_000_000);
        $key = ApiKey::generate('k', null, $owner->id);
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);
        $headers = ['xi-api-key' => $key->key];

        // Generate while funded (sync queue driver completes inline)…
        $id = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Hello async world.'])
            ->assertStatus(200)
            ->json('id');

        // …then drain the balance: new POSTs are blocked, reads are not.
        $owner->forceFill(['credit_balance_micro' => 0])->save();

        $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'More new work.'])
            ->assertStatus(402);

        $this->withHeaders($headers)->getJson("/v1/text-to-speech/jobs/{$id}")->assertStatus(200);
        $this->withHeaders($headers)->get("/v1/text-to-speech/jobs/{$id}/audio")->assertStatus(200);
    }

    public function test_studio_generation_is_gated_by_the_owner_not_the_viewer(): void
    {
        // A SuperAdmin (unlimited) working inside a drained user's project is
        // still blocked: renders would spend the OWNER's credit.
        $owner = $this->drained();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $project = $this->twoChunkProject($owner);
        $chunk = $project->chunks()->orderBy('position')->first();

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertStatus(402)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'out of credit'));

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.reroll', [$project, $chunk]))
            ->assertStatus(402);
    }

    public function test_existing_audio_survives_a_drained_balance(): void
    {
        $owner = $this->userWithBalance(10_000_000);
        $project = $this->twoChunkProject($owner);
        $service = app(ProjectService::class);
        $project->chunks()->orderBy('position')->get()->each(fn ($c) => $service->generateChunk($c));

        $owner->forceFill(['credit_balance_micro' => 0])->save();

        // Rebuild stitches STORED chunk audio locally (no provider call) and
        // the final download streams an existing file — both stay open.
        $this->actingAs($owner)
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(200);
        $this->actingAs($owner)
            ->get(route('admin.studio.projects.audio', $project))
            ->assertStatus(200);
    }

    public function test_a_queued_speech_fails_cleanly_when_the_balance_drains_in_queue(): void
    {
        Queue::fake();
        $owner = $this->userWithBalance(10_000_000);
        $key = ApiKey::generate('k', null, $owner->id);
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);
        $headers = ['xi-api-key' => $key->key];

        $id = $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Queued while funded.'])
            ->json('id');

        // Balance drains before the worker picks the job up.
        $owner->forceFill(['credit_balance_micro' => 0])->save();
        (new GenerateSpeechJob($id))->handle(app(SpeechService::class));

        $speech = Speech::findOrFail($id);
        $this->assertSame(SpeechStatus::Failed, $speech->status);
        $this->assertSame(CreditService::OUT_OF_CREDIT_MESSAGE, $speech->error_message);

        // The plugin's poll surfaces the message verbatim.
        $this->withHeaders($headers)
            ->getJson("/v1/text-to-speech/jobs/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('error', CreditService::OUT_OF_CREDIT_MESSAGE);
    }

    public function test_genblaze_run_is_gated_at_the_button_and_at_job_start(): void
    {
        $user = $this->drained();
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        $this->actingAs($user)
            ->postJson(route('admin.studio.genblaze.run'), ['text' => 'Hello there.', 'voice' => 'v'])
            ->assertStatus(402);

        // A run queued while funded still fails cleanly if the balance drains
        // before the worker starts (nothing has been spent yet).
        $store = app(GenblazeRunStore::class);
        $store->create('run-1');
        app()->call([new RunGenblazeJob('run-1', 'Hello there.', $voice->id, null, $user->id), 'handle']);

        $state = $store->get('run-1');
        $this->assertSame('failed', $state['status']);
        $this->assertSame(CreditService::OUT_OF_CREDIT_MESSAGE, $state['error']);
    }

    public function test_panel_test_endpoints_are_gated(): void
    {
        $user = $this->drained();
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        $this->actingAs($user)
            ->postJson(route('admin.voices.test', $voice))
            ->assertStatus(402);

        $this->actingAs($user)
            ->postJson(route('admin.pronunciations.test'), ['phonetic' => 'lah-RAH-vel'])
            ->assertStatus(402);

        $this->actingAs($user)
            ->postJson(route('admin.health.test.short'))
            ->assertStatus(402);

        $this->actingAs($user)
            ->postJson(route('admin.studio.synthesize'), ['text' => 'Blocked.', 'voice' => 'v'])
            ->assertStatus(402);

        $this->actingAs($user)
            ->postJson(route('admin.studio.stitch'), ['text' => 'Blocked.', 'voice' => 'v'])
            ->assertStatus(402);
    }

    /** A 2-chunk project owned by $owner (mirrors CreditChargingTest). */
    private function twoChunkProject(User $owner): TtsProject
    {
        $voice = Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'Enforcement test',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            userId: $owner->id,
        );
    }
}
