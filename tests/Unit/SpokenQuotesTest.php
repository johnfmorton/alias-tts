<?php

namespace Tests\Unit;

use App\Services\SpokenQuotes;
use App\Services\TextNormalizer;
use PHPUnit\Framework\TestCase;

class SpokenQuotesTest extends TestCase
{
    /** @return array{text: string, applied: int} */
    private function voiced(string $text, string $mode = SpokenQuotes::MODE_OPEN_CLOSE): array
    {
        return (new SpokenQuotes)->apply($text, $mode);
    }

    /**
     * Assert the transform result AND that the output is a fixed point of
     * TextNormalizer — the Genblaze path normalizes AFTER this transform, so
     * any insertion normalize would rewrite is a bug.
     */
    private function assertVoiced(string $expected, string $input, string $mode = SpokenQuotes::MODE_OPEN_CLOSE, int $applied = 1): void
    {
        $out = $this->voiced($input, $mode);

        $this->assertSame($expected, $out['text']);
        $this->assertSame($applied, $out['applied']);
        $this->assertSame($out['text'], (new TextNormalizer)->normalize($out['text']), 'output must be normalize-stable');
    }

    private function assertUntouched(string $input, string $mode = SpokenQuotes::MODE_OPEN_CLOSE): void
    {
        $out = $this->voiced($input, $mode);

        $this->assertSame($input, $out['text']);
        $this->assertSame(0, $out['applied']);
    }

    public function test_off_mode_is_byte_identical(): void
    {
        $text = "He said, \"Hello.\" And “curly” too, at 5' 10\" tall.\n\n“Even across.\n\n“Paragraphs.”";

        $out = (new SpokenQuotes)->apply($text, SpokenQuotes::MODE_OFF);

        $this->assertSame($text, $out['text']);
        $this->assertSame(0, $out['applied']);
    }

    public function test_an_unknown_mode_is_byte_identical(): void
    {
        $out = (new SpokenQuotes)->apply('He said, "Hello."', 'loud');

        $this->assertSame('He said, "Hello."', $out['text']);
        $this->assertSame(0, $out['applied']);
    }

    public function test_straight_pair_open_close(): void
    {
        $this->assertVoiced(
            'He said, open quote, Hello there, close quote. Then left.',
            'He said, "Hello there." Then left.'
        );
    }

    public function test_straight_pair_open_only(): void
    {
        $this->assertVoiced(
            'He said, quote, Hello there. Then left.',
            'He said, "Hello there." Then left.',
            SpokenQuotes::MODE_OPEN_ONLY
        );
    }

    public function test_straight_pair_quote_close(): void
    {
        $this->assertVoiced(
            'He said, quote, Hello there, close quote. Then left.',
            'He said, "Hello there." Then left.',
            SpokenQuotes::MODE_QUOTE_CLOSE
        );
    }

    public function test_continuation_across_three_paragraphs_quote_close(): void
    {
        $this->assertVoiced(
            "quote, First part starts here.\n\nIt continues in the middle.\n\nAnd it ends here, close quote, she said.",
            "“First part starts here.\n\n“It continues in the middle.\n\n“And it ends here,” she said.",
            SpokenQuotes::MODE_QUOTE_CLOSE
        );
    }

    public function test_curly_pair(): void
    {
        $this->assertVoiced(
            'She replied, open quote, On my way, close quote. Then silence.',
            'She replied, “On my way.” Then silence.'
        );
    }

    public function test_mixed_curly_open_straight_close(): void
    {
        $this->assertVoiced(
            'He said, open quote, Hello there, close quote. Then left.',
            'He said, “Hello there." Then left.'
        );
    }

    public function test_mixed_straight_open_curly_close(): void
    {
        $this->assertVoiced(
            'He said, open quote, Hello there, close quote. Then left.',
            'He said, "Hello there.” Then left.'
        );
    }

    public function test_close_preceded_by_comma_keeps_the_comma_outermost(): void
    {
        $this->assertVoiced(
            'open quote, Hi, close quote, he said.',
            '"Hi," he said.'
        );
    }

    public function test_close_glued_to_a_word(): void
    {
        $this->assertVoiced(
            'She yelled open quote, stop, close quote loudly.',
            'She yelled "stop" loudly.'
        );
    }

    public function test_curly_close_glued_to_a_following_word_gets_a_space(): void
    {
        $this->assertVoiced(
            'Read open quote, stop, close quote signs today.',
            'Read “stop”signs today.'
        );
    }

    public function test_curly_close_after_a_space_never_leaves_space_before_the_comma(): void
    {
        $this->assertVoiced(
            'open quote, Wait, close quote he said.',
            '“Wait ” he said.'
        );
    }

    public function test_a_stray_inches_mark_is_untouched(): void
    {
        $this->assertUntouched("He is 5' 10\" tall.");
    }

    public function test_an_inches_mark_inside_a_curly_quotation_is_preserved(): void
    {
        $this->assertVoiced(
            "open quote, I am 5' 10\" tall, close quote, she said.",
            "“I am 5' 10\" tall,” she said."
        );
    }

    public function test_a_straight_quote_ending_in_a_digit_is_left_entirely_alone(): void
    {
        // The digit-preceded " is treated as inches, so the opener never
        // resolves and the conservative fallback keeps the whole chain intact.
        $this->assertUntouched('He said "It\'s 10" yesterday.');
    }

