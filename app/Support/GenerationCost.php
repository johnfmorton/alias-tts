<?php

namespace App\Support;

use App\Services\Tts\ModelCatalog;

/**
 * Formats the estimated provider cost of generated speech for the Studio
 * spend readouts. Each catalog model carries its OWN per-1k-character rate
 * (config `tts.models.*.cost_per_1k_chars`), mirroring how Replicate meters
 * the Chatterbox family: billed per INPUT CHARACTER of the prompt — reference
 * clips and tuning knobs are free, and every render is its own charge.
 *
 * Character counts passed in are LIFETIME counters (see
 * ProjectService::recordTake()): deleting a take or chunk never lowers them,
 * because the money is already spent. Counts arrive either as a plain int
 * (legacy: all classic chatterbox) or as a per-model map from SpendCounters,
 * so mixed-engine projects price each model's characters at its own rate.
 */
final class GenerationCost
{
    /** Readouts are hidden entirely when no model has a rate (all 0). */
    public static function enabled(): bool
    {
        foreach (ModelCatalog::keys() as $model) {
            if (ModelCatalog::costPer1k($model) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /** USD per 1,000 input characters for one catalog model. */
    public static function ratePer1k(string $model = ModelCatalog::DEFAULT): float
    {
        return ModelCatalog::costPer1k($model);
    }

    /**
     * Total estimated dollars for a count. An int prices as classic
     * chatterbox; a map prices each model's characters at its own rate.
     *
     * @param  int|array<string, int>  $characters
     */
    private static function dollars(int|array $characters): float
    {
        $map = is_array($characters) ? $characters : ['chatterbox' => $characters];

        $dollars = 0.0;
        foreach ($map as $model => $chars) {
            $dollars += max(0, (int) $chars) * self::ratePer1k((string) $model) / 1000;
        }

        return $dollars;
    }

    /** @param  int|array<string, int>  $characters */
    private static function totalCharacters(int|array $characters): int
    {
        return is_array($characters) ? array_sum(array_map('intval', $characters)) : max(0, $characters);
    }

    /**
     * Compact label: "0¢", "0.1¢", "7.0¢", "$1.02". `$markup` scales the
     * figure into the user-facing price (limited users see marked-up money,
     * SuperAdmins the actual provider spend — see CreditService::markup()).
     *
     * @param  int|array<string, int>  $characters
     */
    public static function label(int|array $characters, float $markup = 1.0): string
    {
        if (self::totalCharacters($characters) <= 0) {
            return '0¢';
        }

        $dollars = self::dollars($characters) * $markup;

        // 99.5¢ rounds to "$1.00", so switch to dollars there — never "100.0¢".
        if ($dollars >= 0.995) {
            return '$'.number_format($dollars, 2);
        }

        return number_format($dollars * 100, 1).'¢';
    }

    /**
     * Tooltip spelling out the estimate — and why it only ever grows. A
     * per-model map appends the per-engine breakdown so a mixed project shows
     * where the money went. With a markup (> 1) the rates shown are the
     * marked-up ones and the wording drops "provider" — a limited user is
     * quoted their own price, not the owner's Replicate bill.
     *
     * @param  int|array<string, int>  $characters
     */
    public static function title(int|array $characters, string $scope, float $markup = 1.0): string
    {
        $title = sprintf(
            '%s across every take ever rendered for this %s: %s characters%s. Deleting takes never lowers it — that money is already spent.',
            $markup > 1.0 ? 'Estimated cost' : 'Estimated provider spend',
            $scope,
            number_format(self::totalCharacters($characters)),
            is_array($characters) ? '' : ' × $'.self::rateLabel(self::ratePer1k() * $markup).' per 1,000',
        );

        if (! is_array($characters)) {
            return $title;
        }

        $parts = [];
        foreach ($characters as $model => $chars) {
            if ((int) $chars <= 0) {
                continue;
            }
            $parts[] = sprintf(
                '%s: %s × $%s/1k',
                ModelCatalog::label((string) $model),
                number_format((int) $chars),
                self::rateLabel(self::ratePer1k((string) $model) * $markup),
            );
        }

        return $parts === [] ? $title : $title.' '.implode(' · ', $parts).'.';
    }

    /** "0.025" with trailing zeros trimmed (never a bare ""). */
    private static function rateLabel(float $rate): string
    {
        $label = rtrim(rtrim(number_format($rate, 3), '0'), '.');

        return $label === '' ? '0' : $label;
    }
}
