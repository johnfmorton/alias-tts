<?php

namespace Tests\Feature;

use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The final's chunk timeline ({chunk_id, start_ms, end_ms} per stitched chunk,
 * persisted by rebuild()) — what the Studio's follow-playback UI maps the hero
 * player's currentTime through. See docs/STUDIO-PLAYBACK-FOLLOW.md.
 */
class StudioProjectTimelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The DOM contract between the Blade and the frontend: markup literal =>
     * the literal app.js binds it by. (A dataset attribute reaches JS
     * camelCased, hence the two spellings on the first row.)
     *
     * Worth pinning because every failure here is SILENT. The follow-playback
     * JS degrades quietly on purpose — optional chaining on the controls,
     * early returns with no timeline, a swallowed fetch — so renaming a hook
     * on either side doesn't throw, doesn't log, and doesn't fail anything
     * else: it just leaves Follow and every card's ▶ permanently disabled.
     * These two tests are what stands between a one-word rename and a dead
     * feature. See docs/STUDIO-PLAYBACK-FOLLOW.md ("Coverage").
     */
    private const FOLLOW_HOOKS = [
        'data-timeline-url' => 'timelineUrl',
        'id="follow-toggle"' => 'follow-toggle',
        'id="follow-resume"' => 'follow-resume',
        'id="project-final-audio"' => 'project-final-audio',
        'chunk-play-final' => 'chunk-play-final',
        'data-chunk-id' => '.studio-chunk[data-chunk-id=',
    ];

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

    /** A 2-chunk project (two paragraphs), like StudioProjectTest::project(). */
    private function project(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'My project',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    private function generateAndBuild(TtsProject $project, User $admin): TtsProject
    {
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($admin)->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        return $project->refresh();
    }

    public function test_rebuild_records_an_ordered_gapped_timeline(): void
    {
        config(['tts.chunk_gap_ms' => 120, 'tts.paragraph_gap_ms' => 400]);
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        $timeline = $project->final_timeline;
        $this->assertIsArray($timeline);

        // One entry per (non-skipped) chunk, in stitch order, keyed by chunk id.
        $chunkIds = $project->chunks()->orderBy('position')->pluck('id')->all();
        $this->assertSame($chunkIds, array_column($timeline, 'chunk_id'));

        // Starts at 0; every entry spans forward; entry n+1 starts exactly one
        // paragraph seam (the first chunk ends a paragraph) after entry n ends.
        $this->assertSame(0, $timeline[0]['start_ms']);
        foreach ($timeline as $entry) {
            $this->assertGreaterThan($entry['start_ms'], $entry['end_ms']);
        }
        $this->assertSame($timeline[0]['end_ms'] + 400, $timeline[1]['start_ms']);
    }

    public function test_timeline_end_matches_the_final_files_real_duration(): void
    {
        // WAV output so the final's duration is readable from its header — the
        // whole point of recording the timeline at stitch time is that it must
        // line up with the bytes actually produced.
        $admin = $this->admin();
        $project = $this->project();
        $project->update(['output_format' => 'wav_44100']);
        $project = $this->generateAndBuild($project, $admin);

        $timeline = $project->final_timeline;
        $finalSeconds = app(AudioConverter::class)->wavDurationSeconds(
            Storage::disk('local')->get($project->final_audio_path),
        );

        $this->assertNotNull($finalSeconds);
        // Post-trim durations + seams reproduce the file's layout; allow a
        // small per-splice rounding tolerance.
        $this->assertEqualsWithDelta($finalSeconds * 1000, end($timeline)['end_ms'], 100);
    }

    public function test_skipped_chunks_are_absent_from_the_timeline(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);
        [$first, $second] = $project->chunks()->orderBy('position')->get();

        $this->actingAs($admin)
            ->patchJson(route('admin.studio.projects.chunks.skip', [$project, $first]), ['skipped' => true])
            ->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        $this->assertSame([(string) $second->id], array_column($project->refresh()->final_timeline, 'chunk_id'));
    }

    public function test_reset_from_text_clears_the_timeline_with_the_final(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);
        $this->assertNotNull($project->final_timeline);

        $this->actingAs($admin)->post(route('admin.studio.projects.reset', $project), [
            'text' => 'Entirely new text for the project, long enough to chunk.',
        ]);

        $project->refresh();
        $this->assertNull($project->final_audio_path);
        $this->assertNull($project->final_timeline);
    }

    public function test_a_converter_without_durations_leaves_the_timeline_null(): void
    {
        // A test double / legacy converter returning the classic 3-tuple must
        // degrade to "no timeline" — never a partly-guessed map.
        $stub = new class extends AudioConverter
        {
            public function concatenate(array $inputChunks, string $outputFormat, string $inputContainer = 'wav', array $seamGapsMs = [], array $preserveTails = [], array $metadata = []): array
            {
                return ['stitched', 'audio/mpeg', 'mp3'];
            }
        };
        $this->app->instance(AudioConverter::class, $stub);

        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        $this->assertNotNull($project->final_audio_path);
        $this->assertNull($project->final_timeline);
    }

    public function test_timeline_endpoint_serves_the_current_map(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        $this->actingAs($admin)
            ->getJson(route('admin.studio.projects.timeline', $project))
            ->assertOk()
            ->assertJsonPath('timeline', $project->final_timeline);
    }

    public function test_timeline_endpoint_is_null_without_a_final(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.timeline', $project))
            ->assertOk()
            ->assertJsonPath('timeline', null);
    }

    public function test_timeline_endpoint_requires_project_access(): void
    {
        $project = $this->project();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson(route('admin.studio.projects.timeline', $project))
            ->assertForbidden();
    }

    public function test_the_project_page_emits_every_hook_follow_playback_binds_to(): void
    {
        // No final needed: the toggle and the per-card ▶ always render (JS
        // owns their enablement), so the hooks must be present from the start.
        $page = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $this->project()))
            ->assertOk();

        foreach (array_keys(self::FOLLOW_HOOKS) as $markup) {
            $page->assertSee($markup, false);
        }
    }

    public function test_the_frontend_still_binds_those_hooks(): void
    {
        // The other half of the pairing above — asserting only the markup
        // would let a rename inside app.js break the feature unnoticed.
        $js = file_get_contents(resource_path('js/app.js'));

        foreach (self::FOLLOW_HOOKS as $markup => $literal) {
            $this->assertStringContainsString(
                $literal,
                $js,
                "app.js no longer binds `{$literal}`, but the project page still emits `{$markup}`.",
            );
        }

        // The highlight is a JS-toggled class with a CSS-only definition —
        // nothing else references it, so a stylesheet cleanup could drop it
        // and leave the ring invisible while everything still "works".
        $this->assertStringContainsString('chunk-live', $js);
        $this->assertStringContainsString(
            '.chunk-live',
            file_get_contents(resource_path('css/app.css')),
            'app.css no longer styles .chunk-live — the playing chunk would highlight invisibly.',
        );
    }

    public function test_timeline_chunk_ids_are_strings(): void
    {
        // Chunk ids are UUIDs, so this holds naturally — but the frontend
        // matches entries against data-chunk-id with `===`, so pin it rather
        // than leave the comparison depending on an unstated assumption.
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        foreach ($project->final_timeline as $entry) {
            $this->assertIsString($entry['chunk_id']);
        }

        $served = $this->actingAs($admin)
            ->getJson(route('admin.studio.projects.timeline', $project))
            ->assertOk()
            ->json('timeline');

        foreach ($served as $entry) {
            $this->assertIsString($entry['chunk_id']);
        }
    }

    public function test_duplicate_rekeys_the_timeline_to_the_copys_chunks(): void
    {
        $admin = $this->admin();
        $project = $this->generateAndBuild($this->project(), $admin);

        $this->actingAs($admin)->post(route('admin.studio.projects.duplicate', $project));
        $copy = TtsProject::where('id', '!=', $project->id)->latest('id')->firstOrFail();

        $sourceTimeline = $project->final_timeline;
        $copyTimeline = $copy->final_timeline;
        $this->assertIsArray($copyTimeline);

        // Same layout (the final copied byte-for-byte), re-keyed to the copy's
        // fresh chunk ids in the same order.
        $this->assertSame(array_column($sourceTimeline, 'start_ms'), array_column($copyTimeline, 'start_ms'));
        $this->assertSame(array_column($sourceTimeline, 'end_ms'), array_column($copyTimeline, 'end_ms'));
        $this->assertSame(
            $copy->chunks()->orderBy('position')->pluck('id')->all(),
            array_column($copyTimeline, 'chunk_id'),
        );
        $this->assertEmpty(array_intersect(array_column($sourceTimeline, 'chunk_id'), array_column($copyTimeline, 'chunk_id')));
    }
}
