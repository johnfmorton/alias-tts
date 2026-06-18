<?php

namespace App\Jobs;

use App\Enums\SpeechStatus;
use App\Models\Speech;
use App\Services\SpeechService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs speech generation for an already-created Processing record off the
 * request cycle, so long text isn't bound by the synchronous ~300s HTTP/cURL
 * ceiling. The poll + audio endpoints read the record's status.
 *
 * Requires a running queue worker (QUEUE_CONNECTION + `queue:work`).
 */
class GenerateSpeechJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Generation is internally resilient (429 retry); a job-level retry would redo everything. */
    public int $tries = 1;

    /** Worker timeout (seconds); the long ceiling async exists to provide. */
    public int $timeout;

    public function __construct(
        public string $speechId,
        public ?int $seed = null,
    ) {
        // Captured at dispatch time and serialized with the job.
        $this->timeout = (int) config('tts.async_timeout', 1800);
    }

    public function handle(SpeechService $service): void
    {
        $speech = Speech::find($this->speechId);

        // Record may have been deleted, or already finished (e.g. a duplicate).
        if (! $speech || $speech->status === SpeechStatus::Completed) {
            return;
        }

        $service->process($speech, $this->seed);
    }

    /**
     * Backstop for failures outside process() (notably a worker timeout, where
     * process() is killed before it can mark the record). process() itself
     * already records its own exceptions as Failed.
     */
    public function failed(Throwable $e): void
    {
        $speech = Speech::find($this->speechId);

        if ($speech && $speech->status !== SpeechStatus::Completed) {
            $speech->update([
                'status' => SpeechStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
