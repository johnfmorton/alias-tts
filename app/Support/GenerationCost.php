<?php

namespace App\Support;

/**
 * Formats the estimated provider cost of generated speech for the Studio
 * spend readouts. The rate (config `tts.cost_per_1k_chars`) mirrors how
 * Replicate meters Chatterbox: billed per INPUT CHARACTER of the prompt —
 * reference clips and tuning knobs are free, and every render is its own
 * charge. The character counts passed in are LIFETIME counters (see
 * ProjectService::recordTake()): deleting a take or chunk never lowers
 * them, because the money is already spent.
 */
final class GenerationCost
{
    /** Readouts are hidden entirely when no rate is configured (rate = 0). */
    public static function enabled(): bool
    {
        return self::ratePer1k() > 0.0;
    }

    /** USD per 1,000 input characters. */
    public static function ratePer1k(): float
    {
        return max(0.0, (float) config('tts.cost_per_1k_chars', 0));
    }

    /** Compact label: "0¢", "0.1¢", "7.0¢", "$1.02". */
    public static function label(int $characters): string
    {
        if ($characters <= 0) {
            return '0¢';
        }

        $dollars = $characters * self::ratePer1k() / 1000;

        // 99.5¢ rounds to "$1.00", so switch to dollars there — never "100.0¢".
        if ($dollars >= 0.995) {
            return '$'.number_format($dollars, 2);
        }

        return number_format($dollars * 100, 1).'¢';
    }

    /** Tooltip spelling out the estimate — and why it only ever grows. */
    public static function title(int $characters, string $scope): string
    {
        return sprintf(
            'Estimated provider spend across every take ever rendered for this %s: %s characters × $%s per 1,000. Deleting takes never lowers it — that money is already spent.',
            $scope,
            number_format($characters),
            rtrim(rtrim(number_format(self::ratePer1k(), 3), '0'), '.'),
        );
    }
}
