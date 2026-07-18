<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\PronunciationEntry;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Voice;
use App\Support\GenerationCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The upgraded Inspector: preview mirrors the PROJECT text pipeline
 * (pronunciation dictionary + spoken quotes), prices the render up front at the
 * viewer's rate, surfaces LLM respelling suggestions, and closes with "Create
 * project" — carrying stashed chunk renders across as takes without re-billing.
 */
class StudioInspectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
        Voice::create(['slug' => 'v', 'name' => 'V']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function user(): User
    {
        return User::factory()->create(['is_super_admin' => false]);
    }

    // ── Preview mirrors the project pipeline ────────────────────────────────

    public function test_preview_applies_the_approved_pronunciation_dictionary(): void
    {
        $user = $this->user();
        PronunciationEntry::create([
            'user_id' => $user->id, 'term' => 'DDEV', 'phonetic' => 'dee dev',
            'approved' => true, 'source' => 'user',
        ]);

        $res = $this->actingAs($user)
            ->postJson(route('admin.studio.preview'), ['text' => 'Install DDEV before anything else.']);

        $res->assertOk()
            ->assertJsonPath('pronunciation.applied.0.term', 'DDEV')
            ->assertJsonPath('pronunciation.applied.0.phonetic', 'dee dev');
        $this->assertStringContainsString('dee dev', $res->json('normalized'));
        $this->assertStringContainsString('dee dev', $res->json('chunks.0.text'));
    }

    public function test_preview_does_not_apply_another_users_dictionary(): void
    {
        PronunciationEntry::create([
            'user_id' => $this->user()->id, 'term' => 'DDEV', 'phonetic' => 'dee dev',
            'approved' => true, 'source' => 'user',
        ]);

        $res = $this->actingAs($this->user())
            ->postJson(route('admin.studio.preview'), ['text' => 'Install DDEV first.']);

        $res->assertOk()->assertJsonPath('pronunciation.applied', []);
        $this->assertStringContainsString('DDEV', $res->json('normalized'));
    }

    public function test_preview_voices_spoken_quotes_per_the_user_setting(): void
    {
        $user = $this->user();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.spoken_quotes', 'value' => 'open_close']);

        $res = $this->actingAs($user)
            ->postJson(route('admin.studio.preview'), ['text' => 'She said "the show must go on" and left.']);

        $res->assertOk();
        $this->assertStringContainsString('open quote', $res->json('normalized'));
        $this->assertSame(1, $res->json('spoken_quotes'));
    }

    // ── Cost estimate ───────────────────────────────────────────────────────

    public function test_preview_estimate_quotes_a_user_at_the_marked_up_rate(): void
    {
        config(['tts.credit.markup' => 2.0]);
        $user = $this->user();
        $user->forceFill(['credit_balance_micro' => 5_000_000])->save();

        $res = $this->actingAs($user)
            ->postJson(route('admin.studio.preview'), ['text' => 'A sentence long enough to price sensibly.']);

        $res->assertOk();
        $estimate = $res->json('estimate');
        $this->assertSame(GenerationCost::label(['chatterbox' => $estimate['chars']], 2.0), $estimate['label']);
        $this->assertStringContainsString("your account's", $estimate['title']);
        $this->assertStringNotContainsString('provider', $estimate['title']);
        $this->assertSame('credit $5.00', $estimate['balance']['label']);
        $this->assertFalse($estimate['balance']['low']);
    }

    public function test_preview_estimate_shows_a_superadmin_actual_cost_plus_the_billed_note(): void
    {
        config(['tts.credit.markup' => 2.0]);

        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.preview'), ['text' => 'A sentence long enough to price sensibly.']);

        $res->assertOk();
        $estimate = $res->json('estimate');
        $this->assertSame(GenerationCost::label(['chatterbox' => $estimate['chars']]), $estimate['label']);
        $this->assertStringContainsString('Users are billed 2×', $estimate['title']);
        $this->assertNull($estimate['balance']); // unlimited account
    }

    public function test_preview_estimate_is_absent_when_no_rates_are_configured(): void
    {
        config(['tts.models.chatterbox.cost_per_1k_chars' => 0, 'tts.models.chatterbox-turbo.cost_per_1k_chars' => 0]);

        $this->actingAs($this->user())
            ->postJson(route('admin.studio.preview'), ['text' => 'Free install.'])
            ->assertOk()
            ->assertJsonPath('estimate', null);
    }

    // ── Pronunciation suggestions ───────────────────────────────────────────

    public function test_suggestions_endpoint_returns_new_terms_minus_the_known_ones(): void
    {
        config(['tts.pronunciation.enabled' => true, 'tts.genblaze.runner_url' => 'http://runner.test']);
        $user = $this->user();
        PronunciationEntry::create([
            'user_id' => $user->id, 'term' => 'nginx', 'phonetic' => 'engine ex',
            'approved' => true, 'source' => 'user',
        ]);
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
                ['term' => 'nginx', 'phonetic' => 'engine ex', 'category' => 'initialism', 'confidence' => 'high'],
            ],
        ])]);

        $this->actingAs($user)
            ->postJson(route('admin.studio.pronunciation.suggestions'), ['text' => 'DDEV proxies nginx.'])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.term', 'DDEV');
    }

    public function test_suggestions_endpoint_degrades_silently_when_the_feature_is_off(): void
    {
        config(['tts.pronunciation.enabled' => false]);
        Http::fake();

        $this->actingAs($this->user())
            ->postJson(route('admin.studio.pronunciation.suggestions'), ['text' => 'DDEV rocks.'])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('suggestions', []);

        Http::assertNothingSent();
    }

    public function test_approving_a_suggestion_adds_an_approved_dictionary_entry(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('admin.studio.pronunciation.approve'), [
                'term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('pronunciation_entries', [
            'user_id' => $user->id, 'term' => 'DDEV', 'phonetic' => 'dee dev',
            'approved' => true, 'source' => 'llm',
        ]);
    }

    // ── Stash + create-project carry-over ───────────────────────────────────

    public function test_synthesize_with_stash_parks_the_raw_render_under_a_token(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user)
            ->post(route('admin.studio.synthesize'), ['text' => 'A chunk worth keeping.', 'stash' => '1']);

        $res->assertOk();
        $token = (string) $res->headers->get('X-Inspector-Take');
        $this->assertNotSame('', $token);

        $disk = Storage::disk('local');
        $disk->assertExists("inspector-takes/{$user->id}/{$token}.wav");
        $meta = json_decode((string) $disk->get("inspector-takes/{$user->id}/{$token}.json"), true);
        $this->assertSame('A chunk worth keeping.', $meta['text']);
        $this->assertSame(Voice::first()->id, $meta['voice_id']);
    }

    public function test_synthesize_returns_the_debited_balance_for_a_metered_user(): void
    {
        // A charged render hands the fresh balance back in a header so the
        // "credit" badge tracks spend live. Markup 1× keeps the math simple.
        config(['tts.credit.markup' => 1.0]);
        $user = $this->user();
        $user->forceFill(['credit_balance_micro' => 5_000_000])->save();

        $res = $this->actingAs($user)
            ->post(route('admin.studio.synthesize'), ['text' => 'Charge me for this render.']);

        $res->assertOk();
        $badge = json_decode((string) $res->headers->get('X-Credit-Balance'), true);
        $this->assertIsArray($badge);
        // The balance dropped by the render's cost — and it's the debited figure,
        // not the pre-charge $5.00 (which would mean the badge went stale).
        $fresh = $user->fresh()->credit_balance_micro;
        $this->assertLessThan(5_000_000, $fresh);
        $this->assertSame('credit '.\App\Services\Credit\CreditService::formatMicro($fresh), $badge['label']);
        $this->assertFalse($badge['low']);
    }

    public function test_synthesize_omits_the_balance_header_for_an_unlimited_user(): void
    {
        // Unlimited accounts (NULL balance) get no readout — and no header.
        $res = $this->actingAs($this->user())
            ->post(route('admin.studio.synthesize'), ['text' => 'No metering here.']);

        $res->assertOk();
        $this->assertFalse($res->headers->has('X-Credit-Balance'));
    }

    public function test_synthesize_without_stash_returns_no_token_and_stores_nothing(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user)
            ->post(route('admin.studio.synthesize'), ['text' => 'Just listening.']);

        $res->assertOk();
        $this->assertFalse($res->headers->has('X-Inspector-Take'));
        $this->assertSame([], Storage::disk('local')->allFiles('inspector-takes'));
    }

    public function test_create_project_from_inspector_carries_stashed_renders_as_takes(): void
    {
        $user = $this->user();
        $text = "This is the first paragraph with plenty of words to stand on its own.\n\n".
                'This is the second paragraph, also long enough to be its own chunk.';

        $preview = $this->actingAs($user)
            ->postJson(route('admin.studio.preview'), ['text' => $text])->assertOk();
        $chunkText = $preview->json('chunks.0.text');

        $token = (string) $this->actingAs($user)
            ->post(route('admin.studio.synthesize'), ['text' => $chunkText, 'voice' => 'v', 'stash' => '1'])
            ->assertOk()->headers->get('X-Inspector-Take');

        $res = $this->actingAs($user)->postJson(route('admin.studio.projects.from-inspector'), [
            'text' => $text,
            'voice' => 'v',
            'takes' => [['index' => 0, 'token' => $token]],
        ]);

        $res->assertOk()->assertJsonPath('ok', true)->assertJsonPath('attached', 1);

        $project = TtsProject::firstOrFail();
        $this->assertSame($user->id, $project->user_id);
        $this->assertSame('This is the first paragraph with plenty', $project->title);

        $chunks = $project->chunks()->orderBy('position')->get();
        $this->assertCount(2, $chunks);
        $this->assertSame('completed', $chunks[0]->status->value);
        $this->assertSame('pending', $chunks[1]->status->value);

        $take = $chunks[0]->takes()->firstOrFail();
        $this->assertSame('inspector', $take->source);
        $this->assertSame($chunkText, $take->text);
        Storage::disk('local')->assertExists($take->audio_path);
        $this->assertSame($take->audio_path, $chunks[0]->audio_path);

        // The carried spend now lives on the project's counters…
        $this->assertSame(mb_strlen($chunkText), (int) $chunks[0]->fresh()->spent_characters);
        // …but credit was charged exactly once, at Inspector render time.
        $this->assertSame(1, CreditTransaction::where('source', 'inspector')->count());
        $this->assertSame(0, CreditTransaction::where('source', 'studio_inspector')->count());

        // Consumed stash files are gone.
        $this->assertSame([], Storage::disk('local')->allFiles("inspector-takes/{$user->id}"));
    }

    public function test_create_project_skips_a_take_whose_text_no_longer_matches(): void
    {
        $user = $this->user();

        $token = (string) $this->actingAs($user)
            ->post(route('admin.studio.synthesize'), ['text' => 'The words I actually rendered.', 'voice' => 'v', 'stash' => '1'])
            ->assertOk()->headers->get('X-Inspector-Take');

        $res = $this->actingAs($user)->postJson(route('admin.studio.projects.from-inspector'), [
            'text' => 'Completely different words now.',
            'voice' => 'v',
            'takes' => [['index' => 0, 'token' => $token]],
        ]);

        $res->assertOk()->assertJsonPath('attached', 0);

        $chunk = TtsProject::firstOrFail()->chunks()->firstOrFail();
        $this->assertSame('pending', $chunk->status->value);
        $this->assertSame(0, $chunk->takes()->count());
    }

    public function test_create_project_cannot_adopt_another_users_stash(): void
    {
        $owner = $this->user();
        $token = (string) $this->actingAs($owner)
            ->post(route('admin.studio.synthesize'), ['text' => 'Mine alone.', 'voice' => 'v', 'stash' => '1'])
            ->assertOk()->headers->get('X-Inspector-Take');

        $this->actingAs($this->user())->postJson(route('admin.studio.projects.from-inspector'), [
            'text' => 'Mine alone.',
            'voice' => 'v',
            'takes' => [['index' => 0, 'token' => $token]],
        ])->assertOk()->assertJsonPath('attached', 0);

        // The owner's stash is untouched.
        Storage::disk('local')->assertExists("inspector-takes/{$owner->id}/{$token}.wav");
    }

    public function test_create_project_uses_an_explicit_title_when_given(): void
    {
        $this->actingAs($this->user())->postJson(route('admin.studio.projects.from-inspector'), [
            'text' => 'Some text.',
            'voice' => 'v',
            'title' => 'My inspection',
        ])->assertOk();

        $this->assertSame('My inspection', TtsProject::firstOrFail()->title);
    }

    public function test_create_project_validates_like_the_other_ajax_endpoints(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('admin.studio.projects.from-inspector'), ['text' => '', 'voice' => 'v'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->actingAs($this->user())
            ->postJson(route('admin.studio.projects.from-inspector'), ['text' => 'Hello.', 'voice' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown voice.');

        $this->actingAs($this->user())
            ->postJson(route('admin.studio.projects.from-inspector'), [
                'text' => 'Hello.', 'voice' => 'v',
                'takes' => [['index' => 0, 'token' => '../../etc/passwd']],
            ])
            ->assertStatus(422);
    }

    // ── Wording ─────────────────────────────────────────────────────────────

    public function test_inspector_copy_does_not_mention_the_bespoken_plugin(): void
    {
        $this->actingAs($this->user())
            ->get(route('admin.studio.index'))
            ->assertOk()
            ->assertSee('Cleaned and normalized text of')
            ->assertDontSee('Bespoken plugin');
    }
}
