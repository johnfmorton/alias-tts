<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use App\Jobs\DeleteStoredFilesJob;
use App\Models\PronunciationEntry;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Revise text": paste the updated manuscript, preview the chunk-level diff,
 * commit — only the changed chunks re-render. Chunk mode is 'sentence' (the
 * global default), so each one-sentence paragraph below is exactly one chunk
 * and edits stay contained.
 */
class ProjectReviseTest extends TestCase
{
    use RefreshDatabase;

    private const P1 = 'This is the first paragraph with plenty of words to stand on its own.';

    private const P2 = 'This is the second paragraph, also long enough to be its own chunk.';

    private const P3 = 'And a third paragraph rounds out the little story nicely.';

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

    /** A generated 3-chunk project (three one-sentence paragraphs). */
    private function generatedProject(?int $userId = null): TtsProject
    {
        $svc = app(ProjectService::class);
        $project = $svc->createFromText(
            title: 'My project',
            voice: Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']),
            text: self::P1."\n\n".self::P2."\n\n".self::P3,
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            userId: $userId,
        );

        foreach ($project->chunks()->get() as $chunk) {
            $svc->generateChunk($chunk);
        }
        $svc->rebuild($project); // Ready, with a final — so staling is observable

        return $project->refresh();
    }

    public function test_revise_page_prefills_the_canonical_current_text(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);

        // Hand-edit a chunk AFTER creation: the prefill must reflect the chunks,
        // not the stale source_text.
        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->updateChunkText($chunk, 'A hand-edited first paragraph that source_text never saw.');

