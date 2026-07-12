<?php

namespace Tests\Feature;

use App\Enums\HealthStatus;
use App\Models\User;
use App\Models\Voice;
use App\Services\Health\HealthReport;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Turbo's operational guards: the Studio refuses chunk text over the engine's
 * per-call cap, the internal chunker shrinks its budget for a turbo voice,
 * ASR QA scores tagged text against the tag-stripped expectation, and the
 * health report flags unpinned models / oversized chunk budgets.
 */
class TurboGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function projectFor(Voice $voice): \App\Models\TtsProject
    {
        return app(ProjectService::class)->createFromText(
            title: 'Guards',
            voice: $voice,
            text: 'A sentence long enough to stand as a chunk of its own.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_studio_refuses_oversized_chunk_text_on_a_turbo_voice(): void
    {
        $turbo = Voice::create(['slug' => 'turbo-v', 'name' => 'Turbo V', 'model' => 'chatterbox-turbo']);
        $project = $this->projectFor($turbo);
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->patchJson(
                route('admin.studio.projects.chunks.update', [$project, $chunk]),
                ['text' => str_repeat('word ', 110)], // 550 chars
            )
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '500'));
    }

    public function test_the_same_text_saves_fine_on_a_classic_voice(): void
    {
        $classic = Voice::create(['slug' => 'classic-v', 'name' => 'Classic V']);
        $project = $this->projectFor($classic);
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->patchJson(
                route('admin.studio.projects.chunks.update', [$project, $chunk]),
                ['text' => str_repeat('word ', 110)],
            )
            ->assertOk();
    }

    public function test_internal_chunker_caps_the_budget_for_a_turbo_voice(): void
    {
        config(['tts.internal.secret' => 's3cret', 'tts.chunk_chars' => 900]);
        $turbo = Voice::create(['slug' => 'turbo-v', 'name' => 'Turbo V', 'model' => 'chatterbox-turbo']);

        $sentence = 'This is a reasonably long sentence used to fill space in the block. ';
        $response = $this->withHeaders(['X-Internal-Secret' => 's3cret'])
            ->postJson('/v1/internal/chunk', [
                'text' => str_repeat($sentence, 12), // ~830 chars
                'voice_id' => $turbo->id,
            ])
            ->assertOk();

        foreach ($response->json('chunks') as $chunk) {
            $this->assertLessThanOrEqual(500, $chunk['characters']);
        }
    }

    public function test_asr_scores_tagged_text_against_the_stripped_expectation(): void
    {
        config(['tts.asr.enabled' => true, 'tts.asr.url' => 'http://asr.test']);

        $voice = Voice::create(['slug' => 'turbo-v', 'name' => 'Turbo V', 'model' => 'chatterbox-turbo']);
        $project = app(ProjectService::class)->createFromText(
            title: 'Tagged',
            voice: $voice,
            text: 'That was such a funny story you told me yesterday [laugh] and I loved it.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
        $chunk = $project->chunks()->first();

        // The transcript contains the SPOKEN words only — no "laugh" token —
        // exactly what turbo produces. Without the expected-text strip this
        // would flag as truncated.
        $spoken = 'That was such a funny story you told me yesterday and I loved it.';
        $tokens = preg_split('/\s+/', $spoken);
        $words = [];
        $t = 0.0;
        foreach ($tokens as $tok) {
            $words[] = ['word' => $tok, 'start' => $t, 'end' => $t + 0.4];
            $t += 0.4;
        }
        Http::fake(['asr.test/transcribe' => Http::response([
            'duration' => $t + 0.2, 'text' => $spoken, 'words' => $words, 'transcribe_ms' => 9,
        ])]);

        app(ProjectService::class)->generateChunk($chunk);

        $this->assertTrue($chunk->refresh()->asr_report['ok']);
    }

    public function test_project_stitch_keeps_a_turbo_chunks_trailing_sound_tag(): void
    {
        // A turbo chunk ending "[laugh]" stores audio whose tail IS the
        // rendered laugh — loud non-speech after the words, exactly the shape
        // the tail-artifact detector cuts. The project stitch must spare it
        // (and must still cut it for a classic chunk with identical audio,
        // where trailing non-speech really is junk).
        $renderWithLoudTail = $this->wrapWav($this->noiseWav(1.0).$this->rawTone(1.0, 8000, 90.0));

        $secondsFor = function (Voice $voice, string $text) use ($renderWithLoudTail): float {
            $project = app(ProjectService::class)->createFromText(
                title: 'Tail '.$voice->slug,
                voice: $voice,
                text: $text,
                settings: config('tts.default_voice_settings'),
                modelId: config('tts.default_model_id'),
                outputFormat: 'wav_44100',
                seed: null,
            );
            $chunk = $project->chunks()->first();
            $path = 'takes/'.$chunk->id.'.wav';
            Storage::disk('local')->put($path, $renderWithLoudTail);
            $chunk->update(['audio_path' => $path, 'status' => \App\Enums\ChunkStatus::Completed]);

            [$bytes] = app(ProjectService::class)->previewConcat($project->fresh(), [$chunk->id]);

            return (float) app(\App\Services\Audio\AudioConverter::class)->wavDurationSeconds($bytes);
        };

        $turbo = Voice::create(['slug' => 'tail-turbo', 'name' => 'Tail Turbo', 'model' => 'chatterbox-turbo']);
        $classic = Voice::create(['slug' => 'tail-classic', 'name' => 'Tail Classic']);
        $text = 'A sentence long enough to stand as a chunk on its own. [laugh]';

        $this->assertGreaterThan(1.8, $secondsFor($turbo, $text), 'The rendered laugh must survive the stitch.');
        $this->assertLessThan(1.4, $secondsFor($classic, $text), 'A classic chunk keeps the artifact cut.');
    }

    public function test_internal_chunk_marks_tag_ending_chunks_and_stitch_honors_the_flag(): void
    {
        config(['tts.internal.secret' => 's3cret']);
        $turbo = Voice::create(['slug' => 'turbo-v', 'name' => 'Turbo V', 'model' => 'chatterbox-turbo']);

        // The chunk endpoint computes preserve_tail (the app owns the tag list).
        $chunks = $this->withHeaders(['X-Internal-Secret' => 's3cret'])
            ->postJson('/v1/internal/chunk', [
                'text' => 'A sentence long enough to stand as a chunk on its own. [laugh]',
                'voice_id' => $turbo->id,
            ])
            ->assertOk()
            ->json('chunks');

        $this->assertTrue($chunks[0]['preserve_tail']);

        // The stitch endpoint honors the echoed flag: same loud-tail audio,
        // kept with the flag, cut without it.
        $renderWithLoudTail = $this->wrapWav($this->noiseWav(1.0).$this->rawTone(1.0, 8000, 90.0));
        $converter = app(\App\Services\Audio\AudioConverter::class);

        $stitch = fn (array $extra) => $this->withHeaders(['X-Internal-Secret' => 's3cret'])
            ->post('/v1/internal/stitch', [
                'chunks' => [\Illuminate\Http\UploadedFile::fake()->createWithContent('c.wav', $renderWithLoudTail)],
                'output_format' => 'wav_44100',
            ] + $extra)
            ->assertOk()
            ->getContent();

        $kept = (float) $converter->wavDurationSeconds($stitch(['preserve_tail' => ['1']]));
        $cut = (float) $converter->wavDurationSeconds($stitch([]));

        $this->assertGreaterThan(1.8, $kept, 'preserve_tail must spare the rendered sound.');
        $this->assertLessThan(1.4, $cut, 'Without the flag the artifact cut still runs.');
    }

    /** Broadband noise ("speech" to the ZCR gate), 16-bit mono 44.1kHz samples. */
    private function noiseWav(float $seconds, int $amp = 15000): string
    {
        mt_srand(1337);
        $n = (int) (44100 * $seconds);
        $samples = '';
        for ($i = 0; $i < $n; $i++) {
            $samples .= pack('s', mt_rand(-$amp, $amp));
        }

        return $samples;
    }

    /** A sustained low-frequency tone — the drone/rendered-sound tail shape. */
    private function rawTone(float $seconds, int $amp, float $freq): string
    {
        $n = (int) (44100 * $seconds);
        $samples = '';
        for ($i = 0; $i < $n; $i++) {
            $samples .= pack('s', (int) ($amp * sin(2 * M_PI * $freq * $i / 44100)));
        }

        return $samples;
    }

    private function wrapWav(string $samples): string
    {
        $rate = 44100;
        $dataSize = strlen($samples);

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $rate).pack('V', $rate * 2)
            .pack('v', 2).pack('v', 16)
            .'data'.pack('V', $dataSize).$samples;
    }

    public function test_health_warns_on_an_unpinned_model_version(): void
    {
        config([
            'tts.provider' => 'replicate',
            'tts.providers.replicate.token' => 'r8_test',
            'tts.models.chatterbox-turbo.version' => '',
        ]);

        $row = collect(app(HealthReport::class)->run())->firstWhere('key', 'model_chatterbox-turbo');

        $this->assertNotNull($row);
        $this->assertSame(HealthStatus::Warn, $row->status);
        $this->assertStringContainsString('no version pinned', $row->detail);
    }

    public function test_health_warns_when_the_chunk_budget_exceeds_a_models_cap(): void
    {
        config([
            'tts.provider' => 'replicate',
            'tts.providers.replicate.token' => 'r8_test',
            'tts.chunk_chars' => 600,
        ]);

        $row = collect(app(HealthReport::class)->run())->firstWhere('key', 'model_chatterbox-turbo_chunk');

        $this->assertNotNull($row);
        $this->assertSame(HealthStatus::Warn, $row->status);
        $this->assertStringContainsString('TTS_CHUNK_CHARS=600', $row->detail);
    }
}
