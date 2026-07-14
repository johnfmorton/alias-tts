<?php

namespace App\Console\Commands;

use App\Services\Tts\LocalChatterboxProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Post-install check for the local Chatterbox sidecar (TTS_PROVIDER=local).
 * Confirms the sidecar is reachable and reports each engine's load state;
 * with --deep it synthesizes a short phrase end-to-end, so a green run proves
 * the whole round-trip (provider → sidecar → WAV bytes) is wired up. Exits
 * non-zero on failure. See docs/CHATTERBOX-LOCAL.md.
 */
class ChatterboxHealth extends Command
{
    protected $signature = 'tts:chatterbox:health {--deep : Synthesize a short phrase and verify WAV bytes come back}';

    protected $description = 'Check the local Chatterbox sidecar: reachability, engine load states, and (with --deep) a live synthesis round-trip';

    public function handle(): int
    {
        $url = rtrim((string) config('tts.providers.local.url'), '/');
        $this->line("Checking Chatterbox sidecar at <options=bold>{$url}</> …");

        $t0 = microtime(true);
        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url.'/health');
        } catch (Throwable $e) {
            $this->error('UNREACHABLE — '.$e->getMessage());
            $this->line('Is the sidecar running? See docs/CHATTERBOX-LOCAL.md.');

            return self::FAILURE;
        }
        $latencyMs = (int) round((microtime(true) - $t0) * 1000);

        $body = $response->json() ?? [];
        if (! $response->successful() || ($body['status'] ?? '') !== 'ok') {
            $this->error('Sidecar is up but unhealthy: '.($body['error'] ?? 'HTTP '.$response->status()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'OK — device %s, python %s, torch %s, chatterbox-tts %s, health %dms%s',
            $body['device'] ?? '?',
            $body['python'] ?? '?',
            $body['torch'] ?? '?',
            $body['chatterbox_tts'] ?? '?',
            $latencyMs,
            ($body['busy'] ?? false) ? ' (currently generating)' : '',
        ));

        foreach (($body['models'] ?? []) as $key => $state) {
            $this->line(sprintf(
                '  %-17s %s%s',
                $key,
                ($state['loaded'] ?? false)
                    ? 'loaded ('.($state['load_seconds'] ?? '?').'s)'
                    : 'not loaded (lazy — loads on first use)',
                empty($state['error']) ? '' : ' — last load error: '.$state['error'],
            ));
        }

        if (config('tts.provider') !== 'local') {
            $this->warn(sprintf(
                "Sidecar reachable, but TTS_PROVIDER is '%s' — generation is NOT using it. Set TTS_PROVIDER=local to switch.",
                config('tts.provider'),
            ));
        }

        if (! $this->option('deep')) {
            $this->line('Run with --deep to synthesize a short phrase through it.');

            return self::SUCCESS;
        }

        return $this->selfTest();
    }

    private function selfTest(): int
    {
        $this->line('Synthesizing a short phrase on the classic chatterbox model …');
        $this->warn('A cold model triggers a one-time ~3.8GB download plus a model load before generating.');

        // Constructed directly from config (not the container binding) so the
        // self-test works even while TTS_PROVIDER is still "replicate".
        $provider = new LocalChatterboxProvider(
            config('tts.providers.local', []),
            (int) config('tts.providers.local.timeout', 300),
            config('tts.models', []),
        );

        try {
            $t0 = microtime(true);
            $audio = $provider->synthesize('The quick brown fox jumps over the lazy dog.', null, []);
            $elapsed = microtime(true) - $t0;
        } catch (Throwable $e) {
            $this->error('Synthesis FAILED — '.$e->getMessage());

            return self::FAILURE;
        }

        if (! str_starts_with($audio, 'RIFF')) {
            $this->error('Synthesis returned '.strlen($audio).' bytes, but not WAV (missing RIFF header).');

            return self::FAILURE;
        }

        $this->info(sprintf('Self-test PASSED — %d KB of WAV in %.1fs.', (int) (strlen($audio) / 1024), $elapsed));

        return self::SUCCESS;
    }
}
