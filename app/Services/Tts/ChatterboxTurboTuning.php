<?php

namespace App\Services\Tts;

/**
 * Chatterbox Turbo's native knobs and the ElevenLabs-compat mapping for
 * voices running on it. Turbo has NO cfg_weight/exaggeration — its knobs are
 * top_p / top_k / repetition_penalty plus the temperature it shares with
 * classic Chatterbox (same practical 0.5–1.5 UI band via
 * {@see ChatterboxTuning::clampTemperature()}; the model itself accepts
 * 0.05–2).
 *
 * ElevenLabs-style knobs on a turbo voice: `stability` still means
 * steadier-vs-varied by mapping INVERSELY onto temperature (0.5 → the 0.8
 * default, higher stability → lower temperature); `style`,
 * `similarity_boost`, and `use_speaker_boost` are accepted and ignored —
 * the same treatment similarity_boost has always had on classic Chatterbox,
 * so no /v1 request can ever error over a knob mismatch.
 */
class ChatterboxTurboTuning
{
    public const TOP_P_MIN = 0.5;

    public const TOP_P_MAX = 1.0;

    public const TOP_P_DEFAULT = 0.95;

    public const TOP_K_MIN = 1;

    public const TOP_K_MAX = 2000;

    public const TOP_K_DEFAULT = 1000;

    public const REPETITION_PENALTY_MIN = 1.0;

    public const REPETITION_PENALTY_MAX = 2.0;

    public const REPETITION_PENALTY_DEFAULT = 1.2;

    public static function clampTopP(float $topP): float
    {
        return max(self::TOP_P_MIN, min(self::TOP_P_MAX, $topP));
    }

    public static function clampTopK(int $topK): int
    {
        return max(self::TOP_K_MIN, min(self::TOP_K_MAX, $topK));
    }

    public static function clampRepetitionPenalty(float $penalty): float
    {
        return max(self::REPETITION_PENALTY_MIN, min(self::REPETITION_PENALTY_MAX, $penalty));
    }

    /**
     * The EL `stability` mapping for turbo: 1.3 − stability, clamped to the
     * shared band. stability 0.5 → 0.8 (both defaults align), 1 → 0.5
     * (steadiest), 0 → 1.3 (most varied).
     */
    public static function temperatureFromStability(float $stability): float
    {
        return ChatterboxTuning::clampTemperature(1.3 - $stability);
    }

    /**
     * Resolve any settings map to the native knobs turbo wants. Explicit
     * native keys win; temperature falls back to the EL stability map; the
     * sampling knobs fall back to the model's own defaults.
     *
     * @param  array<string, mixed>  $settings
     * @return array{temperature: float, top_p: float, top_k: int, repetition_penalty: float}
     */
    public static function resolveNative(array $settings): array
    {
        $temperature = isset($settings['temperature'])
            ? ChatterboxTuning::clampTemperature((float) $settings['temperature'])
            : (isset($settings['stability'])
                ? self::temperatureFromStability((float) $settings['stability'])
                : ChatterboxTuning::TEMPERATURE_DEFAULT);

        return [
            'temperature' => $temperature,
            'top_p' => self::clampTopP((float) ($settings['top_p'] ?? self::TOP_P_DEFAULT)),
            'top_k' => self::clampTopK((int) ($settings['top_k'] ?? self::TOP_K_DEFAULT)),
            'repetition_penalty' => self::clampRepetitionPenalty(
                (float) ($settings['repetition_penalty'] ?? self::REPETITION_PENALTY_DEFAULT),
            ),
        ];
    }
}
