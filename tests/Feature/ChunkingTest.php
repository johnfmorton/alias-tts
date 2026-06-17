<?php

namespace Tests\Feature;

use App\Models\ApiKey;
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

    public function test_long_text_is_chunked_and_concatenated(): void
    {
        // Counting provider that returns a short silent WAV per call.
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

            public function outputContainer(): string
            {
                return 'wav';
            }
        };

        $this->app->instance(TtsProvider::class, $provider);

        $key = ApiKey::generate('chunk');
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
}
