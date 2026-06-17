<?php

namespace Tests\Feature;

use App\Services\Audio\AudioConverter;
use Tests\TestCase;

class AudioConverterTest extends TestCase
{
    public function test_normalize_reference_returns_mono_wav(): void
    {
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));

        $out = $converter->normalizeReference($this->loudStereoWav(0.5));

        // Valid RIFF/WAVE container...
        $this->assertSame('RIFF', substr($out, 0, 4));
        $this->assertSame('WAVE', substr($out, 8, 4));

        // ...downmixed to mono (NumChannels lives at byte offset 22, LE uint16).
        $channels = unpack('v', substr($out, 22, 2))[1];
        $this->assertSame(1, $channels);
    }

    public function test_crossfade_shortens_concatenated_output(): void
    {
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunks = [$this->silentMonoWav(0.3), $this->silentMonoWav(0.3), $this->silentMonoWav(0.3)];

        config(['tts.chunk_crossfade_ms' => 0]);
        [$hard, $mime] = $converter->concatenate($chunks, 'mp3_44100_128', 'wav');

        config(['tts.chunk_crossfade_ms' => 50]);
        [$crossfaded] = $converter->concatenate($chunks, 'mp3_44100_128', 'wav');

        $this->assertSame('audio/mpeg', $mime);
        $this->assertNotEmpty($crossfaded);
        $this->assertLessThan(strlen($hard), strlen($crossfaded), 'Crossfaded chunks overlap, so the output is shorter.');
    }

    private function silentMonoWav(float $seconds): string
    {
        $rate = 44100;
        $samples = (int) ($rate * $seconds);
        $data = str_repeat("\x00", $samples * 2);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $rate).pack('V', $rate * 2).pack('v', 2).pack('v', 16)
            .'data'.pack('V', strlen($data)).$data;
    }

    /**
     * Build a loud (near-full-scale) stereo 16-bit PCM WAV in memory.
     */
    private function loudStereoWav(float $seconds): string
    {
        $sampleRate = 44100;
        $channels = 2;
        $bits = 16;
        $numSamples = (int) ($sampleRate * $seconds);

        $samples = '';
        for ($i = 0; $i < $numSamples; $i++) {
            $value = (int) (30000 * sin(2 * M_PI * 220 * $i / $sampleRate));
            $packed = pack('v', $value & 0xFFFF);
            $samples .= $packed.$packed; // left + right
        }

        $dataSize = strlen($samples);
        $byteRate = $sampleRate * $channels * ($bits / 8);
        $blockAlign = $channels * ($bits / 8);

        $header = 'RIFF'.pack('V', 36 + $dataSize).'WAVE';
        $header .= 'fmt '.pack('V', 16).pack('v', 1).pack('v', $channels)
            .pack('V', $sampleRate).pack('V', $byteRate)
            .pack('v', $blockAlign).pack('v', $bits);
        $header .= 'data'.pack('V', $dataSize);

        return $header.$samples;
    }
}
