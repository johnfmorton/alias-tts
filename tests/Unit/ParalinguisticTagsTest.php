<?php

namespace Tests\Unit;

use App\Services\Tts\ParalinguisticTags;
use PHPUnit\Framework\TestCase;

class ParalinguisticTagsTest extends TestCase
{
    public function test_strips_known_tags_and_tidies_spacing(): void
    {
        $this->assertSame(
            "Oh, that's hilarious! Let me tell you more.",
            ParalinguisticTags::strip("Oh, that's hilarious! [chuckle] Let me tell you more."),
        );

        // Mid-sentence removal must not leave a doubled space or a floating comma.
        $this->assertSame(
            'Well, that happened.',
            ParalinguisticTags::strip('Well [sigh], that happened.'),
        );

        // Multi-word tag.
        $this->assertSame('Ahem. Moving on.', ParalinguisticTags::strip('[clear throat] Ahem. Moving on.'));
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertSame('Ha!', ParalinguisticTags::strip('Ha! [LAUGH]'));
    }

    public function test_leaves_unknown_brackets_alone(): void
    {
        // Square brackets that aren't known tags are legitimate text.
        $this->assertSame('See [figure 3] for details.', ParalinguisticTags::strip('See [figure 3] for details.'));
    }

    public function test_has_detects_tags(): void
    {
        $this->assertTrue(ParalinguisticTags::has('So funny [laugh]'));
        $this->assertFalse(ParalinguisticTags::has('No tags here [figure 3].'));
    }

    public function test_ends_with_detects_a_trailing_tag(): void
    {
        $this->assertTrue(ParalinguisticTags::endsWith("Don't lose faith. [sniff]"));
        $this->assertTrue(ParalinguisticTags::endsWith('as it is for your lovers. [clear throat]'));

        // Tolerates trailing punctuation, closing quotes, and whitespace.
        $this->assertTrue(ParalinguisticTags::endsWith('So funny! [laugh].'));
        $this->assertTrue(ParalinguisticTags::endsWith('So funny! [LAUGH]  '));
        $this->assertTrue(ParalinguisticTags::endsWith('"So funny! [chuckle]"'));
    }

    public function test_ends_with_rejects_mid_text_and_unknown_tags(): void
    {
        // A mid-text tag renders INSIDE speech — the tail is normal audio.
        $this->assertFalse(ParalinguisticTags::endsWith('So funny [laugh] right?'));
        $this->assertFalse(ParalinguisticTags::endsWith('See [figure 3]'));
        $this->assertFalse(ParalinguisticTags::endsWith('No tags at all.'));
    }
}
