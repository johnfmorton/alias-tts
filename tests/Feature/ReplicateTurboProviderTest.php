<?php

namespace Tests\Feature;

use App\Services\Tts\ReplicateChatterboxProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * The provider must build a per-model payload from the catalog: turbo speaks
 * text/reference_audio with sampling knobs (top_p/top_k/repetition_penalty),
 * classic keeps prompt/audio_prompt with cfg_weight/exaggeration — and each
 * posts against its own pinned version.
 */
class ReplicateTurboProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function provider(): ReplicateChatterboxProvider
    {
        return new ReplicateChatterboxProvider([
            'token' => 'r8_test',
            'max_retries' => 1,
            'retry_base_ms' => 1,
            'retry_max_ms' => 10,
            'min_request_gap_ms' => 0,
        ], 300, [
            'chatterbox' => [
                'label' => 'Chatterbox',
                'model' => 'resemble-ai/chatterbox',
                'version' => 'classic-version',
                'text_field' => 'prompt',
                'reference_field' => 'audio_prompt',
                'output_container' => 'wav',
                'max_input_chars' => 0,
                'knobs' => 'chatterbox',
            ],
            'chatterbox-turbo' => [
                'label' => 'Chatterbox Turbo',
                'model' => 'resemble-ai/chatterbox-turbo',
                'version' => 'turbo-version',
                'text_field' => 'text',
                'reference_field' => 'reference_audio',
                'output_container' => 'wav',
                'max_input_chars' => 500,
                'knobs' => 'turbo',
                'supports_tags' => true,
            ],
        ]);
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'api.replicate.com/v1/predictions' => Http::response([
                'id' => 'pred_1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/out.wav',
            ], 200),
            'replicate.delivery/*' => Http::response('AUDIO-BYTES', 200),
        ]);
    }

    private function sentInput(): array
    {
        $input = null;
        Http::assertSent(function (Request $request) use (&$input) {
            if (str_contains($request->url(), '/predictions')) {
                $input = $request->data();

                return true;
            }

            return false;
        });

        return $input;
    }

    public function test_turbo_payload_uses_turbo_fields_knobs_and_version(): void
    {
        $this->fakeSuccess();

        $audio = $this->provider()->synthesize('Hello turbo.', null, [
            'model' => 'chatterbox-turbo',
            'temperature' => 1.0,
            'top_p' => 0.9,
            'top_k' => 500,
            'repetition_penalty' => 1.5,
        ]);

        $this->assertSame('AUDIO-BYTES', $audio);

        $sent = $this->sentInput();
        $this->assertSame('turbo-version', $sent['version']);
        $this->assertSame('Hello turbo.', $sent['input']['text']);
        $this->assertSame(1.0, $sent['input']['temperature']);
        $this->assertSame(0.9, $sent['input']['top_p']);
        $this->assertSame(500, $sent['input']['top_k']);
        $this->assertSame(1.5, $sent['input']['repetition_penalty']);

        // Classic-only keys must never reach turbo.
        $this->assertArrayNotHasKey('prompt', $sent['input']);
        $this->assertArrayNotHasKey('cfg_weight', $sent['input']);
        $this->assertArrayNotHasKey('exaggeration', $sent['input']);
        // No seed pinned -> omitted (turbo treats absent as random).
        $this->assertArrayNotHasKey('seed', $sent['input']);
    }

    public function test_clipless_turbo_sends_a_preset_voice(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, [
            'model' => 'chatterbox-turbo',
            'voice_preset' => 'Laura',
        ]);

        $this->assertSame('Laura', $this->sentInput()['input']['voice']);
    }

    public function test_clipless_turbo_defaults_to_andy(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, ['model' => 'chatterbox-turbo']);

        $this->assertSame('Andy', $this->sentInput()['input']['voice']);
    }

    public function test_a_reference_clip_wins_over_the_preset(): void
    {
        $this->fakeSuccess();

        $clip = tempnam(sys_get_temp_dir(), 'clip').'.wav';
        file_put_contents($clip, 'RIFF-fake-wav-bytes');

        try {
            $this->provider()->synthesize('Hi.', $clip, [
                'model' => 'chatterbox-turbo',
                'voice_preset' => 'Laura',
            ]);
        } finally {
            @unlink($clip);
        }

        $sent = $this->sentInput();
        $this->assertStringStartsWith('data:audio/wav;base64,', $sent['input']['reference_audio']);
        $this->assertArrayNotHasKey('voice', $sent['input']);
        $this->assertArrayNotHasKey('audio_prompt', $sent['input']);
    }

    public function test_turbo_pins_the_seed_when_provided(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, ['model' => 'chatterbox-turbo', 'seed' => 4242]);

        $this->assertSame(4242, $this->sentInput()['input']['seed']);
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

    public function test_absent_model_key_keeps_the_classic_payload(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hello classic.', null, []);

        $sent = $this->sentInput();
        $this->assertSame('classic-version', $sent['version']);
        $this->assertSame('Hello classic.', $sent['input']['prompt']);
        $this->assertSame(0.5, $sent['input']['cfg_weight']);
        $this->assertSame(0.5, $sent['input']['exaggeration']);
        $this->assertSame(0.8, $sent['input']['temperature']);
        $this->assertArrayNotHasKey('top_p', $sent['input']);
        $this->assertArrayNotHasKey('voice', $sent['input']);
    }

    public function test_output_container_resolves_per_model(): void
    {
        $provider = $this->provider();

        $this->assertSame('wav', $provider->outputContainer());
        $this->assertSame('wav', $provider->outputContainer('chatterbox-turbo'));
    }

    public function test_classic_payloads_strip_sound_tags_turbo_keeps_them(): void
    {
        $this->fakeSuccess();
        $text = 'So funny! [laugh] Right?';

        // Classic chatterbox would read "[laugh]" aloud — the payload drops it.
        $this->provider()->synthesize($text, null, []);
        $this->assertSame('So funny! Right?', $this->sentInput()['input']['prompt']);

        // Turbo renders it as an actual laugh — the payload keeps it.
        // (sentInput() reads the LAST recorded prediction request.)
        $this->provider()->synthesize($text, null, ['model' => 'chatterbox-turbo']);
        $this->assertSame($text, $this->sentInput()['input']['text']);
    }
}
