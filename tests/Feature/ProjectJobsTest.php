<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectJobStatus;
use App\Jobs\GenerateProjectChunksJob;
use App\Models\TtsChunk;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Models\User;
use App\Models\Voice;
use App\Services\Credit\CreditService;
use App\Services\ProjectService;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Background "Generate remaining" runs: dispatch + join semantics, the queue
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
            ->postJson(route('admin.studio.projects.chunks.reroll', [$project, $chunk]))
            ->assertStatus(409);
        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(409);
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
            ->assertJsonPath('chunks.1.status', 'pending');

        $chunks = $res->json('chunks');
        $this->assertNotEmpty($chunks[0]['takes']); // finished → full card payload
        $this->assertArrayNotHasKey('takes', $chunks[1]); // outstanding → light
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
}
