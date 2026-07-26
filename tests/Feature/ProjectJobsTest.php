<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectJobStatus;
use App\Enums\ProjectStatus;
use App\Jobs\DuplicateProjectJob;
use App\Jobs\GenerateProjectChunksJob;
use App\Jobs\StitchProjectJob;
use App\Models\TtsChunk;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Models\User;
use App\Models\Voice;
use App\Services\Credit\CreditService;
use App\Services\ProjectService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use App\Support\GenerationTimings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Background "Generate remaining" runs: dispatch + join semantics (including
 * the single-chunk run a Regenerate starts when no run is active), the queue
 * job's chunk loop (failures, cancellation, credit), the status poll, and the
 * Jobs page (scoping + cancel). The queue is sync in tests, so dispatching
 * without Queue::fake() runs the whole job inline.
 */
class ProjectJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local', 'tts.studio_generate_pace_ms' => 0]);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** A 2-chunk project (two paragraphs), optionally owned. */
    private function project(?int $userId = null): TtsProject
    {
        $voice = Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'My project',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            userId: $userId,
        );
    }

    /** A queued run row over the project's outstanding chunks (no worker involved). */
    private function queuedRun(TtsProject $project, ?int $createdById = null): TtsProjectJob
    {
        $ids = $project->chunks()->pluck('id')->all();

        return TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $createdById ?? $project->user_id,
            'chunk_ids' => $ids,
            'chunks_total' => count($ids),
        ]);
    }

    private function runJob(TtsProjectJob $job): void
    {
        (new GenerateProjectChunksJob($job->id))->handle(app(ProjectService::class));
    }

    // --- Dispatch ------------------------------------------------------------

    public function test_generate_remaining_dispatches_a_background_run(): void
    {
        Queue::fake();
        $project = $this->project();

        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project));

        $res->assertStatus(202)
            ->assertJsonPath('job.status', 'queued')
            ->assertJsonPath('job.chunks_total', 2)
            ->assertJsonPath('job.active', true);

        Queue::assertPushed(GenerateProjectChunksJob::class, 1);
        $job = TtsProjectJob::sole();
        $this->assertSame($project->chunks()->pluck('id')->all(), $job->chunk_ids);
        $this->assertSame(ProjectJobStatus::Queued, $job->status);
        // Up-front estimate stamped at creation: 2 chunks at the learned (here
        // default) per-model rate; pace is 0 in this suite.
        $this->assertSame(2 * GenerationTimings::perChunkMs('chatterbox'), $job->estimated_ms);
    }

    public function test_project_page_shows_the_pre_run_estimate(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('to generate the 2 remaining clips');
    }

    public function test_estimate_endpoint_tracks_the_outstanding_set(): void
    {
        $project = $this->project();

        // Two outstanding chunks → an estimate is offered (the page refetches
        // this as chunks change state, so the hint survives client-side edits).
        $res = $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.estimate', $project))
            ->assertOk();
        $this->assertStringContainsString('to generate the 2 remaining clips', $res->json('estimate'));

        // Generate everything (sync queue runs it inline) → nothing outstanding.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project));

        $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.estimate', $project))
            ->assertOk()
            ->assertJsonPath('estimate', null);
    }

    public function test_generate_remaining_runs_inline_on_the_sync_queue(): void
    {
        $project = $this->project();

        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project));

        // The sync queue executed the whole run inside the POST — the response
        // already reports the finished state.
        $res->assertOk()
            ->assertJsonPath('job.status', 'completed')
            ->assertJsonPath('job.chunks_done', 2)
            ->assertJsonPath('job.chunks_failed', 0);

        $this->assertSame(
            [ChunkStatus::Completed, ChunkStatus::Completed],
            $project->chunks()->get()->pluck('status')->all(),
        );
    }

    public function test_generate_remaining_snapshots_only_outstanding_unskipped_chunks(): void
    {
        Queue::fake();
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $first->update(['status' => ChunkStatus::Completed]);
        $third = TtsChunk::create([
            'tts_project_id' => $project->id,
            'position' => 2,
            'text' => 'A skipped closing paragraph long enough to stand alone.',
            'break_after' => 'sentence',
            'status' => ChunkStatus::Pending,
            'skipped' => true,
            'characters' => 55,
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertStatus(202);

        $this->assertSame([$second->id], TtsProjectJob::sole()->chunk_ids);
    }

    public function test_generate_remaining_422s_when_nothing_is_outstanding(): void
    {
        $project = $this->project();
        $project->chunks()->update(['status' => ChunkStatus::Completed->value]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertStatus(422);

        $this->assertSame(0, TtsProjectJob::count());
    }

    public function test_a_second_click_joins_the_active_run(): void
    {
        Queue::fake();
        $project = $this->project();
        $admin = $this->admin();

        $first = $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->json('job.id');

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertOk()
            ->assertJsonPath('job.id', $first);

        $this->assertSame(1, TtsProjectJob::count());
        Queue::assertPushed(GenerateProjectChunksJob::class, 1);
    }

    public function test_generate_remaining_402s_when_the_owner_is_out_of_credit(): void
    {
        $owner = User::factory()->create(['credit_balance_micro' => 0]);
        $project = $this->project($owner->id);

        $this->actingAs($owner)
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertStatus(402);

        $this->assertSame(0, TtsProjectJob::count());
    }

    public function test_manual_generation_is_blocked_while_a_run_is_active(): void
    {
        $project = $this->project();
        $this->queuedRun($project);
        $chunk = $project->chunks()->first();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertStatus(409);
        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(409);
    }

    // --- Queueing a chunk into an active run ---------------------------------

    public function test_regenerate_queues_a_completed_chunk_into_the_active_run(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $first->update(['status' => ChunkStatus::Completed]);
        $run = TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $project->user_id,
            'chunk_ids' => [$second->id],
            'chunks_total' => 1,
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $first]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('message', 'Saved — clip 1 will regenerate next in this run.')
            ->assertJsonPath('job.chunks_total', 2);

        // Stale (not completed) so the worker can't skip it — and FIRST in
        // line: no worker has claimed this run yet, so the clip the user is
        // actively fixing goes to the head, not behind the whole backlog.
        $this->assertSame(ChunkStatus::Stale, $first->fresh()->status);
        $this->assertSame([$first->id, $second->id], $run->fresh()->chunk_ids);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $run->status);
        $this->assertSame(2, $run->chunks_done);
        $this->assertSame(ChunkStatus::Completed, $first->fresh()->status);
        $this->assertGreaterThan(0, $first->takes()->count());
    }

    public function test_a_chunk_the_run_already_passed_can_be_requeued(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $run = $this->queuedRun($project);
        // The worker has moved past chunk one (it rendered fine)…
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_done' => 1]);
        $first->update(['status' => ChunkStatus::Completed]);

        // …and the user, listening along, finds that clip bad and re-queues it.
        // It slots in right after the clip in flight (here: the end anyway).
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $first]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('job.chunks_total', 3);

        $this->assertSame([$first->id, $second->id, $first->id], $run->fresh()->chunk_ids);
        $this->assertSame(ChunkStatus::Stale, $first->fresh()->status);
    }

    public function test_requeue_mid_run_inserts_right_after_the_in_flight_chunk(): void
    {
        $voice = Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);
        $project = app(ProjectService::class)->createFromText(
            title: 'Three chunks',
            voice: $voice,
            text: "First paragraph with plenty of words to stand on its own two feet.\n\n".
                  "Second paragraph, also long enough to be its very own chunk here.\n\n".
                  'Third paragraph, again long enough to be split off by the chunker.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            userId: null,
        );
        [$a, $b, $c] = $project->chunks()->get();
        $run = $this->queuedRun($project);
        // The worker finished A and is rendering B; C still waits its turn.
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_done' => 1]);
        $a->update(['status' => ChunkStatus::Completed]);

        // Re-queueing A puts it right after in-flight B — ahead of C, not last.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $a]))
            ->assertOk()
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('message', 'Saved — clip 1 will regenerate next in this run.');

        $this->assertSame([$a->id, $b->id, $a->id, $c->id], $run->fresh()->chunk_ids);

        // The poll reports every waiting card's place in that same line.
        $res = $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.generation-status', $project));
        $res->assertOk()
            ->assertJsonPath('chunks.0.status', 'queued')
            ->assertJsonPath('chunks.0.queue_label', 'queued · next in line')
            ->assertJsonPath('chunks.1.queue_label', 'rendering')
            ->assertJsonPath('chunks.2.queue_label', 'queued · 2nd in line');
    }

    public function test_queueing_the_chunk_being_rendered_is_left_alone(): void
    {
        $project = $this->project();
        [$first] = $project->chunks()->get();
        $run = $this->queuedRun($project);
        // The worker is on chunk one right now.
        $run->update(['status' => ProjectJobStatus::Running]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $first]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'rendering')
            ->assertJsonPath('job.chunks_total', 2);

        // No duplicate booking, no status change — the render in progress owns it.
        $this->assertCount(2, $run->fresh()->chunk_ids);
        $this->assertSame(ChunkStatus::Pending, $first->fresh()->status);
        $this->assertStringContainsString('rendering right now', $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $first]))
            ->json('message'));
    }

    public function test_queueing_a_chunk_already_waiting_in_the_run_is_a_no_op(): void
    {
        $project = $this->project();
        [, $second] = $project->chunks()->get();
        $run = $this->queuedRun($project);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $second]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · 2nd in line')
            ->assertJsonPath('message', 'Clip 2 is already queued (2nd in line) — it will render with the edits you just saved.')
            ->assertJsonPath('job.chunks_total', 2);

        $this->assertCount(2, $run->fresh()->chunk_ids);
        $this->assertSame(ChunkStatus::Pending, $second->fresh()->status);
    }

    public function test_a_completed_chunk_already_in_line_is_rearmed_in_place(): void
    {
        $project = $this->project();
        [$first] = $project->chunks()->get();
        $run = $this->queuedRun($project);
        // The chunk completed while waiting (say, a take was selected). The
        // worker's already-generated guard would pass it — so a regenerate
        // must re-arm the existing entry, not silently do nothing.
        $first->update(['status' => ChunkStatus::Completed]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $first]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('message', 'Clip 1 is already queued (next in line) — it will render with the edits you just saved.');

        $this->assertCount(2, $run->fresh()->chunk_ids);
        $this->assertSame(ChunkStatus::Stale, $first->fresh()->status);
    }

    public function test_a_chunk_appended_mid_run_is_picked_up_by_the_worker(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $run = TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $project->user_id,
            'chunk_ids' => [$first->id],
            'chunks_total' => 1,
        ]);

        // While the worker renders chunk one, "the user" queues chunk two —
        // the loop must pick it up even though it started with a 1-chunk list.
        $provider = new class($run->id, $second->id) extends FakeTtsProvider
        {
            public function __construct(private string $runId, private string $appendId) {}

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                $run = TtsProjectJob::find($this->runId);
                $ids = array_values((array) $run->chunk_ids);
                if (! in_array($this->appendId, $ids, true)) {
                    $run->update(['chunk_ids' => [...$ids, $this->appendId], 'chunks_total' => $run->chunks_total + 1]);
                }

                return parent::synthesize($text, $referenceAudio, $settings);
            }
        };
        $this->app->instance(TtsProvider::class, $provider);

        $this->runJob($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $run->status);
        $this->assertSame(2, $run->chunks_done);
        $this->assertSame(ChunkStatus::Completed, $second->fresh()->status);
    }

    public function test_regenerate_with_no_run_starts_a_single_chunk_run(): void
    {
        Queue::fake();
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $first->update(['status' => ChunkStatus::Completed]);

        // No active run: the click starts a background run over just this
        // chunk — never a render inside the web request (that path is what
        // 504'd on long renders and lost the worker mid-clip).
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $first]))
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('message', 'Saved — clip 1 is regenerating in the background.')
            ->assertJsonPath('job.chunks_total', 1)
            ->assertJsonPath('job.active', true);

        Queue::assertPushed(GenerateProjectChunksJob::class, 1);
        $run = TtsProjectJob::sole();
        $this->assertSame([$first->id], $run->chunk_ids);
        // Re-armed so the worker's already-generated guard can't skip it; the
        // untouched sibling is not the run's business.
        $this->assertSame(ChunkStatus::Stale, $first->fresh()->status);
        $this->assertSame(ChunkStatus::Pending, $second->fresh()->status);
        $this->assertSame(GenerationTimings::perChunkMs('chatterbox'), $run->estimated_ms);
    }

    public function test_a_single_chunk_run_rides_the_interactive_queue_when_configured(): void
    {
        config(['tts.generation.interactive_queue' => 'interactive']);
        Queue::fake();
        $project = $this->project();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $project->chunks()->first()]))
            ->assertStatus(202);

        // Workers listening --queue=interactive,default serve this ahead of
        // bulk runs and API speech jobs sitting on the default queue.
        Queue::assertPushedOn('interactive', GenerateProjectChunksJob::class);
    }

    public function test_single_chunk_run_renders_inline_on_the_sync_queue(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        // The sync queue executed the run inside the POST — the response
        // already reports the finished state and the real chunk status.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $chunk]))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('queue_label', null)
            ->assertJsonPath('job.status', 'completed')
            ->assertJsonPath('job.chunks_done', 1)
            // Scoped to the one clip — "All 1 chunk(s) generated" would read
            // as the whole project on a many-chunk page.
            ->assertJsonPath('job.message', '✓ Clip 1 generated — build the final to include it.');

        $this->assertSame(ChunkStatus::Completed, $chunk->fresh()->status);
        $this->assertGreaterThan(0, $chunk->takes()->count());
    }

    public function test_queueing_is_refused_while_the_run_is_stopping(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        // A stopping run winds down without reaching new work, and a second
        // run can't start while it's still active — refuse the click.
        $this->queuedRun($project)->update(['cancel_requested' => true]);
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $chunk]))
            ->assertStatus(409);
    }

    public function test_queueing_a_skipped_or_empty_chunk_is_refused(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $this->queuedRun($project);
        $first->update(['skipped' => true]);
        $second->update(['text' => '  ']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $first]))
            ->assertStatus(422);
        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $second]))
            ->assertStatus(422);
    }

    public function test_queueing_402s_when_the_owner_is_out_of_credit(): void
    {
        $owner = User::factory()->create(['credit_balance_micro' => 0]);
        $project = $this->project($owner->id);
        $run = $this->queuedRun($project);

        $this->actingAs($owner)
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $project->chunks()->first()]))
            ->assertStatus(402);

        $this->assertCount(2, $run->fresh()->chunk_ids);
    }

    // --- The queue job -------------------------------------------------------

    public function test_a_chunk_failure_is_counted_and_the_run_carries_on(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();

        // First chunk renders, second throws — the run must reach the end anyway.
        $provider = new class($second->text) extends FakeTtsProvider
        {
            public function __construct(private string $failText) {}

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                if ($text === $this->failText) {
                    throw new RuntimeException('provider exploded');
                }

                return parent::synthesize($text, $referenceAudio, $settings);
            }
        };
        $this->app->instance(TtsProvider::class, $provider);

        $job = $this->queuedRun($project);
        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $job->status);
        $this->assertSame(1, $job->chunks_done);
        $this->assertSame(1, $job->chunks_failed);
        $this->assertSame(ChunkStatus::Completed, $first->fresh()->status);
        $this->assertSame(ChunkStatus::Failed, $second->fresh()->status);
        $this->assertStringContainsString('failed', $job->statusPayload()['message']);
    }

    public function test_cancelling_mid_run_stops_after_the_current_chunk(): void
    {
        $project = $this->project();
        $job = $this->queuedRun($project);

        // The "user" hits Stop while the first chunk is rendering: the flag is
        // set mid-synthesis, and the worker must wind down before chunk two.
        $provider = new class($job->id) extends FakeTtsProvider
        {
            public function __construct(private string $jobId) {}

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                TtsProjectJob::whereKey($this->jobId)->update(['cancel_requested' => true]);

                return parent::synthesize($text, $referenceAudio, $settings);
            }
        };
        $this->app->instance(TtsProvider::class, $provider);

        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Cancelled, $job->status);
        $this->assertSame(1, $job->chunks_done);
        [$first, $second] = $project->chunks()->get();
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertSame(ChunkStatus::Pending, $second->status);
    }

    public function test_a_run_cancelled_while_queued_never_touches_a_chunk(): void
    {
        $project = $this->project();
        $job = $this->queuedRun($project);
        $job->update(['cancel_requested' => true]);

        $this->runJob($job);

        $this->assertSame(ProjectJobStatus::Cancelled, $job->fresh()->status);
        $this->assertSame(
            [ChunkStatus::Pending, ChunkStatus::Pending],
            $project->chunks()->get()->pluck('status')->all(),
        );
    }

    public function test_an_out_of_credit_owner_fails_the_run_before_spending(): void
    {
        $owner = User::factory()->create(['credit_balance_micro' => 0]);
        $project = $this->project($owner->id);
        $job = $this->queuedRun($project);

        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Failed, $job->status);
        $this->assertSame(CreditService::OUT_OF_CREDIT_MESSAGE, $job->error);
        $this->assertSame(0, $job->chunks_done);
    }

    public function test_a_chunk_deleted_or_hand_generated_since_dispatch_counts_as_done(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $job = $this->queuedRun($project);

        // Between dispatch and pickup: one chunk was deleted, the other was
        // generated by hand (no active-run guard applies to the worker itself).
        $first->delete();
        $second->update(['status' => ChunkStatus::Completed]);

        $this->runJob($job);

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $job->status);
        $this->assertSame(2, $job->chunks_done);
        $this->assertSame(100, $job->statusPayload()['percent']);
    }

    // --- Checkpoint + continuation -------------------------------------------

    public function test_a_run_out_of_time_checkpoints_into_a_continuation_job(): void
    {
        Queue::fake();
        config(['tts.async_timeout' => 0]); // zero budget: checkpoint after every chunk
        $project = $this->project();
        $job = $this->queuedRun($project);

        $this->runJob($job);

        // Chunk one rendered, then the loop handed off instead of running out
        // the clock — the run row stays live for the continuation.
        $job->refresh();
        $this->assertSame(ProjectJobStatus::Running, $job->status);
        $this->assertSame(1, $job->chunks_done);
        [$first, $second] = $project->chunks()->get();
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertSame(ChunkStatus::Pending, $second->status);

        Queue::assertPushed(
            GenerateProjectChunksJob::class,
            fn (GenerateProjectChunksJob $pushed) => $pushed->jobId === $job->id && $pushed->startIndex === 1,
        );
    }

    public function test_the_fairness_slice_checkpoints_a_run_and_keeps_its_queue(): void
    {
        Queue::fake();
        // The slice alone forces the hand-off — the timeout ceiling is miles off.
        config(['tts.generation.slice_seconds' => 0.000001]);
        $project = $this->project();
        $run = $this->queuedRun($project);

        $job = new GenerateProjectChunksJob($run->id);
        $job->onQueue('interactive'); // as the controller pins an interactive run
        $job->handle(app(ProjectService::class));

        // Chunk one rendered, then the slice handed off — on the SAME queue,
        // so an interactive run never falls back into the bulk line.
        $this->assertSame(1, $run->fresh()->chunks_done);
        Queue::assertPushedOn(
            'interactive',
            GenerateProjectChunksJob::class,
            fn (GenerateProjectChunksJob $pushed) => $pushed->jobId === $run->id && $pushed->startIndex === 1,
        );
    }

    public function test_a_continuation_resumes_at_its_cursor_without_re_rendering(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $first->update(['status' => ChunkStatus::Completed]);
        $job = $this->queuedRun($project);
        $startedAt = now()->subMinutes(20)->startOfSecond();
        $job->update(['status' => ProjectJobStatus::Running, 'chunks_done' => 1, 'started_at' => $startedAt]);

        (new GenerateProjectChunksJob($job->id, startIndex: 1))->handle(app(ProjectService::class));

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $job->status);
        $this->assertSame(2, $job->chunks_done);
        $this->assertSame(ChunkStatus::Completed, $second->fresh()->status);
        // The cursor skipped chunk one entirely: no re-render, no re-charge,
        // no double count in chunks_done…
        $this->assertSame(0, $first->takes()->count());
        // …and the run keeps its original start time across the handoff.
        $this->assertSame($startedAt->timestamp, $job->started_at->timestamp);
    }

    public function test_a_worker_kill_surfaces_a_friendly_error_but_other_failures_keep_theirs(): void
    {
        $project = $this->project();
        $job = $this->queuedRun($project);
        $job->update(['status' => ProjectJobStatus::Running]);

        // What a dead worker leaves behind (timeout kill, retry refused).
        (new GenerateProjectChunksJob($job->id))->failed(
            new MaxAttemptsExceededException(GenerateProjectChunksJob::class.' has been attempted too many times.'),
        );

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Failed, $job->status);
        $this->assertStringNotContainsString('attempted too many times', $job->error);
        $this->assertStringContainsString('Generate remaining', $job->error);

        // A real error keeps its own words.
        $job->update(['status' => ProjectJobStatus::Running, 'error' => null]);
        (new GenerateProjectChunksJob($job->id))->failed(new RuntimeException('disk exploded'));
        $this->assertSame('disk exploded', $job->fresh()->error);
    }

    // --- Status poll ---------------------------------------------------------

    public function test_generation_status_sends_full_payloads_only_for_finished_chunks(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $job = $this->queuedRun($project);
        app(ProjectService::class)->generateChunk($first); // the run "finished" one chunk

        $res = $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.generation-status', $project));

        $res->assertOk()
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('chunks.0.id', $first->id)
            ->assertJsonPath('chunks.0.status', 'completed')
            ->assertJsonPath('chunks.1.id', $second->id)
            // Still waiting its turn → the virtual 'queued' status + place in
            // line, not the underlying 'pending'.
            ->assertJsonPath('chunks.1.status', 'queued')
            ->assertJsonPath('chunks.1.queue_label', 'queued · next in line');

        $chunks = $res->json('chunks');
        $this->assertNotEmpty($chunks[0]['takes']); // finished → full card payload
        $this->assertArrayNotHasKey('takes', $chunks[1]); // outstanding → light
    }

    public function test_generation_status_drops_the_queue_labels_while_stopping(): void
    {
        $project = $this->project();
        // A stopping run reaches no new work — "queued" would be a lie, so the
        // waiting chunks report their real statuses instead.
        $this->queuedRun($project)->update(['cancel_requested' => true]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.generation-status', $project))
            ->assertOk()
            ->assertJsonPath('chunks.0.status', 'pending')
            ->assertJsonMissingPath('chunks.0.queue_label');
    }

    public function test_project_page_marks_waiting_chunks_queued(): void
    {
        $project = $this->project();
        $this->queuedRun($project);

        // A mid-run page load shows each waiting chunk's place in line (the
        // pill) and flips its render button to Queued — no bare "stale" or
        // "pending" masquerading as a problem.
        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('queued · next in line')
            ->assertSee('queued · 2nd in line')
            ->assertSee('⏳ Queued')
            ->assertSee('data-queued="1"', false);
    }

    public function test_generation_status_is_empty_for_a_project_with_no_runs(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.generation-status', $this->project()))
            ->assertOk()
            ->assertJsonPath('job', null)
            ->assertJsonPath('chunks', []);
    }

    // --- Cancel + Jobs page --------------------------------------------------

    public function test_cancel_flips_a_queued_run_immediately_and_guards_ownership(): void
    {
        $owner = User::factory()->create();
        $project = $this->project($owner->id);
        $job = $this->queuedRun($project);

        // A stranger (not owner, not SuperAdmin) may not stop it.
        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.jobs.cancel', $job))
            ->assertStatus(403);

        // The owner may — and a queued run cancels on the spot (there may be
        // no worker to honor the flag).
        $this->actingAs($owner)
            ->postJson(route('admin.jobs.cancel', $job))
            ->assertOk()
            ->assertJsonPath('job.status', 'cancelled');

        // Once settled, cancelling again is refused.
        $this->actingAs($owner)
            ->postJson(route('admin.jobs.cancel', $job))
            ->assertStatus(409);
    }

    public function test_jobs_page_shows_own_runs_only_unless_super_admin(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceJob = $this->queuedRun($this->project($alice->id));
        $bobJob = $this->queuedRun($this->project($bob->id));

        $this->actingAs($alice)->get(route('admin.jobs.index'))->assertOk();
        $ids = $this->actingAs($alice)->getJson(route('admin.jobs.status'))->json('jobs.*.id');
        $this->assertSame([$aliceJob->id], $ids);

        $ids = $this->actingAs($this->admin())->getJson(route('admin.jobs.status'))->json('jobs.*.id');
        $this->assertEqualsCanonicalizing([$aliceJob->id, $bobJob->id], $ids);
    }

    public function test_the_jobs_page_and_its_poll_paginate(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user->id);

        // 51 runs over one project — one past a page — with distinct, ordered
        // timestamps so newest-first paging is deterministic.
        $jobs = [];
        for ($i = 0; $i < 51; $i++) {
            $jobs[] = TtsProjectJob::forceCreate([
                'tts_project_id' => $project->id,
                'user_id' => $user->id,
                'created_by_id' => $user->id,
                'chunk_ids' => [],
                'chunks_total' => 0,
                'created_at' => now()->addSeconds($i),
            ]);
        }
        $newest = end($jobs);
        $oldest = $jobs[0];

        // Page 1's poll returns the 50 newest — the single oldest spills to page 2.
        $page1 = $this->actingAs($user)->getJson(route('admin.jobs.status'))->json('jobs.*.id');
        $this->assertCount(50, $page1);
        $this->assertContains($newest->id, $page1);
        $this->assertNotContains($oldest->id, $page1);

        // The page-2 poll returns exactly the spillover run — no run is unreachable.
        $page2 = $this->actingAs($user)->getJson(route('admin.jobs.status', ['page' => 2]))->json('jobs.*.id');
        $this->assertSame([$oldest->id], $page2);

        // The page itself renders the paginator: a next-page link and the total.
        $this->actingAs($user)->get(route('admin.jobs.index'))
            ->assertOk()
            ->assertSee('of 51 run(s)')
            ->assertSee('page=2');
    }

    // --- Jobs-page retention (jobs:prune) -------------------------------------

    /** A finished run row for the project, backdated to $createdAt. */
    private function finishedRun(TtsProject $project, Carbon $createdAt, ProjectJobStatus $status = ProjectJobStatus::Completed): TtsProjectJob
    {
        return TtsProjectJob::forceCreate([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $project->user_id,
            'status' => $status,
            'chunk_ids' => [],
            'chunks_total' => 0,
            'created_at' => $createdAt,
        ]);
    }

    public function test_prune_deletes_finished_runs_older_than_the_window(): void
    {
        $project = $this->project(User::factory()->create()->id);
        $old = $this->finishedRun($project, now()->subDays(8));
        $fresh = $this->finishedRun($project, now()->subDays(6));

        $this->artisan('jobs:prune')->expectsOutput('Pruned 1 finished run(s).')->assertExitCode(0);

        $this->assertNull(TtsProjectJob::find($old->id));
        $this->assertNotNull(TtsProjectJob::find($fresh->id));
    }

    public function test_prune_caps_finished_runs_per_owner_independently(): void
    {
        config(['tts.jobs_keep_per_user' => 2]);
        $aliceProject = $this->project(User::factory()->create()->id);
        $bobProject = $this->project(User::factory()->create()->id);

        // Three recent finished runs each (inside the age window) — only the
        // oldest of each owner's three falls past the cap.
        $aliceRuns = $bobRuns = [];
        foreach ([3, 2, 1] as $minutes) {
            $aliceRuns[] = $this->finishedRun($aliceProject, now()->subMinutes($minutes));
            $bobRuns[] = $this->finishedRun($bobProject, now()->subMinutes($minutes));
        }

        $this->artisan('jobs:prune')->expectsOutput('Pruned 2 finished run(s).')->assertExitCode(0);

        foreach ([$aliceRuns, $bobRuns] as $runs) {
            $this->assertNull(TtsProjectJob::find($runs[0]->id));
            $this->assertNotNull(TtsProjectJob::find($runs[1]->id));
            $this->assertNotNull(TtsProjectJob::find($runs[2]->id));
        }
    }

    public function test_prune_never_touches_active_runs(): void
    {
        config(['tts.jobs_keep_per_user' => 1]);
        $project = $this->project(User::factory()->create()->id);

        // Both active rows are ancient AND newer rows exist past the cap — an
        // active run must survive both rules (the worker owns those rows), and
        // it must not occupy a slot in the per-owner keep list either.
        $queued = $this->finishedRun($project, now()->subDays(30), ProjectJobStatus::Queued);
        $running = $this->finishedRun($project, now()->subDays(30), ProjectJobStatus::Running);
        $finished = $this->finishedRun($project, now()->subMinutes(5));

        $this->artisan('jobs:prune')->expectsOutput('Pruned 0 finished run(s).')->assertExitCode(0);

        $this->assertNotNull(TtsProjectJob::find($queued->id));
        $this->assertNotNull(TtsProjectJob::find($running->id));
        $this->assertNotNull(TtsProjectJob::find($finished->id));
    }

    public function test_prune_dry_run_counts_each_run_once_and_deletes_nothing(): void
    {
        config(['tts.jobs_keep_per_user' => 1]);
        $project = $this->project(User::factory()->create()->id);

        // The old run trips BOTH rules (past the window and past the cap) —
        // the dry-run count must not double-count it.
        $old = $this->finishedRun($project, now()->subDays(8));
        $fresh = $this->finishedRun($project, now()->subMinutes(5));

        $this->artisan('jobs:prune', ['--dry-run' => true])
            ->expectsOutput('1 finished run(s) would be pruned.')
            ->assertExitCode(0);

        $this->assertNotNull(TtsProjectJob::find($old->id));
        $this->assertNotNull(TtsProjectJob::find($fresh->id));
    }

    // --- Async "Build final" (stitch runs) ------------------------------------

    /** A project with every chunk generated, ready to stitch. */
    private function generatedProject(?int $userId = null): TtsProject
    {
        $project = $this->project($userId);
        $svc = app(ProjectService::class);
        foreach ($project->chunks()->get() as $chunk) {
            $svc->generateChunk($chunk);
        }

        return $project->refresh();
    }

    /** A queued stitch run row (no worker involved). */
    private function queuedStitch(TtsProject $project): TtsProjectJob
    {
        return TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $project->user_id,
            'type' => TtsProjectJob::TYPE_STITCH,
            'chunk_ids' => [],
            'chunks_total' => $project->chunks()->count(),
        ])->refresh(); // pull the DB-default status
    }

    public function test_build_final_books_a_stitch_run(): void
    {
        Queue::fake();
        $project = $this->generatedProject();

        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project));

        // The stitch runs on the queue worker, never inside the request — the
        // in-request path 504'd on big projects.
        $res->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.type', 'stitch')
            ->assertJsonPath('job.status', 'queued')
            ->assertJsonPath('job.active', true);

        Queue::assertPushed(StitchProjectJob::class, 1);
        $job = TtsProjectJob::sole();
        $this->assertTrue($job->isStitch());
        $this->assertSame([], $job->chunk_ids);
        $this->assertSame(2, $job->chunks_total);
        $this->assertNull($project->refresh()->final_audio_path);
    }

    public function test_stitch_job_builds_the_final_and_completes_the_run(): void
    {
        $project = $this->generatedProject();
        $job = $this->queuedStitch($project);

        (new StitchProjectJob($job->id))->handle(app(ProjectService::class));

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $job->status);
        // All-or-nothing: the counters flip to full so the Jobs page reads 100%.
        $this->assertSame($job->chunks_total, $job->chunks_done);

        $project->refresh();
        $this->assertSame(ProjectStatus::Ready, $project->status);
        $this->assertNotNull($project->final_audio_path);
        Storage::disk('local')->assertExists($project->final_audio_path);
    }

    public function test_stitch_run_failure_is_recorded_on_the_row(): void
    {
        $project = $this->generatedProject();
        // Break the stitch after booking: wipe a chunk's audio file reference.
        $project->chunks()->orderBy('position')->first()->update(['audio_path' => null]);
        $job = $this->queuedStitch($project);

        (new StitchProjectJob($job->id))->handle(app(ProjectService::class));

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Failed, $job->status);
        $this->assertSame('1 chunk(s) still need to be generated before rebuilding.', $job->error);
    }

    public function test_stitch_cancelled_while_queued_never_touches_the_final(): void
    {
        $project = $this->generatedProject();
        $job = $this->queuedStitch($project);
        $job->update(['cancel_requested' => true]);

        (new StitchProjectJob($job->id))->handle(app(ProjectService::class));

        $this->assertSame(ProjectJobStatus::Cancelled, $job->refresh()->status);
        $this->assertNull($project->refresh()->final_audio_path);
    }

    public function test_rebuild_preflight_rejects_without_booking_a_run(): void
    {
        Queue::fake();
        $project = $this->project(); // nothing generated

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(422)
            ->assertJsonPath('message', '2 chunk(s) still need to be generated before rebuilding.');

        Queue::assertNothingPushed();
        $this->assertSame(0, TtsProjectJob::count());
    }

    public function test_build_final_refuses_while_a_generate_run_is_active(): void
    {
        Queue::fake();
        $project = $this->generatedProject();
        $this->queuedRun($project);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_generate_endpoints_refuse_while_a_stitch_run_is_active(): void
    {
        Queue::fake();
        $project = $this->generatedProject();
        $this->queuedStitch($project);
        $chunk = $project->chunks()->orderBy('position')->first();
        $admin = $this->admin();

        // A stitch has no chunk line to join — every render path 409s while it
        // holds the project, and so does a second Build final.
        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertStatus(409);
        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $chunk]))
            ->assertStatus(409);
        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))
            ->assertStatus(409);
        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_stitch_status_payload_offers_no_cancel_while_running(): void
    {
        $project = $this->generatedProject();
        $job = $this->queuedStitch($project);

        // Queued: still cancellable (nothing is being written yet).
        $this->assertNotNull($job->statusPayload()['cancel_url']);

        // Running: one ffmpeg pass, no seam to stop at — Stop disappears.
        $job->update(['status' => ProjectJobStatus::Running, 'started_at' => now()]);
        $this->assertNull($job->refresh()->statusPayload()['cancel_url']);
    }

    /** A queued duplicate run row (no worker involved). */
    private function queuedDuplicate(TtsProject $project, ?int $createdById = null): TtsProjectJob
    {
        return TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $createdById ?? $project->user_id,
            'type' => TtsProjectJob::TYPE_DUPLICATE,
            'chunk_ids' => [],
            'chunks_total' => $project->chunks()->count(),
        ])->refresh(); // pull the DB-default status
    }

    public function test_duplicate_books_a_background_run_and_stays_on_the_source(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $project = $this->generatedProject();

        // A full-page form POST. With the worker faked the copy can't exist yet,
        // so the page stays on the source and follows the run (the old inline
        // path 504'd on long projects: one storage round-trip per clip).
        $this->actingAs($admin)
            ->post(route('admin.studio.projects.duplicate', $project))
            ->assertRedirect(route('admin.studio.projects.show', $project));

        Queue::assertPushed(DuplicateProjectJob::class, 1);

        $job = TtsProjectJob::sole();
        $this->assertTrue($job->isDuplicate());
        $this->assertSame(ProjectJobStatus::Queued, $job->status);
        $this->assertSame([], $job->chunk_ids);
        $this->assertSame(2, $job->chunks_total);
        $this->assertNull($job->result_project_id);

        // Nothing copied yet — the source is still the only project.
        $this->assertSame(1, TtsProject::count());
    }

    public function test_duplicate_run_is_refused_while_another_run_is_active(): void
    {
        Queue::fake();
        $project = $this->generatedProject();
        $this->queuedStitch($project); // occupies the project

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.duplicate', $project))
            ->assertRedirect(route('admin.studio.projects.show', $project))
            ->assertSessionHas('error');

        // Only the stitch row exists — no duplicate was booked.
        Queue::assertNothingPushed();
        $this->assertSame(0, TtsProjectJob::where('type', TtsProjectJob::TYPE_DUPLICATE)->count());
    }

    public function test_duplicate_job_creates_the_copy_and_carries_the_redirect_url(): void
    {
        $admin = $this->admin();
        $project = $this->generatedProject($admin->id);
        $job = $this->queuedDuplicate($project, $admin->id);

        (new DuplicateProjectJob($job->id))->handle(app(ProjectService::class));

        $job->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $job->status);
        // The per-clip progress counter reaches full so the Jobs page reads 100%.
        $this->assertSame($job->chunks_total, $job->chunks_done);

        // The copy is a real, independent project the run points the page at.
        $copy = TtsProject::findOrFail($job->result_project_id);
        $this->assertNotSame($project->id, $copy->id);
        $this->assertSame($admin->id, $copy->user_id);
        $this->assertSame($project->chunks()->count(), $copy->chunks()->count());

        $payload = $job->statusPayload();
        $this->assertSame('completed', $payload['status']);
        $this->assertSame(route('admin.studio.projects.show', $copy->id), $payload['redirect_url']);
        $this->assertStringContainsString('opening the copy', $payload['message']);
    }

    public function test_duplicate_run_offers_no_cancel_while_running(): void
    {
        $project = $this->generatedProject();
        $job = $this->queuedDuplicate($project);

        // Queued: still cancellable (no clip copied yet).
        $this->assertNotNull($job->statusPayload()['cancel_url']);

        // Running: a single copy sweep, no seam to stop at — Stop disappears.
        $job->update(['status' => ProjectJobStatus::Running, 'started_at' => now()]);
        $this->assertNull($job->refresh()->statusPayload()['cancel_url']);
    }
}
