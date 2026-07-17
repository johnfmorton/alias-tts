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
use Throwable;

/**
 * Executes one "Generate remaining" run ({@see TtsProjectJob}) off the request
 * cycle, so the run survives the user leaving the page. Chunks are generated
 * strictly one at a time with the same pacing the in-page loop used (a burst
 * of predictions can spin up cold GPU replicas that fail with transient CUDA
 * asserts). A chunk failure records on that chunk and the run moves on —
 * matching the old loop — while out-of-credit stops the run cold, since every
 * further chunk would fail the same way.
 *
 * A run larger than one worker attempt's time budget checkpoints itself: near
 * the timeout the loop re-dispatches a continuation job carrying the next
 * chunk index, so a big or slow run is never killed mid-render (and never hits
 * the tries=1 retry refusal a kill leaves behind).
 *
 * Requires a running queue worker (QUEUE_CONNECTION + `queue:work`).
 */
class GenerateProjectChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Chunk failures are handled inside the run; a job-level retry would re-charge completed chunks. */
    public int $tries = 1;

    /**
     * Seconds held back from the worker time budget: the loop checkpoints once
     * elapsed time crosses (timeout − margin), leaving the in-flight chunk room
     * to finish before the worker's kill switch. Must comfortably exceed one
     * chunk render, ASR re-rolls included.
     */
    private const CHECKPOINT_MARGIN_SECONDS = 300;

    /** Worker timeout (seconds); generous — a run is many sequential renders. */
    public int $timeout;

    public function __construct(
        public string $jobId,
        /** Continuation cursor — a checkpointed run resumes at this chunk index. */
        public int $startIndex = 0,
    ) {
        // Captured at dispatch time and serialized with the job.
        $this->timeout = (int) config('tts.async_timeout', 1800);
    }

    public function handle(ProjectService $service): void
    {
        $startedAt = microtime(true);
        $job = TtsProjectJob::find($this->jobId);

        // Row gone (project deleted while queued) or already handled.
        if (! $job || ! $job->isActive()) {
            return;
        }

        // Cancelled while still queued — never touch a chunk.
        if ($job->cancel_requested) {
            $this->finish($job, ProjectJobStatus::Cancelled);

            return;
        }

        // started_at survives checkpoint handoffs: same run, new queue job.
        $job->update(['status' => ProjectJobStatus::Running, 'started_at' => $job->started_at ?? now()]);

        // Run under the DISPATCHING user's settings (per-user settings): the run
        // is their click, exactly as if they had stayed on the page — the same
        // overlay ApplyUserSettings gave the interactive per-chunk requests.
        app(SettingsManager::class)->applyForUser($job->created_by_id ?? $job->user_id);

        $paceMs = max(0, (int) config('tts.studio_generate_pace_ms', 800));
        $credit = app(CreditService::class);

        for ($i = $this->startIndex; ; $i++) {
            // Fresh row each pass: the cancel flag is set from another process,
            // the row vanishes if the project is deleted mid-run — and the chunk
            // list can GROW, because "Regenerate" on the project page appends to
            // an active run (queueChunk) instead of racing the worker.
            $job = TtsProjectJob::find($job->id);
            if (! $job) {
                return;
            }
            if ($job->cancel_requested) {
                $this->finish($job, ProjectJobStatus::Cancelled);

                return;
            }

            $chunkIds = array_values((array) $job->chunk_ids);
            if ($i >= count($chunkIds)) {
                break;
            }

            // A worker attempt has a fixed time budget (timeout) no matter how
            // many chunks the run holds. Blowing it gets the process killed
            // mid-render and the retry refused (tries = 1) — so near the line,
            // hand the remainder to a FRESH queue job (new attempt counter, new
            // reservation) and exit clean. At least one chunk per attempt, so
            // even a tiny budget still makes progress.
            if ($i > $this->startIndex
                && microtime(true) - $startedAt >= max(0, $this->timeout - self::CHECKPOINT_MARGIN_SECONDS)) {
                self::dispatch($this->jobId, $i);

                return;
            }

            $chunk = TtsChunk::find($chunkIds[$i]);

            // Deleted, skipped, or generated by hand since dispatch — no work
            // left for this one, so it counts as done (done = "no longer
            // outstanding", which keeps the bar honest and finishing at 100%).
            if (! $chunk || $chunk->skipped || $chunk->status === ChunkStatus::Completed) {
                $job->increment('chunks_done');

                continue;
            }

            // An empty chunk can't be generated (the per-chunk endpoint 422s
            // it); record the failure on the run without touching the chunk.
            if (trim((string) $chunk->text) === '') {
                $job->increment('chunks_failed');

                continue;
            }

            // Renders spend the PROJECT OWNER's credit (recordTake charges
            // them); the balance can run out mid-run, and every further chunk
            // would fail the same way — stop the run instead.
            if (! $credit->canSpend($job->project?->user)) {
                $this->finish($job, ProjectJobStatus::Failed, CreditService::OUT_OF_CREDIT_MESSAGE);

                return;
            }

            try {
                $service->generateChunk($chunk);
                $job->increment('chunks_done');
            } catch (Throwable $e) {
                // generateChunk() already marked the chunk Failed with the
                // message; the run carries on like the in-page loop did.
                report($e);
                $job->increment('chunks_failed');
            }

            if ($paceMs > 0 && $i < count($chunkIds) - 1) {
                usleep($paceMs * 1000);
            }
        }

        // $job is the fresh read the loop just broke on.
        $this->finish($job, ProjectJobStatus::Completed);
    }

    /**
     * Backstop for failures outside the chunk loop (notably a worker timeout,
     * where handle() is killed before it can mark the row). Chunk-level errors
     * never reach here — they're caught and counted in the loop.
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
     * worker-kill artifacts, not user errors — the raw "has been attempted too
     * many times" reads like data loss. Say what actually matters: finished
     * chunks are kept and the run is resumable.
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
