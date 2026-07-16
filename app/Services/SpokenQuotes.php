<?php

namespace App\Services;

/**
 * Voices paired double quotation marks as spoken words — "open quote … close
 * quote" — the way automated news narration announces quoted passages, applied
 * to the text BEFORE it reaches the chunker/TTS engine. Pure (no DB/IO/config)
 * so it is trivially testable and reusable on any code path.
 *
 * Semantics (see docs/SPOKEN-QUOTES.md):
 *  - Double quotes only: " (U+0022), “ (U+201C), ” (U+201D). Single quotes,
 *    apostrophes and guillemets are never touched.
 *  - Curly marks are trusted as written (“ opens, ” closes). A straight " is
 *    classified by its neighbours; one preceded by a digit is treated as an
 *    inches mark (5' 10") and ignored.
 *  - Only marks that PAIR into a resolved quotation are altered. Everything
 *    else — stray, ambiguous, or unresolvable marks and all surrounding text —
 *    is preserved byte for byte.
 *  - Journalistic continuation: a quotation that never closes in its paragraph
 *    continues if (and only if) the very next paragraph re-opens as its first
 *    content. The opening is spoken once at the true start, the re-opening
 *    marks are consumed silently, and the close is spoken at the true end.
 *  - Output is a fixed point of {@see TextNormalizer::normalize()} — the
 *    Genblaze path normalizes AFTER this transform, so the insertions never
 *    produce spacing/punctuation that normalization would rewrite.
 *
 * Paragraph boundaries mirror {@see TextChunker}: blank lines or runs of
 * >= $blockSpaceRun spaces (the Bespoken plugin's flattened block marker).
 */
class SpokenQuotes
{
    public const MODE_OFF = 'off';

    /** Speak "open quote" at the opening mark and "close quote" at the close. */
    public const MODE_OPEN_CLOSE = 'open_close';

    /** Speak "quote" at the opening mark and "close quote" at the close (news-narration style). */
    public const MODE_QUOTE_CLOSE = 'quote_close';

    /** Speak "quote" at the opening mark; the closing mark is consumed silently. */
    public const MODE_OPEN_ONLY = 'open_only';

    private const OPEN = 'open';

    private const CLOSE = 'close';

    private const AMBIGUOUS = 'ambiguous';

    /** Left-neighbour characters (besides whitespace) that still read as "a quote starts here". */
    private const OPENERS_LEFT = ['(', '[', '{', '–', '—', '-'];

    /** Right-neighbour characters (besides whitespace) that still read as "a quote just ended". */
    private const CLOSERS_RIGHT = ['.', ',', ';', ':', '!', '?', '…', ')', ']', '}', '–', '—', '-'];

    /** Sentence punctuation the spoken close is inserted in front of ("done." -> "done, close quote."). */
    private const RUN_PUNCT = ['.', ',', ';', ':', '!', '?', '…'];

    /**
     * @return array{text: string, applied: int}  `applied` = quotations voiced
     */
    public function apply(string $text, string $mode, int $blockSpaceRun = 4): array
    {
        // Unknown modes deliberately fall through to the identity — a bad stored
        // setting must never mangle a writer's text.
        if (! in_array($mode, [self::MODE_OPEN_CLOSE, self::MODE_QUOTE_CLOSE, self::MODE_OPEN_ONLY], true) || $text === '') {
            return ['text' => $text, 'applied' => 0];
        }

        $marks = $this->classifiedMarks($text, $this->paragraphSpans($text, $blockSpaceRun));
        $chains = $this->pair($text, $marks);

        $edits = [];
        foreach ($chains as $chain) {
            $edits[] = $this->openEdit($text, $chain['open'], $mode);
            foreach ($chain['continuations'] as $reopen) {
                // Paragraph-initial by definition, so plain deletion never fuses words.
                $edits[] = ['start' => $reopen['offset'], 'length' => $reopen['bytes'], 'replacement' => ''];
            }
            $edits[] = $this->closeEdit($text, $chain['close'], $mode);
        }

        // Splice right-to-left so earlier offsets never shift.
        usort($edits, fn (array $a, array $b) => $b['start'] <=> $a['start']);
        foreach ($edits as $edit) {
            $text = substr_replace($text, $edit['replacement'], $edit['start'], $edit['length']);
        }

        return ['text' => $text, 'applied' => count($chains)];
    }

