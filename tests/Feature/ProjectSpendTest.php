<?php

namespace Tests\Feature;

use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Support\GenerationCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The lifetime generation-spend counters behind the Studio cost readouts.
 * The invariant under test: counters only ever GROW — a real render bumps
 * them, and nothing the user does afterward (deleting takes, deleting
 * chunks, keeping a preview) ever lowers them, because the provider was
 * already paid. See ProjectService::recordTake() and GenerationCost.
 */
class ProjectSpendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.models.chatterbox.cost_per_1k_chars' => 0.025,
        ]);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** A 2-chunk project (two paragraphs, each long enough to stand alone). */
    private function project(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'Spend test',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_generating_counts_spend_on_chunk_and_project(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        app(ProjectService::class)->generateChunk($chunk);

        $spent = mb_strlen($chunk->text);
        $this->assertSame($spent, $chunk->fresh()->spent_characters);
        $this->assertSame($spent, $project->fresh()->spent_characters);
    }

    public function test_rerolling_adds_to_spend(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $service = app(ProjectService::class);

        $service->generateChunk($chunk);
        $service->generateChunk($chunk, reroll: true);

        $this->assertSame(2 * mb_strlen($chunk->text), $chunk->fresh()->spent_characters);
        $this->assertSame(2 * mb_strlen($chunk->text), $project->fresh()->spent_characters);
    }

    public function test_previews_count_but_keeping_one_does_not_double_count(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $service = app(ProjectService::class);

        // The preview is a real render, so it costs one pass…
        $bytes = $service->previewChunkTuning($chunk, ['exaggeration' => 0.7]);
        $this->assertSame(mb_strlen($chunk->text), $chunk->fresh()->spent_characters);

        // …but "Use this take" re-records those already-paid bytes without a
        // provider call, so the counters must not move again.
        $service->useChunkPreview($chunk, $bytes, ['exaggeration' => 0.7]);
        $this->assertSame(mb_strlen($chunk->text), $chunk->fresh()->spent_characters);
        $this->assertSame(mb_strlen($chunk->text), $project->fresh()->spent_characters);
    }

    public function test_deleting_a_take_never_lowers_spend(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $service = app(ProjectService::class);

        $service->generateChunk($chunk);
        $service->generateChunk($chunk, reroll: true);
        $spent = $chunk->fresh()->spent_characters;

        // The re-roll is selected, so the older take is deletable.
        $unselected = $chunk->takes()->get()->first(
            fn ($take) => $take->audio_path !== $chunk->fresh()->audio_path,
        );
        $service->deleteTake($unselected);

        $this->assertSame($spent, $chunk->fresh()->spent_characters);
        $this->assertSame($spent, $project->fresh()->spent_characters);
    }

    public function test_deleting_a_chunk_never_lowers_project_spend(): void
    {
        $project = $this->project();
        $service = app(ProjectService::class);
        [$first, $second] = $project->chunks()->orderBy('position')->get()->all();

        $service->generateChunk($first);
        $service->generateChunk($second);
        $spent = $project->fresh()->spent_characters;

        $service->deleteChunk($second);

        $this->assertSame($spent, $project->fresh()->spent_characters);
    }

    public function test_a_duplicate_starts_with_zero_spend(): void
    {
        $project = $this->project();
        $service = app(ProjectService::class);
        foreach ($project->chunks()->get() as $chunk) {
            $service->generateChunk($chunk);
        }

        $copy = $service->duplicate($project->refresh(), $this->admin());

        // The copy byte-copied its audio — no provider call, no spend — while
        // the original keeps its own counters untouched. (fresh(): duplicate()'s
        // forceFill omits the column, so the DB default is the value under test.)
        $this->assertSame(0, $copy->fresh()->spent_characters);
        $this->assertSame(0, (int) $copy->chunks()->sum('spent_characters'));
        $this->assertGreaterThan(0, $project->fresh()->spent_characters);
    }

    public function test_backfill_initializes_counters_from_surviving_takes(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $service = app(ProjectService::class);

        $service->generateChunk($chunk);
        $bytes = $service->previewChunkTuning($chunk, ['exaggeration' => 0.7]);
        $service->useChunkPreview($chunk, $bytes, ['exaggeration' => 0.7]); // a 'use' take — not billable

        // Re-run the spent-characters migration: its backfill must sum the takes
        // that exist, skipping the 'use' copy (generate + preview = 2 renders).
        // Seven steps because the take-duration column, the turbo preset knobs,
        // the per-model spend counters, the per-chunk skip flag, the credit
        // system, and the project-jobs table sit on top of it — bump this when
        // a migration lands above them.
        Artisan::call('migrate:rollback', ['--step' => 7]);
        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(2 * mb_strlen($chunk->text), $chunk->fresh()->spent_characters);
        $this->assertSame(2 * mb_strlen($chunk->text), $project->fresh()->spent_characters);
    }

    public function test_generate_endpoint_reports_spend(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        $response = $this->actingAs($this->admin())->postJson(
            route('admin.studio.projects.chunks.generate', [$project, $chunk]),
        );

        $response->assertOk()
            ->assertJsonPath('spend.chunk.spent', mb_strlen($chunk->text))
            ->assertJsonPath('spend.chunk.label', GenerationCost::label(mb_strlen($chunk->text)))
            ->assertJsonPath('spend.project.label', 'est. spend '.GenerationCost::label(mb_strlen($chunk->text)));
    }

    public function test_readouts_hidden_when_no_rate_is_configured(): void
    {
        // Readouts hide only when EVERY model's rate is zero — each catalog
        // model carries its own per-1k rate.
        config([
            'tts.models.chatterbox.cost_per_1k_chars' => 0,
            'tts.models.chatterbox-turbo.cost_per_1k_chars' => 0,
        ]);
        $project = $this->project();

        $page = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project));

        $page->assertOk()->assertDontSee('project-spend')->assertDontSee('est. spend');

        // And the JSON side goes quiet too, rather than reporting $0.00.
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertOk()
            ->assertJsonPath('spend', null);
    }

    public function test_readouts_render_on_the_project_page(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('est. spend '.GenerationCost::label($project->fresh()->spent_characters));
    }
}
