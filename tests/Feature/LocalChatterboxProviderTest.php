<?php

namespace Tests\Feature;

use App\Services\Tts\LocalChatterboxProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The local-sidecar driver (TTS_PROVIDER=local) must speak the sidecar's own
 * multipart contract — text/model plus each engine's native knobs — while
 * keeping the catalog-driven guarantees (turbo's input cap, tag stripping)
 * identical to the Replicate driver. Unlike Replicate there is no preset
 * `voice` field and no retry logic; failures map to actionable messages.
 */
class LocalChatterboxProviderTest extends TestCase
{
    private function provider(): LocalChatterboxProvider
    {
        return new LocalChatterboxProvider([
            'url' => 'http://127.0.0.1:8766',
        ], 300, [
            'chatterbox' => [
                'label' => 'Chatterbox',
                'max_input_chars' => 0,
                'knobs' => 'chatterbox',
            ],
            'chatterbox-turbo' => [
                'label' => 'Chatterbox Turbo',
                'max_input_chars' => 500,
                'knobs' => 'turbo',
                'supports_tags' => true,
            ],
        ]);
    }

    private function fakeSuccess(): void
    {
        Http::fake(['127.0.0.1:8766/synthesize' => Http::response('RIFF-fake-wav-bytes', 200)]);
    }

    /** The multipart form fields of the recorded /synthesize request, keyed by name. */
    private function sentFields(): array
    {
        $fields = null;
        Http::assertSent(function (Request $request) use (&$fields) {
            if (! str_contains($request->url(), '/synthesize')) {
                return false;
            }

            $fields = [];
            foreach ($request->data() as $part) {
                $fields[$part['name']] = $part['contents'];
            }

            return true;
        });

        return $fields;
    }

    /** The full multipart part (contents + filename) for a field, or null. */
    private function sentPart(string $name): ?array
    {
        $part = null;
        Http::assertSent(function (Request $request) use (&$part, $name) {
            foreach ($request->data() as $candidate) {
                if (($candidate['name'] ?? null) === $name) {
                    $part = $candidate;
                }
            }

            return true;
        });

        return $part;
    }

    public function test_classic_payload_carries_native_knobs_and_strips_tags(): void
    {
        $this->fakeSuccess();

        $audio = $this->provider()->synthesize('So funny! [laugh] Right?', null, [
            'cfg_weight' => 0.7,
            'exaggeration' => 1.1,
            'temperature' => 0.9,
        ]);

        $this->assertSame('RIFF-fake-wav-bytes', $audio);

        $sent = $this->sentFields();
        $this->assertSame('So funny! Right?', $sent['text']); // classic reads tags aloud — stripped
        $this->assertSame('chatterbox', $sent['model']);
        $this->assertSame(0.7, $sent['cfg_weight']);
        $this->assertSame(1.1, $sent['exaggeration']);
        $this->assertSame(0.9, $sent['temperature']);
        $this->assertArrayNotHasKey('top_p', $sent);
        $this->assertArrayNotHasKey('seed', $sent);
    }

    public function test_turbo_payload_carries_sampling_knobs_and_keeps_tags(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('So funny! [laugh] Right?', null, [
            'model' => 'chatterbox-turbo',
            'temperature' => 1.0,
            'top_p' => 0.9,
            'top_k' => 500,
            'repetition_penalty' => 1.5,
        ]);

        $sent = $this->sentFields();
        $this->assertSame('So funny! [laugh] Right?', $sent['text']); // turbo renders tags
        $this->assertSame('chatterbox-turbo', $sent['model']);
        $this->assertSame(1.0, $sent['temperature']);
        $this->assertSame(0.9, $sent['top_p']);
        $this->assertSame(500, $sent['top_k']);
        $this->assertSame(1.5, $sent['repetition_penalty']);
        $this->assertArrayNotHasKey('cfg_weight', $sent);
        $this->assertArrayNotHasKey('exaggeration', $sent);
        // The sidecar has no named presets — a clip-less turbo request must
        // NOT send a voice field (it uses the model's built-in voice).
        $this->assertArrayNotHasKey('voice', $sent);
        $this->assertArrayNotHasKey('voice_preset', $sent);
    }

    public function test_a_reference_clip_is_attached_as_multipart(): void
    {
        $this->fakeSuccess();

        $clip = tempnam(sys_get_temp_dir(), 'clip').'.wav';
        file_put_contents($clip, 'RIFF-clip-bytes');

        try {
            $this->provider()->synthesize('Hi.', $clip, []);
        } finally {
            @unlink($clip);
        }

        $part = $this->sentPart('reference');
        $this->assertNotNull($part);
        $this->assertSame('RIFF-clip-bytes', $part['contents']);
        $this->assertSame(basename($clip), $part['filename']);
    }

    public function test_a_clipless_call_sends_no_reference_part(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, []);

        $this->assertNull($this->sentPart('reference'));
    }

    public function test_the_seed_is_forwarded_only_when_pinned(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, ['seed' => 4242]);

        $this->assertSame(4242, $this->sentFields()['seed']);
    }

    public function test_oversized_turbo_input_fails_before_any_http_call(): void
    {
        Http::fake();

        try {
            $this->provider()->synthesize(str_repeat('a', 501), null, ['model' => 'chatterbox-turbo']);
            $this->fail('Expected a RuntimeException for the 500-char cap.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringContainsString('501', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_unreachable_sidecar_maps_to_an_actionable_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        try {
            $this->provider()->synthesize('Hi.', null, []);
            $this->fail('Expected a RuntimeException for the connection failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unreachable at http://127.0.0.1:8766', $e->getMessage());
            $this->assertStringContainsString('docs/CHATTERBOX-LOCAL.md', $e->getMessage());
        }
    }

    public function test_a_model_load_failure_names_the_model(): void
    {
        Http::fake([
            '127.0.0.1:8766/synthesize' => Http::response(['error' => 'download failed', 'model' => 'chatterbox'], 503),
        ]);

        try {
            $this->provider()->synthesize('Hi.', null, []);
            $this->fail('Expected a RuntimeException for the 503.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("'chatterbox' model", $e->getMessage());
            $this->assertStringContainsString('download failed', $e->getMessage());
        }
    }

    public function test_other_sidecar_errors_surface_status_and_detail(): void
    {
        Http::fake([
            '127.0.0.1:8766/synthesize' => Http::response(['error' => 'boom'], 500),
        ]);

        try {
            $this->provider()->synthesize('Hi.', null, []);
            $this->fail('Expected a RuntimeException for the 500.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function test_output_container_is_always_wav(): void
    {
        $provider = $this->provider();

        $this->assertSame('wav', $provider->outputContainer());
        $this->assertSame('wav', $provider->outputContainer('chatterbox-turbo'));
    }

    public function test_the_container_binds_the_local_provider(): void
    {
        config(['tts.provider' => 'local']);

        $this->assertInstanceOf(LocalChatterboxProvider::class, app(TtsProvider::class));
    }
}
