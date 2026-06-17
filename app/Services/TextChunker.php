<?php

namespace App\Services;

/**
 * Splits text into backend-friendly chunks. Chatterbox is short-form, so long
 * text must be broken into ~sentence-sized pieces, generated separately, and
 * concatenated. Splits on sentence boundaries, greedily packing up to a target
 * character budget; an over-long sentence falls back to clause splitting, then a
 * hard word-wrap.
 */
class TextChunker
{
    /**
     * @return array<int, string>
     */
    public function split(string $text, int $targetChars = 280): array
    {
        $targetChars = max(1, $targetChars);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $targetChars) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach ($this->sentences($text) as $sentence) {
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
