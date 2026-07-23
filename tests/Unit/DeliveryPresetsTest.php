<?php

namespace Tests\Unit;

use App\Services\Tts\ChatterboxTuning;
use App\Services\Tts\ChatterboxTurboTuning;
use App\Services\Tts\DeliveryPresets;
use PHPUnit\Framework\TestCase;

/**
 * The built-in Delivery archetypes (Steady / Balanced / Expressive) the Studio
 * takes-&-tuning panel offers as chips. Balanced must track the tuning
 * services' neutral defaults; Steady/Expressive must stay inside each knob's
 * range.
 */
class DeliveryPresetsTest extends TestCase
{
    public function test_exposes_three_archetypes_per_engine_in_order(): void
    {
        $all = DeliveryPresets::all();

        $this->assertSame(['chatterbox', 'chatterbox-turbo', 'qwen3-tts'], array_keys($all));
        foreach (['chatterbox', 'chatterbox-turbo'] as $engine) {
            $this->assertSame(['steady', 'balanced', 'expressive'], array_column($all[$engine], 'key'));
        }
        // Qwen has no numeric knobs — no archetypes to offer (the chips hide).
        $this->assertSame([], $all['qwen3-tts']);
    }

    public function test_classic_archetypes_carry_the_classic_knob_set(): void
    {
        foreach (DeliveryPresets::forEngine('chatterbox') as $preset) {
            $this->assertSame(['exaggeration', 'cfg_weight', 'temperature'], array_keys($preset['values']));
        }
    }

    public function test_turbo_archetypes_carry_the_turbo_knob_set(): void
    {
        foreach (DeliveryPresets::forEngine('chatterbox-turbo') as $preset) {
            $this->assertSame(
                ['temperature', 'top_p', 'top_k', 'repetition_penalty'],
                array_keys($preset['values']),
            );
        }
    }

    public function test_balanced_equals_the_neutral_defaults(): void
    {
        $classic = collect(DeliveryPresets::forEngine('chatterbox'))->firstWhere('key', 'balanced')['values'];
        $this->assertSame(ChatterboxTuning::resolveNative([])['exaggeration'], $classic['exaggeration']);
        $this->assertSame(ChatterboxTuning::resolveNative([])['cfg_weight'], $classic['cfg_weight']);
        $this->assertSame(ChatterboxTuning::TEMPERATURE_DEFAULT, $classic['temperature']);

        $turbo = collect(DeliveryPresets::forEngine('chatterbox-turbo'))->firstWhere('key', 'balanced')['values'];
        $this->assertSame(ChatterboxTurboTuning::resolveNative([]), $turbo);
    }

    public function test_archetype_values_stay_within_each_knob_range(): void
    {
        foreach (DeliveryPresets::forEngine('chatterbox') as $preset) {
            $v = $preset['values'];
            $this->assertSame($v['exaggeration'], ChatterboxTuning::clampExaggeration($v['exaggeration']));
            $this->assertSame($v['cfg_weight'], ChatterboxTuning::clampCfgWeight($v['cfg_weight']));
            $this->assertSame($v['temperature'], ChatterboxTuning::clampTemperature($v['temperature']));
        }

        foreach (DeliveryPresets::forEngine('chatterbox-turbo') as $preset) {
            $v = $preset['values'];
            $this->assertSame($v['top_p'], ChatterboxTurboTuning::clampTopP($v['top_p']));
            $this->assertSame($v['top_k'], ChatterboxTurboTuning::clampTopK($v['top_k']));
            $this->assertSame($v['repetition_penalty'], ChatterboxTurboTuning::clampRepetitionPenalty($v['repetition_penalty']));
            $this->assertSame($v['temperature'], ChatterboxTuning::clampTemperature($v['temperature']));
        }
    }

    public function test_unknown_engine_falls_back_to_classic(): void
    {
        $this->assertSame(
            DeliveryPresets::forEngine('chatterbox'),
            DeliveryPresets::forEngine('something-else'),
        );
    }
}
