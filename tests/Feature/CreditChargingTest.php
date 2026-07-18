<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\CreditTransaction;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Flow-level charging: every provider render debits its owner exactly once,
 * across all the generation paths — Studio takes, /v1 sync + async, the
 * Inspector lab — with the Speech→project import (api_project_mode=always)
 * explicitly NOT double-charging what the /v1 path already billed.
 */
class CreditChargingTest extends TestCase
{
    use RefreshDatabase;

    /** Micro-dollars per character at the $0.025/1k test rate. */
    private const MICRO_PER_CHAR = 25;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'cache.default' => 'array',
            'tts.models.chatterbox.cost_per_1k_chars' => 0.025,
            'tts.credit.markup' => 1.0,
        ]);
        Storage::fake('local');
    }

    private function fundedUser(?int $micro = 10_000_000): User
    {
        $user = User::factory()->create();
        $user->forceFill(['credit_balance_micro' => $micro])->save();

        return $user;
    }

    /** A 2-chunk project owned by $owner (two paragraphs, each standalone). */
    private function project(User $owner): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'Credit test',
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

    public function test_studio_renders_charge_the_project_owner_per_take(): void
    {
        $owner = $this->fundedUser();
        $project = $this->project($owner);
        $chunk = $project->chunks()->orderBy('position')->first();
        $service = app(ProjectService::class);

        $service->generateChunk($chunk);
        $service->generateChunk($chunk); // a regenerate is another billable render

        $this->assertSame(
            ['studio_generate', 'studio_generate'],
            CreditTransaction::orderBy('id')->pluck('source')->all(),
        );
        $this->assertSame(
            10_000_000 - 2 * mb_strlen($chunk->text) * self::MICRO_PER_CHAR,
            $owner->fresh()->credit_balance_micro,
        );
    }

    public function test_v1_sync_charges_each_segment_exactly_once_despite_project_import(): void
    {
        config(['tts.api_project_mode' => 'always']);

        $owner = $this->fundedUser();
        $key = ApiKey::generate('k', null, $owner->id);
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', [
                'text' => "This is the first paragraph with plenty of words to stand on its own.\n\n".
                          'This is the second paragraph, also long enough to be its own chunk.',
            ])->assertStatus(200);

        // The always-mode import created a project whose chunks are the exact
        // segments /v1 read — its spend counter is the billing ground truth.
        $project = TtsProject::sole();
        $this->assertGreaterThan(0, $project->spent_characters);

        // Every charge row is an /v1 segment charge (the import books spend
        // counters only — recordTake ran with chargeCredit: false)...
        $charges = CreditTransaction::where('type', CreditTransaction::TYPE_CHARGE)->get();
        $this->assertSame(['api'], $charges->pluck('source')->unique()->all());
        $this->assertSame($project->chunks()->count(), $charges->count());

        // ...and the money matches the imported project's characters exactly
        // once — the double-charge would show up here as 2×.
        $this->assertSame(
            10_000_000 - (int) $project->spent_characters * self::MICRO_PER_CHAR,
            $owner->fresh()->credit_balance_micro,
        );
    }

    public function test_async_jobs_charge_the_key_owner(): void
    {
        $owner = $this->fundedUser();
        $key = ApiKey::generate('k', null, $owner->id);
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);

        // The sync queue driver runs GenerateSpeechJob inline.
        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice/jobs', ['text' => 'Hello async world.'])
            ->assertStatus(200);

        $this->assertTrue(CreditTransaction::where('source', 'api')->exists());
        $this->assertLessThan(10_000_000, $owner->fresh()->credit_balance_micro);
    }

    public function test_an_unlimited_user_gets_ledger_rows_but_no_decrement(): void
    {
        $owner = User::factory()->create(); // NULL balance = unlimited
        $project = $this->project($owner);
        $chunk = $project->chunks()->orderBy('position')->first();

        app(ProjectService::class)->generateChunk($chunk);

        $this->assertSame(1, CreditTransaction::count());
        $this->assertNull($owner->fresh()->credit_balance_micro);
    }

    public function test_a_generation_that_started_under_budget_finishes_and_overshoots(): void
    {
        $owner = $this->fundedUser(1); // positive, but far below one render
        $project = $this->project($owner);
        $chunk = $project->chunks()->orderBy('position')->first();

        app(ProjectService::class)->generateChunk($chunk);

        $this->assertSame('completed', $chunk->fresh()->status->value);
        $this->assertLessThan(0, $owner->fresh()->credit_balance_micro);
    }

    public function test_the_inspector_lab_charges_the_signed_in_user(): void
    {
        $user = $this->fundedUser();
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $this->actingAs($user)
            ->postJson(route('admin.studio.synthesize'), ['text' => 'Inspect this sentence.', 'voice' => 'v'])
            ->assertStatus(200);

        $tx = CreditTransaction::sole();
        $this->assertSame('inspector', $tx->source);
        $this->assertSame($user->id, $tx->user_id);
        $this->assertSame(
            10_000_000 - mb_strlen('Inspect this sentence.') * self::MICRO_PER_CHAR,
            $user->fresh()->credit_balance_micro,
        );
    }
}
