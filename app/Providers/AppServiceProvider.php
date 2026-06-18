<?php

namespace App\Providers;

use App\Services\Audio\AudioConverter;
use App\Services\Health\HealthReport;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\ReplicateChatterboxProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AudioConverter::class, fn () => new AudioConverter(
            config('tts.ffmpeg_path', 'ffmpeg'),
        ));

        // Resolve the configured TTS backend driver. Bound lazily so tests can
        // flip tts.provider to "fake" before the controller resolves it.
        $this->app->bind(TtsProvider::class, function () {
            return match (config('tts.provider')) {
                'fake' => new FakeTtsProvider,
                'replicate' => new ReplicateChatterboxProvider(
                    config('tts.providers.replicate', []),
                    (int) config('tts.request_timeout', 300),
                ),
                default => throw new InvalidArgumentException(
                    'Unknown TTS provider: '.config('tts.provider'),
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A running `queue:work` stamps a heartbeat each loop (and on job
        // pickup) so the health check can distinguish a live worker from a
        // configured-but-idle queue. Throttled, and guarded so a cache hiccup
        // never disrupts job processing.
        $stampWorkerHeartbeat = function (): void {
            static $last = 0;

            $now = time();
            if ($now - $last < 15) {
                return;
            }
            $last = $now;

            try {
                Cache::put(HealthReport::QUEUE_WORKER_HEARTBEAT_KEY, $now, now()->addMinutes(5));
            } catch (Throwable) {
                // A heartbeat write failure must not break the worker.
            }
        };

        Event::listen(Looping::class, $stampWorkerHeartbeat);
        Event::listen(JobProcessing::class, $stampWorkerHeartbeat);
    }
}