    /**
     * Byte ranges of the paragraphs, mirroring TextChunker::blocks() boundaries
     * (blank lines, CRLF-tolerant, or runs of >= $blockSpaceRun spaces) WITHOUT
     * mutating the text — offsets must stay true to the original bytes.
     *
     * @return array<int, array{start: int, end: int}>
     */
    private function paragraphSpans(string $text, int $blockSpaceRun): array
    {
        $run = max(2, $blockSpaceRun);
        preg_match_all(
            '/(?:\r\n|\r|\n)[ \t]*(?:\r\n|\r|\n)+| {'.$run.',}/u',
            $text,
            $boundaries,
            PREG_OFFSET_CAPTURE
        );

        $spans = [];
        $cursor = 0;
        foreach ($boundaries[0] as [$boundary, $offset]) {
            $spans[] = ['start' => $cursor, 'end' => $offset];
            $cursor = $offset + strlen($boundary);
        }
        $spans[] = ['start' => $cursor, 'end' => strlen($text)];

        return $spans;
    }

    /**
     * Locate and classify every double-quote mark.
     *
     * @param  array<int, array{start: int, end: int}>  $spans
     * @return array<int, array{offset: int, bytes: int, type: string, para: int, initial: bool}>
     */
    private function classifiedMarks(string $text, array $spans): array
    {
        preg_match_all('/\x{201C}|\x{201D}|"/u', $text, $found, PREG_OFFSET_CAPTURE);

        $marks = [];
        $para = 0;
        foreach ($found[0] as [$mark, $offset]) {
            // Marks are whitespace-free, so each one sits inside a content span.
            while ($offset >= $spans[$para]['end']) {
                $para++;
            }

            $marks[] = [
                'offset' => $offset,
                'bytes' => strlen($mark),
                'type' => $this->classify($text, $mark, $offset),
                'para' => $para,
                // "Paragraph-initial": nothing but whitespace before it in its paragraph.
                'initial' => trim(substr($text, $spans[$para]['start'], $offset - $spans[$para]['start'])) === '',
            ];
        }

        return $marks;
    }

    private function classify(string $text, string $mark, int $offset): string
    {
        if ($mark === "\u{201C}") {
            return self::OPEN;
        }
        if ($mark === "\u{201D}") {
            // Trusted even after a digit: software that emits ” also emitted a
            // real “ — the inches hazard is only ever a straight mark.
            return self::CLOSE;
        }

        $prev = $this->charBefore($text, $offset);
        $next = $this->charAfter($text, $offset + strlen($mark));

        // The inches heuristic (5' 10"). Costs a genuine straight quote that
        // ends in a digit — its opener never resolves, so the whole quotation
        // is left untouched. Curly quotes handle that case fully.
        if (preg_match('/^[0-9]$/', $prev)) {
            return self::AMBIGUOUS;
        }

        // Left edge looks like a start, content on the right.
        if (($this->isWhitespace($prev) || in_array($prev, self::OPENERS_LEFT, true))
            && $next !== '' && ! $this->isWhitespace($next)) {
            return self::OPEN;
        }

        // Glued to the end of content, boundary on the right.
        if ($prev !== '' && ! $this->isWhitespace($prev)
            && ($this->isWhitespace($next) || in_array($next, self::CLOSERS_RIGHT, true))) {
            return self::CLOSE;
        }

        return self::AMBIGUOUS;
    }

    /**
     * Single-pending pairing scan (double quotes do not nest in practice).
     * Ambiguous marks are invisible — skipped, never a barrier. Anything that
     * cannot be resolved with confidence is abandoned untouched.
     *
     * @param  array<int, array{offset: int, bytes: int, type: string, para: int, initial: bool}>  $marks
     * @return array<int, array{open: array, continuations: array<int, array>, close: array}>
     */
    private function pair(string $text, array $marks): array
    {
        $chains = [];
        $pending = null;

        foreach ($marks as $mark) {
            if ($mark['type'] === self::AMBIGUOUS) {
                continue;
            }

            if ($pending !== null && $mark['para'] !== $pending['para']) {
                // Crossing a paragraph boundary while a quotation is open: the
                // journalistic convention says the VERY NEXT paragraph re-opens
                // as its first content. Anything else breaks the chain, and
                // every mark in it stays exactly as written.
                if ($mark['para'] === $pending['para'] + 1 && $mark['type'] === self::OPEN && $mark['initial']) {
                    $pending['continuations'][] = $mark;
                    $pending['para'] = $mark['para'];

                    continue;
                }
                $pending = null;
            }

            if ($mark['type'] === self::OPEN) {
                // A second open mid-paragraph means the earlier one evidently
                // never closed — abandon it (untouched) and give this mark its
                // own chance to pair.
                $pending = ['open' => $mark, 'continuations' => [], 'para' => $mark['para']];

                continue;
            }

            if ($pending === null) {
                continue; // close with no open — stray, untouched
            }

            $contentStart = $pending['open']['offset'] + $pending['open']['bytes'];
            $content = substr($text, $contentStart, $mark['offset'] - $contentStart);

            // Nothing voiceable between the marks ("" or “...”) — announcing an
            // empty quotation is noise, so leave both marks as written.
            if (! preg_match('/[\p{L}\p{N}]/u', $content)) {
                $pending = null;

                continue;
            }

            $chains[] = ['open' => $pending['open'], 'continuations' => $pending['continuations'], 'close' => $mark];
            $pending = null;
        }

        return $chains;
    }

