<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Learned per-model generation timing (tts_generation_timings) behind plain,
 * portable query-builder calls — the storage half of the "how long will the
 * rest take?" estimate. Every successful render adds one sample and its
 * wall-clock ms to its engine's row; {@see perChunkMs()} reads back the running
 * average. Same atomic increment-then-insert dance as {@see SpendCounters}, so
 * the interactive requests and the background worker can record concurrently.
 *
 * The wall-clock passed in spans a whole chunk generation (auto-rerolls
 * included), so the average already prices reroll cost — no separate rate.
 */
final class GenerationTimings
{
    private const TABLE = 'tts_generation_timings';

    /**
     * Record one generation's wall-clock for a model (atomic). No-ops when
     * timing is off, and rejects implausible samples — a cold GPU replica or a
     * queue-spiked first call would otherwise skew the average for every later
     * estimate.
     */
    public static function record(string $model, int $elapsedMs): void
    {
        if (! config('tts.timing.enabled', true)) {
            return;
        }

        $min = (int) config('tts.timing.min_sample_ms', 300);
        $max = (int) config('tts.timing.max_sample_ms', 180000);
        if ($elapsedMs < $min || $elapsedMs > $max) {
            return;
        }

        if (DB::table(self::TABLE)->where('model', $model)->exists()) {
            self::bump($model, $elapsedMs);

            return;
        }

        try {
            DB::table(self::TABLE)->insert([
                'model' => $model,
                'samples' => 1,
                'sum_ms' => $elapsedMs,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent writer inserted the row between our miss and insert.
            self::bump($model, $elapsedMs);
        }
    }

    /** Atomic +1 sample / +elapsed sum on an existing row. */
    private static function bump(string $model, int $elapsedMs): void
    {
        DB::table(self::TABLE)->where('model', $model)->update([
            'samples' => DB::raw('samples + 1'),
            'sum_ms' => DB::raw('sum_ms + '.$elapsedMs),
            'updated_at' => now(),
        ]);
    }

    /**
     * Average wall-clock per chunk for one model, in ms. Falls back to the
     * configured per-model default until that engine has a sample, so an
     * estimate is sane on day one.
     */
    public static function perChunkMs(string $model): int
    {
        $row = DB::table(self::TABLE)->where('model', $model)->first();

        if ($row && (int) $row->samples > 0) {
            return (int) round((int) $row->sum_ms / (int) $row->samples);
        }

        return self::defaultMs($model);
    }

    /**
     * Per-model averages for every engine that has samples (observability /
     * future readouts). Models without samples are omitted.
     *
     * @return array<string, int> model key => avg ms
     */
    public static function averages(): array
    {
        return DB::table(self::TABLE)
            ->where('samples', '>', 0)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->model => (int) round((int) $row->sum_ms / (int) $row->samples)])
            ->all();
    }

    /** The configured fallback ms/chunk for a model (or classic chatterbox's). */
    private static function defaultMs(string $model): int
    {
        $defaults = (array) config('tts.timing.defaults', []);

        return (int) ($defaults[$model] ?? $defaults['chatterbox'] ?? 6000);
    }
}
