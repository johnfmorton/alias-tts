<?php

namespace App\Jobs;

use App\Enums\ProjectJobStatus;
use App\Models\TtsProjectJob;
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

        $job->update(['status' => ProjectJobStatus::Running, 'started_at' => now()]);

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
            $service->rebuild($project);
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