    /**
     * @param  array{offset: int, bytes: int}  $mark
     * @return array{start: int, length: int, replacement: string}
     */
    private function openEdit(string $text, array $mark, string $mode): array
    {
        $phrase = $mode === self::MODE_OPEN_CLOSE ? 'open quote' : 'quote';
        $prev = $this->charBefore($text, $mark['offset']);
        $next = $this->charAfter($text, $mark['offset'] + $mark['bytes']);

        // said,"Hello -> said, open quote, Hello — and no trailing space when
        // the text already provides one (“ Hello), so runs of spaces (block
        // markers) are never created.
        $replacement = ($this->isWhitespace($prev) ? '' : ' ')
            .$phrase.','
            .($this->isWhitespace($next) ? '' : ' ');

        return ['start' => $mark['offset'], 'length' => $mark['bytes'], 'replacement' => $replacement];
    }

    /**
     * @param  array{offset: int, bytes: int}  $mark
     * @return array{start: int, length: int, replacement: string}
     */
    private function closeEdit(string $text, array $mark, string $mode): array
    {
        // Consume any sentence punctuation glued to the mark's left (done." /
        // world,") plus stray horizontal whitespace before it, so the spoken
        // close lands INSIDE the sentence: done, close quote. — the terminal
        // punctuation stays outermost and the chunker still sees the boundary.
        $runStart = $this->scanBack($text, $mark['offset'], self::RUN_PUNCT);
        $editStart = $this->scanBack($text, $runStart, [' ', "\t", "\u{00A0}"]);
        $run = substr($text, $runStart, $mark['offset'] - $runStart);
        $end = $mark['offset'] + $mark['bytes'];

        if ($mode === self::MODE_OPEN_ONLY) {
            // Silent consume. Keep the punctuation run; drop one neighbouring
            // space when the mark had whitespace on both sides (word ” next).
            $keepFrom = $run === '' && $editStart < $runStart && $this->isWhitespace($this->charAfter($text, $end))
                ? $editStart
                : $runStart;

            return ['start' => $keepFrom, 'length' => $end - $keepFrom, 'replacement' => $run];
        }

        $next = $this->charAfter($text, $end);
        $pad = $next !== '' && ! $this->isWhitespace($next) && ! in_array($next, self::CLOSERS_RIGHT, true)
            ? ' ' // “stop”sign -> stop, close quote sign
            : '';

        return ['start' => $editStart, 'length' => $end - $editStart, 'replacement' => ', close quote'.$run.$pad];
    }

    /** Walk back from $offset over characters in $set; returns where the run starts. */
    private function scanBack(string $text, int $offset, array $set): int
    {
        while ($offset > 0) {
            $char = $this->charBefore($text, $offset);
            if ($char === '' || ! in_array($char, $set, true)) {
                break;
            }
            $offset -= strlen($char);
        }

        return $offset;
    }

    private function charBefore(string $text, int $byteOffset): string
    {
        return $byteOffset > 0 ? mb_substr(substr($text, 0, $byteOffset), -1) : '';
    }

    private function charAfter(string $text, int $byteOffset): string
    {
        return mb_substr(substr($text, $byteOffset), 0, 1);
    }

    /** '' (text edge) counts as whitespace; NBSP explicitly — PCRE \s misses it. */
    private function isWhitespace(string $char): bool
    {
        return $char === '' || $char === "\u{00A0}" || preg_match('/^\s$/u', $char) === 1;
    }
}
