<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\ApiKey;
use App\Models\PronunciationEntry;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a project does no generation, but keep the cache isolated.
        config(['cache.default' => 'array']);
    }

    private function makeKey(): ApiKey
    {
        return ApiKey::generate('test');
    }

    private function makeVoice(string $slug = 'my-voice'): Voice
    {
        return Voice::create(['slug' => $slug, 'name' => 'My Voice']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** Two paragraphs, each long enough to stand alone => two chunks. */
    private function twoParagraphText(): string
    {
        return "This is the first paragraph with plenty of words to stand on its own.\n\n".
               'This is the second paragraph, also long enough to be its own chunk.';
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->makeVoice();

        $this->postJson('/v1/projects', ['voice_id' => 'my-voice', 'text' => 'Hello there.'])
            ->assertStatus(401)
            ->assertJsonStructure(['detail' => ['message']]);

        $this->assertSame(0, TtsProject::count());
    }

    public function test_it_rejects_an_invalid_api_key(): void
    {
        $this->makeVoice();

        $this->withHeaders(['xi-api-key' => 'sk_nope'])
            ->postJson('/v1/projects', ['voice_id' => 'my-voice', 'text' => 'Hello there.'])
            ->assertStatus(401)
            ->assertJsonStructure(['detail' => ['message']]);

        $this->assertSame(0, TtsProject::count());
    }

    public function test_it_rejects_a_deactivated_api_key(): void
    {
        $key = $this->makeKey();
        $key->update(['is_active' => false]);
        $this->makeVoice();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', ['voice_id' => 'my-voice', 'text' => 'Hello there.'])
            ->assertStatus(403);

        $this->assertSame(0, TtsProject::count());
    }

    public function test_it_returns_an_el_shaped_error_for_unknown_voice(): void
    {
        $key = $this->makeKey();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', ['voice_id' => 'does-not-exist', 'text' => 'Hello there.'])
            ->assertStatus(404)
            ->assertJsonPath('detail.message', fn ($m) => str_contains((string) $m, 'does-not-exist'));
    }

    public function test_a_voice_alias_maps_an_elevenlabs_voice_id_to_a_slug(): void
    {
        config(['tts.elevenlabs_voice_aliases' => ['21m00Tcm4TlvDq8ikWAM' => 'my-voice']]);

        $this->admin();
        $key = $this->makeKey();
        $this->makeVoice();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', [
                'voice_id' => '21m00Tcm4TlvDq8ikWAM',
                'text' => 'Hello there.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('voice_id', 'my-voice');
    }

    public function test_it_validates_required_text_and_voice(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', ['voice_id' => 'my-voice'])
            ->assertStatus(422)
            ->assertJsonStructure(['detail' => ['message']]);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', ['text' => 'Hello there.'])
            ->assertStatus(422)
            ->assertJsonStructure(['detail' => ['message']]);
    }

    public function test_it_creates_a_project_with_chunks_and_a_login_link(): void
    {
        $this->admin();
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', [
                'title' => 'My article',
                'voice_id' => 'my-voice',
                'text' => $this->twoParagraphText(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', 'My article')
            ->assertJsonPath('status', ProjectStatus::Draft->value)
            ->assertJsonPath('voice_id', 'my-voice')
            ->assertJsonPath('chunk_count', 2)
            ->assertJsonStructure(['id', 'url', 'characters', 'created_at'])
            // The auto-login edit link was removed (privilege-escalation risk); the
            // response is a plain pointer the owner opens after a normal login.
            ->assertJsonMissingPath('edit_url')
            ->assertJsonMissingPath('edit_url_expires_at');

        $project = TtsProject::firstWhere('title', 'My article');
        $this->assertNotNull($project);
        $this->assertCount(2, $project->chunks);
        $this->assertSame(ProjectStatus::Draft, $project->status);

        // The created project is tagged with the calling API key.
        $this->assertSame($key->id, $project->api_key_id);

        // ...and stamped as coming from the projects endpoint, which the Studio
        // list uses to tell it apart from a hand-made project and from an audio
        // generation persisted by the text-to-speech endpoints ('api').
        $this->assertSame('api_project', $project->origin);
    }

    public function test_the_spoken_quotes_setting_never_touches_v1_projects(): void
    {
        // The key owner has opted in, and /v1 runs under the owner's settings
        // overlay (ValidateApiKey) — but spoken quotes is a Studio/Genblaze
        // feature only: the mode is passed into ProjectService by the Studio
        // controllers, never read from config inside it. Pins the exclusion
        // against future refactors.
        $user = User::factory()->create();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.spoken_quotes', 'value' => 'open_close']);
        $key = ApiKey::generate('test', null, $user->id);
        $this->makeVoice();

        $text = 'He said, "Hello there." Then he left without another word.';
        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', [
                'title' => 'Quoted via API',
                'voice_id' => 'my-voice',
                'text' => $text,
            ])
            ->assertStatus(201);

        $this->assertSame($text, TtsProject::firstWhere('title', 'Quoted via API')->normalized_text);
    }

    public function test_apply_to_api_off_leaves_v1_project_text_verbatim(): void
    {
        // The default: even with an approved dictionary entry, /v1 stays a faithful
        // passthrough (the Bespoken plugin substitutes upstream) — the chunks keep
        // the writer's exact words.
        $user = User::factory()->create();
        PronunciationEntry::create([
            'user_id' => $user->id, 'term' => 'SHA-256', 'phonetic' => 'shah two fifty-six',
            'match_mode' => 'case_sensitive', 'source' => 'user', 'approved' => true,
        ]);
        $key = ApiKey::generate('test', null, $user->id);
        $this->makeVoice();

        $text = 'We seal every final with SHA-256 provenance.';
        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', ['title' => 'Off', 'voice_id' => 'my-voice', 'text' => $text])
            ->assertStatus(201);

        $project = TtsProject::firstWhere('title', 'Off');
        $this->assertSame($text, $project->source_text);
        $this->assertSame($text, $project->normalized_text);
    }

    public function test_apply_to_api_on_respells_chunks_but_keeps_source_text_verbatim(): void
    {
        // Opted in: the dictionary respells the chunks the voice will read, while
        // source_text (what "Start over" re-opens) keeps the original words — the
        // same split ProjectService gives a Studio-made project. The entry is
        // stored with a typographic en dash to also pin the dash-fold: it must
        // still match the plain hyphen the writer typed.
        $user = User::factory()->create();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.pronunciation.apply_to_api', 'value' => true]);
        PronunciationEntry::create([
            'user_id' => $user->id, 'term' => "SHA\u{2013}256", 'phonetic' => 'shah two fifty-six',
            'match_mode' => 'case_sensitive', 'source' => 'llm', 'approved' => true,
        ]);
        $key = ApiKey::generate('test', null, $user->id);
        $this->makeVoice();

        $text = 'We seal every final with SHA-256 provenance.';
        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', ['title' => 'On', 'voice_id' => 'my-voice', 'text' => $text])
            ->assertStatus(201);

        $project = TtsProject::firstWhere('title', 'On');
        $this->assertSame($text, $project->source_text);
        $this->assertStringContainsString('shah two fifty-six', (string) $project->normalized_text);
        $this->assertStringNotContainsString('SHA-256', (string) $project->normalized_text);
    }

    public function test_it_auto_generates_a_title_when_omitted(): void
    {
        $this->admin();
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', [
                'voice_id' => 'my-voice',
                'text' => 'A single short sentence to synthesize.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', fn ($t) => is_string($t) && str_starts_with($t, 'Audio project #'));
    }

    public function test_it_rejects_text_over_the_async_max_length(): void
    {
        config(['tts.max_async_text_length' => 10]);

        $key = $this->makeKey();
        $this->makeVoice();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/projects', ['voice_id' => 'my-voice', 'text' => str_repeat('a', 11)])
            ->assertStatus(422)
            ->assertJsonPath('detail.status', 422);
    }
}
