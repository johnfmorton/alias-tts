<?php

namespace App\Services\Tts;

/**
 * Qwen3 TTS's knob dialect. Unlike both Chatterbox dialects it has NO numeric
 * sampling knobs — its only per-render controls are `language` (an exact enum,
 * 'auto' detects) and `style_instruction` (a free-text delivery note like
 * "speak slowly and calmly").
 *
 * Every other knob a settings map may carry — the ElevenLabs-style keys the
 * public /v1 API speaks AND both Chatterbox dialects' native keys — is accepted
 * and ignored, the same contract turbo has for `style`/`similarity_boost`, so
 * no /v1 request can ever error over a knob mismatch.
 *
 * resolveNative() emits ONLY schema-valid qwen input keys, and omits each one
 * at its default ('auto' language, blank style) to keep the payload minimal —
 * qwen validates its input, so nothing foreign may leak through.
 */
class Qwen3TtsTuning
{
    public const LANGUAGES = [
        'auto', 'Chinese', 'English', 'Japanese', 'Korean', 'French', 'German',
        'Italian', 'Spanish', 'Portuguese', 'Russian',
    ];

    public const LANGUAGE_DEFAULT = 'auto';

    /** No documented schema cap; bounds the payload and the UI field alike. */
    public const STYLE_INSTRUCTION_MAX = 500;

    /** Exact enum match or the auto default — qwen rejects unknown values. */
    public static function clampLanguage(?string $language): string
    {
        return in_array($language, self::LANGUAGES, true) ? $language : self::LANGUAGE_DEFAULT;
    }

    /** Trimmed and capped; blank collapses to null (= omit from the input). */
    public static function cleanStyleInstruction(?string $instruction): ?string
    {
        $instruction = trim((string) $instruction);

        if ($instruction === '') {
            return null;
        }

        return mb_substr($instruction, 0, self::STYLE_INSTRUCTION_MAX);
    }

    /**
     * Resolve any settings map to the qwen input keys it implies — and nothing
     * else. Defaults are expressed by omission.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public static function resolveNative(array $settings): array
    {
        $input = [];

        $language = self::clampLanguage(
            isset($settings['language']) ? (string) $settings['language'] : null,
        );
        if ($language !== self::LANGUAGE_DEFAULT) {
            $input['language'] = $language;
        }

        $style = self::cleanStyleInstruction(
            isset($settings['style_instruction']) ? (string) $settings['style_instruction'] : null,
        );
        if ($style !== null) {
            $input['style_instruction'] = $style;
        }

        return $input;
    }
}
