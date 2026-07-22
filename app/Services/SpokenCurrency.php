<?php

namespace App\Services;

/**
 * Expands monetary amounts into the words a narrator would speak: "$0.18" ->
 * "eighteen cents", "$1,299.99" -> "one thousand two hundred ninety nine
 * dollars and ninety nine cents", "$3.5 million" -> "three point five million
 * dollars". TTS engines routinely garble currency notation (reading "$0.18"
 * as "zero point one eight dollars", or spelling the symbol), so amounts are
 * expanded before the text reaches an engine.
 *
 * Deliberately conservative: only clearly monetary shapes are touched — a
 * currency symbol ($, €, £) followed by digits (optional thousands commas,
 * cents, or a scale suffix like "million"/"k"), or digits followed by "¢".
 * Ambiguous shapes (three-plus decimals with no scale word, 16+ digit runs)
 * are left verbatim. Spelled numbers avoid hyphens ("ninety nine") because
 * the engines read a mid-word hyphen as a hard pause.
 *
 * Pure and idempotent — expanded text contains no currency notation, so a
 * second pass is a no-op. Invoked from {@see TextNormalizer::normalize()}.
 */
class SpokenCurrency
{
    /**
     * Symbol-led amounts: "$5", "$0.18", "$1,299.99", "$3.5 million", "$10k".
     * The integer/decimal parts are individually optional so "$.18" parses;
     * the callback bails (returns the match verbatim) when both are absent.
     * Word scale suffixes may be space-separated; letter suffixes (k/m/bn)
     * only count when attached to the digits, so "$5 more" keeps "more".
     */
    private const AMOUNT_PATTERN =
        '/([$€£]) ?(\d{1,3}(?:,\d{3})+|\d+)?(?:\.(\d+))?'.
        '(?: ?(thousand|million|billion|trillion)(?![a-z0-9])|(k|m|bn?)(?![a-z0-9]))?/ui';

    /** Trailing cent sign: "18¢", "1¢". */
    private const CENTS_PATTERN = '/(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))? ?¢/u';

    /** symbol => [major singular, major plural, minor singular, minor plural] */
    private const UNITS = [
        '$' => ['dollar', 'dollars', 'cent', 'cents'],
        '€' => ['euro', 'euros', 'cent', 'cents'],
        '£' => ['pound', 'pounds', 'penny', 'pence'],
    ];

    private const ONES = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
        'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
        'sixteen', 'seventeen', 'eighteen', 'nineteen',
    ];

    private const TENS = [
        2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
        6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety',
    ];

    public function apply(string $text): string
    {
        $text = (string) preg_replace_callback(
            self::AMOUNT_PATTERN,
            fn (array $m) => $this->speakAmount($m),
            $text,
        );

        return (string) preg_replace_callback(
            self::CENTS_PATTERN,
            fn (array $m) => $this->speakCents($m),
            $text,
        );
    }

    /**
     * @param  array<int, string>  $m
     */
    private function speakAmount(array $m): string
    {
        $integer = str_replace(',', '', $m[2] ?? '');
        $decimal = $m[3] ?? '';
        $suffix = strtolower($m[4] ?? '') ?: strtolower($m[5] ?? '');

        if (($integer === '' && $decimal === '') || strlen($integer) > 15) {
            return $m[0];
        }

        [$one, $many, $centOne, $centMany] = self::UNITS[$m[1]];

        if ($suffix !== '') {
            $scale = ['k' => 'thousand', 'm' => 'million', 'b' => 'billion', 'bn' => 'billion'][$suffix] ?? $suffix;
            $words = $this->spell((int) ($integer === '' ? '0' : $integer));
            $fraction = rtrim($decimal, '0');
            if ($fraction !== '') {
                $words .= ' point '.$this->spellDigits($fraction);
            }

            return $words.' '.$scale.' '.$many;
        }

        // Three-plus decimals with no scale word is likelier a version/id than
        // a price ("$1.2345") — leave it alone.
        if (strlen($decimal) > 2) {
            return $m[0];
        }

        $major = (int) ($integer === '' ? '0' : $integer);
        $minor = $decimal === '' ? 0 : (int) str_pad($decimal, 2, '0');

        $parts = [];
        if ($major > 0 || $minor === 0) {
            $parts[] = $this->spell($major).' '.($major === 1 ? $one : $many);
        }
        if ($minor > 0) {
            $parts[] = $this->spell($minor).' '.($minor === 1 ? $centOne : $centMany);
        }

        return implode(' and ', $parts);
    }

    /**
     * @param  array<int, string>  $m
     */
    private function speakCents(array $m): string
    {
        $integer = str_replace(',', '', $m[1]);
        if (strlen($integer) > 15) {
            return $m[0];
        }

        $count = (int) $integer;
        $fraction = rtrim($m[2] ?? '', '0');
        if ($fraction !== '') {
            return $this->spell($count).' point '.$this->spellDigits($fraction).' cents';
        }

        return $count === 1 ? 'one cent' : $this->spell($count).' cents';
    }

    /** Digits read individually, for after a spoken decimal point: "75" -> "seven five". */
    private function spellDigits(string $digits): string
    {
        return implode(' ', array_map(
            fn (string $d) => self::ONES[(int) $d],
            str_split($digits),
        ));
    }

    private function spell(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        if ($n < 100) {
            $rest = $n % 10;

            return self::TENS[intdiv($n, 10)].($rest === 0 ? '' : ' '.self::ONES[$rest]);
        }
        if ($n < 1000) {
            $rest = $n % 100;

            return self::ONES[intdiv($n, 100)].' hundred'.($rest === 0 ? '' : ' '.$this->spell($rest));
        }
        foreach ([1_000_000_000_000 => 'trillion', 1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'] as $value => $word) {
            if ($n >= $value) {
                $rest = $n % $value;

                return $this->spell(intdiv($n, $value)).' '.$word.($rest === 0 ? '' : ' '.$this->spell($rest));
            }
        }

        return (string) $n; // unreachable: 15-digit guard keeps $n below a quadrillion
    }
}
