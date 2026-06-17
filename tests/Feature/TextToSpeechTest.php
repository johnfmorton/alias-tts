<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
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

    public function test_it_returns_an_el_shaped_error_for_unknown_voice(): void
    {
        $key = $this->makeKey();

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/does-not-exist', ['text' => 'Hello']);

        $response->assertStatus(404)
            ->assertJsonPath('detail.message', fn ($m) => str_contains((string) $m, 'does-not-exist'));
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
