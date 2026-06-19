<?php

namespace App\Services\Tts;

use App\Models\Voice;

/**
 * Resolves the effective voice-generation settings for one request by layering,
 * lowest to highest precedence:
 *
 *   1. system defaults    — config('tts.default_voice_settings')
 *   2. voice defaults      — $voice->settings (the tunable keys only)
 *   3. explicit overrides  — per-request / per-project values the caller set
 *
 * This is the single resolution chain shared by the public /v1 API, the Studio
 * inspector, and Studio projects, so a voice tuned in one place sounds the same
 * everywhere. See docs/STUDIO-TUNING.md.
 *
 * `seed` is deliberately NOT handled here: it lives in its own slot (a column on
 * projects, a separate argument on {@see \App\Services\SpeechService}) and has
 * its own voice-default fallback, so this resolver only owns the voice_settings
 * map and ignores any `seed` key it is handed.
 */
class VoiceSettingsResolver
{
    /** The tunable keys this resolver owns. `seed` is handled outside it. */
    private const KEYS = ['stability', 'similarity_boost', 'style', 'use_speaker_boost'];

    /**
     * @param  array<string, mixed>  $overrides  values the caller explicitly set; only known keys apply
     * @return array<string, mixed>
     */
    public function resolve(Voice $voice, array $overrides = []): array
    {
        $settings = $this->only((array) config('tts.default_voice_settings', []));

        if (is_array($voice->settings)) {
            $settings = array_merge($settings, $this->only($voice->settings));
        }

        $settings = array_merge($settings, $this->only($overrides));

        return $this->cast($settings);
    }

    /**
     * Keep only the tunable keys — drops `seed` (handled elsewhere) and any
     * unrelated keys a caller or a voice row might carry.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function only(array $values): array
    {
        return array_intersect_key($values, array_flip(self::KEYS));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function cast(array $settings): array
    {
        foreach (['stability', 'similarity_boost', 'style'] as $key) {
            if (isset($settings[$key])) {
                $settings[$key] = (float) $settings[$key];
            }
        }

        if (isset($settings['use_speaker_boost'])) {
            $settings['use_speaker_boost'] = (bool) $settings['use_speaker_boost'];
        }

        return $settings;
    }
}
