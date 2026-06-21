<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use App\Services\SpeechService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ASR transcript QA on the synchronous / queued path (SpeechService::process),
 * which is what the /v1 API and the Bespoken plugin use. The sidecar is faked.
 */
class AsrSpeechServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.asr.enabled' => true,
            'tts.asr.url' => 'http://asr.test',
        ]);
        Storage::fake('local');
    }

    /** A FakeTtsProvider that counts synthesize() calls (each re-roll is a call). */
    private function countingProvider(): object
    {
        $provider = new class extends FakeTtsProvider
        {
            public int $calls = 0;

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $this->calls++;

                return parent::synthesize($text, $referenceAudio, $settings);
            }
        };
        $this->app->instance(TtsProvider::class, $provider);

        return $provider;
    }

    /** A one-segment Speech record ready for process(). */
    private function speech(string $text = 'This is a clean test sentence with enough words to stand alone.'): Speech
    {
        $key = ApiKey::generate('test');
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return Speech::create([
            'api_key_id' => $key->id,
            'voice_id' => $voice->id,
            'text' => $text,
            'cache_hash' => 'hash-'.uniqid(),
            'settings' => [],
            'model_id' => config('tts.default_model_id'),
            'output_format' => config('tts.default_output_format'),
            'status' => SpeechStatus::Processing,
        ]);
    }

    /** Build a fake transcript payload (subset of words for truncation). */
    private function transcript(string $text, float $duration, float $perWord = 0.4): array
    {
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        $words = [];
        $t = 0.0;
        foreach ($tokens as $tok) {
            $words[] = ['word' => $tok, 'start' => $t, 'end' => $t + $perWord];
            $t += $perWord;
        }

        return ['duration' => $duration, 'text' => $text, 'words' => $words, 'transcribe_ms' => 9];
    }

    public function test_log_mode_flags_a_segment_without_rerolling(): void
    {
        config(['tts.asr.action' => 'log']);
        $provider = $this->countingProvider();
        $speech = $this->speech();

        $partial = implode(' ', array_slice(preg_split('/\s+/', $speech->text), 0, 3));
        Http::fake(['asr.test/transcribe' => Http::response($this->transcript($partial, 8.0))]);

        app(SpeechService::class)->process($speech);

        $this->assertSame(1, $provider->calls);  // logged, never re-rolled
        $this->assertSame(SpeechStatus::Completed, $speech->refresh()->status);
    }

    public function test_auto_mode_rerolls_a_truncated_segment(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $speech = $this->speech();

        $partial = implode(' ', array_slice(preg_split('/\s+/', $speech->text), 0, 3));
        $endAt = str_word_count($speech->text) * 0.4;

        Http::fake([
            'asr.test/transcribe' => Http::sequence()
                ->push($this->transcript($partial, 8.0))           // first take truncates
                ->push($this->transcript($speech->text, $endAt + 0.3)), // re-roll is clean
        ]);

        app(SpeechService::class)->process($speech);

        $this->assertSame(2, $provider->calls);  // initial + 1 re-roll
        $this->assertSame(SpeechStatus::Completed, $speech->refresh()->status);
    }

    public function test_auto_mode_trims_a_tail_only_segment_without_rerolling(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.max_rerolls' => 2]);
        $provider = $this->countingProvider();
        $speech = $this->speech();

        // Full coverage but a long trailing tail → TAIL, trimmed (no re-roll).
        $endAt = str_word_count($speech->text) * 0.4;
        Http::fake(['asr.test/transcribe' => Http::response($this->transcript($speech->text, $endAt + 5.0))]);

        app(SpeechService::class)->process($speech);

        $this->assertSame(1, $provider->calls);
        $this->assertSame(SpeechStatus::Completed, $speech->refresh()->status);
    }

    public function test_unreachable_sidecar_does_not_break_generation(): void
    {
        config(['tts.asr.action' => 'auto']);
        $provider = $this->countingProvider();
        $speech = $this->speech();

        Http::fake(['asr.test/*' => Http::response('boom', 500)]);

        app(SpeechService::class)->process($speech);

        $this->assertSame(1, $provider->calls);  // QA skipped, no re-roll
        $this->assertSame(SpeechStatus::Completed, $speech->refresh()->status);
    }

    public function test_qa_is_skipped_when_disabled(): void
    {
        config(['tts.asr.enabled' => false]);
        Http::fake();
        $provider = $this->countingProvider();

        app(SpeechService::class)->process($this->speech());

        $this->assertSame(1, $provider->calls);
        Http::assertNothingSent();
    }
}
