<?php

namespace Tests\Feature;

use App\Enums\ProjectJobStatus;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\SpeechProgressStore;
use App\Support\GenerationEstimator;
use App\Support\GenerationTimings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The learned generation-time estimate: the per-model timing store
 * ({@see GenerationTimings}), the ETA math + wording ({@see GenerationEstimator}),
 * and the two surfaces that fold it into their status message — the background
 * run's {@see TtsProjectJob::statusPayload()} and the /v1 poll's
 * {@see SpeechProgressStore::payload()}.
 */
class GenerationTimingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'cache.default' => 'array',
            'tts.timing.enabled' => true,
            'tts.timing.defaults' => ['chatterbox' => 7000, 'chatterbox-turbo' => 3500],
            'tts.timing.min_sample_ms' => 300,
            'tts.timing.max_sample_ms' => 180000,
        ]);
        Storage::fake('local');
    }

    private function project(?Voice $voice = null): TtsProject
    {
        $voice ??= Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'Timing project',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    // --- the store -----------------------------------------------------------

    public function test_record_averages_samples_per_model(): void
    {
        GenerationTimings::record('chatterbox', 4000);
        GenerationTimings::record('chatterbox', 6000);
        GenerationTimings::record('chatterbox-turbo', 3000);

        $this->assertSame(5000, GenerationTimings::perChunkMs('chatterbox'));
        $this->assertSame(3000, GenerationTimings::perChunkMs('chatterbox-turbo'));
        $this->assertSame(['chatterbox' => 5000, 'chatterbox-turbo' => 3000], GenerationTimings::averages());
    }

    public function test_per_chunk_ms_falls_back_to_config_default_without_samples(): void
    {
        $this->assertSame(7000, GenerationTimings::perChunkMs('chatterbox'));
        $this->assertSame(3500, GenerationTimings::perChunkMs('chatterbox-turbo'));
        // An unknown model borrows the classic default rather than 0.
        $this->assertSame(7000, GenerationTimings::perChunkMs('made-up'));
    }

    public function test_outlier_samples_are_rejected(): void
    {
        GenerationTimings::record('chatterbox', 100);      // below min
        GenerationTimings::record('chatterbox', 500000);   // above max
        $this->assertSame([], GenerationTimings::averages());

        GenerationTimings::record('chatterbox', 5000);     // in range
        $this->assertSame(['chatterbox' => 5000], GenerationTimings::averages());
    }

    public function test_disabled_records_nothing(): void
    {
        config(['tts.timing.enabled' => false]);
        GenerationTimings::record('chatterbox', 5000);

        $this->assertSame(0, DB::table('tts_generation_timings')->count());
    }

    public function test_generate_chunk_records_timing_bucketed_by_model(): void
    {
        // The fake provider returns instantly, so drop the min bound for the
        // integration path (a real render is seconds; this just proves a row
        // lands under the right engine).
        config(['tts.timing.min_sample_ms' => 0]);

        $classic = $this->project();
        app(ProjectService::class)->generateChunk($classic->chunks()->orderBy('position')->first());

        $turboVoice = Voice::create(['slug' => 'turbo', 'name' => 'Turbo', 'model' => 'chatterbox-turbo']);
        $turbo = $this->project($turboVoice);
        app(ProjectService::class)->generateChunk($turbo->chunks()->orderBy('position')->first());

        $this->assertGreaterThanOrEqual(1, (int) DB::table('tts_generation_timings')->where('model', 'chatterbox')->value('samples'));
        $this->assertGreaterThanOrEqual(1, (int) DB::table('tts_generation_timings')->where('model', 'chatterbox-turbo')->value('samples'));
    }

    // --- the estimator -------------------------------------------------------

    public function test_live_ms_projects_the_running_average(): void
    {
        $this->assertSame(240000, GenerationEstimator::liveMs(60000, 2, 8));
        $this->assertNull(GenerationEstimator::liveMs(60000, 0, 8)); // nothing processed yet
        $this->assertNull(GenerationEstimator::liveMs(0, 2, 8));     // no elapsed time yet
        $this->assertNull(GenerationEstimator::liveMs(60000, 2, 0)); // nothing left
    }

    public function test_seed_ms_sums_per_model_plus_pace(): void
    {
        $counts = ['chatterbox' => 2, 'chatterbox-turbo' => 2];

        $this->assertSame(21000, GenerationEstimator::seedMs($counts));          // 2*7000 + 2*3500
        $this->assertSame(23400, GenerationEstimator::seedMs($counts, 800));      // + 800 * (4 - 1) gaps
    }

    public function test_humanize_is_rounded_and_reads_naturally(): void
    {
        $this->assertSame('under a minute', GenerationEstimator::humanize(30));
        $this->assertSame('about a minute', GenerationEstimator::humanize(60));
        $this->assertSame('about 4 min', GenerationEstimator::humanize(240));
        $this->assertSame('about 1 hr', GenerationEstimator::humanize(3600));
        $this->assertSame('about 1 hr 5 min', GenerationEstimator::humanize(3900));
    }

    public function test_payload_pairs_seconds_with_phrase(): void
    {
        $this->assertSame(['eta_seconds' => null, 'eta_human' => null], GenerationEstimator::payload(null));
        $this->assertSame(['eta_seconds' => null, 'eta_human' => null], GenerationEstimator::payload(0));
        $this->assertSame(['eta_seconds' => 240, 'eta_human' => 'about 4 min'], GenerationEstimator::payload(240000));
    }

    // --- the background-run surface -----------------------------------------

    private function runningJob(array $attributes): TtsProjectJob
    {
        return TtsProjectJob::create(array_merge([
            'tts_project_id' => $this->project()->id,
            'status' => ProjectJobStatus::Running,
            'chunk_ids' => [],
            'chunks_total' => 10,
            'chunks_done' => 0,
            'chunks_failed' => 0,
        ], $attributes));
    }

    public function test_status_payload_folds_the_live_average_into_the_message(): void
    {
        $job = $this->runningJob([
            'chunks_done' => 2,
            'started_at' => now()->subSeconds(60), // 30s/chunk so far
        ]);

        $payload = $job->statusPayload();

        // 8 left * 30s = 240s.
        $this->assertSame('about 4 min', $payload['eta_human']);
        $this->assertStringContainsString('about 4 min left', $payload['message']);
        $this->assertGreaterThan(200, $payload['eta_seconds']);
    }

    public function test_status_payload_seeds_from_the_estimate_before_the_first_chunk(): void
    {
        $job = $this->runningJob([
            'chunks_done' => 0,
            'estimated_ms' => 60000,
            'started_at' => now(),
        ]);

        $payload = $job->statusPayload();

        // Seed = estimated_ms * (remaining / total) = 60000 * 10/10 = 60s.
        $this->assertSame('about a minute', $payload['eta_human']);
        $this->assertStringContainsString('about a minute left', $payload['message']);
    }

    public function test_status_payload_has_no_eta_on_terminal_states(): void
    {
        $job = $this->runningJob([
            'status' => ProjectJobStatus::Completed,
            'chunks_done' => 10,
            'finished_at' => now(),
        ]);

        $payload = $job->statusPayload();

        $this->assertNull($payload['eta_seconds']);
        $this->assertNull($payload['eta_human']);
        $this->assertStringNotContainsString('left', $payload['message']);
    }

    // --- the /v1 poll surface -----------------------------------------------

    public function test_speech_progress_payload_folds_in_the_eta(): void
    {
        $store = app(SpeechProgressStore::class);
        $store->begin('sp-1', 4, 'chatterbox');

        $payload = $store->payload('sp-1');

        // Seed for 4 clips at the 7000ms default = 28s.
        $this->assertSame('under a minute', $payload['eta_human']);
        $this->assertStringContainsString('under a minute left', $payload['message']);
    }

    public function test_speech_progress_payload_has_no_eta_while_stitching(): void
    {
        $store = app(SpeechProgressStore::class);
        $store->begin('sp-2', 3, 'chatterbox');
        $store->stitching('sp-2', 3);

        $payload = $store->payload('sp-2');

        $this->assertNull($payload['eta_human']);
        $this->assertStringNotContainsString('left', $payload['message']);
    }
}
