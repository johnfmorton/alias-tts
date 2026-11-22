<?php

namespace App\Jobs;

use App\Enums\ChunkStatus;
use App\Enums\ProjectJobStatus;
use App\Enums\ProjectStatus;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Services\ProjectService;
use App\Services\Settings\SettingsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Executes one "Build final" stitch ({@see TtsProjectJob} of type `stitch`)
 * off the request cycle. Stitching a large project — downloading every chunk's
 * audio, concatenating with seam gaps, encoding, uploading — can outlive any
 * gateway timeout (the in-request path 504'd on a 111-chunk project, exactly
 * the way synchronous Regenerate used to), so the request only books the run
 * and the page follows it on the generation-status poll.
 *
 * Unlike a generate run there is no per-chunk seam to checkpoint or cancel at:
 * the stitch is all-or-nothing, so cancel is honored only while still queued.
 *
 * Requires a running queue worker (QUEUE_CONNECTION + `queue:work`).
 */
class StitchProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One shot: a retry would re-stitch bytes that may already have landed. */
    public int $tries = 1;

    /** Worker timeout (seconds); generous — a big stitch is minutes of I/O + ffmpeg. */
    public int $timeout;

    public function __construct(public string $jobId)
    {
        // Captured at dispatch time and serialized with the job.
        $this->timeout = (int) config('tts.async_timeout', 1800);
    }

    /**
     * Book a stitch run for a project: the row only — hand it to
     * {@see start()} once any surrounding transaction has committed.
     * $chunkIds is the finished generate run's line when the stitch is its
     * automatic follow-up (empty for a manual "Build final"): the status poll
     * serves those chunks' fresh takes even though the stitch is now the
     * latest run, so a page that missed the generate run's last payload still
     * lands them on their cards.
     *
     * @param  array<int, string>  $chunkIds
     */
    public static function book(TtsProject $project, ?int $createdById, array $chunkIds = []): TtsProjectJob
    {
        return TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $createdById,
            'type' => TtsProjectJob::TYPE_STITCH,
            'chunk_ids' => array_values($chunkIds),
            'chunks_total' => $project->chunks()->where('skipped', false)->count(),
        ]);
    }

    /**
     * Queue the worker for a booked stitch. Interactive lane, like single-chunk
     * Regenerate: someone is waiting at the page for the final, so it may jump
     * bulk runs and API speech jobs. Dispatched as an instance so the queue is
     * pinned before dispatch() fires.
     */
    public static function start(TtsProjectJob $run): void
    {
        $stitch = new self($run->id);
        if ($queue = config('tts.generation.interactive_queue')) {
            $stitch->onQueue($queue);
        }
        dispatch($stitch);
    }

    /**
     * Auto-build: book the stitch that follows a generate run which just
     * completed, or null when the final should not (or cannot) be rebuilt.
     * MUST be called inside the transaction that holds the run row's lock and
     * flips it to Completed — that is what makes the follow-up exactly-once
     * when several workers settle together, and leaves no instant in which
     * the project has no active run for a competing request to slip into.
     * The caller {@see start()}s the returned row after the commit.
     *
     * Only a clean run qualifies: a run with failed chunks leaves the user
     * something to retry first, and a cancelled run was told to stop. The
     * build runs as the same user the generate run ran as, so the seam gaps
     * resolve from the same per-user settings.
     */
    public static function bookAfter(TtsProjectJob $run): ?TtsProjectJob
    {
        if (! config('tts.auto_build_final', true)
            || $run->type !== TtsProjectJob::TYPE_GENERATE
            || $run->status !== ProjectJobStatus::Completed
            || $run->chunks_failed > 0) {
            return null;
        }

        $project = $run->project;
        // Ready = the final already reflects every chunk (the run found
        // nothing to render); rebuilding would only clear a seal for nothing.
        if (! $project || $project->status === ProjectStatus::Ready) {
            return null;
        }

        // Stricter than a manual Build final, which only needs every chunk to
        // HAVE audio: a Stale or Failed chunk still holds its previous take,
        // and building that in unasked would present outdated audio as the
        // current final. Auto-build only when every included chunk is current.
        $outstanding = $project->chunks()
            ->where('skipped', false)
            ->where('status', '!=', ChunkStatus::Completed->value)
            ->exists();
        if ($outstanding) {
            return null;
        }

        try {
            app(ProjectService::class)->assertRebuildable($project);
        } catch (RuntimeException) {
            return null; // nothing buildable (no chunks, or all skipped)
        }

        return self::book($project, $run->created_by_id ?? $run->user_id, (array) $run->chunk_ids);
    }

    public function handle(ProjectService $service): void
    {
        $job = TtsProjectJob::find($this->jobId);

        // Row gone (project deleted while queued) or already handled.
        if (! $job || ! $job->isActive()) {
            return;
        }

        // Cancelled while still queued — never touch the final.
        if ($job->cancel_requested) {
            $this->finish($job, ProjectJobStatus::Cancelled);

            return;
        }

        // Claim the run with a conditional update: a Regenerate click may
        // supersede a still-queued stitch (StudioProjectController::queueChunk
        // flips it to Cancelled the same way), and exactly one side must win.
        $claimed = TtsProjectJob::query()
            ->whereKey($job->id)
            ->where('status', ProjectJobStatus::Queued->value)
            ->update(['status' => ProjectJobStatus::Running->value, 'started_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $job->refresh();

        $project = $job->project;
        if (! $project) {
            $this->finish($job, ProjectJobStatus::Failed, 'The project no longer exists.');

            return;
        }

        // Stitch under the DISPATCHING user's settings (per-user settings):
        // the seam gaps (ChunkGaps) resolve from their overlay, exactly as the
        // old in-request rebuild did.
        app(SettingsManager::class)->applyForUser($job->created_by_id ?? $job->user_id);

        try {
            // Climb the counter as chunks are readied (a cold build prepares
            // each one; a warm one flies through), written at most every few
            // chunks and held one short of full until the final has landed.
            $service->rebuild($project, function (int $done) use ($job): void {
                if ($done % 8 === 0 && $done < $job->chunks_total) {
                    $job->update(['chunks_done' => $done]);
                }
            });
        } catch (Throwable $e) {
            report($e);
            $this->finish($job, ProjectJobStatus::Failed, $e->getMessage());

            return;
        }

        // All-or-nothing: the counters flip to full only when the final landed,
        // so the Jobs page reads 111/111 · 100% instead of a stuck 0%.
        $job->update(['chunks_done' => $job->chunks_total]);
        $this->finish($job, ProjectJobStatus::Completed);
    }

    /**
     * Backstop for failures outside handle()'s try (notably a worker timeout,
     * where the process is killed before it can mark the row).
     */
    public function failed(Throwable $e): void
    {
        $job = TtsProjectJob::find($this->jobId);

        if ($job && $job->isActive()) {
            $this->finish($job, ProjectJobStatus::Failed, $this->friendlyMessage($e));
        }
    }

    /** A worker kill reads like data loss — say what actually matters instead. */
    private function friendlyMessage(Throwable $e): string
    {
        if ($e instanceof MaxAttemptsExceededException) {
            return 'The rebuild was interrupted by the background time limit — the clips are untouched; build the final again.';
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
