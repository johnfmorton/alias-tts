<?php

namespace Tests\Unit;

use App\Services\Tts\Qwen3TtsTuning;
use PHPUnit\Framework\TestCase;

class Qwen3TtsTuningTest extends TestCase
{
    public function test_language_clamps_to_the_exact_enum(): void
    {
        $this->assertSame('English', Qwen3TtsTuning::clampLanguage('English'));
        $this->assertSame('auto', Qwen3TtsTuning::clampLanguage('english')); // case-sensitive enum
        $this->assertSame('auto', Qwen3TtsTuning::clampLanguage('Klingon'));
        $this->assertSame('auto', Qwen3TtsTuning::clampLanguage(null));
    }

    public function test_style_instruction_is_trimmed_capped_and_nulled_when_blank(): void
    {
        $this->assertSame('speak slowly', Qwen3TtsTuning::cleanStyleInstruction('  speak slowly  '));
        $this->assertNull(Qwen3TtsTuning::cleanStyleInstruction('   '));
        $this->assertNull(Qwen3TtsTuning::cleanStyleInstruction(null));

        $capped = Qwen3TtsTuning::cleanStyleInstruction(str_repeat('a', 600));
        $this->assertSame(Qwen3TtsTuning::STYLE_INSTRUCTION_MAX, mb_strlen($capped));
    }

    public function test_resolve_native_expresses_defaults_by_omission(): void
    {
        $this->assertSame([], Qwen3TtsTuning::resolveNative([]));
        $this->assertSame([], Qwen3TtsTuning::resolveNative(['language' => 'auto', 'style_instruction' => '']));

        $this->assertSame(
            ['language' => 'Japanese', 'style_instruction' => 'excited tone'],
            Qwen3TtsTuning::resolveNative(['language' => 'Japanese', 'style_instruction' => 'excited tone']),
        );
    }

    public function test_reference_text_fit_guard_drops_a_transcript_that_overruns_the_clip(): void
    {
        // The real regression: a 572-char transcript stamped on a 24.6s clip —
        // qwen would speak the ~9s the audio doesn't cover before the target.
        $overrun = str_repeat('a', 572);
        $this->assertFalse(Qwen3TtsTuning::referenceTextFitsClip($overrun, 24.62));

        // A transcript that matches a 24.6s clip (~14 c/s) is kept.
        $matching = str_repeat('a', 345);
        $this->assertTrue(Qwen3TtsTuning::referenceTextFitsClip($matching, 24.62));
    }

    public function test_reference_text_fit_guard_keeps_the_transcript_when_the_clip_is_unmeasurable(): void
    {
        // Null duration (non-WAV or unreadable) can't prove an overrun — keep it.
        $this->assertTrue(Qwen3TtsTuning::referenceTextFitsClip(str_repeat('a', 5000), null));
        $this->assertTrue(Qwen3TtsTuning::referenceTextFitsClip(str_repeat('a', 5000), 0.0));

        // A blank transcript trivially fits regardless of clip length.
        $this->assertTrue(Qwen3TtsTuning::referenceTextFitsClip('   ', 3.0));
    }

    public function test_resolve_native_ignores_every_foreign_knob(): void
    {
        $this->assertSame([], Qwen3TtsTuning::resolveNative([
            'stability' => 0.4,
            'similarity_boost' => 0.9,
            'style' => 0.6,
            'use_speaker_boost' => true,
            'cfg_weight' => 0.5,
            'exaggeration' => 1.2,
            'temperature' => 1.0,
            'top_p' => 0.9,
            'top_k' => 500,
            'repetition_penalty' => 1.5,
            'seed' => 4242,
        ]));
    }
}
