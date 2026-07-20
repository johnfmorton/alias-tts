<?php

namespace Tests\Unit;

use App\Services\Tts\ChunkGaps;
use Tests\TestCase;

/**
 * The seam-gap resolver: how the per-user "Pause between sentences / paragraphs"
 * settings and the mode-aware Auto default combine into the two gaps a stitched
 * render uses.
 */
class ChunkGapsTest extends TestCase
{
    private function config(array $values): void
    {
        config(array_merge([
            'tts.chunk_gap_ms' => 120,
            'tts.paragraph_gap_ms' => 400,
            'tts.sentence_gap_ms' => 200,
            'tts.sentence_gap_override_ms' => 0,
            'tts.paragraph_gap_override_ms' => 0,
        ], $values));
    }

    public function test_auto_uses_the_tight_sentence_gap_in_packed_mode(): void
    {
        $this->config(['tts.chunk_mode' => 'packed']);

        $this->assertSame([120, 400], ChunkGaps::resolve());
    }

    public function test_auto_gives_per_sentence_chunking_a_roomier_gap(): void
    {
        // The smart default: every sentence is its own hard seam, so Auto opens
        // the sentence pause from 120 ms to 200 ms. Paragraph pause is unchanged.
        $this->config(['tts.chunk_mode' => 'sentence']);

        $this->assertSame([200, 400], ChunkGaps::resolve());
    }

    public function test_an_explicit_sentence_pause_wins_in_either_mode(): void
    {
        $this->config(['tts.chunk_mode' => 'sentence', 'tts.sentence_gap_override_ms' => 90]);
        $this->assertSame([90, 400], ChunkGaps::resolve());

        $this->config(['tts.chunk_mode' => 'packed', 'tts.sentence_gap_override_ms' => 90]);
        $this->assertSame([90, 400], ChunkGaps::resolve());
    }

    public function test_an_explicit_paragraph_pause_overrides_the_base(): void
    {
        $this->config(['tts.chunk_mode' => 'packed', 'tts.paragraph_gap_override_ms' => 650]);

        $this->assertSame([120, 650], ChunkGaps::resolve());
    }

    public function test_an_explicit_mode_argument_beats_the_config_mode(): void
    {
        // A render path that chunked with an explicit mode paces its seams by that
        // mode, not whatever config('tts.chunk_mode') happens to say.
        $this->config(['tts.chunk_mode' => 'packed']);

        $this->assertSame([200, 400], ChunkGaps::resolve('sentence'));
    }
}
