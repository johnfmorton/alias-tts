<?php

namespace Tests\Unit;

use App\Services\Tts\ChatterboxTurboTuning;
use PHPUnit\Framework\TestCase;

class ChatterboxTurboTuningTest extends TestCase
{
    public function test_defaults_when_nothing_is_set(): void
    {
        $native = ChatterboxTurboTuning::resolveNative([]);

        $this->assertSame(0.8, $native['temperature']);
        $this->assertSame(0.95, $native['top_p']);
        $this->assertSame(1000, $native['top_k']);
        $this->assertSame(1.2, $native['repetition_penalty']);
    }

    public function test_explicit_native_knobs_win_and_clamp(): void
    {
        $native = ChatterboxTurboTuning::resolveNative([
            'temperature' => 9.0,
            'top_p' => 0.1,
            'top_k' => 99999,
            'repetition_penalty' => 0.2,
        ]);

        $this->assertSame(1.5, $native['temperature']);       // shared UI band cap
        $this->assertSame(0.5, $native['top_p']);
        $this->assertSame(2000, $native['top_k']);
        $this->assertSame(1.0, $native['repetition_penalty']);
    }

    public function test_stability_maps_inversely_onto_temperature(): void
    {
        // The EL default (0.5) must land exactly on turbo's default temperature.
        $this->assertSame(0.8, ChatterboxTurboTuning::resolveNative(['stability' => 0.5])['temperature']);

        // Max stability = steadiest = the band floor (1.3 - 1.0 = 0.3 clamps to 0.5).
        $this->assertSame(0.5, ChatterboxTurboTuning::resolveNative(['stability' => 1.0])['temperature']);

        // Zero stability = most varied.
        $this->assertSame(1.3, ChatterboxTurboTuning::resolveNative(['stability' => 0.0])['temperature']);
    }

    public function test_explicit_temperature_beats_stability(): void
    {
        $native = ChatterboxTurboTuning::resolveNative(['temperature' => 1.1, 'stability' => 1.0]);

        $this->assertSame(1.1, $native['temperature']);
    }

    public function test_foreign_el_knobs_are_ignored_without_error(): void
    {
        // style / similarity_boost / use_speaker_boost have no turbo counterpart;
        // cfg_weight / exaggeration belong to classic chatterbox. All must be
        // silently ignored so no /v1 request can error over a knob mismatch.
        $native = ChatterboxTurboTuning::resolveNative([
            'style' => 1.0,
            'similarity_boost' => 0.9,
            'use_speaker_boost' => true,
            'cfg_weight' => 0.9,
            'exaggeration' => 1.8,
        ]);

        $this->assertSame(
            ['temperature' => 0.8, 'top_p' => 0.95, 'top_k' => 1000, 'repetition_penalty' => 1.2],
            $native,
        );
    }
}
