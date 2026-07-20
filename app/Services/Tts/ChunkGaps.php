<?php

namespace App\Services\Tts;

use App\Services\TextChunker;

/**
 * Resolves the two seam gaps (ms) inserted when a multi-chunk render is stitched
 * back into one file: the pause at a sentence seam and the (longer) pause at a
 * block/paragraph seam. Centralised here because five call sites — the /v1 API
 * render (SpeechService), the Studio render + upload-concat (StudioController),
 * the internal concat endpoint (PipelineController) and the project rebuild
 * (ProjectService) — must all pace their seams identically.
 *
 * Precedence, per user (config() already reflects the user's settings by the
 * time these render paths run — see SettingsManager::applyForUser):
 *
 *   - A per-user "Pause between sentences / paragraphs" > 0 is an explicit
 *     choice and wins outright, in either chunking mode.
 *   - Otherwise the pause is "Auto" (the stored 0 sentinel):
 *       · paragraph seam → tts.paragraph_gap_ms (400 ms base).
 *       · sentence seam  → tts.sentence_gap_ms (200 ms) when chunking PER
 *         SENTENCE, else tts.chunk_gap_ms (120 ms). Per-sentence chunking makes
 *         every sentence boundary a hard seam, so Auto gives it a little more
 *         air; packed mode keeps the tight default.
 *
 * The mode-aware branch reads config('tts.chunk_mode') by default, which matches
 * how each render path already chunked its text; a caller that chunked with an
 * explicit mode can pass it to keep the pause in step.
 *
 * A third, smaller gap — tts.continuation_gap_ms — paces a MID-SENTENCE seam: a
 * long sentence split across chunks, or a block boundary the reflowed text no
 * longer reflects (a bulleted list rewritten into running prose). {@see seamGap}
 * applies it whenever the chunk's stored break says 'continuation' OR its FINAL
 * text reads as a continuation (TextChunker::isContinuation) — the latter catches
 * older chunks whose text was reshaped after their break was assigned.
 */
final class ChunkGaps
{
    /**
     * The silence (ms) for one seam, given the chunk's stored break and — when
     * available — its text and the next chunk's text. Reading the final text lets
     * a chunk reshaped after chunking (its colon dropped, its list flattened) still
     * be paced as the continuation it now is, regardless of a stale stored break.
     */
    public static function seamGap(string $breakAfter, string $prevText = '', string $nextText = '', ?string $chunkMode = null): int
    {
        [$sentence, $paragraph] = self::resolve($chunkMode);

        if ($breakAfter === 'continuation' || TextChunker::isContinuation($prevText, $nextText)) {
            return (int) config('tts.continuation_gap_ms', 50);
        }

        return $breakAfter === 'paragraph' ? $paragraph : $sentence;
    }

    /**
     * @return array{0: int, 1: int} [sentenceGapMs, paragraphGapMs]
     */
    public static function resolve(?string $chunkMode = null): array
    {
        $chunkMode ??= (string) config('tts.chunk_mode', TextChunker::MODE_PACKED);

        $sentenceOverride = (int) config('tts.sentence_gap_override_ms', 0);
        $sentence = $sentenceOverride > 0
            ? $sentenceOverride
            : ($chunkMode === TextChunker::MODE_SENTENCE
                ? (int) config('tts.sentence_gap_ms', 200)
                : (int) config('tts.chunk_gap_ms', 120));

        $paragraphOverride = (int) config('tts.paragraph_gap_override_ms', 0);
        $paragraph = $paragraphOverride > 0
            ? $paragraphOverride
            : (int) config('tts.paragraph_gap_ms', 400);

        return [$sentence, $paragraph];
    }
}
