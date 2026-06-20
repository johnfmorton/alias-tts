<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudioProjectTest extends TestCase
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

    /** A 2-chunk project (two paragraphs, each long enough to stand alone). */
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

    public function test_create_page_requires_admin(): void
    {
        $this->get(route('admin.studio.projects.create'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.studio.projects.create'))
            ->assertForbidden();
    }

    public function test_store_creates_project_with_chunks(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $res = $this->actingAs($this->admin())->post(route('admin.studio.projects.store'), [
            'title' => 'Doc',
            'voice' => 'v',
            'text' => "First paragraph that is comfortably long enough.\n\nSecond paragraph that is also long enough.",
        ]);

        $project = TtsProject::firstWhere('title', 'Doc');
        $this->assertNotNull($project);
        $res->assertRedirect(route('admin.studio.projects.show', $project));
        $this->assertCount(2, $project->chunks);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertSame(ChunkStatus::Pending, $project->chunks->first()->status);
    }

    public function test_editor_page_renders(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee($project->title)
            ->assertSee('Rebuild final')
            ->assertSee($project->chunks()->first()->text);
    }

    public function test_update_renames_project(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.update', $project), ['title' => '  Renamed project  '])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('title', 'Renamed project'); // trimmed

        $this->assertSame('Renamed project', $project->refresh()->title);
    }

    public function test_update_rejects_blank_title(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.update', $project), ['title' => '   '])
            ->assertStatus(422);

        $this->assertSame('My project', $project->refresh()->title);
    }

    public function test_generate_chunk_persists_audio_and_is_selective(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get()->all();

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $first->refresh();
        $second->refresh();

        // Only the targeted chunk was generated.
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertNotNull($first->audio_path);
        $this->assertTrue(Storage::disk('local')->exists($first->audio_path));
        $this->assertSame(ChunkStatus::Pending, $second->status);
        $this->assertNull($second->audio_path);
    }

    public function test_changing_project_voice_marks_generated_chunks_stale(): void
    {
        $project = $this->project();
        $first = $project->chunks()->first();
        $other = Voice::create(['slug' => 'other', 'name' => 'Other']);

        // Generate one chunk so there is audio tied to the old voice.
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => 'other'])
            ->assertOk()
            ->assertJsonPath('voice', 'other');

        $project->refresh();
        $this->assertSame($other->id, $project->voice_id);
        // The generated chunk no longer matches the new voice -> Stale; the
        // ungenerated one stays Pending.
        $this->assertSame(ChunkStatus::Stale, $first->refresh()->status);
        $this->assertSame(ChunkStatus::Pending, $project->chunks()->orderBy('position')->get()->last()->status);
    }

    public function test_show_page_renders_the_voice_picker(): void
    {
        $project = $this->project();
        Voice::create(['slug' => 'other', 'name' => 'Other']);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('id="project-voice"', false)
            ->assertSee('Other');
    }

    public function test_changing_project_voice_rejects_unknown_voice(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.voice', $project), ['voice' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unknown voice.');
    }

    public function test_chunk_audio_is_served_after_generation(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));

        $res = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.chunks.audio', [$project, $chunk]));

        $res->assertOk();
        $this->assertStringStartsWith('audio/wav', (string) $res->headers->get('content-type'));
    }

    public function test_rebuild_requires_all_chunks_generated(): void
    {
        $project = $this->project();
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $project->chunks()->first()]));

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(422)
            ->assertJsonPath('message', '1 chunk(s) still need to be generated before rebuilding.');
    }

    public function test_rebuild_stitches_and_serves_final(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $project->refresh();
        $this->assertSame(ProjectStatus::Ready, $project->status);
        $this->assertNotNull($project->final_audio_path);
        $this->assertTrue(Storage::disk('local')->exists($project->final_audio_path));

        $audio = $this->actingAs($this->admin())->get(route('admin.studio.projects.audio', $project));
        $audio->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $audio->headers->get('content-type'));
    }

    public function test_preview_stitches_selected_chunks_without_persisting(): void
    {
        $project = $this->project();
        $chunks = $project->chunks()->get();
        foreach ($chunks as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $res = $this->actingAs($this->admin())->postJson(route('admin.studio.projects.preview', $project), [
            'chunks' => $chunks->pluck('id')->all(),
        ]);

        $res->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());
        // Preview must not write a final file.
        $this->assertNull($project->refresh()->final_audio_path);
    }

    public function test_preview_rejects_ungenerated_selection(): void
    {
        $project = $this->project(); // chunks exist but none generated

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.preview', $project), [
                'chunks' => $project->chunks()->pluck('id')->all(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select at least one generated chunk to preview.');
    }

    public function test_editing_a_chunk_marks_it_and_the_project_stale(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project));

        $chunk = $project->chunks()->first();
        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.update', [$project, $chunk]), ['text' => 'A rewritten sentence here.'])
            ->assertOk()
            ->assertJsonPath('status', 'stale')
            ->assertJsonPath('project_status', 'stale')
            ->assertJsonPath('rechunked', false); // short edit fits one chunk

        $this->assertSame(ChunkStatus::Stale, $chunk->refresh()->status);
        $this->assertSame('A rewritten sentence here.', $chunk->text);
    }

    public function test_inserting_a_chunk_shifts_positions_and_creates_empty_pending(): void
    {
        $project = $this->project(); // positions 0, 1

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 1])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $chunks = $project->chunks()->get();
        $this->assertCount(3, $chunks);
        $this->assertSame([0, 1, 2], $chunks->pluck('position')->all());

        $inserted = $chunks[1];
        $this->assertSame('', $inserted->text);
        $this->assertSame(0, $inserted->characters);
        $this->assertSame(ChunkStatus::Pending, $inserted->status);
    }

    public function test_inserting_at_boundaries_keeps_positions_contiguous(): void
    {
        $project = $this->project();

        // Lead (0) then append (current count).
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 0])->assertOk();
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => $project->chunks()->count()])->assertOk();

        $this->assertSame([0, 1, 2, 3], $project->chunks()->pluck('position')->all());
    }

    public function test_insert_rejects_out_of_range_position(): void
    {
        $project = $this->project(); // 2 chunks → valid positions 0..2

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 99])
            ->assertStatus(422);

        $this->assertCount(2, $project->chunks()->get());
    }

    public function test_generate_rejects_empty_chunk(): void
    {
        $project = $this->project();
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.store', $project), ['position' => 0])->assertOk();

        $empty = $project->chunks()->where('position', 0)->first();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $empty]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This chunk is empty — add text before generating.');
    }

    public function test_long_edit_splits_chunk_and_preserves_sibling_audio(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get()->all();

        // Generate both; capture the SECOND (sibling) chunk's audio.
        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $first]))->assertOk();
        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $second]))->assertOk();
        $siblingAudio = $second->refresh()->audio_path;
        $this->assertNotNull($siblingAudio);
        $this->assertTrue(Storage::disk('local')->exists($siblingAudio));

        // Edit the first chunk with text far over the ~280-char budget.
        $long = str_repeat('A reasonably long sentence that contributes plenty of characters here. ', 8);
        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.update', [$project, $first]), ['text' => $long])
            ->assertOk()
            ->assertJsonPath('rechunked', true);

        // The chunk list grew, positions stay contiguous.
        $positions = $project->chunks()->pluck('position')->all();
        $this->assertGreaterThan(2, count($positions));
        $this->assertSame(range(0, count($positions) - 1), $positions);

        // The edited chunk (had audio) is Stale; the untouched sibling keeps its audio.
        $this->assertSame(ChunkStatus::Stale, $first->refresh()->status);
        $second->refresh();
        $this->assertSame($siblingAudio, $second->audio_path);
        $this->assertTrue(Storage::disk('local')->exists($siblingAudio));
        $this->assertTrue($second->position > $first->position); // sibling shifted down
    }

    public function test_split_preserves_paragraph_break_on_the_last_piece(): void
    {
        $project = $this->project();
        $first = $project->chunks()->first();
        $this->assertSame('paragraph', $first->break_after); // precondition: ends a paragraph

        // A single long paragraph (no blank line) so internal seams are sentences.
        $long = str_repeat('This sentence is fairly long and adds plenty of characters to exceed the budget. ', 6);
        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.update', [$project, $first]), ['text' => $long])
            ->assertOk()
            ->assertJsonPath('rechunked', true);

        $chunks = $project->chunks()->get();
        // First piece is a within-block sentence seam...
        $this->assertSame('sentence', $chunks->first()->break_after);
        // ...and the last split piece (just before the original 2nd chunk) inherits paragraph.
        $this->assertSame('paragraph', $chunks[$chunks->count() - 2]->break_after);
    }

    public function test_reset_rechunks_text_and_wipes_audio(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        $project->refresh();
        $oldAudio = $project->chunks()->whereNotNull('audio_path')->pluck('audio_path')->all();
        $finalPath = $project->final_audio_path;
        $voiceId = $project->voice_id;
        $this->assertNotEmpty($oldAudio);
        $this->assertNotNull($finalPath);

        $newText = "Brand new first paragraph that stands comfortably on its own.\n\n".
                   'Brand new second paragraph that is also long enough to be its own chunk.';

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.reset', $project), ['text' => $newText])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $project->refresh();
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertNull($project->final_audio_path);
        $this->assertSame($voiceId, $project->voice_id); // voice preserved
        $this->assertSame($newText, $project->source_text);
        $this->assertStringContainsString('Brand new first paragraph', $project->chunks()->first()->text);
        $this->assertFalse($project->chunks()->where('status', '!=', 'pending')->exists());

        // All previous audio (chunks + final) is gone from disk.
        foreach ($oldAudio as $path) {
            $this->assertFalse(Storage::disk('local')->exists($path));
        }
        $this->assertFalse(Storage::disk('local')->exists($finalPath));
    }

    public function test_edit_page_renders_source_text(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.edit', $project))
            ->assertOk()
            ->assertSee('Start over')
            ->assertSee('This is the first paragraph');
    }

    public function test_destroy_removes_project_and_audio(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();
        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $path = $chunk->refresh()->audio_path;

        $this->actingAs($this->admin())
            ->delete(route('admin.studio.projects.destroy', $project))
            ->assertRedirect(route('admin.studio.index'));

        $this->assertNull(TtsProject::find($project->id));
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    // --- Phase 2: per-chunk tuning overrides + re-roll -------------------------

    /** A provider that records the settings it's handed (and returns junk bytes,
     *  which generateChunk just stores raw). */
    private function capturingProvider(): TtsProvider
    {
        $provider = new class implements TtsProvider
        {
            /** @var array<string, mixed> */
            public array $lastSettings = [];

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $this->lastSettings = $settings;

                return 'RIFFfake';
            }

            public function outputContainer(): string
            {
                return 'wav';
            }
        };

        $this->app->instance(TtsProvider::class, $provider);

        return $provider;
    }

    public function test_tune_chunk_saves_override_and_marks_completed_chunk_stale(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->generateChunk($chunk);
        $this->assertSame(ChunkStatus::Completed, $chunk->refresh()->status);

        $this->actingAs($this->admin())
            ->patch(route('admin.studio.projects.chunks.tuning', [$project, $chunk]), [
                'stability' => 0.9,
                'style' => 0.4,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'stale');

        $chunk->refresh();
        $this->assertSame(0.9, $chunk->settings['stability']);
        $this->assertSame(0.4, $chunk->settings['style']);
        $this->assertSame(ChunkStatus::Stale, $chunk->status);
    }

    public function test_tune_chunk_rejects_out_of_range(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.tuning', [$project, $chunk]), ['stability' => 2])
            ->assertStatus(422);
    }

    public function test_chunk_override_overlays_project_settings_at_generation(): void
    {
        $provider = $this->capturingProvider();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        app(ProjectService::class)->updateChunkTuning($chunk, 0.9, null);
        app(ProjectService::class)->generateChunk($chunk->refresh());

        $this->assertSame(0.9, $provider->lastSettings['stability']);       // chunk override wins
        $this->assertSame(0.75, $provider->lastSettings['similarity_boost']); // project/config kept
    }

    public function test_reroll_drops_the_pinned_project_seed(): void
    {
        $provider = $this->capturingProvider();
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);
        $project = app(ProjectService::class)->createFromText(
            title: 'P',
            voice: $voice,
            text: 'A single short line to speak.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: 123,
        );
        $chunk = $project->chunks()->first();

        app(ProjectService::class)->generateChunk($chunk);
        $this->assertSame(123, $provider->lastSettings['seed']);

        app(ProjectService::class)->generateChunk($chunk->refresh(), reroll: true);
        $this->assertArrayNotHasKey('seed', $provider->lastSettings);
    }

    public function test_reroll_endpoint_regenerates_a_chunk(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->generateChunk($chunk);

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.reroll', [$project, $chunk]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_preview_chunk_tuning_returns_audio_without_persisting(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();
        $originalStatus = $chunk->status;

        $res = $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.preview-tuning', [$project, $chunk]), ['stability' => 0.9]);

        $res->assertOk();
        $this->assertStringStartsWith('audio/', (string) $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());

        // A preview persists nothing — no stored override, no audio, same status.
        $chunk->refresh();
        $this->assertNull($chunk->settings);
        $this->assertNull($chunk->audio_path);
        $this->assertSame($originalStatus, $chunk->status);
    }

    public function test_preview_chunk_tuning_uses_the_candidate_override(): void
    {
        $provider = $this->capturingProvider();
        $project = $this->project();
        $chunk = $project->chunks()->orderBy('position')->first();

        app(ProjectService::class)->previewChunkTuning($chunk, 0.9, null);

        $this->assertSame(0.9, $provider->lastSettings['stability']);        // candidate wins
        $this->assertSame(0.75, $provider->lastSettings['similarity_boost']); // project base kept
    }

    public function test_preview_chunk_tuning_validates_range(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.preview-tuning', [$project, $chunk]), ['stability' => 2])
            ->assertStatus(422);
    }
}
