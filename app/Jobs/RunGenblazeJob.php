<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Voice;
use App\Services\Credit\CreditService;
use App\Services\Genblaze\GenblazeRunnerClient;
use App\Services\Genblaze\GenblazeRunStore;
use App\Services\Pronunciation\PronunciationDetector;
use App\Services\Pronunciation\PronunciationSubstituter;
use App\Services\Settings\SettingsManager;
use App\Services\SpokenQuotes;
use App\Services\Tts\ModelCatalog;
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
        public readonly ?int $userId = null,
    ) {
        // A little headroom over the runner client's own HTTP timeout.
        $this->timeout = (int) config('tts.genblaze.timeout', 600) + 60;
    }

    public function handle(
        GenblazeRunnerClient $runner,
        GenblazeRunStore $store,
        PronunciationDetector $detector,
        PronunciationSubstituter $substituter,
        SpokenQuotes $quotes,
    ): void {
        $store->markRunning($this->runId);

        // Credit gate: the run hasn't spent anything yet, so an exhausted
        // balance fails it cleanly here (the panel shows the message). The
        // controller checked too, but the balance can drain while queued.
        $credit = app(CreditService::class);
        if (! $credit->canSpend($this->userId !== null ? User::find($this->userId) : null)) {
            $store->fail($this->runId, CreditService::OUT_OF_CREDIT_MESSAGE);

            return;
        }

        // Run under the dispatching user's settings (settings are per-user).
        app(SettingsManager::class)->applyForUser($this->userId);

        try {
            // Genblaze CHAT pronunciation pass — always on for this judge-facing
            // page (force past the global toggle) so the LLM respelling step is a
            // visible part of the demo. Degrade-safe: any runner/LLM failure yields
            // no substitutions and the original text goes through unchanged.
            $detection = $detector->detect($this->text, $this->userId, force: true);
            $applied = $substituter->apply($this->text, $detection['substitutions'] ?? []);

            // Record the pronunciation pass as the first LIVE pipeline step (the
            // runner reports the rest — chunk/generate/stitch/seal/upload — as it
            // works). A coarse detail here; the final panel fills the exact
            // respellings from the result's pronunciation block.
            $subs = $detection['substitutions'] ?? [];
            $store->appendProgress($this->runId, [
                'step' => 'pronounce',
                'detail' => ($detection['available'] ?? false)
                    ? (count($subs) ? count($subs).' term(s) respelled' : 'no changes needed')
                    : 'unavailable',
            ]);

            // Spoken quote marks — strictly AFTER pronunciation, so the
            // inserted words can never be rewritten by a dictionary entry.
            // Resolved AFTER applyForUser above = the dispatching user's
            // setting; default off, and off is a byte-identical no-op.
            $mode = (string) config('tts.spoken_quotes', SpokenQuotes::MODE_OFF);
            $quoted = $quotes->apply($applied['text'], $mode, (int) config('tts.block_space_run', 4));
            if ($mode !== SpokenQuotes::MODE_OFF) {
                $store->appendProgress($this->runId, [
                    'step' => 'quotes',
                    'detail' => $quoted['applied']
                        ? $quoted['applied'].' quotation(s) voiced'
                        : 'no paired quotes found',
                ]);
            }

            $result = $runner->run(
                text: $quoted['text'],
                voice: $this->voice,
                seed: $this->seed,
                runId: $this->runId,
                // Resolved AFTER applyForUser above, so this is the dispatching
                // user's setting; the runner forwards it to /v1/internal/chunk,
                // which otherwise runs userless on instance defaults.
                chunkMode: (string) config('tts.chunk_mode', 'packed'),
            );

            // One whole-text charge per SUCCESSFUL run, at the voice's engine
            // rate. The runner's internal QA re-rolls are deliberately
            // uncharged (unknowable from here), and a failed run charges
            // nothing — the owner absorbs partial provider cost, and the
            // markup is the buffer for both.
            $voice = Voice::find($this->voice);
            $credit->charge(
                $this->userId,
                mb_strlen($quoted['text']),
                $voice !== null ? ModelCatalog::forVoice($voice) : ModelCatalog::DEFAULT,
                'genblaze',
                'genblaze_run',
                $this->runId,
            );

            // Rides through GenblazeController::status() untouched so the panel can
            // reveal it as the first pipeline step.
            $result['pronunciation'] = [
                'available' => (bool) ($detection['available'] ?? false),
                'provider' => (string) config('tts.pronunciation.llm_provider', 'replicate'),
                'model' => config('tts.pronunciation.model'),
                'substitutions' => $detection['substitutions'] ?? [],
                'applied' => $applied['applied'],
                'error' => $detection['error'] ?? null,
            ];

            $store->complete($this->runId, $result);
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
