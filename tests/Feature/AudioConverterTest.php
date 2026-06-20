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

    public function test_long_low_frequency_tail_artifact_is_removed(): void
    {
        // Real Chatterbox output: ~14.85s of speech followed by a ~3s loud,
        // low-frequency drone (total 17.8s) that the bounded silence trim cannot
        // reach. The long-tail detector must cut it at the speech end.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $artifact = file_get_contents(__DIR__.'/../Fixtures/tail-artifact.wav');

        [$out] = $converter->concatenate([$artifact], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // Was 17.8s; speech ends ~14.85s. Allow margin around the ~14.9s cut.
        $this->assertGreaterThan(14.0, $seconds, 'Speech must be preserved.');
        $this->assertLessThan(15.8, $seconds, 'The multi-second drone must be removed.');
    }

    public function test_long_tail_detector_trims_synthetic_drone(): void
    {
        // Broadband "speech" (high zero-crossing rate) then a sustained loud
        // ~90 Hz tone (low ZCR) — the artifact's defining shape. The detector
        // keys on ZCR, so it cuts at the speech/tone boundary.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->noiseWav(1.0, 15000).$this->rawTone(1.0, 8000, 90.0);
        $chunk = $this->wrapWav($chunk);

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // ~1.0s speech + small guard; well under the 2.0s input (the bounded
        // trim alone could only remove ~0.3s, so this proves the detector fired).
        $this->assertGreaterThan(0.85, $seconds, 'The broadband speech must survive.');
        $this->assertLessThan(1.4, $seconds, 'The long low-frequency tail must be cut.');
    }

    public function test_clean_clip_is_not_over_trimmed_by_detector(): void
    {
        // No trailing artifact: broadband speech only. The detector must return
        // null (trailing non-speech < min_artifact_ms) so the clip is preserved.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav($this->noiseWav(1.5, 15000));

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        $this->assertGreaterThan(1.3, $seconds, 'A clean clip must not be over-trimmed.');
    }

    public function test_detector_removes_decay_then_reswell_blip_tail(): void
    {
        // Regression for the tail that slipped past 0.9.0: speech | long quiet
        // decay (~-50 dB, below the floor) | a brief loud, mid-band "re-swell"
        // blip that clears both speech gates. The blip sits at EOF, so the old
        // "last speech window" rule set the speech end to ~EOF and the whole
        // 1.25s tail survived. The peel must drop the long-gap-isolated blip and
        // cut at the real speech end (~1.0s).
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->noiseWav(1.0, 15000)      // speech body (high ZCR)
            .$this->rawTone(1.0, 150, 90.0)  // quiet decay tail (below the RMS floor)
            .$this->noiseWav(0.25, 12000)    // loud re-swell blip (clears both gates)
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // ~1.0s body + guard. Was 2.25s when the blip defeated the detector.
        $this->assertGreaterThan(0.85, $seconds, 'The speech body must survive.');
        $this->assertLessThan(1.4, $seconds, 'The decay tail and re-swell blip must be cut.');
    }

    public function test_loud_low_zcr_speech_is_not_mistaken_for_a_gap_before_the_final_word(): void
    {
        // Regression for over-trimming: a quiet/short final word ("will be") ends
        // in LOUD low-ZCR voiced windows. Those fail the speech (high-ZCR) gate but
        // are not silence, so they must NOT count as the "gap" the blip-peel keys
        // on — otherwise the real word is hard-cut. Layout: broadband speech | a
        // loud 120 Hz voiced region (loud, low ZCR) | a short broadband final word.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->noiseWav(0.8, 15000)        // speech body (high ZCR)
            .$this->rawTone(0.5, 15000, 120.0) // loud voiced region (loud, low ZCR)
            .$this->noiseWav(0.2, 12000)       // short final word (high ZCR)
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // ~1.5s preserved. The bug counted the loud voiced region as a gap, peeled
        // the final word, and cut to ~0.86s.
        $this->assertGreaterThan(1.3, $seconds, 'A final word after loud voiced speech must not be cut.');
    }

    public function test_detector_keeps_short_final_word_after_brief_pause(): void
    {
        // Guard against the peel over-reaching: a genuine short final word after a
        // brief pause (gap < min_artifact_ms) is NOT an isolated artifact blip and
        // must be preserved — only a LONG gap marks the prior speech as ended.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->noiseWav(1.0, 15000)     // speech body
            .$this->rawTone(0.15, 0, 0.0)   // brief internal pause (digital silence)
            .$this->noiseWav(0.3, 12000)    // genuine short final word
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        $this->assertGreaterThan(1.3, $seconds, 'A short final word after a brief pause must survive.');
    }

    /** Broadband noise PCM (high ZCR) — a stand-in for real speech. */
    private function noiseWav(float $seconds, int $amp): string
    {
        mt_srand(1337);
        $n = (int) (44100 * $seconds);
        $samples = '';
        for ($i = 0; $i < $n; $i++) {
            $samples .= pack('v', mt_rand(-$amp, $amp) & 0xFFFF);
        }

        return $samples;
    }

    /** Raw tone PCM samples (no header) at 44.1 kHz. */
    private function rawTone(float $seconds, int $amp, float $freq): string
    {
        $n = (int) (44100 * $seconds);
        $samples = '';
        for ($i = 0; $i < $n; $i++) {
            $value = (int) ($amp * sin(2 * M_PI * $freq * $i / 44100));
            $samples .= pack('v', $value & 0xFFFF);
        }

        return $samples;
    }

    /** Wrap raw mono 16-bit PCM samples in a 44.1 kHz WAV container. */
    private function wrapWav(string $samples): string
    {
        $rate = 44100;

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $rate).pack('V', $rate * 2).pack('v', 2).pack('v', 16)
            .'data'.pack('V', strlen($samples)).$samples;
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
