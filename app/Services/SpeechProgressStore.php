<?php

namespace App\Services;

use App\Jobs\GenerateSpeechJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache-backed live progress for one /v1 speech generation. The chunk loop in
 * {@see SpeechService::process()} overwrites a small "clip N of M" snapshot as
 * it runs — from the web process on the sync path or the worker via
 * {@see GenerateSpeechJob} — and the job-status endpoint reads it, so a polling
 * client can show real progress instead of a bare "processing". The state is
 * transient (the durable record is the speeches row), so the cache — shared
 * between web + worker via the database/redis store — is enough; no table
 * required.
 *
 * Every write/read is best-effort: progress must never fail a paid render, so
 * cache errors are swallowed and logged at debug.
 */
class SpeechProgressStore
{
    public const STAGE_GENERATING = 'generating';

    public const STAGE_STITCHING = 'stitching';

    public function begin(string $speechId, int $chunksTotal): void
    {
        $this->write($speechId, self::STAGE_GENERATING, 0, $chunksTotal);
    }

    public function advance(string $speechId, int $chunksDone, int $chunksTotal): void
    {
        $this->write($speechId, self::STAGE_GENERATING, $chunksDone, $chunksTotal);
    }

    /** All clips rendered; the (possibly slow) concatenate + store step is running. */
    public function stitching(string $speechId, int $chunksTotal): void
    {
        $this->write($speechId, self::STAGE_STITCHING, $chunksTotal, $chunksTotal);
    }

    public function clear(string $speechId): void
    {
        try {
            Cache::forget($this->key($speechId));
        } catch (Throwable $e) {
            Log::debug('Speech progress clear failed: '.$e->getMessage(), ['speech_id' => $speechId]);
        }
    }

    /** @return array{stage: string, chunks_done: int, chunks_total: int}|null */
    public function get(string $speechId): ?array
    {
        try {
            $state = Cache::get($this->key($speechId));
        } catch (Throwable $e) {
            Log::debug('Speech progress read failed: '.$e->getMessage(), ['speech_id' => $speechId]);

            return null;
        }

        return is_array($state) ? $state : null;
    }

    /**
     * The snapshot in its public API shape. `percent` and `message` are
     * computed at read time — never stored — so the wording can change without
     * leaving stale strings in the cache.
     *
     * @return array{stage: string, chunks_total: int, chunks_done: int, percent: int, message: string}|null
     */
    public function payload(string $speechId): ?array
    {
        $state = $this->get($speechId);
        if ($state === null) {
            return null;
        }

        $stage = (string) ($state['stage'] ?? self::STAGE_GENERATING);
        $total = max(1, (int) ($state['chunks_total'] ?? 1));
        $done = min((int) ($state['chunks_done'] ?? 0), $total);

        return [
            'stage' => $stage,
            'chunks_total' => $total,
            'chunks_done' => $done,
            'percent' => (int) floor($done / $total * 100),
            'message' => $stage === self::STAGE_STITCHING
                ? 'Stitching '.$total.' '.($total === 1 ? 'clip' : 'clips').' together'
                : 'Creating clip '.min($done + 1, $total)." of {$total}",
        ];
    }

    private function write(string $speechId, string $stage, int $chunksDone, int $chunksTotal): void
    {
        try {
            Cache::put(
                $this->key($speechId),
                ['stage' => $stage, 'chunks_done' => $chunksDone, 'chunks_total' => $chunksTotal],
                // Outlive the worker timeout by a margin so a live job's entry
                // never expires mid-run, while a crashed job's leftover reaps
                // itself on the same clock the in-flight dedup uses.
                now()->addSeconds((int) config('tts.async_timeout', 1800) + 300),
            );
        } catch (Throwable $e) {
            Log::debug('Speech progress write failed: '.$e->getMessage(), ['speech_id' => $speechId]);
        }
    }

    private function key(string $speechId): string
    {
        return "speech:progress:{$speechId}";
    }
}
