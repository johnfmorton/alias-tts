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
