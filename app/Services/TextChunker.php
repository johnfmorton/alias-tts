<?php

namespace App\Services;

/**
 * Splits text into backend-friendly chunks. Chatterbox is short-form, so long
 * text must be broken into ~sentence-sized pieces, generated separately, and
 * concatenated.
 *
 * Text is first split into blocks (paragraphs / headings / list items), then
 * each block is packed into chunks on sentence boundaries up to a character
 * budget; an over-long sentence falls back to clause splitting, then a hard
 * word-wrap. {@see self::segment()} tags each chunk with the kind of pause that
 * should follow it so the audio layer can insert a longer silence at block
 * (paragraph) seams than at sentence seams.
 *
 * Blocks are detected from blank lines OR runs of spaces: the Bespoken Craft
 * plugin currently flattens a post to a single line and encodes every block
 * boundary as a run of (4) spaces rather than a newline, so the run-of-spaces
 * rule is what recovers paragraph structure from real traffic. Each block is
 * also cleaned of stray spacing the plugin emits (" ." -> ".", "word. ." ->
 * "word.") which otherwise reaches Chatterbox as awkward pauses / near-empty
 * fragments and amplifies its tendency to hallucinate noise.
 */
class TextChunker
{
    /**
     * Back-compat helper returning just the chunk strings (no pause tags).
     *
     * @return array<int, string>
     */
    public function split(string $text, int $targetChars = 280): array
    {
        return array_map(static fn ($c) => $c['text'], $this->segment($text, $targetChars));
    }

    /**
     * Split text into chunks, each tagged with the pause that should follow it:
     * 'paragraph' after the last chunk of a block (when another block follows),
     * 'sentence' otherwise. The tag after the final chunk is unused.
     *
     * @return array<int, array{text: string, breakAfter: 'sentence'|'paragraph'}>
     */
    public function segment(string $text, int $targetChars = 280, int $blockSpaceRun = 4): array
    {
        $targetChars = max(1, $targetChars);

        $blocks = $this->blocks($text, $blockSpaceRun);
        if ($blocks === []) {
            return [];
        }

        $segments = [];
        $lastBlock = count($blocks) - 1;

        foreach ($blocks as $bi => $block) {
            $chunks = $this->packBlock($block, $targetChars);
            $lastChunk = count($chunks) - 1;

            foreach ($chunks as $ci => $chunk) {
                // A block's final chunk ends a paragraph; otherwise it's a
                // sentence seam within the block.
                $breakAfter = ($ci === $lastChunk && $bi !== $lastBlock) ? 'paragraph' : 'sentence';
                $segments[] = ['text' => $chunk, 'breakAfter' => $breakAfter];
            }
        }

        return $segments;
    }

    /**
     * Split text into cleaned, non-empty blocks. Boundaries are blank lines or
     * runs of >= $blockSpaceRun spaces (the plugin's block marker).
     *
     * @return array<int, string>
     */
    private function blocks(string $text, int $blockSpaceRun): array
    {
        $run = max(2, $blockSpaceRun);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $parts = preg_split('/\n[ \t]*\n+| {'.$run.',}/u', $text) ?: [$text];

        $blocks = [];
        foreach ($parts as $part) {
            $clean = $this->normalizeBlock($part);
            if ($clean !== '') {
                $blocks[] = $clean;
            }
        }

        return $blocks;
    }

    /**
     * Clean a single block's stray spacing before chunking.
     */
    private function normalizeBlock(string $block): string
    {
        // Collapse spaced double-terminators ("word. ." -> "word."), leaving
        // ellipses ("...", no internal whitespace) untouched.
        $block = (string) preg_replace('/([.!?])(\s+[.!?])+/u', '$1', $block);

        // Drop whitespace before punctuation ("editor ." -> "editor.").
        $block = (string) preg_replace('/\s+([.,;:!?])/u', '$1', $block);

        // Collapse remaining whitespace (incl. soft newlines) to single spaces.
        return trim((string) preg_replace('/\s+/u', ' ', $block));
    }

    /**
     * Greedily pack a cleaned block into chunks no longer than $targetChars,
     * splitting on sentence boundaries.
     *
     * @return array<int, string>
     */
    private function packBlock(string $block, int $targetChars): array
    {
        if (mb_strlen($block) <= $targetChars) {
            return [$block];
        }

        $chunks = [];
        $current = '';

        foreach ($this->sentences($block) as $sentence) {
            if (mb_strlen($sentence) > $targetChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->splitLong($sentence, $targetChars) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            $candidate = $current === '' ? $sentence : $current.' '.$sentence;
            if (mb_strlen($candidate) > $targetChars) {
                $chunks[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, static fn ($c) => trim($c) !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function sentences(string $text): array
    {
        // Split after . ! ? (plus any trailing quotes/brackets) followed by space.
        $parts = preg_split('/(?<=[.!?])["\')\]]*\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts ?: [$text];
    }

    /**
     * Break an over-long sentence on clause boundaries, then hard-wrap by words.
     *
     * @return array<int, string>
     */
    private function splitLong(string $sentence, int $targetChars): array
    {
        $clauses = preg_split('/(?<=[,;:])\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [$sentence];

        return $this->pack($clauses, $targetChars, fn ($clause) => $this->hardWrap($clause, $targetChars));
    }

    /**
     * Last resort: wrap by words up to the target length.
     *
     * @return array<int, string>
     */
    private function hardWrap(string $text, int $targetChars): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];

        return $this->pack($words, $targetChars, static fn ($word) => [$word]);
    }

    /**
     * Greedily pack pieces into chunks no longer than $targetChars. Pieces that
     * are themselves too long are handed to $overflow.
     *
     * @param  array<int, string>  $pieces
     * @param  callable(string): array<int, string>  $overflow
     * @return array<int, string>
     */
    private function pack(array $pieces, int $targetChars, callable $overflow): array
    {
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            if (mb_strlen($piece) > $targetChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($overflow($piece) as $sub) {
                    $chunks[] = $sub;
                }

                continue;
            }

            $candidate = $current === '' ? $piece : $current.' '.$piece;
            if (mb_strlen($candidate) > $targetChars && $current !== '') {
                $chunks[] = $current;
                $current = $piece;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
