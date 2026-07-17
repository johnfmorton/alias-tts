<?php

namespace Tests\Unit;

use App\Services\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    private function normalize(string $text): string
    {
        return (new TextNormalizer)->normalize($text);
    }

    public function test_decodes_html_entities(): void
    {
        $this->assertSame('Tom & Jerry', $this->normalize('Tom &amp; Jerry'));
    }

    public function test_replaces_non_breaking_spaces(): void
    {
        $this->assertSame('one two three', $this->normalize("one\u{00A0}two\u{00A0}three"));
    }

    public function test_strips_angle_brackets(): void
    {
        $result = $this->normalize('Hello <world> and &lt;again&gt;');

        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function test_strips_emoji(): void
    {
        $result = $this->normalize('Cheers 🍻 mate 🎉');

        $this->assertStringContainsString('Cheers', $result);
        $this->assertStringContainsString('mate', $result);
        $this->assertStringNotContainsString('🍻', $result);
        $this->assertStringNotContainsString('🎉', $result);
    }

    public function test_strips_asterisk_bullet_markers_and_ends_each_item(): void
    {
        $list = "After generation, a loop checks for:\n\n* missing or dropped words\n* truncated speech\n* stalls and repetitions";
        $result = $this->normalize($list);

        $this->assertStringNotContainsString('*', $result);
        // Each item ends in a period so the chunker reads it as its own sentence.
        $this->assertStringContainsString('missing or dropped words.', $result);
        $this->assertStringContainsString('truncated speech.', $result);
        $this->assertStringContainsString('stalls and repetitions.', $result);
    }

    public function test_strips_dash_and_plus_bullet_markers(): void
    {
        $this->assertSame("first item.\nsecond item.", $this->normalize("- first item\n+ second item"));
    }

    public function test_strips_indented_bullet_markers(): void
    {
        $this->assertSame('nested item.', $this->normalize('    * nested item'));
    }

    public function test_normalizes_soft_and_doubled_terminators_on_bullets(): void
    {
        // A bullet already ending in soft punctuation collapses to one period;
        // one already ending in a period does not gain a second.
        $this->assertSame(
            "run assets and manifests.\nvoice reference clips.",
            $this->normalize("* run assets and manifests;\n* voice reference clips.")
        );
    }

    public function test_preserves_terminal_mark_on_bullets(): void
    {
        $this->assertSame("Ready?\nGo!", $this->normalize("* Ready?\n* Go!"));
    }

    public function test_rejoins_soft_wrapped_bullet_lines(): void
    {
        // A hard-wrapped item (hanging-indent continuation lines) rejoins into a
        // single sentence — the period lands at the end of the item, not the wrap.
        $wrapped = "* this is a long bullet that\n  wraps to a second line\n* next bullet";
        $this->assertSame("this is a long bullet that wraps to a second line.\nnext bullet.", $this->normalize($wrapped));
    }

    public function test_blank_line_ends_a_wrapped_bullet(): void
    {
        // A blank line closes the list, so the following paragraph is never
        // swallowed into the last bullet.
        $text = "* only bullet\nstill the bullet\n\nA separate paragraph.";
        $this->assertSame("only bullet still the bullet.\n\nA separate paragraph.", $this->normalize($text));
    }

    public function test_bullet_normalization_is_idempotent(): void
    {
        $once = $this->normalize("* wrapped item\n  second line\n* plain item");
        $this->assertSame($once, $this->normalize($once));
    }

    public function test_rejoins_crlf_wrapped_bullet_and_ends_at_crlf_blank_line(): void
    {
        // Under CRLF, a soft-wrapped item still rejoins and a blank line still
        // closes the list, so the trailing paragraph is not swallowed.
        $out = $this->normalize("* first line\r\n  second line\r\n\r\nA separate paragraph.");
        $this->assertStringContainsString('first line second line.', $out);
        $this->assertStringContainsString('A separate paragraph.', $out);
    }

    public function test_leaves_crlf_newlines_in_non_list_text_untouched(): void
    {
        // TextNormalizer must stay a fixed point on line endings it did not
        // touch — SpokenQuotes output (CRLF paragraphs) is normalized afterward.
        $crlf = "One two.\r\n\r\nThree four.";
        $this->assertSame($crlf, $this->normalize($crlf));
    }

    public function test_leaves_inline_and_emphasis_asterisks_alone(): void
    {
        // A marker only counts at a line start followed by whitespace, so
        // emphasis and inline math survive.
        $this->assertSame('This is *important* to note.', $this->normalize('This is *important* to note.'));
        $this->assertSame('The area is 3 * 4 units.', $this->normalize('The area is 3 * 4 units.'));
    }

    public function test_leaves_leading_negative_number_alone(): void
    {
        // "-5" has no whitespace after the dash, so it is not a bullet marker.
        $this->assertSame('-5 degrees outside.', $this->normalize('-5 degrees outside.'));
    }

    public function test_drops_space_before_punctuation(): void
    {
        $this->assertSame('I use the editor.', $this->normalize('I use the editor .'));
    }

    public function test_leaves_dot_prefixed_tokens_alone(): void
    {
        // The (?=\s|$) guard means a ".gitignore" mid-sentence is not touched.
        $this->assertSame('Edit the .gitignore file.', $this->normalize('Edit the .gitignore file.'));
    }

    public function test_collapses_soft_punctuation_before_appended_period(): void
    {
        $this->assertSame('Watch the videos.', $this->normalize('Watch the videos:.'));
    }

    public function test_keeps_question_mark_over_appended_period(): void
    {
        $this->assertSame('Really?', $this->normalize('Really?.'));
    }

    public function test_collapses_doubled_period_but_preserves_ellipsis(): void
    {
        $this->assertSame('Wait.', $this->normalize('Wait. .'));
        $this->assertSame('Done...', $this->normalize('Done...'));
    }

    public function test_preserves_paragraph_structure(): void
    {
        // Blank-line and 4-space block boundaries (the plugin's markers) must
        // survive so the chunker can still recover paragraphs.
        $blankLines = "First block.\n\nSecond block.";
        $this->assertSame($blankLines, $this->normalize($blankLines));

        $spaceRun = 'First block.    Second block.';
        $this->assertSame($spaceRun, $this->normalize($spaceRun));
    }

    public function test_is_idempotent(): void
    {
        $messy = "Tom &amp; Jerry watch videos:.  I use the editor .\n\nReally?. 🍻";
        $once = $this->normalize($messy);

        $this->assertSame($once, $this->normalize($once));
    }
}
