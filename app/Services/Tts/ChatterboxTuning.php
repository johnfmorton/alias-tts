<?php

namespace App\Services\Tts;

/**
 * Single source of truth for mapping the ElevenLabs-style 0..1 knobs
 * (stability / style) the public /v1 API speaks onto Chatterbox's native knobs
 * (cfg_weight / exaggeration). Shared by {@see ReplicateChatterboxProvider} (which
 * sends them to the model), the Studio panel's inherited-value display, and the
 * takes list, so the formula can never drift between PHP call sites. The formula
 * lives ONLY here — there is no JS mirror; the JS/Blade knob widgets duplicate
 * just the knob ranges/defaults (app.js bench rows, the x-tuning-knob component)
 * — keep those in sync with the clamps below.
 *
 *   cfg_weight   in [0.2, 1.0]  — higher stability => steadier pacing.
 *   exaggeration in [0.25, 2.0] — style 0 => 0.5 (neutral), style 1 => 2.0.
 *   temperature  in [0.5, 1.5]  — sampling randomness (native-only, no EL twin);
 *                                 low => flat/steady, high => varied/expressive.
 */
class ChatterboxTuning
{
    /** Practical UI band for temperature; the model itself accepts 0.05–5. */
    public const TEMPERATURE_MIN = 0.5;

    public const TEMPERATURE_MAX = 1.5;

    public const TEMPERATURE_DEFAULT = 0.8;

    public static function clampCfgWeight(float $cfgWeight): float
    {
        return max(0.2, min(1.0, $cfgWeight));
    }

    public static function clampExaggeration(float $exaggeration): float
    {
        return max(0.25, min(2.0, $exaggeration));
    }

    public static function clampTemperature(float $temperature): float
    {
        return max(self::TEMPERATURE_MIN, min(self::TEMPERATURE_MAX, $temperature));
    }

    public static function cfgWeightFromStability(float $stability): float
    {
        return self::clampCfgWeight($stability);
    }

    public static function exaggerationFromStyle(float $style): float
    {
        return self::clampExaggeration(0.5 + $style * 1.5);
    }

    /**
     * Resolve any settings map to the native pair the model wants, preferring
     * explicit native keys and falling back to deriving them from the EL knobs
     * (defaults: stability 0.5, style 0.0). This IS the precedence the provider
     * applies — "native wins, EL is the fallback".
     *
     * @param  array<string, mixed>  $settings
     * @return array{exaggeration: float, cfg_weight: float}
     */
    public static function resolveNative(array $settings): array
    {
        $cfg = isset($settings['cfg_weight'])
            ? self::clampCfgWeight((float) $settings['cfg_weight'])
            : self::cfgWeightFromStability((float) ($settings['stability'] ?? 0.5));

        $exag = isset($settings['exaggeration'])
            ? self::clampExaggeration((float) $settings['exaggeration'])
            : self::exaggerationFromStyle((float) ($settings['style'] ?? 0.0));

        return ['exaggeration' => $exag, 'cfg_weight' => $cfg];
    }
}
