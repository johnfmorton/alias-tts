<?php

namespace App\Providers;

use App\Services\Audio\AudioConverter;
use App\Services\Enhance\EnhanceProvider;
use App\Services\Enhance\FakeEnhanceProvider;
use App\Services\Enhance\ReplicateEnhanceProvider;
use App\Services\Health\HealthReport;
use App\Services\Tts\FakeTtsProvider;
use App\Services\Tts\ReplicateChatterboxProvider;
use App\Services\Tts\TtsProvider;
use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem as Flysystem;
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
                    config('tts.models', []),
                ),
                default => throw new InvalidArgumentException(
                    'Unknown TTS provider: '.config('tts.provider'),
                ),
            };
        });

        // Reference-clip cleanup backend. Replicate only (plus a fake for
        // tests/offline); the seam keeps a future local backend possible. Reuses
        // the Replicate token — no separate secret.
        $this->app->bind(EnhanceProvider::class, function () {
            return match (config('tts.enhance.provider')) {
                'fake' => new FakeEnhanceProvider,
                'replicate' => new ReplicateEnhanceProvider(
                    config('tts.enhance.replicate', []) + ['token' => config('tts.providers.replicate.token')],
                    (int) config('tts.enhance.timeout', 120),
                ),
                default => throw new InvalidArgumentException(
                    'Unknown enhance provider: '.config('tts.enhance.provider'),
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

        $this->registerAclLessS3Driver();
    }

    /**
     * Re-register the `s3` filesystem driver so it never sends an object ACL.
     *
     * Laravel's S3 driver always stamps a canned ACL ("private") on every
     * upload, but Backblaze B2's S3-compatible API rejects object ACLs outright
     * ("Unsupported value for canned acl 'private'") — it manages visibility at
     * the bucket level. So we rebuild the driver with an SDK middleware that
     * strips the `ACL` parameter from every command. Harmless on real AWS too,
     * where modern "bucket owner enforced" buckets also reject ACLs.
     */
    private function registerAclLessS3Driver(): void
    {
        Storage::extend('s3', function ($app, array $config): FilesystemAdapter {
            $client = new S3Client([
                'version' => 'latest',
                'region' => $config['region'] ?? 'us-east-1',
                'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? false,
                'credentials' => array_filter([
                    'key' => $config['key'] ?? null,
                    'secret' => $config['secret'] ?? null,
                    'token' => $config['token'] ?? null,
                ]),
            ] + (empty($config['endpoint']) ? [] : ['endpoint' => $config['endpoint']]));

            $client->getHandlerList()->appendInit(
                Middleware::mapCommand(function (CommandInterface $command) {
                    unset($command['ACL']);

                    return $command;
                }),
                'b2-strip-acl',
            );

            $adapter = new AwsS3V3Adapter($client, $config['bucket'], $config['root'] ?? '');

            return new FilesystemAdapter(new Flysystem($adapter, $config), $adapter, $config);
        });
    }
}
