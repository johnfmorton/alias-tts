<?php

namespace Tests\Feature;

use App\Models\Voice;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the /v1/internal/* pipeline primitives that the Genblaze orchestrator
 * calls. Uses the deterministic fake provider; trim/stitch exercise real ffmpeg
 * (same as the existing speech tests).
 */
class InternalPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.internal.secret' => $this->secret,
            'tts.asr.enabled' => false,
            'cache.default' => 'array',
        ]);

        Storage::fake('local');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Internal-Secret' => $this->secret];
    }

    private function fakeWav(string $text = 'Hello there.'): string
    {
        return app(TtsProvider::class)->synthesize($text, null, []);
    }

    public function test_internal_routes_require_the_secret(): void
    {
        $this->postJson('/v1/internal/chunk', ['text' => 'Hi'])
            ->assertStatus(403)
            ->assertJsonStructure(['detail' => ['message']]);
    }

    public function test_surface_is_disabled_when_no_secret_configured(): void
    {
        config(['tts.internal.secret' => null]);

        $this->withHeaders($this->headers())
            ->postJson('/v1/internal/chunk', ['text' => 'Hi'])
            ->assertStatus(503);
    }

    public function test_chunk_normalizes_and_segments(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/v1/internal/chunk', ['text' => 'First sentence. Second sentence.'])
            ->assertStatus(200)
            ->assertJsonStructure([
                'normalized_text',
                'chunks' => [['position', 'text', 'break_after', 'characters']],
            ]);
    }

    public function test_generate_returns_audio_for_a_known_voice(): void
    {
        Voice::create(['slug' => 'my-voice', 'name' => 'My Voice']);

        $response = $this->withHeaders($this->headers())
            ->postJson('/v1/internal/generate', [
                'voice_id' => 'my-voice',
                'text' => 'Hello there.',
                'seed' => 7,
            ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('audio/', (string) $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
    }

    public function test_generate_404s_for_unknown_voice(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/v1/internal/generate', ['voice_id' => 'nope', 'text' => 'Hi'])
            ->assertStatus(404)
            ->assertJsonPath('detail.message', fn ($m) => str_contains((string) $m, 'nope'));
    }

    public function test_score_reports_unavailable_when_asr_disabled(): void
    {
        $audio = UploadedFile::fake()->createWithContent('chunk.wav', $this->fakeWav());

        $this->withHeaders($this->headers())
            ->post('/v1/internal/score', ['text' => 'Hello there.', 'audio' => $audio])
            ->assertStatus(200)
            ->assertJsonPath('available', false);
    }

    public function test_trim_validates_the_cut_point(): void
    {
        $audio = UploadedFile::fake()->createWithContent('chunk.wav', $this->fakeWav());

        $this->withHeaders($this->headers())
            ->post('/v1/internal/trim', ['audio' => $audio, 'trim_at_ms' => 0])
            ->assertStatus(422);
    }

    public function test_stitch_concatenates_chunks_into_the_requested_format(): void
    {
        $a = UploadedFile::fake()->createWithContent('a.wav', $this->fakeWav('First chunk.'));
        $b = UploadedFile::fake()->createWithContent('b.wav', $this->fakeWav('Second chunk.'));

        $response = $this->withHeaders($this->headers())
            ->post('/v1/internal/stitch', [
                'chunks' => [$a, $b],
                'break_after' => ['sentence', 'paragraph'],
                'output_format' => 'mp3_44100_128',
            ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('audio/mpeg', (string) $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
    }
}
