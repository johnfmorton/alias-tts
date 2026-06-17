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
}
