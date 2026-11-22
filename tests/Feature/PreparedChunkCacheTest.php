<?php

namespace Tests\Feature;

use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\Audio\PreparedChunkCache;
use App\Services\ProjectService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The prepared-chunk cache: a project build reuses each unchanged chunk's
 * trimmed audio instead of preparing every chunk again. The one rule that
 * matters is that a cached build is byte-for-byte the uncached build — speed
 * never buys different audio. Runs real ffmpeg, like AudioConverterTest.
 */
class PreparedChunkCacheTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = sys_get_temp_dir().'/tts-prepared-test-'.bin2hex(random_bytes(6));

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            // WAV out: lossless and untagged, so two builds of the same audio
            // are comparable byte-for-byte.
            'tts.default_output_format' => 'wav_44100',
            'tts.prepared_cache.enabled' => true,
            'tts.prepared_cache.path' => $this->cacheDir,
        ]);
        Storage::fake('local');

        // The stock fake renders silence, which trimChunk() treats as "trimmed
        // to nothing" (a never-cached fallback). Render audible, per-text
        // distinct tones instead so chunks prepare on the normal path.
        $this->app->instance(TtsProvider::class, new class extends FakeTtsProvider
        {
            public int $calls = 0;

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $this->calls++;
                $rate = 44100;
                $freq = 180 + (crc32($text.$this->calls) % 400);
                $pad = str_repeat(pack('v', 0), (int) ($rate * 0.1));
                $tone = '';
                for ($i = 0, $n = (int) ($rate * 0.6); $i < $n; $i++) {
                    $tone .= pack('v', (int) (9000 * sin(2 * M_PI * $freq * $i / $rate)) & 0xFFFF);
                }
                $data = $pad.$tone.$pad;

                return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
                    .'fmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
                    .'data'.pack('V', strlen($data)).$data;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function generatedProject(User $admin): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        $project = app(ProjectService::class)->createFromText(
            title: 'Cache project',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  "This is the second paragraph, also long enough to be its own chunk.\n\n".
                  'And a third paragraph follows, so one chunk can change while two stay put.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );

        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        return $project->fresh();
    }

    /** @return array{0: string, 1: array<string, int>, 2: mixed} [final bytes, stitch stats, timeline] */
    private function build(TtsProject $project): array
    {
        $service = app(ProjectService::class);
        $built = $service->rebuild($project->fresh());

        return [Storage::disk('local')->get($built->final_audio_path), $service->lastStitchTimings, $built->final_timeline];
    }

    private function cachedFileCount(): int
    {
        return is_dir($this->cacheDir) ? count(File::allFiles($this->cacheDir)) : 0;
    }

    public function test_a_cached_build_is_byte_identical_to_an_uncached_one(): void
    {
        $project = $this->generatedProject($this->admin());
        $this->assertSame(3, $project->chunks()->count());

        config(['tts.prepared_cache.enabled' => false]);
        [$reference, , $referenceTimeline] = $this->build($project);

        config(['tts.prepared_cache.enabled' => true]);
        File::deleteDirectory($this->cacheDir); // drop the generation-time prewarm
        [$cold, $coldStats, $coldTimeline] = $this->build($project);
        [$warm, $warmStats, $warmTimeline] = $this->build($project);

        $this->assertSame(3, $coldStats['prepared']);
        $this->assertSame(0, $coldStats['cache_hits']);
        $this->assertSame(0, $warmStats['prepared']);
        $this->assertSame(3, $warmStats['cache_hits']);

        $this->assertTrue($reference === $cold, 'A cold cached build must match the uncached build byte-for-byte.');
        $this->assertTrue($reference === $warm, 'A warm cached build must match the uncached build byte-for-byte.');
        $this->assertSame($referenceTimeline, $coldTimeline);
        $this->assertSame($referenceTimeline, $warmTimeline);
    }

    public function test_generation_prepares_each_chunk_so_the_first_build_only_joins(): void
    {
        $project = $this->generatedProject($this->admin());

        $this->assertSame(3, $this->cachedFileCount());

        [, $stats] = $this->build($project);
        $this->assertSame(0, $stats['prepared']);
        $this->assertSame(3, $stats['cache_hits']);
    }

    public function test_a_failing_prewarm_never_fails_the_generation(): void
    {
        $this->app->instance(AudioConverter::class, new class extends AudioConverter
        {
            public function prepareChunk(string $bytes, string $outputFormat, bool $preserveTail = false): array
            {
                throw new \RuntimeException('ffmpeg exploded');
            }
        });

        $project = $this->generatedProject($this->admin());

        $this->assertSame(0, $this->cachedFileCount());
        foreach ($project->chunks()->get() as $chunk) {
            $this->assertSame('completed', $chunk->status->value);
            $this->assertNotNull($chunk->audio_path);
        }
    }

    public function test_a_warm_build_reads_no_chunk_audio_from_storage(): void
    {
        $project = $this->generatedProject($this->admin());
        $this->build($project);

        // Remove every chunk's stored audio: a build that still succeeds can
        // only have used the cache.
        foreach ($project->chunks()->get() as $chunk) {
            Storage::disk('local')->delete($chunk->audio_path);
        }

        [$bytes, $stats] = $this->build($project);
        $this->assertSame(3, $stats['cache_hits']);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_regenerating_one_chunk_prepares_only_that_chunk(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin);
        $this->build($project);

        $chunk = $project->chunks()->orderBy('position')->skip(1)->first();
        config(['tts.prepared_cache.enabled' => false]);  // regenerate WITHOUT the prewarm
        $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        config(['tts.prepared_cache.enabled' => true]);

        [$cached, $stats] = $this->build($project);
        $this->assertSame(1, $stats['prepared']);
        $this->assertSame(2, $stats['cache_hits']);

        config(['tts.prepared_cache.enabled' => false]);
        [$reference] = $this->build($project);
        $this->assertTrue($reference === $cached, 'The build after a one-chunk fix must match a from-scratch build.');
    }

    public function test_a_trim_setting_change_invalidates_the_cache(): void
    {
        $project = $this->generatedProject($this->admin());
        $this->build($project);

        config(['tts.chunk_fade_ms' => 20]);

        [$cached, $stats] = $this->build($project);
        $this->assertSame(3, $stats['prepared']);
        $this->assertSame(0, $stats['cache_hits']);

        config(['tts.prepared_cache.enabled' => false]);
        [$reference] = $this->build($project);
        $this->assertTrue($reference === $cached);
    }

    public function test_the_key_covers_every_input_of_the_prepare(): void
    {
        $cache = app(PreparedChunkCache::class);
        $sha = str_repeat('a', 64);
        $base = $cache->key($sha, 'mp3_44100_128', false);

        $this->assertSame($base, $cache->key($sha, 'mp3_44100_128', false));
        $this->assertSame($base, $cache->key($sha, 'wav_44100', false), 'Same rate, different codec: the prepared WAV is the same.');
        $this->assertNotSame($base, $cache->key(str_repeat('b', 64), 'mp3_44100_128', false));
        $this->assertNotSame($base, $cache->key($sha, 'mp3_22050_32', false));
        $this->assertNotSame($base, $cache->key($sha, 'mp3_44100_128', true));

        config(['tts.chunk_tail_guard_ms' => (int) config('tts.chunk_tail_guard_ms') + 1]);
        $this->assertNotSame($base, $cache->key($sha, 'mp3_44100_128', false));
    }

    /**
     * The key fingerprints config by PREFIX. A new trim/detector knob named
     * outside those prefixes would silently escape the key and let stale audio
     * be reused — so fail here until it is covered.
     */
    public function test_every_chunk_config_key_the_converter_reads_is_in_the_key(): void
    {
        $source = file_get_contents(app_path('Services/Audio/AudioConverter.php'));
        preg_match_all("/config\('tts\.(chunk_[a-z0-9_]+)'/", $source, $matches);
        $keys = array_unique($matches[1]);

        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertTrue(
                str($key)->startsWith(PreparedChunkCache::CONFIG_PREFIXES),
                "tts.{$key} is read by AudioConverter but not covered by PreparedChunkCache::CONFIG_PREFIXES.",
            );
            $this->assertArrayHasKey($key, config('tts'), "tts.{$key} has no entry in config/tts.php, so it cannot be fingerprinted.");
        }
    }

    public function test_fallback_audio_is_stitched_but_never_cached(): void
    {
        // A fully silent chunk trims to nothing, so prepareChunk() falls back
        // to the untrimmed audio — the same path a transient ffmpeg failure
        // takes. Caching that would pin it past the next (healthy) build.
        $silence = str_repeat("\x00", 44100 * 2);
        $wav = 'RIFF'.pack('V', 36 + strlen($silence)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, 44100, 88200, 2, 16)
            .'data'.pack('V', strlen($silence)).$silence;

        [$prepared, $clean] = app(AudioConverter::class)->prepareChunk($wav, 'wav_44100');

        $this->assertFalse($clean);
        $this->assertGreaterThan(1000, strlen($prepared));
    }

    public function test_prune_removes_only_files_unused_past_the_window(): void
    {
        $cache = app(PreparedChunkCache::class);
        $old = $cache->put(str_repeat('1', 64), str_repeat('x', 2000));
        $fresh = $cache->put(str_repeat('2', 64), str_repeat('x', 2000));
        touch($old, time() - 20 * 86400);

        $this->artisan('audio:prune-prepared', ['--dry-run' => true])->assertSuccessful();
        $this->assertFileExists($old);

        $this->artisan('audio:prune-prepared')->assertSuccessful();
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
    }
}
