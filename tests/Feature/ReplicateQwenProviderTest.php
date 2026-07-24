<?php

namespace Tests\Feature;

use App\Services\Tts\ReplicateChatterboxProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Qwen3 TTS validates its input, so its payload must hold schema keys ONLY:
 * mode/speaker (clipless) or mode/reference_audio/reference_text (clip), plus
 * language/style_instruction when non-default. None of the chatterbox numeric
 * knobs — and never a seed (qwen's schema has no seed field).
 */
class ReplicateQwenProviderTest extends TestCase
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
            'qwen3-tts' => [
                'label' => 'Qwen3 TTS',
                'model' => 'qwen/qwen3-tts',
                'version' => 'qwen-version',
                'text_field' => 'text',
                'reference_field' => 'reference_audio',
                'output_container' => 'wav',
                'max_input_chars' => 0,
                'knobs' => 'qwen3-tts',
                'supports_tags' => false,
                'supports_seed' => false,
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

    /** @return string a temp clip path the caller must unlink */
    private function clip(): string
    {
        $clip = tempnam(sys_get_temp_dir(), 'clip').'.wav';
        file_put_contents($clip, 'RIFF-fake-wav-bytes');

        return $clip;
    }

    /**
     * A minimal but VALID PCM WAV of $seconds, so wavDurationSeconds parses a
     * real duration (byteRate 100 B/s → tiny files). @return string temp path.
     */
    private function wavClip(float $seconds): string
    {
        $byteRate = 100;
        $dataSize = (int) round($seconds * $byteRate);
        $wav = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $byteRate).pack('V', $byteRate).pack('v', 1).pack('v', 8)
            .'data'.pack('V', $dataSize).str_repeat("\x00", $dataSize);

        $clip = tempnam(sys_get_temp_dir(), 'wav').'.wav';
        file_put_contents($clip, $wav);

        return $clip;
    }

    public function test_clipless_qwen_speaks_a_preset_speaker(): void
    {
        $this->fakeSuccess();

        $audio = $this->provider()->synthesize('Hello qwen.', null, [
            'model' => 'qwen3-tts',
            'voice_preset' => 'Vivian',
        ]);

        $this->assertSame('AUDIO-BYTES', $audio);

        $sent = $this->sentInput();
        $this->assertSame('qwen-version', $sent['version']);
        $this->assertSame('Hello qwen.', $sent['input']['text']);
        // NAMING TRAP: 'custom_voice' is qwen's PRESET mode, not cloning.
        $this->assertSame('custom_voice', $sent['input']['mode']);
        $this->assertSame('Vivian', $sent['input']['speaker']);
        $this->assertArrayNotHasKey('reference_audio', $sent['input']);
        $this->assertArrayNotHasKey('reference_text', $sent['input']);
    }

    public function test_clipless_qwen_defaults_to_serena(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, ['model' => 'qwen3-tts']);

        $this->assertSame('Serena', $this->sentInput()['input']['speaker']);
    }

    public function test_a_clip_switches_to_voice_clone_mode(): void
    {
        $this->fakeSuccess();

        $clip = $this->clip();
        try {
            $this->provider()->synthesize('Hi.', $clip, [
                'model' => 'qwen3-tts',
                'voice_preset' => 'Vivian',
                'reference_text' => 'What the clip says.',
            ]);
        } finally {
            @unlink($clip);
        }

        $sent = $this->sentInput();
        $this->assertSame('voice_clone', $sent['input']['mode']);
        $this->assertStringStartsWith('data:audio/wav;base64,', $sent['input']['reference_audio']);
        $this->assertSame('What the clip says.', $sent['input']['reference_text']);
        // A clip always wins: the preset speaker must not ride along.
        $this->assertArrayNotHasKey('speaker', $sent['input']);
    }

    public function test_a_transcript_that_overruns_the_clip_is_dropped(): void
    {
        // qwen speaks any reference_text its audio doesn't cover, so a
        // transcript longer than the clip would leak aloud. A 300-char note on
        // a 3s clip plainly overruns — it must not ride along.
        $this->fakeSuccess();

        $clip = $this->wavClip(3.0);
        try {
            $this->provider()->synthesize('Hi.', $clip, [
                'model' => 'qwen3-tts',
                'reference_text' => str_repeat('word ', 60), // 300 chars ≫ 3s of speech
            ]);
        } finally {
            @unlink($clip);
        }

        $sent = $this->sentInput();
        $this->assertSame('voice_clone', $sent['input']['mode']);
        $this->assertStringStartsWith('data:audio/wav;base64,', $sent['input']['reference_audio']);
        $this->assertArrayNotHasKey('reference_text', $sent['input']);
    }

    public function test_a_transcript_that_fits_the_clip_is_kept(): void
    {
        // A transcript the clip can plausibly contain still sharpens the clone.
        $this->fakeSuccess();

        $clip = $this->wavClip(6.0); // ~102 chars of headroom
        try {
            $this->provider()->synthesize('Hi.', $clip, [
                'model' => 'qwen3-tts',
                'reference_text' => 'What the clip actually says.',
            ]);
        } finally {
            @unlink($clip);
        }

        $this->assertSame('What the clip actually says.', $this->sentInput()['input']['reference_text']);
    }

    public function test_blank_reference_text_is_omitted(): void
    {
        $this->fakeSuccess();

        $clip = $this->clip();
        try {
            $this->provider()->synthesize('Hi.', $clip, [
                'model' => 'qwen3-tts',
                'reference_text' => '   ',
            ]);
        } finally {
            @unlink($clip);
        }

        $this->assertArrayNotHasKey('reference_text', $this->sentInput()['input']);
    }

    public function test_qwen_never_receives_a_seed_or_foreign_knobs(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, [
            'model' => 'qwen3-tts',
            'seed' => 4242,
            'temperature' => 1.0,
            'top_p' => 0.9,
            'top_k' => 500,
            'repetition_penalty' => 1.5,
            'stability' => 0.4,
            'style' => 0.6,
            'cfg_weight' => 0.5,
            'exaggeration' => 1.2,
        ]);

        $input = $this->sentInput()['input'];
        $this->assertSame(
            ['text', 'mode', 'speaker'],
            array_keys($input),
            'Qwen validates its input — only schema keys may be sent.',
        );
    }

    public function test_language_is_sent_only_when_not_auto(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, ['model' => 'qwen3-tts', 'language' => 'auto']);
        $this->assertArrayNotHasKey('language', $this->sentInput()['input']);

        $this->provider()->synthesize('Hi.', null, ['model' => 'qwen3-tts', 'language' => 'English']);
        $this->assertSame('English', $this->sentInput()['input']['language']);
    }

    public function test_style_instruction_is_trimmed_and_blank_is_omitted(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('Hi.', null, [
            'model' => 'qwen3-tts', 'style_instruction' => '  speak slowly  ',
        ]);
        $this->assertSame('speak slowly', $this->sentInput()['input']['style_instruction']);

        $this->provider()->synthesize('Hi.', null, [
            'model' => 'qwen3-tts', 'style_instruction' => '   ',
        ]);
        $this->assertArrayNotHasKey('style_instruction', $this->sentInput()['input']);
    }

    public function test_qwen_payloads_strip_sound_tags(): void
    {
        $this->fakeSuccess();

        $this->provider()->synthesize('So funny! [laugh] Right?', null, ['model' => 'qwen3-tts']);

        $this->assertSame('So funny! Right?', $this->sentInput()['input']['text']);
    }
}
