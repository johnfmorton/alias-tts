<?php

namespace App\Jobs;

use App\Enums\ChunkStatus;
use App\Enums\ProjectJobStatus;
use App\Models\TtsChunk;
use App\Models\TtsProjectJob;
use App\Services\Credit\CreditService;
use App\Services\ProjectService;
use App\Services\Settings\SettingsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One worker of a bounded-concurrency "Generate remaining" run
 * (docs/GENERATION-CONCURRENCY.md). generateRemaining() dispatches
 * min(max_concurrency, outstanding) of these per run; each loops claiming the
 * next unclaimed chunk_ids entry and rendering it through the same
 * {@see ProjectService::generateChunk()} path the serial job uses, so provider,
 * ASR, take, spend, and credit behavior are identical — only the number of
 * chunks in flight changes. Actual parallelism is capped by how many queue
 * workers execute these jobs, never more than the run's stamped `concurrency`.
 *
 * Claims move the run's `chunks_claimed` cursor under the run row's lock — the
 * same lock queueChunk() inserts under, so no two workers ever hold the same
 * list entry and insertions always land on unclaimed ground. Completion runs
 * out of order; results persist by chunk identity and final assembly orders by
 * project position, so order of landing never matters.
 *
 * Every exit path settles under that lock, and only the worker that sees all
 * claims processed and none left transitions the run terminal — exactly one
 * coordinator, no matter how workers interleave.
 */
class GenerateProjectChunkWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Chunk failures are handled inside the loop; a job-level retry would re-charge completed chunks. */
    public int $tries = 1;

    /** Same handoff margin as the serial job — one render must fit inside it. */
    private const CHECKPOINT_MARGIN_SECONDS = 300;

    /** Worker timeout (seconds); a worker may render many chunks in sequence. */
    public int $timeout;

    public function __construct(
        public string $jobId,
    ) {
        // Captured at dispatch time and serialized with the job — a checkpoint
        // re-dispatch from inside a worker re-captures both the same way.
        $this->timeout = (int) config('tts.async_timeout', 1800);
        if ($queue = config('tts.generation.queue')) {
            $this->onQueue($queue);
        }
    }

    /**
     * Seconds one queue attempt may run before handing off — same shape as
     * the serial job's sliceBudget(): the fairness slice
     * (tts.generation.slice_seconds, 0 = off) under the worker-timeout
     * ceiling, so co-queued jobs interleave with a long run.
     */
    private function sliceBudget(): float
    {
        $ceiling = max(0, $this->timeout - self::CHECKPOINT_MARGIN_SECONDS);
        $slice = (float) config('tts.generation.slice_seconds', 120);

        return $slice > 0 ? min($slice, $ceiling) : $ceiling;
    }

    public function handle(ProjectService $service): void
    {
        $startedAt = microtime(true);
        $job = TtsProjectJob::find($this->jobId);

        // Row gone (project deleted while queued), already finished, or a
        // sibling/credit-stop ended the run before this worker started.
        if (! $job || ! $job->isActive()) {
            return;
        }

        // The first worker in flips the run Running; started_at survives both
        // checkpoint handoffs and sibling workers arriving later.
        $job->update(['status' => ProjectJobStatus::Running, 'started_at' => $job->started_at ?? now()]);

        // The dispatching user's settings overlay, exactly like the serial job.
        app(SettingsManager::class)->applyForUser($job->created_by_id ?? $job->user_id);

        $paceMs = max(0, (int) config('tts.studio_generate_pace_ms', 800));
        $credit = app(CreditService::class);
        $handled = 0;

        while (true) {
            // Fixed time budget per queue attempt (sliceBudget): near the
            // line, hand the remainder to a fresh worker job (new attempt
            // counter) on the same queue, and exit clean — without settling,
            // since unclaimed work remains for the replacement. At least one
            // claim per attempt, so even a tiny budget still makes progress.
            if ($handled > 0 && microtime(true) - $startedAt >= $this->sliceBudget()) {
                self::dispatch($this->jobId)->onQueue($this->queue);

                return;
            }

            $chunkId = $this->claimNext();
            if ($chunkId === null) {
                // Out of unclaimed work, cancelled, or the run ended — settle
                // decides under the lock; `true` means work appeared between
                // our last claim and the settle (queueChunk insertion), and
                // this worker keeps going instead of stranding it.
                if ($this->settleOrContinue()) {
                    continue;
                }

                return;
            }

            $handled++;
            $chunk = TtsChunk::find($chunkId);

            // Deleted, skipped, or generated by hand since dispatch — nothing
            // to render, so the entry counts as done (done = "no longer
            // outstanding", keeping the bar honest and finishing at 100%).
            if (! $chunk || $chunk->skipped || $chunk->status === ChunkStatus::Completed) {
                TtsProjectJob::whereKey($this->jobId)->increment('chunks_done');

                continue;
            }

            // An empty chunk can't be generated (the per-chunk endpoint 422s
            // it); record the failure on the run without touching the chunk.
            if (trim((string) $chunk->text) === '') {
                TtsProjectJob::whereKey($this->jobId)->increment('chunks_failed');

                continue;
            }

            // Fresh row: the project relation (and its owner's balance) must be
            // current — the balance can drain mid-run, and every further chunk
            // would fail the same way, so stop the run cold like the serial job.
            // Siblings mid-render finish and persist; they stop at their next
            // claim. Concurrency can overshoot the balance by up to K in-flight
            // chunks — the documented bounded-overshoot policy, one chunk of
            // which the serial path already allows.
            $job = TtsProjectJob::find($this->jobId);
            if (! $job) {
                return;
            }
            if (! $credit->canSpend($job->project?->user)) {
                $this->finish($job, ProjectJobStatus::Failed, CreditService::OUT_OF_CREDIT_MESSAGE);

                return;
            }

            try {
                $service->generateChunk($chunk);
                TtsProjectJob::whereKey($this->jobId)->increment('chunks_done');
            } catch (Throwable $e) {
                // generateChunk() already marked the chunk Failed with the
                // message; the run carries on like the serial loop did.
                report($e);
                TtsProjectJob::whereKey($this->jobId)->increment('chunks_failed');
            }

            // Same prediction pacing as the serial loop, per worker.
            if ($paceMs > 0) {
                usleep($paceMs * 1000);
            }
        }
    }

    /**
     * Atomically claim the next unclaimed chunk_ids entry by advancing the
     * claim cursor under the run row's lock. Null when there is nothing left
     * to claim, the run was cancelled, or the run row is gone/terminal —
     * settleOrContinue() re-examines those cases under the same lock.
     */
    private function claimNext(): ?string
    {
        return DB::transaction(function (): ?string {
            $run = TtsProjectJob::query()->whereKey($this->jobId)->lockForUpdate()->first();

            if (! $run || ! $run->isActive() || $run->cancel_requested) {
                return null;
            }

            $ids = array_values((array) $run->chunk_ids);
            if ($run->chunks_claimed >= count($ids)) {
                return null;
            }

            $id = $ids[$run->chunks_claimed];
            $run->update(['chunks_claimed' => $run->chunks_claimed + 1]);

            return $id;
        });
    }

    /**
     * Terminal-state coordination, run by every worker on its way out. Under
     * the run row's lock: while any claimed entry hasn't landed (a sibling is
     * still rendering), leave the run alone — that sibling settles later. Once
     * nothing is in flight, cancellation wins (mirroring the serial loop, which
     * checks the flag before noticing it ran out of work), then full claim
     * coverage completes the run. The remaining case — new entries appeared
     * after this worker's last claim (a queueChunk insertion racing our exit) —
     * returns true so the caller resumes claiming instead of stranding them;
     * insertions can't land after the terminal transition because queueChunk
     * re-checks the run is active under this same lock.
     */
    private function settleOrContinue(): bool
    {
        return DB::transaction(function (): bool {
            $run = TtsProjectJob::query()->whereKey($this->jobId)->lockForUpdate()->first();

            if (! $run || ! $run->isActive()) {
                return false;
            }

            $inFlight = $run->chunks_claimed - $run->chunks_done - $run->chunks_failed;
            if ($inFlight > 0) {
                return false;
            }

            if ($run->cancel_requested) {
                $this->finish($run, ProjectJobStatus::Cancelled);

                return false;
            }

            if ($run->chunks_claimed >= count(array_values((array) $run->chunk_ids))) {
                $this->finish($run, ProjectJobStatus::Completed);

                return false;
            }

            return true;
        });
    }

    /**
     * Backstop for failures outside the chunk loop (notably a worker timeout,
     * where handle() is killed before it can settle). Ends the whole run —
     * siblings stop at their next claim, finished chunks are kept, and
     * "Generate remaining" resumes the rest — matching the serial job's
     * recovery story. Chunk-level errors never reach here.
     */
    public function failed(Throwable $e): void
    {
        $job = TtsProjectJob::find($this->jobId);

        if ($job && $job->isActive()) {
            $this->finish($job, ProjectJobStatus::Failed, $this->friendlyMessage($e));
        }
    }

    /**
     * MaxAttemptsExceeded / TimeoutExceeded (the latter extends the former) are
     * worker-kill artifacts, not user errors — say what actually matters:
     * finished chunks are kept and the run is resumable.
     */
    private function friendlyMessage(Throwable $e): string
    {
        if ($e instanceof MaxAttemptsExceededException) {
            return 'The run was interrupted by the background time limit. '
                .'Finished clips are kept — Generate remaining picks up where it left off.';
        }

        return $e->getMessage();
    }

    private function finish(TtsProjectJob $job, ProjectJobStatus $status, ?string $error = null): void
    {
        $job->update([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }
}
