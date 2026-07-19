<?php

namespace Tests\Feature;

use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Support\GenerationCost;
use App\Support\SpendCounters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-model spend: each engine's characters are counted separately (so each
 * model's own rate prices them), the splits always sum to the all-model
 * `spent_characters` totals, the migration backfills legacy spend as classic
 * chatterbox, and a duplicated project starts from zero like the totals do.
 */
class SpendCountersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.models.chatterbox.cost_per_1k_chars' => 0.025,
            'tts.models.chatterbox-turbo.cost_per_1k_chars' => 0.010,
        ]);
        Storage::fake('local');
    }

    private function project(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'Spend split',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_a_mixed_engine_project_splits_spend_by_model(): void
    {
        $turbo = Voice::create(['slug' => 'turbo-v', 'name' => 'Turbo V', 'model' => 'chatterbox-turbo']);
        $project = $this->project();
        [$first, $second] = $project->chunks()->orderBy('position')->get();

        $second->update(['voice_id' => $turbo->id]);

        $service = app(ProjectService::class);
        $service->generateChunk($first);
        $service->generateChunk($second->refresh());

        $split = SpendCounters::forOwner('project', $project->id);
        $this->assertSame(mb_strlen($first->text), $split['chatterbox']);
        $this->assertSame(mb_strlen($second->text), $split['chatterbox-turbo']);

        // The split always sums to the all-model lifetime total.
        $this->assertSame((int) $project->fresh()->spent_characters, array_sum($split));

        // Each chunk's own counter carries only its engine.
        $this->assertSame(['chatterbox' => mb_strlen($first->text)], SpendCounters::forOwner('chunk', $first->id));
        $this->assertSame(['chatterbox-turbo' => mb_strlen($second->text)], SpendCounters::forOwner('chunk', $second->id));
    }

    public function test_regenerates_accumulate_into_the_same_model_counter(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        $service = app(ProjectService::class);
        $service->generateChunk($chunk);
        $service->generateChunk($chunk->refresh());

        $this->assertSame(
            ['chatterbox' => 2 * mb_strlen($chunk->text)],
            SpendCounters::forOwner('chunk', $chunk->id),
        );
    }

    public function test_the_migration_backfills_legacy_spend_as_chatterbox(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        // Re-run the counters migration over existing spend, as a deploy would.
        // Seven steps: the per-chunk skip flag, the credit system, the project-jobs
        // table, the generation-timings table, the voice-clip status column, and
        // the take-voice column sit on top of it.
        Artisan::call('migrate:rollback', ['--step' => 8]);
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('tts_spend_counters'));
        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(
            ['chatterbox' => (int) $project->fresh()->spent_characters],
            SpendCounters::forOwner('project', $project->id),
        );
    }

    public function test_a_duplicated_project_starts_with_zero_counters(): void
    {
        $owner = User::factory()->create(['is_super_admin' => true]);
        $project = $this->project();
        $project->update(['user_id' => $owner->id]);
        $chunk = $project->chunks()->first();
        app(ProjectService::class)->generateChunk($chunk);

        $copy = app(ProjectService::class)->duplicate($project->fresh(), $owner);

        // Copies never inherit spend — the money was spent on the original
        // (same regression-locked behavior as the spent_characters totals).
        $this->assertSame(0, (int) $copy->spent_characters);
        $this->assertSame([], SpendCounters::forOwner('project', $copy->id));
    }

    public function test_generation_cost_prices_each_model_at_its_own_rate(): void
    {
        // 1,000 chatterbox chars at $0.025/1k + 1,000 turbo chars at $0.010/1k = 3.5¢.
        $map = ['chatterbox' => 1000, 'chatterbox-turbo' => 1000];

        $this->assertSame('3.5¢', GenerationCost::label($map));

        $title = GenerationCost::title($map, 'project');
        $this->assertStringContainsString('2,000 characters', $title);
        $this->assertStringContainsString('Chatterbox: 1,000 × $0.025/1k', $title);
        $this->assertStringContainsString('Chatterbox Turbo: 1,000 × $0.01/1k', $title);
    }

    public function test_generation_cost_keeps_the_legacy_int_path(): void
    {
        $this->assertSame('2.5¢', GenerationCost::label(1000));
        $this->assertSame('0¢', GenerationCost::label(0));
        $this->assertSame('0¢', GenerationCost::label([]));
        $this->assertStringContainsString('× $0.025 per 1,000', GenerationCost::title(1000, 'chunk'));
    }

    public function test_enabled_requires_any_nonzero_rate(): void
    {
        $this->assertTrue(GenerationCost::enabled());

        config([
            'tts.models.chatterbox.cost_per_1k_chars' => 0,
            'tts.models.chatterbox-turbo.cost_per_1k_chars' => 0,
        ]);
        $this->assertFalse(GenerationCost::enabled());

        config(['tts.models.chatterbox-turbo.cost_per_1k_chars' => 0.01]);
        $this->assertTrue(GenerationCost::enabled());
    }
}
