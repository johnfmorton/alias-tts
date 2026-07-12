<?php

namespace App\Services\Tts;

/**
 * The paralinguistic sound tags Chatterbox Turbo renders as nonspeech audio
 * inside the text (e.g. "That's hilarious! [chuckle]"). Two consumers:
 *
 *  - the provider STRIPS known tags from classic-chatterbox payloads (it
 *    would read them aloud as words) while turbo payloads keep them;
 *  - ASR QA strips them from the EXPECTED text before scoring, since a tag
 *    never appears as its word in a transcript (turbo renders a sound,
 *    classic never received it) and would otherwise flag a truncation.
 *
 * Chunk text on screen and in receipts is never touched — only outgoing
 * payloads and QA expectations.
 */
final class ParalinguisticTags
{
    /** @var list<string> */
    public const TAGS = [
        'clear throat', 'sigh', 'sush', 'cough', 'groan',
        'sniff', 'gasp', 'chuckle', 'laugh',
    ];

    private static ?string $pattern = null;

    private static function pattern(): string
    {
        return self::$pattern ??= '/\[(?:'.implode('|', array_map(
            fn (string $tag) => preg_quote($tag, '/'),
            self::TAGS,
        )).')\]/i';
    }

    public static function has(string $text): bool
    {
        return (bool) preg_match(self::pattern(), $text);
    }

    /** Remove known tags, collapsing the space they leave behind. */
    public static function strip(string $text): string
    {
        $stripped = (string) preg_replace(self::pattern(), '', $text);
        // Tidy doubled spaces and space-before-punctuation left by a removal.
        $stripped = (string) preg_replace('/[^\S\n]{2,}/', ' ', $stripped);
        $stripped = (string) preg_replace('/[^\S\n]+([.,;:!?])/', '$1', $stripped);

        return trim($stripped);
    }
}
