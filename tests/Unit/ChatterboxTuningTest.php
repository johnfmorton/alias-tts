<?php

namespace Tests\Unit;

use App\Services\Tts\ChatterboxTuning;
use PHPUnit\Framework\TestCase;

/**
 * The single source of truth for mapping ElevenLabs stability/style onto
 * Chatterbox's native cfg_weight/exaggeration. Native keys win; EL keys are the
 * fallback the public /v1 API relies on.
 */
class ChatterboxTuningTest extends TestCase
{
    public function test_derives_native_from_elevenlabs_knobs(): void
    {
        $native = ChatterboxTuning::resolveNative(['stability' => 0.8, 'style' => 0.3]);

        $this->assertSame(0.8, $native['cfg_weight']);          // cfg = clamp(stability)
        $this->assertEqualsWithDelta(0.95, $native['exaggeration'], 1e-9); // 0.5 + 0.3*1.5
    }

    public function test_defaults_when_no_knobs_are_set(): void
    {
        $native = ChatterboxTuning::resolveNative([]);

        $this->assertSame(0.5, $native['cfg_weight']);
        $this->assertSame(0.5, $native['exaggeration']);
    }

    public function test_native_keys_win_over_elevenlabs_twins(): void
    {
        $native = ChatterboxTuning::resolveNative([
            'stability' => 0.8, 'style' => 0.3,   // would derive 0.8 / 0.95
            'exaggeration' => 1.2, 'cfg_weight' => 0.4,
        ]);

        $this->assertSame(0.4, $native['cfg_weight']);
        $this->assertSame(1.2, $native['exaggeration']);
    }

    public function test_clamps_to_chatterbox_bounds(): void
    {
        $hi = ChatterboxTuning::resolveNative(['exaggeration' => 5, 'cfg_weight' => 9]);
        $this->assertSame(2.0, $hi['exaggeration']);
        $this->assertSame(1.0, $hi['cfg_weight']);

        $lo = ChatterboxTuning::resolveNative(['exaggeration' => 0.0, 'cfg_weight' => 0.0]);
        $this->assertSame(0.25, $lo['exaggeration']);
        $this->assertSame(0.2, $lo['cfg_weight']);
    }
}
