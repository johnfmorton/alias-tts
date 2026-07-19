<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectJobStatus;
use App\Jobs\GenerateProjectChunksJob;
use App\Jobs\GenerateProjectChunkWorkerJob;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Bounded-concurrency "Generate remaining" (docs/GENERATION-CONCURRENCY.md):
 * flag-gated dispatch, the claim-based worker loop (atomic claiming, failure
 * isolation, cancellation, credit, checkpointing), single-coordinator run
 * completion, and queueChunk/label behavior against the claim cursor. True
 * multi-process interleaving can't run inside one test process; interleavings
 * are simulated by staging the run row the way an in-flight sibling would
 * leave it, which exercises the same lock-guarded state transitions.
 */
class ConcurrentGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.studio_generate_pace_ms' => 0,
            'tts.generation.concurrent_enabled' => true,
            'tts.generation.max_concurrency' => 2,
        ]);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** A project with 2 (default) or 3 paragraph chunks, optionally owned. */
    private function project(?int $userId = null, int $chunks = 2): TtsProject
    {
        $voice = Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);
        $paragraphs = array_slice([
            'This is the first paragraph with plenty of words to stand on its own.',
            'This is the second paragraph, also long enough to be its own chunk.',
            'This is the third paragraph, again split off on its own by the chunker.',
        ], 0, $chunks);

        return app(ProjectService::class)->createFromText(
            title: 'My project',
            voice: $voice,
            text: implode("\n\n", $paragraphs),
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            userId: $userId,
        );
    }

    /** A claim-based run row over the project's chunks (no worker involved). */
    private function claimRun(TtsProject $project, int $concurrency = 2): TtsProjectJob
    {
        $ids = $project->chunks()->pluck('id')->all();

        return TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $project->user_id,
            'chunk_ids' => $ids,
            'chunks_total' => count($ids),
            'concurrency' => $concurrency,
        ]);
    }

    private function runWorker(TtsProjectJob $run): void
    {
        (new GenerateProjectChunkWorkerJob($run->id))->handle(app(ProjectService::class));
    }

    // --- Dispatch ------------------------------------------------------------

    public function test_flag_off_keeps_the_serial_job_and_an_unstamped_run(): void
    {
        config(['tts.generation.concurrent_enabled' => false]);
        Queue::fake();
        $project = $this->project();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertStatus(202);

        Queue::assertPushed(GenerateProjectChunksJob::class, 1);
        Queue::assertNotPushed(GenerateProjectChunkWorkerJob::class);
        $run = TtsProjectJob::sole();
        $this->assertNull($run->concurrency);
        $this->assertFalse($run->claimBased());
    }

    public function test_flag_on_dispatches_workers_capped_by_outstanding_chunks(): void
    {
        config(['tts.generation.max_concurrency' => 3]);
        Queue::fake();
        $project = $this->project(); // 2 outstanding < K=3

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertStatus(202);

        Queue::assertPushed(GenerateProjectChunkWorkerJob::class, 2);
        Queue::assertNotPushed(GenerateProjectChunksJob::class);
        $run = TtsProjectJob::sole();
        $this->assertSame(2, $run->concurrency);
        // The estimate seed is the ideal concurrent envelope: serial time / K.
        $this->assertSame(GenerationTimings::perChunkMs('chatterbox'), $run->estimated_ms);
    }

    public function test_workers_go_to_the_configured_generation_queue(): void
    {
        config(['tts.generation.queue' => 'generation']);
        Queue::fake();
        $project = $this->project();

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project));

        Queue::assertPushedOn('generation', GenerateProjectChunkWorkerJob::class);
    }

    // --- The claim loop ------------------------------------------------------

    public function test_a_concurrent_run_completes_on_the_sync_queue(): void
    {
        $project = $this->project();

        // Sync queue: worker 1 runs inline and claims everything; worker 2
        // then finds the run terminal and no-ops — the degenerate one-process
        // case the design guarantees stays correct.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.generate-remaining', $project))
            ->assertOk()
            ->assertJsonPath('job.status', 'completed')
            ->assertJsonPath('job.chunks_done', 2)
            ->assertJsonPath('job.chunks_failed', 0);

        $run = TtsProjectJob::sole();
        $this->assertSame(2, $run->chunks_claimed);
        $this->assertSame(
            [ChunkStatus::Completed, ChunkStatus::Completed],
            $project->chunks()->get()->pluck('status')->all(),
        );
    }

    public function test_a_worker_leaves_the_run_open_while_a_sibling_still_renders(): void
    {
        $project = $this->project();
        [$a, $b] = $project->chunks()->get();
        $run = $this->claimRun($project);
        // Stage what a sibling mid-render leaves behind: entry A claimed (in
        // flight, not landed), B unclaimed.
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_claimed' => 1]);

        $this->runWorker($run);

        // This worker claimed and rendered B, then found A still in flight at
        // settle — the run MUST stay open for A's worker to finish.
        $run->refresh();
        $this->assertSame(ProjectJobStatus::Running, $run->status);
        $this->assertSame(2, $run->chunks_claimed);
        $this->assertSame(1, $run->chunks_done);
        $this->assertSame(ChunkStatus::Completed, $b->fresh()->status);
        $this->assertSame(ChunkStatus::Pending, $a->fresh()->status);

        // "A's worker" lands its chunk and exits through the same settle path:
        // now nothing is in flight and nothing unclaimed — the run completes,
        // by exactly one coordinator (the terminal guard makes later settles
        // and workers no-ops).
        app(ProjectService::class)->generateChunk($a);
        $run->increment('chunks_done');
        $this->runWorker($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $run->status);
        $this->assertSame(2, $run->chunks_done);
        $this->assertNotNull($run->finished_at);
    }

    public function test_one_failed_chunk_does_not_cancel_siblings(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();

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

        $run = $this->claimRun($project);
        $this->runWorker($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $run->status);
        $this->assertSame(1, $run->chunks_done);
        $this->assertSame(1, $run->chunks_failed);
        $this->assertSame(ChunkStatus::Completed, $first->fresh()->status);
        $this->assertSame(ChunkStatus::Failed, $second->fresh()->status);
    }

    public function test_cancelling_mid_run_stops_after_the_in_flight_chunk(): void
    {
        $project = $this->project();
        $run = $this->claimRun($project);

        $provider = new class($run->id) extends FakeTtsProvider
        {
            public function __construct(private string $jobId) {}

            public function synthesize(string $text, ?string $referenceAudio, array $settings): string
            {
                TtsProjectJob::whereKey($this->jobId)->update(['cancel_requested' => true]);

                return parent::synthesize($text, $referenceAudio, $settings);
            }
        };
        $this->app->instance(TtsProvider::class, $provider);

        $this->runWorker($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Cancelled, $run->status);
        $this->assertSame(1, $run->chunks_done);
        [$first, $second] = $project->chunks()->get();
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertSame(ChunkStatus::Pending, $second->status);
    }

    public function test_a_run_cancelled_while_queued_never_touches_a_chunk(): void
    {
        $project = $this->project();
        $run = $this->claimRun($project);
        $run->update(['cancel_requested' => true]);

        $this->runWorker($run);

        $this->assertSame(ProjectJobStatus::Cancelled, $run->fresh()->status);
        $this->assertSame(
            [ChunkStatus::Pending, ChunkStatus::Pending],
            $project->chunks()->get()->pluck('status')->all(),
        );
    }

    public function test_an_out_of_credit_owner_fails_the_run_before_spending(): void
    {
        $owner = User::factory()->create(['credit_balance_micro' => 0]);
        $project = $this->project($owner->id);
        $run = $this->claimRun($project);

        $this->runWorker($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Failed, $run->status);
        $this->assertSame(CreditService::OUT_OF_CREDIT_MESSAGE, $run->error);
        $this->assertSame(0, $run->chunks_done);
    }

    public function test_ineligible_entries_are_counted_without_rendering(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $run = $this->claimRun($project);

        // Between dispatch and pickup: one chunk deleted, the other generated
        // by hand — both count as done ("no longer outstanding"), like serial.
        $first->delete();
        $second->update(['status' => ChunkStatus::Completed]);

        $this->runWorker($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $run->status);
        $this->assertSame(2, $run->chunks_done);
        $this->assertSame(100, $run->statusPayload()['percent']);
    }

    public function test_a_chunk_appended_mid_render_is_claimed_by_the_same_worker(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get();
        $run = TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $project->user_id,
            'chunk_ids' => [$first->id],
            'chunks_total' => 1,
            'concurrency' => 1,
        ]);

        // While the worker renders chunk one, "the user" queues chunk two —
        // the claim loop must pick it up even though it started 1-long.
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

        $this->runWorker($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Completed, $run->status);
        $this->assertSame(2, $run->chunks_done);
        $this->assertSame(2, $run->chunks_claimed);
        $this->assertSame(ChunkStatus::Completed, $second->fresh()->status);
    }

    // --- Checkpoint + failure backstop ---------------------------------------

    public function test_a_worker_out_of_time_hands_off_to_a_fresh_worker_job(): void
    {
        Queue::fake();
        config(['tts.async_timeout' => 0]); // zero budget: hand off after every chunk
        $project = $this->project();
        $run = $this->claimRun($project, concurrency: 1);

        $this->runWorker($run);

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Running, $run->status);
        $this->assertSame(1, $run->chunks_done);
        [$first, $second] = $project->chunks()->get();
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertSame(ChunkStatus::Pending, $second->status);

        Queue::assertPushed(
            GenerateProjectChunkWorkerJob::class,
            fn (GenerateProjectChunkWorkerJob $pushed) => $pushed->jobId === $run->id,
        );
    }

    public function test_a_worker_kill_fails_the_run_with_the_friendly_message(): void
    {
        $project = $this->project();
        $run = $this->claimRun($project);
        $run->update(['status' => ProjectJobStatus::Running]);

        (new GenerateProjectChunkWorkerJob($run->id))->failed(
            new MaxAttemptsExceededException(GenerateProjectChunkWorkerJob::class.' has been attempted too many times.'),
        );

        $run->refresh();
        $this->assertSame(ProjectJobStatus::Failed, $run->status);
        $this->assertStringNotContainsString('attempted too many times', $run->error);
        $this->assertStringContainsString('Generate remaining', $run->error);
    }

    // --- Status + labels against the claim cursor ----------------------------

    public function test_status_message_counts_landings_when_several_clips_fly(): void
    {
        $project = $this->project(chunks: 3);
        $run = $this->claimRun($project);
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_claimed' => 2, 'chunks_done' => 1]);

        // Two in flight — "Creating clip 2 of 3" would be a lie, count landings.
        $this->assertStringContainsString('Creating clips — 1 of 3 done', $run->statusPayload()['message']);

        // A K=1 claim run is sequential in fact, so it keeps the serial wording.
        $solo = TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'chunk_ids' => $run->chunk_ids,
            'chunks_total' => 3,
            'concurrency' => 1,
            'status' => ProjectJobStatus::Running,
            'chunks_claimed' => 2,
            'chunks_done' => 1,
        ]);
        $this->assertStringContainsString('Creating clip 2 of 3', $solo->statusPayload()['message']);
    }

    public function test_generation_status_marks_every_in_flight_chunk_rendering(): void
    {
        $project = $this->project(chunks: 3);
        [$a, $b, $c] = $project->chunks()->get();
        $run = $this->claimRun($project);
        // Both workers busy: A and B claimed and unlanded; C waits.
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_claimed' => 2]);

        $res = $this->actingAs($this->admin())
            ->getJson(route('admin.studio.projects.generation-status', $project));

        $res->assertOk()
            ->assertJsonPath('chunks.0.queue_label', 'rendering')
            ->assertJsonPath('chunks.1.queue_label', 'rendering')
            ->assertJsonPath('chunks.2.queue_label', 'queued · next in line');
    }

    // --- queueChunk against the claim cursor ---------------------------------

    public function test_requeue_inserts_at_the_claim_cursor_ahead_of_the_backlog(): void
    {
        $project = $this->project(chunks: 3);
        [$a, $b, $c] = $project->chunks()->get();
        $a->update(['status' => ChunkStatus::Completed]);
        $run = TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $project->user_id,
            'chunk_ids' => [$b->id, $c->id],
            'chunks_total' => 2,
            'concurrency' => 2,
            'status' => ProjectJobStatus::Running,
            'chunks_claimed' => 1, // B in flight, C waiting
        ]);

        // Re-queueing A lands at the cursor — the very next claim — not
        // behind C's backlog.
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $a]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('job.chunks_total', 3);

        $this->assertSame([$b->id, $a->id, $c->id], $run->fresh()->chunk_ids);
        $this->assertSame(ChunkStatus::Stale, $a->fresh()->status);
    }

    public function test_queueing_an_in_flight_chunk_is_left_alone(): void
    {
        $project = $this->project();
        [$a] = $project->chunks()->get();
        $run = $this->claimRun($project);
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_claimed' => 1]); // A in flight

        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $a]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'rendering')
            ->assertJsonPath('job.chunks_total', 2);

        $this->assertCount(2, $run->fresh()->chunk_ids);
        $this->assertSame(ChunkStatus::Pending, $a->fresh()->status);
        $this->assertStringContainsString('rendering right now', $res->json('message'));
    }

    public function test_a_chunk_whose_claimed_entry_landed_can_be_requeued(): void
    {
        $project = $this->project();
        [$a, $b] = $project->chunks()->get();
        $run = $this->claimRun($project);
        // A claimed AND landed (completed); B unclaimed — the run is mid-flight.
        $a->update(['status' => ChunkStatus::Completed]);
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_claimed' => 1, 'chunks_done' => 1]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $a]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('job.chunks_total', 3);

        // Booked as a NEW entry at the cursor, re-armed for the render.
        $this->assertSame([$a->id, $a->id, $b->id], $run->fresh()->chunk_ids);
        $this->assertSame(ChunkStatus::Stale, $a->fresh()->status);
    }

    public function test_queueing_a_waiting_chunk_keeps_its_spot(): void
    {
        $project = $this->project();
        [, $b] = $project->chunks()->get();
        $run = $this->claimRun($project);
        $run->update(['status' => ProjectJobStatus::Running, 'chunks_claimed' => 1]); // A in flight, B waiting

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.chunks.queue', [$project, $b]))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('queue_label', 'queued · next in line')
            ->assertJsonPath('job.chunks_total', 2);

        $this->assertCount(2, $run->fresh()->chunk_ids);
    }
}
