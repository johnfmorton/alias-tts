<?php

namespace App\Services\Enhance;

use App\Services\Tts\ReplicateChatterboxProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Cleans up a reference clip via the official `resemble-ai/resemble-enhance`
 * model on Replicate (denoise + enhance). Modeled on
 * {@see ReplicateChatterboxProvider} — `Prefer: wait` with a
 * polling fallback (the model averages ~39s, so the poll WILL be exercised) and
 * one retry that honors a 429 `retry_after`.
 *
 * The whole surface is DEGRADE-SAFE: every failure path logs and returns null so
 * the caller falls back to the original clip. It never throws.
 *
 * NOTE: the model returns a TWO-element list [denoised, enhanced]. We download
 * the enhanced take (index 1) normally, or the denoised take (index 0) when the
 * caller asks for denoise_only. Confirm the input field names / pin a version
 * from the model's API page.
 */
class ReplicateEnhanceProvider implements EnhanceProvider
{
    private const BASE = 'https://api.replicate.com/v1';

    public function __construct(
        private array $config,
        private int $timeout = 120,
    ) {}

    public function enhance(string $wavBytes, array $options = []): ?string
    {
        $token = $this->config['token'] ?? null;
        if (! $token) {
            Log::warning('Reference enhancement skipped: REPLICATE_API_TOKEN is not configured.');

            return null;
        }

        $denoiseOnly = (bool) ($options['denoise_only'] ?? false);

        try {
            $input = [
                ($this->config['audio_field'] ?? 'input_audio') => $this->toDataUri($wavBytes),
                // We always denoise — the point of cleanup is to remove room
                // noise/hiss from a possibly-imperfect recording.
                ($this->config['denoise_flag_field'] ?? 'denoise_flag') => true,
            ];

            $prediction = $this->awaitCompletion($token, $this->createPrediction($token, $input));

            if (($prediction['status'] ?? '') !== 'succeeded') {
                $err = $prediction['error'] ?? 'unknown error';
                Log::warning('Reference enhancement failed', ['status' => $prediction['status'] ?? '?', 'error' => is_string($err) ? $err : json_encode($err)]);

                return null;
            }

            // Output is [denoised, enhanced]; pick per denoise_only. Tolerate a
            // bare-string output in case the schema ever returns a single file.
            $output = $prediction['output'] ?? null;
            $url = is_array($output)
                ? ($denoiseOnly ? ($output[0] ?? null) : ($output[1] ?? $output[0] ?? null))
                : $output;

            if (! is_string($url) || $url === '') {
                Log::warning('Reference enhancement returned no output URL', ['output' => $output]);

                return null;
            }

            $audio = Http::withToken($token)->timeout($this->timeout)->get($url);
            if (! $audio->successful()) {
                Log::warning('Reference enhancement output download failed', ['status' => $audio->status()]);

                return null;
            }

            $bytes = $audio->body();

            return $bytes === '' ? null : $bytes;
        } catch (Throwable $e) {
            Log::warning('Reference enhancement failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function health(bool $deep = false): array
    {
        $token = $this->config['token'] ?? null;
        if (! $token) {
            return ['reachable' => false, 'detail' => 'REPLICATE_API_TOKEN is not set', 'error' => 'missing token'];
        }

        $model = (string) ($this->config['model'] ?? 'resemble-ai/resemble-enhance');
        $version = (string) ($this->config['version'] ?? '');

        if (! $deep) {
            return ['reachable' => true, 'detail' => "token set, model {$model}".($version === '' ? '' : " @ {$version}"), 'error' => null];
        }

        try {
            $start = microtime(true);
            $res = Http::withToken($token)->timeout(15)->get(self::BASE."/models/{$model}");
            $ms = (int) round((microtime(true) - $start) * 1000);

            return $res->successful()
                ? ['reachable' => true, 'detail' => "model {$model} reachable ({$ms}ms)", 'error' => null]
                : ['reachable' => false, 'detail' => "model probe failed (HTTP {$res->status()})", 'error' => 'HTTP '.$res->status()];
        } catch (Throwable $e) {
            return ['reachable' => false, 'detail' => 'could not reach Replicate', 'error' => $e->getMessage()];
        }
    }

    private function createPrediction(string $token, array $input): array
    {
        $response = $this->sendWithRetry(function () use ($token, $input) {
            $http = Http::withToken($token)->timeout($this->timeout)->withHeaders(['Prefer' => 'wait']);

            if (! empty($this->config['version'])) {
                return $http->post(self::BASE.'/predictions', [
                    'version' => $this->config['version'],
                    'input' => $input,
                ]);
            }

            $model = $this->config['model'] ?? 'resemble-ai/resemble-enhance';

            return $http->post(self::BASE."/models/{$model}/predictions", ['input' => $input]);
        });

        return $response->json();
    }

    /**
     * Poll a prediction until terminal (the ~39s runtime usually outlives the
     * `Prefer: wait` window). Returns the prediction as-is; the caller decides.
     */
    private function awaitCompletion(string $token, array $prediction): array
    {
        $deadline = time() + $this->timeout;

        while (in_array($prediction['status'] ?? '', ['starting', 'processing'], true)) {
            if (time() >= $deadline) {
                throw new \RuntimeException('Replicate enhancement timed out.');
            }

            Sleep::for(1500)->milliseconds();

            $get = $prediction['urls']['get'] ?? (self::BASE.'/predictions/'.($prediction['id'] ?? ''));
            $prediction = $this->sendWithRetry(
                fn () => Http::withToken($token)->timeout($this->timeout)->get($get),
            )->json();
        }

        return $prediction;
    }

    /**
     * Send a Replicate request, retrying once per attempt on 429 (honoring
     * retry_after, else exponential backoff), bounded by max_retries and the
     * per-request timeout. Non-429 failures throw (caught upstream → null).
     *
     * @param  callable():Response  $send
     */
    private function sendWithRetry(callable $send): Response
    {
        $maxRetries = max(0, (int) ($this->config['max_retries'] ?? 2));
        $baseMs = max(0, (int) ($this->config['retry_base_ms'] ?? 1000));
        $capMs = max($baseMs, (int) ($this->config['retry_max_ms'] ?? 30000));
        $deadline = time() + $this->timeout;

        $attempt = 0;
        while (true) {
            $response = $send();

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() !== 429 || $attempt >= $maxRetries) {
                throw new \RuntimeException('Replicate enhancement request failed (HTTP '.$response->status().'): '.$response->body());
            }

            $hint = $this->retryAfterSeconds($response);
            $delayMs = $hint !== null
                ? (int) min($capMs, max(0, (int) round($hint * 1000)))
                : (int) min($capMs, $baseMs * (2 ** $attempt));

            if (time() + (int) ceil($delayMs / 1000) >= $deadline) {
                throw new \RuntimeException('Replicate enhancement throttled (429); retry budget exhausted.');
            }

            Sleep::for($delayMs)->milliseconds();
            $attempt++;
        }
    }

    private function retryAfterSeconds(Response $response): ?float
    {
        $body = $response->json();
        if (is_array($body) && isset($body['retry_after']) && is_numeric($body['retry_after'])) {
            return (float) $body['retry_after'];
        }

        $header = $response->header('Retry-After');

        return is_numeric($header) ? (float) $header : null;
    }

    private function toDataUri(string $wavBytes): string
    {
        return 'data:audio/wav;base64,'.base64_encode($wavBytes);
    }
}