    public function test_a_curly_quote_ending_in_a_digit_is_voiced(): void
    {
        $this->assertVoiced(
            'She wrote open quote, The answer is 42, close quote on the board.',
            'She wrote “The answer is 42” on the board.'
        );
    }

    public function test_a_quote_spanning_the_entire_text(): void
    {
        $this->assertVoiced(
            'open quote, Entire text quoted, close quote.',
            '"Entire text quoted."'
        );
    }

    public function test_empty_and_whitespace_only_pairs_are_untouched(): void
    {
        $this->assertUntouched('He wrote "" and moved on.');
        $this->assertUntouched('He typed " " twice.');
        $this->assertUntouched('She sighed “...” loudly.');
    }

    public function test_stray_marks_are_untouched(): void
    {
        $this->assertUntouched('A lone " here.');
        $this->assertUntouched('Missing the opening.” Yes.');
        $this->assertUntouched('“Never closed at all');
    }

    public function test_an_ambiguous_mark_inside_a_resolved_pair_is_preserved(): void
    {
        $this->assertVoiced(
            'open quote, He measured a " gap, close quote, she said.',
            '“He measured a " gap,” she said.'
        );
    }

    public function test_multiple_quotes_in_one_paragraph(): void
    {
        $this->assertVoiced(
            'open quote, One, close quote. Then open quote, Two, close quote. End.',
            '"One." Then "Two." End.',
            SpokenQuotes::MODE_OPEN_CLOSE,
            2
        );
    }

    public function test_dialogue_heavy_text(): void
    {
        $this->assertVoiced(
            "open quote, Are you coming, close quote? she asked.\n\n"
                .'open quote, No, close quote, he said. open quote, Not today, close quote.',
            "\"Are you coming?\" she asked.\n\n\"No,\" he said. \"Not today.\"",
            SpokenQuotes::MODE_OPEN_CLOSE,
            3
        );
    }

    public function test_continuation_across_three_paragraphs(): void
    {
        $this->assertVoiced(
            "open quote, First part starts here.\n\nIt continues in the middle.\n\nAnd it ends here, close quote, she said.",
            "“First part starts here.\n\n“It continues in the middle.\n\n“And it ends here,” she said."
        );
    }

    public function test_continuation_across_three_paragraphs_open_only(): void
    {
        $this->assertVoiced(
            "quote, First part starts here.\n\nIt continues in the middle.\n\nAnd it ends here, she said.",
            "“First part starts here.\n\n“It continues in the middle.\n\n“And it ends here,” she said.",
            SpokenQuotes::MODE_OPEN_ONLY
        );
    }

    public function test_continuation_with_crlf_paragraphs(): void
    {
        $this->assertVoiced(
            "open quote, One two.\r\n\r\nThree four, close quote, she said.",
            "“One two.\r\n\r\n“Three four,” she said."
        );
    }

    public function test_continuation_across_a_space_run_block_boundary(): void
    {
        // The Bespoken plugin flattens posts to one line and marks block
        // boundaries with runs of four-plus spaces.
        $this->assertVoiced(
            'open quote, Start of quote.    End of it, close quote. Done.',
            '“Start of quote.    “End of it.” Done.'
        );
    }

    public function test_a_pending_open_is_abandoned_when_the_next_paragraph_does_not_reopen(): void
    {
        $this->assertVoiced(
            "\"He began speaking.\n\nThen plain text.\n\nopen quote, A new quote, close quote. Done.",
            "\"He began speaking.\n\nThen plain text.\n\n\"A new quote.\" Done."
        );
    }

    public function test_a_mid_paragraph_open_in_the_next_paragraph_breaks_the_chain(): void
    {
        $this->assertVoiced(
            "\"Unclosed start.\n\nHe said open quote, another, close quote. End.",
            "\"Unclosed start.\n\nHe said \"another.\" End."
        );
    }

    public function test_a_second_open_mid_paragraph_abandons_the_first(): void
    {
        $this->assertVoiced(
            'He said "first and then open quote, second, close quote. Done.',
            'He said "first and then "second." Done.'
        );
    }

    public function test_the_literal_word_quote_in_the_text_is_never_confused(): void
    {
        $this->assertVoiced(
            'The word quote appears, and open quote, so, close quote does this.',
            'The word quote appears, and "so" does this.'
        );
    }

    public function test_nbsp_counts_as_whitespace_for_classification(): void
    {
        // No normalize-stability assert here: the input's own NBSP predates the
        // transform, and normalize rewrites NBSP regardless of what we insert.
        $out = $this->voiced("He said\u{00A0}\"yes\" now.");

        $this->assertSame("He said\u{00A0}open quote, yes, close quote now.", $out['text']);
        $this->assertSame(1, $out['applied']);
    }

    public function test_sound_tag_ending_open_close(): void
    {
        $this->assertVoiced(
            'open quote, Great! [laugh], close quote',
            '“Great! [laugh]”'
        );
    }

    public function test_sound_tag_ending_open_only_keeps_the_tag_text_final(): void
    {
        $this->assertVoiced(
            'quote, Great! [laugh]',
            '“Great! [laugh]”',
            SpokenQuotes::MODE_OPEN_ONLY
        );
    }

    public function test_open_only_collapses_a_doubly_spaced_consumed_close(): void
    {
        $this->assertVoiced(
            'quote, Wait he said.',
            '“Wait ” he said.',
            SpokenQuotes::MODE_OPEN_ONLY
        );
    }
}
