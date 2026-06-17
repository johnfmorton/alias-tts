<?php

namespace App\Providers;

use App\Services\Audio\AudioConverter;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\ReplicateChatterboxProvider;
use App\Services\Tts\TtsProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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
        //
    }
}
