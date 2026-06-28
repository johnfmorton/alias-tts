<?php

namespace Tests\Unit;

use App\Models\Voice;
use App\Services\Tts\VoiceSettingsResolver;
use Tests\TestCase;

class VoiceSettingsResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.default_voice_settings' => [
            'stability' => 0.5,
            'similarity_boost' => 0.75,
            'style' => 0.0,
            'use_speaker_boost' => true,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $voiceSettings
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function resolve(array $voiceSettings, array $overrides = []): array
    {
        $voice = new Voice(['settings' => $voiceSettings]);

        return (new VoiceSettingsResolver)->resolve($voice, $overrides);
    }

    public function test_falls_back_to_config_defaults_when_nothing_set(): void
    {
        $this->assertSame([
            'stability' => 0.5,
            'similarity_boost' => 0.75,
            'style' => 0.0,
            'use_speaker_boost' => true,
        ], $this->resolve([]));
    }

    public function test_voice_defaults_override_config(): void
    {
        $resolved = $this->resolve(['stability' => 0.8, 'style' => 0.3]);

        $this->assertSame(0.8, $resolved['stability']);
        $this->assertSame(0.3, $resolved['style']);
        // Keys the voice didn't set keep the config default.
        $this->assertSame(0.75, $resolved['similarity_boost']);
    }

    public function test_request_overrides_beat_voice_defaults(): void
    {
        $resolved = $this->resolve(['stability' => 0.8], ['stability' => 0.2]);

        $this->assertSame(0.2, $resolved['stability']);
    }

    public function test_seed_is_never_included(): void
    {
        // seed lives in its own slot and must not leak into the settings map.
        $resolved = $this->resolve(['seed' => 42, 'stability' => 0.8], ['seed' => 99]);

        $this->assertArrayNotHasKey('seed', $resolved);
        $this->assertSame(0.8, $resolved['stability']);
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $resolved = $this->resolve(['bogus' => 1], ['nonsense' => 2]);

        $this->assertArrayNotHasKey('bogus', $resolved);
        $this->assertArrayNotHasKey('nonsense', $resolved);
    }

    public function test_native_knobs_pass_through_and_are_cast(): void
    {
        // The Studio speaks native; these must survive resolution as floats so the
        // provider can use them directly (it prefers native over the EL twins).
        $resolved = $this->resolve([], ['exaggeration' => '1.2', 'cfg_weight' => '0.4']);

        $this->assertSame(1.2, $resolved['exaggeration']);
        $this->assertSame(0.4, $resolved['cfg_weight']);
    }

    public function test_values_are_cast_to_their_types(): void
    {
        $resolved = $this->resolve([], ['stability' => '0.7', 'use_speaker_boost' => 0]);

        $this->assertSame(0.7, $resolved['stability']);
        $this->assertFalse($resolved['use_speaker_boost']);
    }
}