        $this->actingAs($admin)
            ->get(route('admin.studio.projects.revise', $project))
            ->assertOk()
            ->assertSee('A hand-edited first paragraph that source_text never saw.')
            ->assertSee('Preview changes');
    }

    public function test_unchanged_paste_is_a_no_op(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        $before = $project->chunks()->orderBy('position')->get(['id', 'text', 'status'])->toArray();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), [
                'text' => app(ProjectService::class)->canonicalText($project),
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project))
            ->assertSessionHas('success', 'Nothing to update — the project already matches the pasted text.');

        $this->assertSame($before, $project->chunks()->orderBy('position')->get(['id', 'text', 'status'])->toArray());
        $this->assertSame(ProjectStatus::Ready, $project->refresh()->status);
    }

    public function test_single_edit_stales_only_that_chunk_and_keeps_its_row(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        [$c1, $c2, $c3] = $project->chunks()->orderBy('position')->get()->all();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), [
                'text' => self::P1."\n\nThis second paragraph now says something different entirely.\n\n".self::P3,
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $c1->refresh();
        $c2->refresh();
        $c3->refresh();

        // Neighbors untouched: same audio, still completed.
        $this->assertSame(ChunkStatus::Completed, $c1->status);
        $this->assertSame(ChunkStatus::Completed, $c3->status);
        $this->assertNotNull($c1->audio_path);

        // The edited slot kept its ROW (takes history) but went stale with the new text.
        $this->assertSame(ChunkStatus::Stale, $c2->status);
        $this->assertSame('This second paragraph now says something different entirely.', $c2->text);
        $this->assertSame(1, $c2->takes()->count());

        // The built final no longer reflects the document.
        $this->assertSame(ProjectStatus::Stale, $project->refresh()->status);
    }

    public function test_insertion_creates_a_pending_chunk_in_place(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        $ids = $project->chunks()->orderBy('position')->pluck('id')->all();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), [
                'text' => self::P1."\n\nA brand new paragraph slots in right here.\n\n".self::P2."\n\n".self::P3,
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $chunks = $project->chunks()->orderBy('position')->get();
        $this->assertCount(4, $chunks);
        $this->assertSame([0, 1, 2, 3], $chunks->pluck('position')->all());
        $this->assertSame('A brand new paragraph slots in right here.', $chunks[1]->text);
        $this->assertSame(ChunkStatus::Pending, $chunks[1]->status);
        // The original rows survived around it.
        $this->assertSame([$ids[0], $ids[1], $ids[2]], [$chunks[0]->id, $chunks[2]->id, $chunks[3]->id]);
        $this->assertSame(ChunkStatus::Completed, $chunks[2]->status);
    }

    public function test_removal_deletes_the_row_and_queues_its_files(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        [$c1, $c2, $c3] = $project->chunks()->orderBy('position')->get()->all();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), [
                'text' => self::P1."\n\n".self::P3,
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $chunks = $project->chunks()->orderBy('position')->get();
        $this->assertSame([$c1->id, $c3->id], $chunks->pluck('id')->all());
        $this->assertSame([0, 1], $chunks->pluck('position')->all());
        $this->assertSame(ChunkStatus::Completed, $chunks[0]->status);

        // The removed chunk's audio reaps on the queue, off the request.
        Queue::assertPushed(DeleteStoredFilesJob::class, function (DeleteStoredFilesJob $job) use ($c2) {
            return in_array($c2->audio_path, $job->paths, true);
        });
    }

    public function test_moved_paragraph_carries_its_audio(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        [$c1, $c2, $c3] = $project->chunks()->orderBy('position')->get()->all();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), [
                'text' => self::P2."\n\n".self::P1."\n\n".self::P3,
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $chunks = $project->chunks()->orderBy('position')->get();
        // Same three rows, first two swapped, nothing re-renders.
        $this->assertSame([$c2->id, $c1->id, $c3->id], $chunks->pluck('id')->all());
        $this->assertSame([ChunkStatus::Completed, ChunkStatus::Completed, ChunkStatus::Completed], $chunks->pluck('status')->all());
        $this->assertNotNull($chunks[0]->audio_path);
        // But the stitched final is out of date — the order changed.
        $this->assertSame(ProjectStatus::Stale, $project->refresh()->status);
    }

    public function test_unchanged_paste_with_a_new_pronunciation_flags_only_affected_chunks(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);

        // The dictionary gained an entry AFTER the project was created — the
        // repair flow: revise with nothing edited, re-render just the hits.
        PronunciationEntry::create([
            'user_id' => $admin->id,
            'term' => 'second',
            'phonetic' => 'sekkund',
            'approved' => true,
        ]);

        $res = $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.revise.preview', $project), [
                'text' => app(ProjectService::class)->canonicalText($project),
            ])
            ->assertOk()
            ->assertJsonPath('pipeline_only', true)
            ->assertJsonPath('changed', true)
            ->assertJsonPath('counts.update', 1);

        $this->assertStringContainsString('sekkund', $res->json('changes.0.text'));

        // Committing the same paste stales exactly the affected chunk.
        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), [
                'text' => app(ProjectService::class)->canonicalText($project),
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $statuses = $project->chunks()->orderBy('position')->pluck('status')->all();
        $this->assertSame([ChunkStatus::Completed, ChunkStatus::Stale, ChunkStatus::Completed], $statuses);
    }

    public function test_preview_is_read_only(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.revise.preview', $project), [
                'text' => self::P1."\n\nEverything after the first paragraph is different now.",
            ])
            ->assertOk()
            ->assertJsonPath('changed', true);

        // Nothing moved: statuses, texts, and the final all as before.
        $this->assertSame(
            [ChunkStatus::Completed, ChunkStatus::Completed, ChunkStatus::Completed],
            $project->chunks()->orderBy('position')->pluck('status')->all(),
        );
        $this->assertSame(ProjectStatus::Ready, $project->refresh()->status);
    }

    public function test_commit_refuses_while_a_run_is_active(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'chunk_ids' => [],
            'chunks_total' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), ['text' => self::P1])
            ->assertRedirect(route('admin.studio.projects.revise', $project))
            ->assertSessionHas('error');

        $this->assertSame(3, $project->chunks()->count());
    }

    public function test_empty_text_is_rejected(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), ['text' => ''])
            ->assertSessionHasErrors('text');

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.revise.preview', $project), ['text' => ''])
            ->assertStatus(422);
    }

    public function test_revision_clears_the_seal(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        app(ProjectService::class)->seal($project, $admin);
        $this->assertTrue($project->refresh()->isSealed());

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.revise.apply', $project), [
                'text' => self::P1."\n\nA sealed project un-approves when its words change.\n\n".self::P3,
            ])
            ->assertRedirect(route('admin.studio.projects.show', $project));

        $this->assertFalse($project->refresh()->isSealed());
    }

    public function test_guests_and_other_users_cannot_revise(): void
    {
        $owner = $this->admin();
        $project = $this->generatedProject($owner->id);

        $this->get(route('admin.studio.projects.revise', $project))->assertRedirect(route('login'));

        // Projects are personal: a non-SuperAdmin stranger is refused by policy.
        $stranger = User::factory()->create(['is_super_admin' => false]);
        $this->actingAs($stranger)
            ->get(route('admin.studio.projects.revise', $project))
            ->assertForbidden();
    }
}
