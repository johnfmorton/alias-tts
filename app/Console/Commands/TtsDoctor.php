<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Confirms the service is configured correctly: PHP, database, ffmpeg, the
 * storage disk (local or S3), the inference provider + token, the queue, the
 * cleanup schedule, and app config. Prints PASS / WARN / FAIL per check and
 * exits non-zero if anything FAILs, so it's usable in CI or a deploy script.
 */
class TtsDoctor extends Command
{
    protected $signature = 'tts:doctor {--deep : Also make live calls (validate the Replicate token)}';

    protected $description = 'Check that the TTS service is configured correctly (ffmpeg, storage, provider, queue, scheduler)';

    private const PASS = 'PASS';

    private const WARN = 'WARN';

    private const FAIL = 'FAIL';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkPhp();
        $this->checkDatabase();
        $this->checkFfmpeg();
        $this->checkStorage();
        $this->checkProvider();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkApp();

        $this->renderResults();

        $fails = count(array_filter($this->results, fn ($r) => $r[0] === self::FAIL));

        return $fails > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $status, string $label, string $detail): void
    {
        $this->results[] = [$status, $label, $detail];
    }

    private function checkPhp(): void
    {
        PHP_VERSION_ID >= 80300
            ? $this->add(self::PASS, 'PHP version', PHP_VERSION)
            : $this->add(self::FAIL, 'PHP version', PHP_VERSION.' (need >= 8.3)');

        $missing = array_values(array_filter(
            ['curl', 'zip', 'fileinfo'],
            fn ($ext) => ! extension_loaded($ext),
        ));

        $missing === []
            ? $this->add(self::PASS, 'PHP extensions', 'curl, zip, fileinfo')
            : $this->add(self::FAIL, 'PHP extensions', 'missing: '.implode(', ', $missing));
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();

            DB::getSchemaBuilder()->hasTable('speeches')
                ? $this->add(self::PASS, 'Database', 'connected; migrations present')
                : $this->add(self::FAIL, 'Database', 'connected but tables missing — run `php artisan migrate`');
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', 'cannot connect: '.$e->getMessage());
        }
    }

    private function checkFfmpeg(): void
    {
        $bin = (string) config('tts.ffmpeg_path', 'ffmpeg');

        try {
            $process = new Process([$bin, '-version']);
            $process->run();

            if ($process->isSuccessful()) {
                $first = strtok($process->getOutput(), "\n");
                $this->add(self::PASS, 'ffmpeg', trim($first ?: 'ffmpeg'));
            } else {
                $this->add(self::FAIL, 'ffmpeg', "`{$bin}` failed — install ffmpeg (e.g. `apt install ffmpeg`) or set TTS_FFMPEG_PATH");
            }
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'ffmpeg', "could not run `{$bin}`: ".$e->getMessage());
        }
    }

    private function checkStorage(): void
    {
        $diskName = (string) config('tts.storage_disk');
        $probe = trim((string) config('tts.storage_path', 'speech'), '/').'/.doctor-'.uniqid().'.txt';

        try {
            $disk = Storage::disk($diskName);
            $disk->put($probe, 'ok');
            $readBack = $disk->get($probe) === 'ok';
            $disk->delete($probe);

            $readBack
                ? $this->add(self::PASS, "Storage [{$diskName}]", 'write / read / delete OK')
                : $this->add(self::FAIL, "Storage [{$diskName}]", 'wrote a probe file but could not read it back');
        } catch (Throwable $e) {
            $this->add(self::FAIL, "Storage [{$diskName}]", 'not usable: '.$e->getMessage());
        }
    }

    private function checkProvider(): void
    {
        $provider = (string) config('tts.provider');

        if ($provider === 'fake') {
            $this->add(self::WARN, 'Provider', 'fake — returns silent placeholder audio (set TTS_PROVIDER=replicate for real voices)');

            return;
        }

        if ($provider !== 'replicate') {
            $this->add(self::WARN, 'Provider', "unknown provider '{$provider}'");

            return;
        }

        $token = config('tts.providers.replicate.token');
        if (! $token) {
            $this->add(self::FAIL, 'Provider [replicate]', 'REPLICATE_API_TOKEN is not set');

            return;
        }

        if (! $this->option('deep')) {
            $this->add(self::PASS, 'Provider [replicate]', 'token set (run with --deep to validate it live)');

            return;
        }

        try {
            $response = Http::withToken($token)->timeout(15)->get('https://api.replicate.com/v1/account');

            $response->successful()
                ? $this->add(self::PASS, 'Provider [replicate]', 'token valid (account: '.($response->json('username') ?? '?').')')
                : $this->add(self::FAIL, 'Provider [replicate]', 'token rejected by Replicate (HTTP '.$response->status().')');
        } catch (Throwable $e) {
            $this->add(self::WARN, 'Provider [replicate]', 'could not reach Replicate: '.$e->getMessage());
        }
    }

    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->add(self::WARN, 'Queue', 'sync — async jobs run inline during the request (fine for short text; long async text needs a real worker)');

            return;
        }

        $this->add(self::PASS, 'Queue', "connection \"{$connection}\" — ensure a `queue:work` worker is running for async generation");
    }

    private function checkScheduler(): void
    {
        try {
            $schedule = app(Schedule::class);
            $scheduled = array_filter(
                $schedule->events(),
                fn ($event) => str_contains((string) ($event->command ?? ''), 'speech:cleanup'),
            );

            $scheduled !== []
                ? $this->add(self::PASS, 'Scheduler', 'speech:cleanup is scheduled — ensure cron runs `php artisan schedule:run` every minute')
                : $this->add(self::WARN, 'Scheduler', 'speech:cleanup is not scheduled; expired audio will accumulate');
        } catch (Throwable $e) {
            $this->add(self::WARN, 'Scheduler', 'could not introspect the schedule: '.$e->getMessage());
        }
    }

    private function checkApp(): void
    {
        config('app.key')
            ? $this->add(self::PASS, 'App key', 'set')
            : $this->add(self::FAIL, 'App key', 'APP_KEY is missing — run `php artisan key:generate`');

        if (app()->environment('production') && config('app.debug')) {
            $this->add(self::FAIL, 'Debug mode', 'APP_DEBUG=true in production — set it to false');
        } else {
            $this->add(self::PASS, 'Debug mode', app()->environment().' / debug='.(config('app.debug') ? 'true' : 'false'));
        }
    }

    private function renderResults(): void
    {
        $this->newLine();

        foreach ($this->results as [$status, $label, $detail]) {
            $badge = match ($status) {
                self::PASS => '<fg=black;bg=green> PASS </>',
                self::WARN => '<fg=black;bg=yellow> WARN </>',
                default => '<fg=white;bg=red> FAIL </>',
            };

            $this->line("{$badge} <options=bold>{$label}</> — {$detail}");
        }

        $count = fn (string $s) => count(array_filter($this->results, fn ($r) => $r[0] === $s));

        $this->newLine();
        $this->line(sprintf(
            'Summary: <fg=green>%d pass</>, <fg=yellow>%d warn</>, <fg=red>%d fail</>',
            $count(self::PASS),
            $count(self::WARN),
            $count(self::FAIL),
        ));
    }
}
