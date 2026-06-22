<?php

namespace App\Services\Asr;

use App\Services\ProjectService;
use App\Services\SpeechService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP client for the local Whisper sidecar (see asr-sidecar/ and
 * docs/ASR-SETUP.md). All methods degrade safely: if the sidecar is disabled or
 * unreachable, {@see transcribe()} returns null and {@see health()} reports it
 * — generation is never blocked by ASR being down.
 */
class AsrClient
{
    public function enabled(): bool
    {
        return (bool) config('tts.asr.enabled', false);
    }

    /**
     * Shared remediation policy when a chunk is flagged: 'log' (record only) or
     * 'auto'. The default for both generation paths; each can override it via
     * {@see studioAction()} / {@see apiAction()}.
     */
    public function action(): string
    {
        return (string) config('tts.asr.action', 'log');
    }

    /**
     * Remediation policy for the interactive Studio / editable-project path
     * ({@see ProjectService}). Inherits {@see action()} when
     * `tts.asr.studio_action` is unset (null).
     */
    public function studioAction(): string
    {
        return (string) (config('tts.asr.studio_action') ?? $this->action());
    }

    /**
     * Remediation policy for the unattended API / synchronous-and-queued path
     * ({@see SpeechService}). Inherits {@see action()} when
     * `tts.asr.api_action` is unset (null).
     */
    public function apiAction(): string
    {
        return (string) (config('tts.asr.api_action') ?? $this->action());
    }

    /** Max automatic re-rolls of a flagged chunk under action=auto. */
    public function maxRerolls(): int
    {
        return max(0, (int) config('tts.asr.max_rerolls', 2));
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('tts.asr.url', 'http://127.0.0.1:8765'), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) config('tts.asr.timeout', 30));
    }

    /**
     * Ping the sidecar's /health endpoint. Never throws.
     *
     * @return array{reachable: bool, latency_ms: int|null, body: array<string, mixed>, error: string|null}
     */
    public function health(): array
    {
        $start = microtime(true);

        try {
            $res = Http::timeout(min(10, $this->timeout()))->get($this->baseUrl().'/health');
            $latency = (int) round((microtime(true) - $start) * 1000);
            $body = is_array($res->json()) ? $res->json() : [];

            return [
                'reachable' => $res->successful(),
                'latency_ms' => $latency,
                'body' => $body,
                'error' => $res->successful() ? null : 'HTTP '.$res->status(),
            ];
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'body' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Transcribe WAV bytes with word-level timestamps. Returns the decoded
     * payload (duration, text, words[]) or null on any failure — callers treat
     * null as "skip QA for this chunk".
     *
     * @return array{duration: float, text: string, words: array<int, array{word: string, start: float, end: float}>, transcribe_ms?: int}|null
     */
    public function transcribe(string $wavBytes, string $filename = 'chunk.wav'): ?array
    {
        try {
            $res = Http::timeout($this->timeout())
                ->attach('audio', $wavBytes, $filename)
                ->post($this->baseUrl().'/transcribe', [
                    'language' => (string) config('tts.asr.language', 'en'),
                ]);

            if (! $res->successful()) {
                Log::warning('ASR transcribe failed', ['status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            $data = $res->json();
            if (! is_array($data) || ! array_key_exists('words', $data)) {
                Log::warning('ASR transcribe returned an unexpected payload');

                return null;
            }

            return $data;
        } catch (Throwable $e) {
            Log::warning('ASR sidecar unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
