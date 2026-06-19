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
