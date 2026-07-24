<?php

namespace App\Services\Tts;

use App\Models\Voice;

/**
 * Single source of truth for the TTS engines the app can drive (config
 * `tts.models`). A voice picks its engine via `voices.model` (null = the
 * default, chatterbox); the choice reaches the provider as the RESERVED
 * settings key `model`, stamped by {@see self::stamp()} at the chokepoints
 * that feed every synthesize call.
 *
 * `stamp()` deliberately OMITS the key for the default engine so the settings
 * maps (and therefore SpeechService's cache hashes and every stored settings
 * JSON) stay byte-identical for existing chatterbox voices — flipping that
 * would regenerate every cached speech once.
 */
final class ModelCatalog
{
    public const DEFAULT = 'chatterbox';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return (array) config('tts.models', []);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function isKnown(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::all());
    }

    /**
     * The catalog entry for a model key; unknown/null keys resolve to the
     * default so a stale `voices.model` value can never break generation.
     *
     * @return array<string, mixed>
     */
    public static function get(?string $key): array
    {
        $models = self::all();

        return $models[$key] ?? $models[self::DEFAULT] ?? [];
    }

    /** The engine a voice generates with (null/unknown = default). */
    public static function forVoice(?Voice $voice): string
    {
        $key = $voice?->model;

        return self::isKnown($key) ? $key : self::DEFAULT;
    }

    public static function label(?string $key): string
    {
        return (string) (self::get($key)['label'] ?? ucfirst((string) $key));
    }

    /**
     * One line naming what an engine trades — shown under its name in the
     * voice-source engine picker. Blank when the catalog entry omits it.
     */
    public static function tagline(?string $key): string
    {
        return (string) (self::get($key)['tagline'] ?? '');
    }

    /** Per-call input cap in characters; 0 = uncapped. */
    public static function maxInputChars(?string $key): int
    {
        return max(0, (int) (self::get($key)['max_input_chars'] ?? 0));
    }

    /** @return list<string> */
    public static function presetVoices(?string $key): array
    {
        return array_values((array) (self::get($key)['preset_voices'] ?? []));
    }

    /** USD per 1,000 input characters for one model. */
    public static function costPer1k(?string $key): float
    {
        return max(0.0, (float) (self::get($key)['cost_per_1k_chars'] ?? 0));
    }

    /** Minimum reference-clip length the model accepts, in seconds (0 = none). */
    public static function minReferenceSeconds(?string $key): float
    {
        return max(0.0, (float) (self::get($key)['min_reference_seconds'] ?? 0));
    }

    public static function supportsTags(?string $key): bool
    {
        return (bool) (self::get($key)['supports_tags'] ?? false);
    }

    /** Whether the engine's input schema has a seed field (qwen has none). */
    public static function supportsSeed(?string $key): bool
    {
        return (bool) (self::get($key)['supports_seed'] ?? true);
    }

    /**
     * Whether the local Chatterbox sidecar can run this engine. Engines that
     * can't (qwen) render via Replicate even under TTS_PROVIDER=local — see
     * {@see HybridTtsProvider}.
     */
    public static function localCapable(?string $key): bool
    {
        return (bool) (self::get($key)['local_capable'] ?? true);
    }

    /** Whether the engine's clone mode accepts the clip's transcript. */
    public static function acceptsReferenceText(?string $key): bool
    {
        return (bool) (self::get($key)['accepts_reference_text'] ?? false);
    }

    /**
     * Stamp the resolved settings map with the effective engine for this
     * voice (or an explicit per-request override, e.g. the OpenAI dialect's
     * `model` field). Must run AFTER VoiceSettingsResolver — the resolver
     * whitelists tuning keys and would drop the reserved ones.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function stamp(array $settings, ?Voice $voice, ?string $override = null): array
    {
        $key = self::isKnown($override) ? $override : self::forVoice($voice);

        if ($key === self::DEFAULT) {
            return $settings;
        }

        $settings['model'] = $key;

        // A clip-less voice on a preset-bearing engine speaks through one of
        // the model's built-in voices; the provider ignores this whenever a
        // reference clip exists.
        $preset = $voice?->settings['preset_voice'] ?? null;
        if (is_string($preset) && $preset !== '' && in_array($preset, self::presetVoices($key), true)) {
            $settings['voice_preset'] = $preset;
        }

        // A cloning engine that accepts the clip's transcript (qwen's
        // reference_text) gets it stamped so it reaches the provider through
        // every chokepoint — and enters the cache hash, so editing the
        // transcript regenerates.
        if (self::acceptsReferenceText($key) && $voice?->reference_audio_path) {
            $referenceText = trim((string) ($voice->settings['reference_text'] ?? ''));
            if ($referenceText !== '') {
                $settings['reference_text'] = $referenceText;
            }
        }

        return $settings;
    }
}
