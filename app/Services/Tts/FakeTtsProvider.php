<?php

namespace App\Services\Tts;

/**
 * Deterministic provider for tests and offline local use. Returns a short
 * silent WAV so the real AudioConverter (ffmpeg) path is still exercised.
 */
class FakeTtsProvider implements TtsProvider
{
    public function synthesize(string $text, ?string $referenceAudio, array $settings): string
    {
        return $this->silentWav(0.2);
    }

    public function outputContainer(?string $model = null): string
    {
        return 'wav';
    }

    private function silentWav(float $seconds): string
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

        return $header.str_repeat("\x00", $dataSize);
    }
}
