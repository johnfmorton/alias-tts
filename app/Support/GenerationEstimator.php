<?php

namespace App\Support;

/**
 * The compute + wording half of the generation-time estimate — the single
 * place every surface (Studio project page, Jobs page, the /v1 poll, the
 * pre-run hint) turns counts and elapsed time into an ETA, so the phrasing and
 * rounding are identical everywhere.
 *
 * Two estimates feed it: {@see seedMs()} from the learned per-model history
 * (used before any chunk of a run has finished, and for the up-front number),
 * and {@see liveMs()} — the run's own running average, which is honest
 * ground-truth for the conditions actually in play and takes over as soon as
 * one chunk completes.
 */
final class GenerationEstimator
{
    /**
     * Running-average ETA for the rest of a run, in ms: the wall-clock spent so
     * far divided over the chunks processed, projected across those remaining.
     * Null until at least one chunk has finished (nothing to average yet).
     */
    public static function liveMs(int $elapsedMs, int $processed, int $remaining): ?int
    {
        if ($processed < 1 || $remaining < 1 || $elapsedMs <= 0) {
            return null;
        }

        return (int) round($elapsedMs / $processed * $remaining);
    }

    /**
     * History-based estimate (ms) for a set of outstanding chunks, grouped by
     * the model each will render on, plus the inter-chunk pace the run waits
     * between clips. Used for the up-front number and as the pre-first-chunk
     * seed.
     *
     * @param  array<string, int>  $modelCounts  model key => number of chunks
     */
    public static function seedMs(array $modelCounts, int $paceMs = 0): int
    {
        $ms = 0;
        $count = 0;
        foreach ($modelCounts as $model => $n) {
            $n = max(0, (int) $n);
            $ms += GenerationTimings::perChunkMs((string) $model) * $n;
            $count += $n;
        }

        // Pace is waited between clips, so (count - 1) gaps.
        if ($count > 1 && $paceMs > 0) {
            $ms += $paceMs * ($count - 1);
        }

        return $ms;
    }

    /**
     * A friendly, deliberately-rounded duration phrase that reads correctly in
     * every context this app uses it: "… · {phrase} left", "{phrase} to
     * generate the N remaining clips", "About {phrase}". Never false precision —
     * an ETA is an estimate.
     */
    public static function humanize(int $seconds): string
    {
        if ($seconds < 55) {
            return 'under a minute';
        }

        $minutes = (int) round($seconds / 60);

        if ($minutes <= 1) {
            return 'about a minute';
        }

        if ($minutes < 60) {
            return "about {$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        return $rem === 0
            ? "about {$hours} hr"
            : "about {$hours} hr {$rem} min";
    }

    /**
     * Package a raw ms estimate as the pair the surfaces render: whole seconds
     * plus the humanized phrase. Null ms (nothing to estimate) yields nulls.
     *
     * @return array{eta_seconds: int|null, eta_human: string|null}
     */
    public static function payload(?int $ms): array
    {
        if ($ms === null || $ms <= 0) {
            return ['eta_seconds' => null, 'eta_human' => null];
        }

        $seconds = (int) round($ms / 1000);

        return ['eta_seconds' => $seconds, 'eta_human' => self::humanize($seconds)];
    }
}
