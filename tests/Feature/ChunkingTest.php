<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Voice;
use App\Services\TextChunker;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChunkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.storage_disk' => 'local', 'tts.chunk_chars' => 120]);
        Storage::fake('local');
    }

    /** Bind a counting provider that returns a short silent WAV per call. */
    private function countingProvider(): TtsProvider
    {
        $provider = new class implements TtsProvider
        {
            public int $calls = 0;

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $this->calls++;
                $rate = 44100;
                $samples = (int) ($rate * 0.1);
                $data = str_repeat("\x00", $samples * 2);

                return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
                    .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
                    .pack('V', $rate).pack('V', $rate * 2).pack('v', 2).pack('v', 16)
                    .'data'.pack('V', strlen($data)).$data;
            }

            public function outputContainer(?string $model = null): string
            {
                return 'wav';
            }
        };

        $this->app->instance(TtsProvider::class, $provider);

        return $provider;
    }

    public function test_long_text_is_chunked_and_concatenated(): void
    {
        $provider = $this->countingProvider();

        // Pin packed mode on the key owner so this asserts the packed multi-chunk
        // path regardless of the instance default (which is per-sentence).
        $user = User::factory()->create();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.chunk_mode', 'value' => 'packed']);

        $key = ApiKey::generate('chunk', null, $user->id);
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $text = str_repeat('This is a sentence that is reasonably long and clear. ', 10);
        $expectedChunks = count((new TextChunker)->split($text, 120));
        $this->assertGreaterThan(1, $expectedChunks);

        $response = $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/v', ['text' => $text]);

        $response->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $response->headers->get('content-type'));
        $this->assertSame($expectedChunks, $provider->calls, 'Provider should be called once per chunk.');
    }

    public function test_a_users_sentence_chunk_mode_setting_drives_one_call_per_sentence(): void
    {
        $provider = $this->countingProvider();

        $user = User::factory()->create();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.chunk_mode', 'value' => 'sentence']);

        $key = ApiKey::generate('chunk', null, $user->id);
        Voice::create(['slug' => 'v', 'name' => 'V']);

        // Four sentences: packed mode at 120 chars would combine them two per
        // chunk; the user's per-sentence setting must yield one call each.
        $sentences = 4;
        $text = trim(str_repeat('This is a sentence that is reasonably long and clear. ', $sentences));
        $this->assertLessThan($sentences, count((new TextChunker)->split($text, 120)));

        $this->withHeaders(['xi-api-key' => $key->key])
            ->postJson('/v1/text-to-speech/v', ['text' => $text])
            ->assertOk();

        $this->assertSame($sentences, $provider->calls, 'Provider should be called once per sentence.');
    }
}
