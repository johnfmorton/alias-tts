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

    public function test_concatenate_inserts_silence_gaps_between_chunks(): void
    {
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        // Loud chunks (no edge silence) so trimming keeps their content and the
        // only length difference is the inserted gap silence.
        $chunks = [$this->loudMonoWav(0.3), $this->loudMonoWav(0.3), $this->loudMonoWav(0.3)];

        [$nogap, $mime] = $converter->concatenate($chunks, 'mp3_44100_128', 'wav', [0, 0]);
        [$gapped] = $converter->concatenate($chunks, 'mp3_44100_128', 'wav', [300, 300]);

        $this->assertSame('audio/mpeg', $mime);
        $this->assertNotEmpty($gapped);
        $this->assertGreaterThan(strlen($nogap), strlen($gapped), 'Inserted silence makes the gapped output longer.');
    }

    public function test_silent_chunks_survive_trimming(): void
    {
        // Trimming a fully-silent chunk would remove everything; the fall-back
        // must keep it so the output is never empty.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunks = [$this->silentMonoWav(0.2), $this->silentMonoWav(0.2)];

        [$out, $mime] = $converter->concatenate($chunks, 'mp3_44100_128', 'wav', [100]);

        $this->assertSame('audio/mpeg', $mime);
        $this->assertNotEmpty($out);
    }

    public function test_quiet_trailing_word_survives_trim(): void
    {
        // Regression: a soft trailing word (like Chatterbox's "Why?") followed by
        // the swoosh tail must NOT be trimmed away. Layout: loud 1.0s | pause 0.3s
        // | quiet word 0.25s (~-42 dB) | swoosh 0.3s (~-50 dB) = 1.85s in.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->loudPauseWordSwooshWav();

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // Word kept -> ~1.55s. The old unbounded trim collapsed this to ~1.0s
        // (word + pause gone). Allow margin; the key line is the lower bound.
        $this->assertGreaterThan(1.3, $seconds, 'The quiet trailing word must survive trimming.');
        $this->assertLessThan(1.75, $seconds, 'The trailing swoosh should still be trimmed.');
    }

    /**
     * loud speech | pause | quiet trailing word | low swoosh tail — the shape
     * that exposed the dropped-word bug. Mono 16-bit PCM WAV at 44.1 kHz.
     */
    private function loudPauseWordSwooshWav(): string
    {
        $rate = 44100;
        $samples = '';
        $tone = function (float $secs, int $amp, float $freq) use (&$samples, $rate): void {
            $n = (int) ($rate * $secs);
            for ($i = 0; $i < $n; $i++) {
                $value = (int) ($amp * sin(2 * M_PI * $freq * $i / $rate));
                $samples .= pack('v', $value & 0xFFFF);
            }
        };

        $tone(1.0, 30000, 220.0);  // loud speech (~ -0.8 dB)
        $tone(0.30, 0, 0.0);       // pause (digital silence)
        $tone(0.25, 260, 330.0);   // soft trailing word (~ -42 dB, below threshold)
        $tone(0.30, 100, 6000.0);  // swoosh/hiss tail (~ -50 dB)

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $rate).pack('V', $rate * 2).pack('v', 2).pack('v', 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }

    /** Bytes in the WAV's data chunk (walks chunks; tolerant of extra ones). */
    private function wavDataBytes(string $wav): int
    {
        $pos = 12; // skip 'RIFF' <size> 'WAVE'
        $len = strlen($wav);
        while ($pos + 8 <= $len) {
            $id = substr($wav, $pos, 4);
            $size = unpack('V', substr($wav, $pos + 4, 4))[1];
            if ($id === 'data') {
                return $size;
            }
            $pos += 8 + $size + ($size & 1); // chunks are word-aligned
        }

        return 0;
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
     * Build a loud (near-full-scale) 220 Hz tone as a mono 16-bit PCM WAV, so it
     * has no leading/trailing silence for the trimmer to remove.
     */
    private function loudMonoWav(float $seconds): string
    {
        $rate = 44100;
        $numSamples = (int) ($rate * $seconds);

        $samples = '';
        for ($i = 0; $i < $numSamples; $i++) {
            $value = (int) (30000 * sin(2 * M_PI * 220 * $i / $rate));
            $samples .= pack('v', $value & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $rate).pack('V', $rate * 2).pack('v', 2).pack('v', 16)
            .'data'.pack('V', strlen($samples)).$samples;
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
