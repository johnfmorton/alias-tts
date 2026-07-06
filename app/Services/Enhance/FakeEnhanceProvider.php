<?php

namespace App\Services\Enhance;

/**
 * Deterministic enhancer for tests and offline use. Returns a short WAV that is
 * byte-distinguishable from any real input (a 0.3s 440 Hz tone) so tests can
 * assert the enhanced variant — not the original — was stored. Construct with
 * `new FakeEnhanceProvider(fail: true)` to exercise the degrade-safe fallback.
 */
class FakeEnhanceProvider implements EnhanceProvider
{
    public function __construct(private bool $fail = false) {}

    public function enhance(string $wavBytes, array $options = []): ?string
    {
        return $this->fail ? null : $this->tone(0.3);
    }

    public function health(bool $deep = false): array
    {
        return $this->fail
            ? ['reachable' => false, 'detail' => 'fake enhancer set to fail', 'error' => 'forced failure']
            : ['reachable' => true, 'detail' => 'fake enhancer (deterministic placeholder)', 'error' => null];
    }

    /** The exact bytes {@see enhance()} returns when not failing — for test assertions. */
    public function output(): string
    {
        return $this->tone(0.3);
    }

    /** A mono 16-bit 44.1kHz sine tone — deterministic and unlike any silent/input WAV. */
    private function tone(float $seconds): string
    {
        $sampleRate = 44100;
        $channels = 1;
        $bits = 16;

        $numSamples = (int) ($sampleRate * $seconds);
        $dataSize = $numSamples * $channels * ($bits / 8);
        $byteRate = $sampleRate * $channels * ($bits / 8);
        $blockAlign = $channels * ($bits / 8);

        $header = 'RIFF'.pack('V', 36 + $dataSize).'WAVE';
        $header .= 'fmt '.pack('V', 16).pack('v', 1).pack('v', $channels)
            .pack('V', $sampleRate).pack('V', $byteRate)
            .pack('v', $blockAlign).pack('v', $bits);
        $header .= 'data'.pack('V', $dataSize);

        $body = '';
        for ($i = 0; $i < $numSamples; $i++) {
            $sample = (int) (0.3 * 32767 * sin(2 * M_PI * 440 * $i / $sampleRate));
            $body .= pack('v', $sample & 0xFFFF);
        }

        return $header.$body;
    }
}
