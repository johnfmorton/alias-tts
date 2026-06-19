<?php

namespace App\Services;

/**
 * Cleans raw pasted text the way the Bespoken Craft plugin cleans a post before
 * it ever reaches the chunker — so the Studio page (and any consumer) can be fed
 * arbitrary text and reproduce what the plugin actually sends to the TTS API.
 *
 * This is a port of the character-level cleanups in the plugin's
 * BespokenController::actionProcessText() (decode entities, drop non-breaking
 * spaces, strip angle brackets and emoji, tidy punctuation spacing). It is
 * deliberately STRUCTURE-PRESERVING: unlike the plugin — which flattens an
 * entire post to one line — this never collapses newlines or the runs of spaces
 * the plugin uses to mark block boundaries. {@see TextChunker} owns block
 * detection and the per-block whitespace collapse, so preserving those
 * boundaries keeps paragraph seams intact and avoids double-flattening.
 *
 * The plugin's pronunciation-rule step is intentionally omitted: those rules
 * live in Craft, not in this service.
 *
 * @see \App\Services\TextChunker
 */
class TextNormalizer
{
    /** Unicode ranges + joiners that make up emoji / pictographic symbols. */
    private const EMOJI_PATTERN = '/['
        .'\x{1F300}-\x{1FAFF}'  // Misc/Supplemental Symbols & Pictographs, Emoticons, Transport, Extended-A
        .'\x{1F000}-\x{1F0FF}'  // Mahjong, Dominoes, Playing Cards
        .'\x{1F100}-\x{1F2FF}'  // Enclosed Alphanumeric & Ideographic Supplement
        .'\x{2600}-\x{27BF}'    // Misc Symbols & Dingbats
        .'\x{2B00}-\x{2BFF}'    // Misc Symbols & Arrows
        .'\x{2300}-\x{23FF}'    // Misc Technical (⏰, ⌚, ▶, etc.)
        .'\x{2190}-\x{21FF}'    // Arrows
        .'\x{FE00}-\x{FE0F}'    // Variation Selectors
        .'\x{1F1E6}-\x{1F1FF}'  // Regional Indicator Symbols (flags)
        .'\x{200D}'             // Zero Width Joiner
        .'\x{20E3}'             // Combining Enclosing Keycap
        .']/u';

    /**
     * Apply the cleanup pipeline. Idempotent: running it on already-normalized
     * text is a no-op, so it is safe to call on text that may have passed through
     * the plugin already.
     */
    public function normalize(string $text): string
    {
        // 1. Decode HTML entities so later rules match real characters
        //    (e.g. CKEditor encodes "->" as "-&amp;gt;").
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Non-breaking spaces (U+00A0) → regular spaces. \s does not match
        //    U+00A0 under PCRE, so the chunker would otherwise leave them in.
        $text = str_replace("\u{00A0}", ' ', $text);

        // 3. Strip angle brackets (literal and any surviving entities). A stray
        //    "<...>" is read as SSML/XML by the TTS engines, silently swallowing
        //    the wrapped content.
        $text = str_replace(['&lt;', '&gt;', '<', '>'], ' ', $text);

        // 4. Strip emoji / pictographic symbols — the TTS engines choke on them
        //    and a single stray emoji can corrupt the generated audio.
        $text = (string) preg_replace(self::EMOJI_PATTERN, '', $text);

        // 5. Drop horizontal whitespace left *before* end-of-token punctuation
        //    ("editor ." -> "editor."). Restricted to horizontal whitespace
        //    ([^\S\n]) so newlines / block boundaries survive; the (?=\s|$) guard
        //    leaves dot-prefixed tokens (".NET", ".gitignore") untouched.
        $text = (string) preg_replace('/[^\S\n]+([.,;:!?])(?=\s|$)/u', '$1', $text);

        // 6. Collapse spurious doubled punctuation, again only across horizontal
        //    whitespace so paragraph breaks are never bridged:
        //    a) soft punctuation then an appended period -> single period
        //       ("videos:." -> "videos.").
        $text = (string) preg_replace('/[,;:]+[^\S\n]*\.(?=\s|$)/u', '.', $text);
        //    b) "!"/"?" then an appended period -> keep the original mark
        //       ("Really?." -> "Really?").
        $text = (string) preg_replace('/([!?])[^\S\n]*\.(?=\s|$)/u', '$1', $text);
        //    c) period then an appended period -> single period, leaving a real
        //       ellipsis ("...") untouched via the negative lookbehind.
        $text = (string) preg_replace('/(?<!\.)\.[^\S\n]*\.(?=\s|$)/u', '.', $text);

        return trim($text);
    }
}
