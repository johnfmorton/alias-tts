<?php

namespace App\Jobs;

use App\Services\Genblaze\GenblazeRunnerClient;
use App\Services\Genblaze\GenblazeRunStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one "Generate via Genblaze" orchestration off the web request: the Studio
 * button dispatches this and polls {@see GenblazeRunStore}, so the long
 * generate → QA → re-roll → stitch → B2 never holds an HTTP request open (which
 * was hitting the web server's fastcgi/proxy read timeout as an HTTP 502).
 */
class RunGenblazeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The orchestration has its own internal re-rolls; a job-level retry would redo everything. */
    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $runId,
        public readonly string $text,
        public readonly string $voice,
        public readonly ?int $seed = null,
    ) {
        // A little headroom over the runner client's own HTTP timeout.
        $this->timeout = (int) config('tts.genblaze.timeout', 600) + 60;
    }

    public function handle(GenblazeRunnerClient $runner, GenblazeRunStore $store): void
    {
        $store->markRunning($this->runId);

        try {
            $store->complete($this->runId, $runner->run(
                text: $this->text,
                voice: $this->voice,
                seed: $this->seed,
            ));
        } catch (Throwable $e) {
            report($e);
            $store->fail($this->runId, $e->getMessage());
        }
    }

    /** Backstop for a failure outside handle() (e.g. a worker timeout). */
    public function failed(?Throwable $e): void
    {
        app(GenblazeRunStore::class)->fail($this->runId, $e?->getMessage() ?? 'The run failed.');
    }
}
