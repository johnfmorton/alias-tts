<?php

namespace App\Services\Tts;

/**
 * The three built-in "Delivery" archetypes surfaced as chips on the Studio
 * takes-&-tuning panel — Steady / Balanced / Expressive. Each maps to a full
 * set of an engine's native knobs, so picking one is the everyday alternative
 * to dragging the raw sliders (which now collapse behind "Fine-tune").
 *
 * This is the single source of truth for those values. The Blade panel renders
 * a chip per archetype carrying these numbers as data-attributes, and the JS
 * both APPLIES them (chip click) and MATCHES against them (dragging a slider
 * off an archetype flips the selection to an implicit "Custom" — no chip lit).
 *
 * `Balanced` is NOT hard-coded — it's read back from {@see ChatterboxTuning}
 * / {@see ChatterboxTurboTuning}'s neutral defaults (resolveNative of an empty
 * settings map), so "Balanced == every slider at neutral" stays true even if a
 * default is ever retuned. Steady/Expressive are deliberate product choices
 * that pull toward focused-consistent vs varied-lively, each move staying well
 * inside the knob's own range (and aligned to its step so the chip re-lights
 * cleanly when re-selected).
 *
 * Native knob keys match the tuning services and the panel's KNOB_INPUTS map:
 *   classic: exaggeration, cfg_weight, temperature
 *   turbo:   top_p, top_k, repetition_penalty, temperature
 */
class DeliveryPresets
{
    /** Archetype order + copy, shared by both engines. */
    private const ARCHETYPES = [
        'steady' => ['label' => 'Steady', 'desc' => 'focused, consistent'],
        'balanced' => ['label' => 'Balanced', 'desc' => 'neutral default'],
        'expressive' => ['label' => 'Expressive', 'desc' => 'varied, lively'],
    ];

    /**
     * Classic Chatterbox knob values per archetype. `balanced` is filled from
     * the neutral defaults at build time (see {@see self::forEngine()}).
     *
     * @var array<string, array<string, float>>
     */
    private const CLASSIC = [
        // Less animated, more measured pacing, steadier sampling.
        'steady' => ['exaggeration' => 0.35, 'cfg_weight' => 0.65, 'temperature' => 0.65],
        // More animated, quicker/looser pacing, livelier sampling.
        'expressive' => ['exaggeration' => 0.85, 'cfg_weight' => 0.40, 'temperature' => 1.00],
    ];

    /**
     * Turbo knob values per archetype. `balanced` filled from neutral defaults.
     *
     * @var array<string, array<string, float|int>>
     */
    private const TURBO = [
        // Narrower sampling pool, a touch more repetition guard, steadier temp.
        // Key order matches ChatterboxTurboTuning::resolveNative (temperature first).
        'steady' => ['temperature' => 0.65, 'top_p' => 0.85, 'top_k' => 500, 'repetition_penalty' => 1.30],
        // Wider pool, looser repetition guard, livelier temp.
        'expressive' => ['temperature' => 1.00, 'top_p' => 1.00, 'top_k' => 1500, 'repetition_penalty' => 1.15],
    ];

    /**
     * The archetypes for one engine, each as an ordered list with its native
     * knob values. Unknown/absent engine falls back to classic (mirrors
     * {@see ModelCatalog::DEFAULT}).
     *
     * @return list<array{key: string, label: string, desc: string, values: array<string, float|int>}>
     */
    public static function forEngine(string $model): array
    {
        // Qwen3 TTS has no numeric knob dialect (language + a free-text style
        // note only) — there are no archetypes to offer, and the chips hide.
        if ($model === 'qwen3-tts') {
            return [];
        }

        $balanced = $model === 'chatterbox-turbo'
            ? ChatterboxTurboTuning::resolveNative([])          // top_p/top_k/repetition_penalty/temperature
            : ChatterboxTuning::resolveNative([]) + [           // exaggeration/cfg_weight …
                'temperature' => ChatterboxTuning::TEMPERATURE_DEFAULT, // … + shared temp
            ];

        $table = $model === 'chatterbox-turbo' ? self::TURBO : self::CLASSIC;

        return array_map(function (string $key) use ($table, $balanced) {
            $meta = self::ARCHETYPES[$key];

            return [
                'key' => $key,
                'label' => $meta['label'],
                'desc' => $meta['desc'],
                'values' => $key === 'balanced' ? $balanced : $table[$key],
            ];
        }, array_keys(self::ARCHETYPES));
    }

    /**
     * Every engine's archetypes, keyed by model — the shape the Studio editor
     * stashes as JSON so the JS can re-point the chips when a chunk's voice
     * (and thus engine) changes.
     *
     * @return array<string, list<array{key: string, label: string, desc: string, values: array<string, float|int>}>>
     */
    public static function all(): array
    {
        // Explicit keys, not ModelCatalog::keys() — this class stays
        // container-free (config() needs the app) so it unit-tests bare.
        return [
            'chatterbox' => self::forEngine('chatterbox'),
            'chatterbox-turbo' => self::forEngine('chatterbox-turbo'),
            'qwen3-tts' => self::forEngine('qwen3-tts'),
        ];
    }
}
