<?php

namespace App\Jobs;

use App\Models\VoiceClip;
use App\Services\VoiceClipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Cleans up a staged reference clip (denoise + enhance) off the request cycle.
 * The enhance step is a Replicate prediction that averages ~40s and can run to
 * its full timeout; done in-band it held the "Use this recording" POST open
 * until a gateway 504'd it (the error that blocked brand-new users). Now the
 * upload returns immediately with the clip PROCESSING and the browser polls
 * until the job flips it READY.
 *
 * Requires a running queue worker (QUEUE_CONNECTION + `queue:work`); on the sync
 * driver it simply runs inline, and the upload returns already-READY.
 */
class PrepareVoiceClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Enhancement is a paid Replicate run; a job-level retry would pay for it twice. */
    public int $tries = 1;

    /** Worker timeout (seconds): the enhancer's own polling ceiling plus headroom. */
    public int $timeout;

    public function __construct(public int $clipId)
    {
        $this->timeout = (int) config('tts.enhance.timeout', 120) + 60;
    }

    public function handle(VoiceClipService $clips): void
    {
        $clip = VoiceClip::find($this->clipId);

        // Row gone (pruned/expired), or already handled by an earlier attempt.
        if (! $clip || $clip->status !== VoiceClip::STATUS_PROCESSING) {
            return;
        }

        $clips->runEnhancement($clip);
    }

    /**
     * A worker kill (timeout) or unexpected error lands here. The original take
     * is already staged and perfectly usable, so don't strand the poller on a
     * clip stuck PROCESSING — mark it READY with a cleanup warning, the same
     * degrade-safe outcome a failed enhance produces.
     */
    public function failed(Throwable $e): void
    {
        $clip = VoiceClip::find($this->clipId);

        if ($clip && $clip->status === VoiceClip::STATUS_PROCESSING) {
            $clip->update([
                'status' => VoiceClip::STATUS_READY,
                'enhance_error' => 'Audio cleanup didn’t finish — the original clip was used instead.',
            ]);
        }
    }
}
