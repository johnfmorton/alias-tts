<?php

namespace Tests\Unit;

use App\Services\TextChunker;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_short_text_is_a_single_chunk(): void
    {
        $this->assertSame(['Hello world.'], (new TextChunker)->split('Hello world.', 280));
    }

    public function test_long_text_splits_into_bounded_chunks(): void
    {
        $text = str_repeat('This is a sentence that is reasonably long and clear. ', 20);
        $chunks = (new TextChunker)->split($text, 120);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(120, mb_strlen($chunk));
        }
    }

    public function test_oversized_sentence_is_hard_wrapped(): void
    {
        $text = trim(str_repeat('word ', 120)); // ~599 chars, no sentence punctuation
        $chunks = (new TextChunker)->split($text, 80);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(80, mb_strlen($chunk));
        }
    }

    public function test_blank_text_returns_no_chunks(): void
    {
        $this->assertSame([], (new TextChunker)->split('   ', 280));
    }

    public function test_segment_splits_blocks_on_space_runs_and_tags_breaks(): void
    {
        // The Bespoken plugin flattens posts to one line, marking blocks with
        // 4-space runs. Sentences within a block keep a single space.
        $text = 'First block sentence one. Second sentence here.    Next block starts here.';
        $segments = (new TextChunker)->segment($text, 280);

        $this->assertCount(2, $segments);
        $this->assertSame('First block sentence one. Second sentence here.', $segments[0]['text']);
        $this->assertSame('paragraph', $segments[0]['breakAfter']);
        $this->assertSame('Next block starts here.', $segments[1]['text']);
    }

    public function test_segment_normalizes_stray_spacing_and_keeps_ellipsis(): void
    {
        // " ." and "word. ." are plugin artifacts; "..." is a real ellipsis.
        $text = 'You will not miss it. .    I use the editor .    Done...';
        $texts = array_map(
            static fn ($s) => $s['text'],
            (new TextChunker)->segment($text, 280)
        );

        $this->assertSame([
            'You will not miss it.',
            'I use the editor.',
            'Done...',
        ], $texts);
    }

    public function test_segment_tags_sentence_seams_within_a_block(): void
    {
        // A single block long enough to span multiple chunks: every seam is a
        // sentence seam (no paragraph break inside one block).
        $text = str_repeat('This is a sentence that is reasonably long and clear. ', 10);
        $segments = (new TextChunker)->segment($text, 120);

        $this->assertGreaterThan(1, count($segments));
        foreach ($segments as $segment) {
            $this->assertSame('sentence', $segment['breakAfter']);
            $this->assertLessThanOrEqual(120, mb_strlen($segment['text']));
        }
    }

    public function test_segment_merges_short_chunk_forward_into_following_block(): void
    {
        // A short heading block ("The to-do list.", 15 chars) would be dropped by
        // Chatterbox if synthesized alone; it merges into the following block.
        $text = "You will not miss Redactor here today.\n\nThe to-do list.\n\nI have a routine set of tasks each time I use the editor.";
        $segments = (new TextChunker)->segment($text, 280, 4, 30);
        $texts = array_map(static fn ($s) => $s['text'], $segments);

        $this->assertSame([
            'You will not miss Redactor here today.',
            'The to-do list. I have a routine set of tasks each time I use the editor.',
        ], $texts);
        // The pause before the short line (previous block's seam) is preserved.
        $this->assertSame('paragraph', $segments[0]['breakAfter']);
    }

    public function test_segment_merges_short_final_chunk_backward(): void
    {
        // A short final chunk has no following block, so it folds back.
        $text = "This is a complete sentence that runs plenty long.\n\nOk.";
        $segments = (new TextChunker)->segment($text, 280, 4, 30);

        $this->assertSame(
            ['This is a complete sentence that runs plenty long. Ok.'],
            array_map(static fn ($s) => $s['text'], $segments)
        );
    }

    public function test_segment_does_not_merge_when_min_chars_is_zero(): void
    {
        // Default (0) keeps short standalone blocks as-is.
        $text = "You will not miss Redactor here today.\n\nThe to-do list.\n\nI have a routine set of tasks each time I use the editor.";
        $segments = (new TextChunker)->segment($text, 280, 4, 0);

        $this->assertCount(3, $segments);
        $this->assertSame('The to-do list.', $segments[1]['text']);
    }

    public function test_short_trailing_sentence_is_lifted_to_next_chunk(): void
    {
        // "Why?" would end the first chunk; lift it to lead the second instead.
        // Chunk budget forces a split before the final long sentence.
        $text = 'This first sentence is comfortably long on its own. Why? '
            .'This is the following long sentence that starts a new chunk.';
        $segments = (new TextChunker)->segment($text, 60, 4, 0, 3);
        $texts = array_map(static fn ($s) => $s['text'], $segments);

        $this->assertCount(2, $segments);
        $this->assertSame('This first sentence is comfortably long on its own.', $texts[0]);
        $this->assertStringStartsWith('Why?', $texts[1]);
    }

    public function test_short_trailer_is_not_lifted_across_a_paragraph_break(): void
    {
        // A short sentence that ends a paragraph genuinely belongs to it; moving
        // it would change meaning and cross a block seam, so it stays put.
        $text = "This sentence comfortably ends the first paragraph. Why?\n\nThis sentence opens the second paragraph here.";
        $segments = (new TextChunker)->segment($text, 280, 4, 0, 3);

        $this->assertCount(2, $segments);
        $this->assertSame('paragraph', $segments[0]['breakAfter']);
        $this->assertStringEndsWith('Why?', $segments[0]['text']);
    }

    public function test_short_trailer_is_kept_when_it_is_the_final_chunk(): void
    {
        // Nothing follows the last chunk, so a trailing short sentence has nowhere
        // to go and is left in place.
        $text = 'This long opening sentence carries the chunk on its own. Why?';
        $segments = (new TextChunker)->segment($text, 280, 4, 0, 3);

        $this->assertCount(1, $segments);
        $this->assertStringEndsWith('Why?', $segments[0]['text']);
    }

    public function test_lifting_is_disabled_by_default(): void
    {
        // Without the 5th arg the trailing short sentence stays where greedy
        // packing put it (back-compat with existing callers/tests).
        $text = 'This first sentence is comfortably long on its own. Why? '
            .'This is the following long sentence that starts a new chunk.';
        $segments = (new TextChunker)->segment($text, 60);

        $this->assertStringEndsWith('Why?', $segments[0]['text']);
    }

    public function test_sentence_mode_gives_each_sentence_its_own_chunk(): void
    {
        // Packed mode would fit all three sentences in one 280-char chunk;
        // sentence mode keeps them separate.
        $text = 'This is the first full sentence of the block. '
            .'This is the second full sentence of the block. '
            .'This is the third full sentence of the block.';
        $segments = (new TextChunker)->segment($text, 280, 4, 0, 0, TextChunker::MODE_SENTENCE);

        $this->assertSame([
            'This is the first full sentence of the block.',
            'This is the second full sentence of the block.',
            'This is the third full sentence of the block.',
        ], array_map(static fn ($s) => $s['text'], $segments));
        $this->assertSame('sentence', $segments[0]['breakAfter']);
    }

    public function test_sentence_mode_tags_paragraph_seams_between_blocks(): void
    {
        $text = "First sentence of the opening block. Second sentence of the opening block.\n\nThe next block opens right here.";
        $segments = (new TextChunker)->segment($text, 280, 4, 0, 0, TextChunker::MODE_SENTENCE);

        $this->assertCount(3, $segments);
        $this->assertSame('sentence', $segments[0]['breakAfter']);
        $this->assertSame('paragraph', $segments[1]['breakAfter']);
    }

    public function test_sentence_mode_still_splits_an_oversized_sentence(): void
    {
        $text = trim(str_repeat('word ', 40)); // ~199 chars, no sentence punctuation
        $segments = (new TextChunker)->segment($text, 80, 4, 0, 0, TextChunker::MODE_SENTENCE);

        $this->assertGreaterThan(1, count($segments));
        foreach ($segments as $segment) {
            $this->assertLessThanOrEqual(80, mb_strlen($segment['text']));
        }
    }

    public function test_sentence_mode_still_merges_very_short_sentences(): void
    {
        // A bare "Why?" is exactly the input Chatterbox garbles, so the
        // min-chars merge guard applies in sentence mode too.
        $text = 'This opening sentence is comfortably long on its own. Why? '
            .'This closing sentence is also comfortably long here.';
        $segments = (new TextChunker)->segment($text, 280, 4, 30, 0, TextChunker::MODE_SENTENCE);

        $this->assertSame([
            'This opening sentence is comfortably long on its own.',
            'Why? This closing sentence is also comfortably long here.',
        ], array_map(static fn ($s) => $s['text'], $segments));
    }
}
