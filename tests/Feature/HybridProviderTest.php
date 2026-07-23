<?php

namespace Tests\Feature;

use App\Services\Tts\HybridTtsProvider;
use App\Services\Tts\LocalChatterboxProvider;
use App\Services\Tts\ReplicateChatterboxProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * TTS_PROVIDER=local is really a hybrid: chatterbox engines hit the local
 * sidecar, engines the sidecar can't run (local_capable=false, qwen) route to
 * Replicate per call — and a missing Replicate token only breaks THOSE voices,
 * with the provider's own clear error.
 */
class HybridProviderTest extends TestCase
{
    private const MODELS = [
        'chatterbox' => [
            'label' => 'Chatterbox',
            'model' => 'resemble-ai/chatterbox',
            'version' => 'classic-version',
            'text_field' => 'prompt',
            'reference_field' => 'audio_prompt',
            'max_input_chars' => 0,
            'knobs' => 'chatterbox',
        ],
        'qwen3-tts' => [
            'label' => 'Qwen3 TTS',
            'model' => 'qwen/qwen3-tts',
            'version' => 'qwen-version',
            'text_field' => 'text',
            'reference_field' => 'reference_audio',
            'max_input_chars' => 0,
            'knobs' => 'qwen3-tts',
            'supports_seed' => false,
            'local_capable' => false,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function provider(?string $token = 'r8_test'): HybridTtsProvider
    {
        return new HybridTtsProvider(
            local: new LocalChatterboxProvider(['url' => 'http://127.0.0.1:8766'], 300, self::MODELS),
            remote: new ReplicateChatterboxProvider([
                'token' => $token,
                'max_retries' => 1,
                'retry_base_ms' => 1,
                'retry_max_ms' => 10,
                'min_request_gap_ms' => 0,
            ], 300, self::MODELS),
            models: self::MODELS,
        );
    }

    public function test_a_chatterbox_render_goes_to_the_sidecar_only(): void
    {
        Http::fake(['127.0.0.1:8766/synthesize' => Http::response('RIFF-local-bytes', 200)]);

        $audio = $this->provider()->synthesize('Hello local.', null, []);

        $this->assertSame('RIFF-local-bytes', $audio);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.replicate.com'));
    }

    public function test_a_qwen_render_goes_to_replicate_only(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out.wav',
            ], 200),
            'replicate.delivery/*' => Http::response('REMOTE-AUDIO', 200),
        ]);

        $audio = $this->provider()->synthesize('Hello qwen.', null, ['model' => 'qwen3-tts']);

        $this->assertSame('REMOTE-AUDIO', $audio);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '127.0.0.1:8766'));
    }

    public function test_an_unknown_engine_falls_back_to_the_local_default(): void
    {
        Http::fake(['127.0.0.1:8766/synthesize' => Http::response('RIFF-local-bytes', 200)]);

        // A stale voices.model value must route local (the default engine),
        // never to a surprise paid render.
        $audio = $this->provider()->synthesize('Hello stale.', null, ['model' => 'discontinued-model']);

        $this->assertSame('RIFF-local-bytes', $audio);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.replicate.com'));
    }

    public function test_a_missing_token_only_breaks_the_remote_leg(): void
    {
        Http::fake(['127.0.0.1:8766/synthesize' => Http::response('RIFF-local-bytes', 200)]);
        $provider = $this->provider(token: null);

        // Chatterbox keeps rendering locally, untouched…
        $this->assertSame('RIFF-local-bytes', $provider->synthesize('Hi.', null, []));

        // …while a qwen render fails with the token error before any HTTP call.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REPLICATE_API_TOKEN');
        $provider->synthesize('Hi.', null, ['model' => 'qwen3-tts']);
    }

    public function test_the_container_binds_the_hybrid_for_provider_local(): void
    {
        config(['tts.provider' => 'local']);

        $this->assertInstanceOf(HybridTtsProvider::class, app(TtsProvider::class));
    }
}
