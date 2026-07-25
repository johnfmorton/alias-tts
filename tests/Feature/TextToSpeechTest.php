<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TextToSpeechTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the deterministic provider + isolated cache/storage.
        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'cache.default' => 'array',
        ]);

        Storage::fake('local');
    }

    private function makeKey(?int $rateLimit = null): ApiKey
    {
        return ApiKey::generate('test', $rateLimit);
    }

    private function makeVoice(string $slug = 'my-voice'): Voice
    {
        return Voice::create(['slug' => $slug, 'name' => 'My Voice']);
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->makeVoice();

        $response = $this->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello']);

        $response->assertStatus(401)
            ->assertJsonPath('detail.message', fn ($m) => is_string($m) && $m !== '');
    }

    public function test_it_rejects_an_invalid_api_key(): void
    {
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => 'sk_nope'])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello']);

        $response->assertStatus(401)->assertJsonStructure(['detail' => ['message']]);
    }

    public function test_a_deactivated_owner_takes_their_keys_down_too(): void
    {
        $this->makeVoice();
        $owner = User::factory()->create(['status' => User::STATUS_SUSPENDED]);
        $key = ApiKey::generate('test', null, $owner->id);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello'])
            ->assertStatus(403)
            ->assertJsonPath('detail.message', 'This API key belongs to a deactivated account.');

        // Reactivating restores the key without touching it.
        $owner->update(['status' => User::STATUS_ACTIVE]);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello'])
            ->assertOk();
    }

    public function test_it_returns_an_el_shaped_error_for_unknown_voice(): void
    {
        $key = $this->makeKey();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/does-not-exist', ['text' => 'Hello']);

        $response->assertStatus(404)
            ->assertJsonPath('detail.message', fn ($m) => str_contains((string) $m, 'does-not-exist'));
    }

    public function test_a_voice_alias_maps_an_elevenlabs_voice_id_to_a_slug(): void
    {
        config(['tts.elevenlabs_voice_aliases' => ['21m00Tcm4TlvDq8ikWAM' => 'my-voice']]);

        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM', ['text' => 'Hello.']);

        $response->assertStatus(200);
        $this->assertSame('my-voice', Speech::first()->voice->slug);
    }

    public function test_a_voice_alias_maps_on_the_queued_jobs_endpoint(): void
    {
        config(['tts.elevenlabs_voice_aliases' => ['21m00Tcm4TlvDq8ikWAM' => 'my-voice']]);

        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM/jobs', ['text' => 'Hello.']);

        $response->assertSuccessful();
        $this->assertSame('my-voice', Speech::first()->voice->slug);
    }

    public function test_alias_lookup_is_case_sensitive_and_a_miss_passes_through(): void
    {
        // ElevenLabs voice IDs are case-sensitive, so the map matches exactly;
        // anything unlisted falls through to normal slug/UUID resolution.
        config(['tts.elevenlabs_voice_aliases' => ['21m00Tcm4TlvDq8ikWAM' => 'my-voice']]);

        $key = $this->makeKey();
        $this->makeVoice();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/21m00tcm4tlvdq8ikwam', ['text' => 'Hello.'])
            ->assertStatus(404);
    }

    public function test_an_alias_to_a_missing_voice_404s_with_the_original_voice_id(): void
    {
        config(['tts.elevenlabs_voice_aliases' => ['21m00Tcm4TlvDq8ikWAM' => 'not-a-real-slug']]);

        $key = $this->makeKey();

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM', ['text' => 'Hello.'])
            ->assertStatus(404)
            ->assertJsonPath('detail.message', fn ($m) => str_contains((string) $m, '21m00Tcm4TlvDq8ikWAM')
                && ! str_contains((string) $m, 'not-a-real-slug'));
    }

    public function test_it_generates_mp3_audio(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', [
                'text' => 'Hello, this is a test.',
                'model_id' => 'eleven_v3',
                'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
            ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('audio/mpeg', (string) $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
        $this->assertNotEmpty($response->headers->get('request-id'));

        $speech = Speech::first();
        $this->assertNotNull($speech);
        $this->assertSame(SpeechStatus::Completed, $speech->status);
        Storage::disk('local')->assertExists($speech->audio_path);
    }

    public function test_voice_default_settings_reach_the_api(): void
    {
        $key = $this->makeKey();
        Voice::create([
            'slug' => 'my-voice',
            'name' => 'My Voice',
            'settings' => ['stability' => 0.8, 'style' => 0.3],
        ]);

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'Hello.']);

        $response->assertStatus(200);

        // A request that sends no voice_settings inherits the voice's saved
        // tuning — the resolution chain that makes "save to voice defaults" mean
        // something for the plugin. (See docs/STUDIO-TUNING.md.)
        $settings = Speech::first()->settings;
        $this->assertSame(0.8, $settings['stability']);
        $this->assertSame(0.3, $settings['style']);
    }

    public function test_request_voice_settings_override_voice_defaults(): void
    {
        $key = $this->makeKey();
        Voice::create([
            'slug' => 'my-voice',
            'name' => 'My Voice',
            'settings' => ['stability' => 0.8],
        ]);

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', [
                'text' => 'Hello.',
                'voice_settings' => ['stability' => 0.2],
            ]);

        $response->assertStatus(200);
        $this->assertSame(0.2, Speech::first()->settings['stability']);
    }

    public function test_identical_requests_are_cached(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $payload = ['text' => 'Cache me please.'];

        $first = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', $payload);
        $second = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', $payload);

        $first->assertStatus(200);
        $second->assertStatus(200);

        $this->assertSame(1, Speech::count(), 'Second identical request should be served from cache.');
        $this->assertSame('HIT', $second->headers->get('x-cache'));
    }

    public function test_it_enforces_rate_limits(): void
    {
        $key = $this->makeKey(rateLimit: 1);
        $this->makeVoice();

        $headers = ['xi-api-key' => $key->key];

        $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'one'])
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson('/v1/text-to-speech/my-voice', ['text' => 'two'])
            ->assertStatus(429)
            ->assertJsonStructure(['detail' => ['message']]);
    }

    public function test_it_validates_required_text(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', []);

        $response->assertStatus(422)->assertJsonStructure(['detail' => ['message']]);
    }

    public function test_it_rejects_text_over_the_max_length(): void
    {
        config(['tts.max_text_length' => 10]);

        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/my-voice', ['text' => str_repeat('a', 11)]);

        $response->assertStatus(422)
            ->assertJsonPath('detail.status', 422)
            ->assertJsonPath('detail.message', fn ($m) => is_string($m) && $m !== '');
    }

    public function test_seed_creates_distinct_cache_entries(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();
        $headers = ['xi-api-key' => $key->key];

        $this->withHeaders($headers)->postJson('/v1/text-to-speech/my-voice', ['text' => 'same', 'seed' => 1])->assertStatus(200);
        $this->withHeaders($headers)->postJson('/v1/text-to-speech/my-voice', ['text' => 'same', 'seed' => 2])->assertStatus(200);
        $this->assertSame(2, Speech::count(), 'Different seeds should not share a cache entry.');

        // Same seed again -> served from cache, no new row.
        $this->withHeaders($headers)->postJson('/v1/text-to-speech/my-voice', ['text' => 'same', 'seed' => 1])->assertStatus(200);
        $this->assertSame(2, Speech::count());
    }

    public function test_voice_default_seed_is_applied(): void
    {
        $key = $this->makeKey();
        Voice::create(['slug' => 'seeded', 'name' => 'Seeded', 'settings' => ['seed' => 5]]);
        $headers = ['xi-api-key' => $key->key];

        // No seed in the request resolves to the voice's default seed...
        $this->withHeaders($headers)->postJson('/v1/text-to-speech/seeded', ['text' => 'hello'])->assertStatus(200);
        // ...so an explicit seed=5 hits the same cache entry (no new row).
        $this->withHeaders($headers)->postJson('/v1/text-to-speech/seeded', ['text' => 'hello', 'seed' => 5])->assertStatus(200);
        $this->assertSame(1, Speech::count(), 'Request without a seed should resolve to the voice default.');
    }
}
