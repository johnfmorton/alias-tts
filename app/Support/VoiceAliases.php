<?php

namespace App\Support;

/**
 * Operator-configured voice alias maps for the public /v1 API dialects.
 *
 * Pure client-ID -> Alias-slug remapping, applied BEFORE Voice::resolveFor().
 * Deliberately not baked into the Voice model, so Studio/admin pages and the
 * internal pipeline are never affected. Single pass — an alias's output is
 * never re-aliased. Unlisted values pass through unchanged, and 404 messages
 * must keep echoing the original client value, never the alias target.
 */
class VoiceAliases
{
    /**
     * ElevenLabs dialect ({voice_id} path segment / voice_id body field).
     * EXACT, case-sensitive match: ElevenLabs voice IDs are case-sensitive
     * alphanumerics ('21m00Tcm4TlvDq8ikWAM'), and lowercasing before the
     * passthrough would corrupt mixed-case slugs and UUIDs.
     */
    public static function elevenLabs(string $voiceId): string
    {
        $aliases = (array) config('tts.elevenlabs_voice_aliases', []);

        return (string) ($aliases[$voiceId] ?? $voiceId);
    }

    /**
     * OpenAI dialect (`voice` body field). Case-INSENSITIVE: the alias keys
     * are OpenAI's fixed lowercase preset names (alloy, nova, …), which
     * clients sometimes send capitalized.
     */
    public static function openAi(string $voice): string
    {
        $aliases = (array) config('tts.openai_voice_aliases', []);

        return (string) ($aliases[strtolower($voice)] ?? $voice);
    }
}
