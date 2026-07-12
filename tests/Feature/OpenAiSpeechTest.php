<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The OpenAI-compatible surface (POST /v1/audio/speech). It adapts OpenAI's
 * request/response shape onto the same SpeechService the ElevenLabs /v1 endpoint
 * uses; these tests cover the translation and the OpenAI-shaped errors.
 */
class OpenAiSpeechTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** @return array<string, string> */
    private function bearer(ApiKey $key): array
    {
        return ['Authorization' => 'Bearer '.$key->key];
    }

    public function test_it_generates_mp3_audio_via_bearer_auth(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', [
                'model' => 'tts-1',
                'voice' => 'my-voice',
                'input' => 'Hello, this is a test.',
            ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('audio/mpeg', (string) $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());

        $speech = Speech::first();
        $this->assertNotNull($speech);
        $this->assertSame(SpeechStatus::Completed, $speech->status);
        Storage::disk('local')->assertExists($speech->audio_path);
    }

    public function test_wav_response_format_sets_the_wav_content_type(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', [
                'voice' => 'my-voice',
                'input' => 'Hello.',
                'response_format' => 'wav',
            ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('audio/wav', (string) $response->headers->get('content-type'));
    }

    public function test_an_unsupported_response_format_is_rejected_openai_shaped(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', [
                'voice' => 'my-voice',
                'input' => 'Hello.',
                'response_format' => 'opus',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('error.param', 'response_format')
            ->assertJsonPath('error.type', 'invalid_request_error');
    }

    public function test_missing_input_returns_an_openai_shaped_400(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', ['voice' => 'my-voice']);

        $response->assertStatus(400)
            ->assertJsonPath('error.param', 'input')
            ->assertJsonPath('error.message', fn ($m) => is_string($m) && $m !== '');
    }

    public function test_unknown_voice_returns_an_openai_shaped_404(): void
    {
        $key = $this->makeKey();

        $response = $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', ['voice' => 'nope', 'input' => 'Hello.']);

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'voice_not_found')
            ->assertJsonPath('error.message', fn ($m) => str_contains((string) $m, 'nope'));
    }

    public function test_a_bad_key_returns_an_openai_shaped_401(): void
    {
        $this->makeVoice();

        $response = $this->withHeaders(['Authorization' => 'Bearer sk_nope'])
            ->postJson('/v1/audio/speech', ['voice' => 'my-voice', 'input' => 'Hello.']);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key')
            ->assertJsonStructure(['error' => ['message', 'type', 'code']]);
    }

    public function test_a_voice_alias_maps_an_openai_preset_name_to_a_slug(): void
    {
        config(['tts.openai_voice_aliases' => ['alloy' => 'my-voice']]);

        $key = $this->makeKey();
        $this->makeVoice();

        $response = $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', ['voice' => 'alloy', 'input' => 'Hello.']);

        $response->assertStatus(200);
        $this->assertSame('my-voice', Speech::first()->voice->slug);
    }

    public function test_rate_limit_is_openai_shaped(): void
    {
        $key = $this->makeKey(rateLimit: 1);
        $this->makeVoice();

        $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', ['voice' => 'my-voice', 'input' => 'one'])
            ->assertStatus(200);

        $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', ['voice' => 'my-voice', 'input' => 'two'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limit_exceeded')
            ->assertJsonStructure(['error' => ['message', 'type']]);
    }

    public function test_it_reuses_the_shared_synthesis_cache(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();
        $payload = ['voice' => 'my-voice', 'input' => 'Cache me please.'];

        $this->withHeaders($this->bearer($key))->postJson('/v1/audio/speech', $payload)->assertStatus(200);
        $second = $this->withHeaders($this->bearer($key))->postJson('/v1/audio/speech', $payload);

        $second->assertStatus(200);
        $this->assertSame(1, Speech::count(), 'Second identical request should be served from cache.');
        $this->assertSame('HIT', $second->headers->get('x-cache'));
    }

    public function test_a_catalog_model_overrides_the_voices_engine(): void
    {
        $key = $this->makeKey();
        $this->makeVoice(); // classic chatterbox voice

        $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', [
                'model' => 'chatterbox-turbo',
                'voice' => 'my-voice',
                'input' => 'Ride the turbo.',
            ])
            ->assertStatus(200);

        $this->assertSame('chatterbox-turbo', Speech::firstOrFail()->settings['model'] ?? null);
    }

    public function test_an_unrecognized_model_keeps_the_voices_engine(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();

        // OpenAI's own model names must never silently switch engines.
        $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', [
                'model' => 'tts-1',
                'voice' => 'my-voice',
                'input' => 'Plain old default.',
            ])
            ->assertStatus(200);

        $this->assertArrayNotHasKey('model', Speech::firstOrFail()->settings ?? []);
    }

    public function test_an_operator_alias_maps_to_an_engine(): void
    {
        config(['tts.openai_model_aliases' => ['tts-1' => 'chatterbox-turbo']]);
        $key = $this->makeKey();
        $this->makeVoice();

        $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', [
                'model' => 'tts-1',
                'voice' => 'my-voice',
                'input' => 'Aliased to turbo.',
            ])
            ->assertStatus(200);

        $this->assertSame('chatterbox-turbo', Speech::firstOrFail()->settings['model'] ?? null);
    }

    public function test_the_engine_override_separates_the_cache(): void
    {
        $key = $this->makeKey();
        $this->makeVoice();
        $payload = ['voice' => 'my-voice', 'input' => 'Same words, two engines.'];

        $this->withHeaders($this->bearer($key))->postJson('/v1/audio/speech', $payload)->assertStatus(200);
        $this->withHeaders($this->bearer($key))
            ->postJson('/v1/audio/speech', $payload + ['model' => 'chatterbox-turbo'])
            ->assertStatus(200);

        // Different engines must never share cached audio.
        $this->assertSame(2, Speech::count());
    }
}
