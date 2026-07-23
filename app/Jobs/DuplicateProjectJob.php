<?php

namespace App\Jobs;

use App\Enums\ProjectJobStatus;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\Settings\SettingsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Executes one "Duplicate project" run ({@see TtsProjectJob} of type `duplicate`)
 * off the request cycle. A deep copy byte-copies every selected clip into the
 * new project's tree, and object storage has no batch copy — each clip is its
 * own round-trip — so a long project's duplicate outlives any gateway timeout
 * (it 504'd in production the same way the synchronous stitch and Regenerate
 * used to). The request only books the run; the source page follows it on the
 * generation-status poll and opens the copy when it lands.
 *
 * All-or-nothing like a stitch: there is no per-clip checkpoint to resume from
 * ({@see ProjectService::duplicate()} wipes a half-made copy on any failure),
 * so cancel is honored only while the run is still queued.
 *
 * Requires a running queue worker (QUEUE_CONNECTION + `queue:work`).
 */
class DuplicateProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One shot: a retry would re-copy a tree the first attempt already wiped. */
    public int $tries = 1;

    /** Worker timeout (seconds); generous — a big deep copy is minutes of I/O. */
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

        // Cancelled while still queued — never start a copy.
        if ($job->cancel_requested) {
            $this->finish($job, ProjectJobStatus::Cancelled);

            return;
        }

        $job->update(['status' => ProjectJobStatus::Running, 'started_at' => now()]);

        $source = $job->project;
        if (! $source) {
            $this->finish($job, ProjectJobStatus::Failed, 'The project no longer exists.');

            return;
        }

        $user = User::find($job->created_by_id ?? $job->user_id);
        if (! $user) {
            $this->finish($job, ProjectJobStatus::Failed, 'The duplicating user no longer exists.');

            return;
        }

        // Copy under the DUPLICATING user's settings (per-user settings), matching
        // the old in-request path: voice reachability resolves from their overlay.
        app(SettingsManager::class)->applyForUser($user->id);

        // Snapshot before copying: only voices minted BY this run are announced
        // (a pre-existing voice the copy was matched to is not news to its owner).
        $preexisting = Voice::pluck('id');

        try {
            // The copier bumps chunks_done per clip so the page shows N-of-M.
            $copy = $service->duplicate($source, $user, function () use ($job) {
                $job->increment('chunks_done');
            });
        } catch (Throwable $e) {
            report($e);
            $this->finish($job, ProjectJobStatus::Failed, $e->getMessage());

            return;
        }

        // Full counter + the copy to open + the notice its page will surface once.
        $job->update([
            'chunks_done' => $job->chunks_total,
            'result_project_id' => $copy->id,
            'result_message' => $this->composeMessage($source, $copy, $preexisting),
        ]);
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
            return 'The duplicate was interrupted by the background time limit — the half-made copy was cleaned up; try again.';
        }

        return $e->getMessage();
    }

    /**
     * The success notice the copy's page shows on arrival. Names any voices this
     * run cloned into the duplicator's account — a foreign duplicate (a
     * SuperAdmin copying someone else's project) mints stand-ins that would
     * otherwise appear on their Voices page out of nowhere. Empty for the
     * everyday case of duplicating your own project.
     */
    private function composeMessage(TtsProject $source, TtsProject $copy, Collection $preexisting): string
    {
        $message = 'Project duplicated — you are now viewing the copy.';

        $refs = fn (TtsProject $p) => $p->chunks()->pluck('voice_id')->push($p->voice_id)->filter()->unique();
        $adopted = Voice::whereIn('id', $refs($copy)->diff($refs($source))->diff($preexisting))->pluck('name');

        if ($adopted->isNotEmpty()) {
            $names = $adopted->map(fn (string $name) => "“{$name}”")->join(', ', ' and ');
            $message .= $adopted->count() === 1
                ? " Its voice {$names} was also copied to your voices."
                : " Its voices {$names} were also copied to your voices.";
        }

        return $message;
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
