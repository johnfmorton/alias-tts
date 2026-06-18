<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Liveness probe used by `tts:doctor --deep`. Carries a one-off token; when a
 * worker runs handle() it stamps that token into the cache, which the doctor
 * polls for to confirm a `queue:work` worker is actually draining the queue
 * (not just that a non-sync connection is configured).
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A failed probe should not be retried — the doctor only waits a few seconds. */
    public int $tries = 1;

    public function __construct(public string $token) {}

    public function handle(): void
    {
        // Self-expiring so an unread token (doctor died mid-probe) can't linger.
        Cache::put(self::cacheKey($this->token), $this->token, now()->addMinutes(5));
    }

    public static function cacheKey(string $token): string
    {
        return 'tts:queue:heartbeat:'.$token;
    }
}
