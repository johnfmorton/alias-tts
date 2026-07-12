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
}
