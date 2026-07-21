<?php

namespace Tests\Feature;

use App\Services\Audio\AudioConverter;
use Symfony\Component\Process\Process;
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

    public function test_concatenate_stamps_id3_metadata_on_mp3(): void
    {
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunks = [$this->loudMonoWav(0.2), $this->loudMonoWav(0.2)];

        [$bytes, $mime] = $converter->concatenate($chunks, 'mp3_44100_128', 'wav', [0], [], [
            'title' => 'My Project',
            'date' => '2026-07-21',
            'comment' => 'Created with Alias TTS · Voices: Emma, James',
        ]);

        $this->assertSame('audio/mpeg', $mime);
        $tags = $this->id3Tags($bytes);
        $this->assertSame('My Project', $tags['title'] ?? null);
        $this->assertSame('Created with Alias TTS · Voices: Emma, James', $tags['comment'] ?? null);
        // ffmpeg writes the year into TDRC/date; players read at least the year.
        $this->assertStringStartsWith('2026', $tags['date'] ?? '');
    }

    public function test_blank_and_whitespace_metadata_values_are_dropped(): void
    {
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunks = [$this->loudMonoWav(0.2), $this->loudMonoWav(0.2)];

        // An empty title must not emit a tag; a multi-line comment collapses to
        // a single line so the ID3 frame never spills.
        [$bytes] = $converter->concatenate($chunks, 'mp3_44100_128', 'wav', [0], [], [
            'title' => '   ',
            'comment' => "line one\nline two",
        ]);

        $tags = $this->id3Tags($bytes);
        $this->assertArrayNotHasKey('title', $tags);
        $this->assertSame('line one line two', $tags['comment'] ?? null);
    }

    public function test_wav_output_ignores_metadata(): void
    {
        // Metadata is an MP3-only feature; a WAV request must encode cleanly and
        // simply carry no title tag (no error, no bogus stream).
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunks = [$this->loudMonoWav(0.2), $this->loudMonoWav(0.2)];

        [$bytes, $mime] = $converter->concatenate($chunks, 'wav_44100', 'wav', [0], [], [
            'title' => 'My Project',
        ]);

        $this->assertSame('audio/wav', $mime);
        $this->assertSame('RIFF', substr($bytes, 0, 4));
        $this->assertArrayNotHasKey('title', $this->id3Tags($bytes));
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

    public function test_preserve_tail_spares_a_rendered_sound_tag(): void
    {
        // Same shape as the synthetic-drone test — loud non-speech after the
        // words — but here the "artifact" is a WANTED sound (a Turbo [laugh]
        // rendered at the end of the chunk). The per-chunk preserveTails flag
        // must sit the detector out so the tail survives; only the safe
        // bounded silence trim + fades run (which touch nothing loud).
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav($this->noiseWav(1.0, 15000).$this->rawTone(1.0, 8000, 90.0));

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', [], [true]);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        $this->assertGreaterThan(1.8, $seconds, 'A preserved tail must keep the rendered sound.');
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

    public function test_detector_removes_a_long_tonal_swell_tail(): void
    {
        // Regression: a re-swell artifact can be LONGER than blip_max_ms — a
        // sustained drone/swell behind a quiet gap (observed in a real chunk that
        // ramped to a steady ~1.3 kHz tone for >1s after the speech ended). It is
        // too long for the blip path but its ZCR is near-constant (a tone), so the
        // tonal path peels it. Layout: speech | quiet gap | loud steady 1.3 kHz tone.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->noiseWav(0.8, 15000)        // speech body (high, variable ZCR)
            .$this->rawTone(0.5, 0, 0.0)       // quiet gap (digital silence)
            .$this->rawTone(1.0, 12000, 1300.0) // long tonal swell (loud, steady ZCR)
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // ~0.8s body + guard. Was ~2.3s when the long tonal tail survived.
        $this->assertGreaterThan(0.7, $seconds, 'The speech body must survive.');
        $this->assertLessThan(1.4, $seconds, 'The long tonal swell must be cut.');
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

    public function test_detector_keeps_a_short_voiced_coda_at_the_end(): void
    {
        // Regression for the concat clip: a word ending in a VOICED nasal coda
        // (/n/, /m/, /ŋ/) — loud but low-frequency, hence low-ZCR — fails the
        // speech (high-ZCR) gate, so the detector read the loud nasal as the start
        // of a trailing artifact and hard-cut mid-word ("...built in" lost its "n").
        // A short voiced run at/below speech level that tapers to silence is a coda,
        // not a drone, and must be folded back into speech and kept. Layout:
        // broadband speech | a short loud 120 Hz voiced coda | trailing silence.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->noiseWav(1.0, 15000)        // speech body (high ZCR)
            .$this->rawTone(0.25, 9000, 120.0) // short voiced coda (loud, low ZCR, below speech)
            .$this->rawTone(0.6, 0, 0.0)       // trailing silence (the word has ended)
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // ~1.25s preserved (speech + coda). The bug cut at the speech/coda boundary
        // (~1.06s), clipping the coda; the trailing silence is still trimmed (the
        // 1.0s drone in test_long_tail_detector_trims_synthetic_drone — longer than
        // voiced_coda_max_ms — proves a sustained voiced tail is still cut).
        $this->assertGreaterThan(1.2, $seconds, 'A short voiced coda at the end must not be clipped.');
        $this->assertLessThan(1.5, $seconds, 'The trailing silence after the coda must still be trimmed.');
    }

    public function test_coda_fold_keeps_a_stressed_final_word_on_a_pause_heavy_chunk(): void
    {
        // Regression for the clipped "do" ("...to love what you do."): the coda
        // fold's over-speech gate compared each voiced window to the span's MEAN
        // RMS, which pauses and quiet passages dilute — on a real 10.6s chunk the
        // mean sat at -25.9 dB while the loudest speech window was -18.8 dB, so the
        // stressed, fully-voiced final word ("do", -19.7 dB — QUIETER than the
        // chunk's own speech peak) measured "6 dB over speech", was ruled a re-swell
        // swoosh, and hard-cut mid-word at the stitch seam. The reference must be
        // the speech PEAK window: no part of a word beats everything the speaker
        // said by over_speech_db; a real appended swoosh does. Layout: a loud
        // phrase, then pauses and QUIETER phrases (mean ~-16 dB, peak ~-11.6 dB),
        // then a voiced final word at ~-8 dB — above the diluted mean +6 (the old
        // cut) but below the peak +6 (a word, not a swoosh) — then trailing silence.
        // The pauses are kept under min_artifact_ms so the blip-peel stays out of it.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->noiseWav(0.45, 15000)        // loud phrase (high ZCR, ~-11.6 dB peak)
            .$this->rawTone(0.35, 0, 0.0)       // pause (dilutes the mean)
            .$this->noiseWav(0.45, 8000)        // quieter phrase (~-17 dB)
            .$this->rawTone(0.35, 0, 0.0)       // pause
            .$this->noiseWav(0.45, 8000)        // quieter phrase
            .$this->rawTone(0.25, 18400, 120.0) // stressed voiced final word (~-8 dB)
            .$this->rawTone(0.6, 0, 0.0)        // trailing silence
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // Speech + pauses + the final word survive (~2.36s; the trailing silence is
        // still trimmed). The mean-referenced gate cut at the word's onset (~2.11s).
        $this->assertGreaterThan(2.25, $seconds, 'A stressed voiced final word must not be clipped on a pause-heavy chunk.');
        $this->assertLessThan(2.55, $seconds, 'The trailing silence after the final word must still be trimmed.');
    }

    public function test_voicing_detector_removes_a_loud_unvoiced_noise_tail(): void
    {
        // The blind spot the voicing path closes: a LOUD, broadband NOISE tail —
        // one LOUDER than the speech body, the defining trait of a real hiss/swoosh
        // artifact (the corpus swoosh measured ~+9 dB over speech). It is loud +
        // high-ZCR (clears the speech gate) AND aperiodic (not a low-ZCR/tonal
        // artifact either), so only the pitch-voicing check — no fundamental — can
        // tell it from speech, AND only because it is louder than the body (the
        // over-speech gate, so a quiet word-final fricative is spared). Layout: a
        // speech-level VOICED body (150 Hz fundamental, ~-19 dB) then a louder
        // (~-11 dB) unvoiced noise tail.
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->rawTone(1.0, 5000, 150.0)   // speech-level voiced body (clear F0)
            .$this->noiseWav(1.2, 16000)        // LOUDER unvoiced noise tail (real artifact)
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // ~1.0s body + fricative allowance + guard. Was 2.2s when the noise tail
        // (loud + high-ZCR but unvoiced) survived the ZCR/tonal gates.
        $this->assertGreaterThan(0.9, $seconds, 'The voiced body must survive.');
        $this->assertLessThan(1.6, $seconds, 'The loud unvoiced noise tail must be cut.');
    }

    public function test_voicing_keeps_a_long_unvoiced_tail_at_speech_level(): void
    {
        // Regression for the clipped-last-word bug: a genuine word-final unvoiced
        // run (a sustained /s/, /f/, or a devoiced/creaky ending) is loud, high-ZCR
        // and aperiodic — voicing-wise indistinguishable from the artifact above —
        // and routinely runs well past fricative_allowance_ms. The ONLY thing that
        // separates it from a hiss tail is loudness: it tapers off the word, so it
        // sits at/below the speech body's level. With the tail at the SAME level as
        // the body (not louder), the over-speech gate must KEEP it. Without the gate
        // this was hard-cut and the final word was lost (badge/contact assets).
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->rawTone(1.0, 14000, 150.0)  // voiced body
            .$this->noiseWav(0.8, 14000)        // long unvoiced coda, SAME level (not louder)
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        // Body + the full coda survive (~1.8s); only the bounded tail-silence trim
        // applies. The pre-fix voicing cut chopped this to ~1.3s.
        $this->assertGreaterThan(1.7, $seconds, 'A coda-level unvoiced tail must not be cut.');
    }

    public function test_voicing_disabled_leaves_the_unvoiced_tail_in_place(): void
    {
        // Turning the voicing refinement off proves it is what removes the tail:
        // the ZCR/tonal gates alone keep the loud high-ZCR noise.
        config(['tts.chunk_tail_voicing_enabled' => false]);
        $converter = new AudioConverter(config('tts.ffmpeg_path', 'ffmpeg'));
        $chunk = $this->wrapWav(
            $this->rawTone(1.0, 5000, 150.0)
            .$this->noiseWav(1.2, 16000)
        );

        [$out] = $converter->concatenate([$chunk], 'wav', 'wav', []);
        $seconds = $this->wavDataBytes($out) / (44100 * 2);

        $this->assertGreaterThan(2.0, $seconds, 'Without voicing, the loud noise tail survives.');
    }

    public function test_analyze_chunk_energy_measures_loud_tail_and_tonal_boundary_gap(): void
    {
        config([
            'tts.asr.energy_window_ms' => 50,
            'tts.asr.tail_release_ms' => 150,
            'tts.asr.boundary_gap_min_ms' => 500,
            'tts.asr.boundary_gap_inset_ms' => 100,
        ]);

        $rate = 44100;
        $samples = '';
        $tone = function (float $secs, int $amp, float $freq) use (&$samples, $rate): void {
            $n = (int) ($rate * $secs);
            for ($i = 0; $i < $n; $i++) {
                $samples .= pack('v', ((int) ($amp * sin(2 * M_PI * $freq * $i / $rate))) & 0xFFFF);
            }
        };

        // word "alpha." | tonal hum gap | word "beta" | loud swoosh tail
        $tone(0.60, 8000, 220.0);   // speech 1            (~ -12 dBFS)
        $tone(0.90, 280, 110.0);    // low-freq hum gap    (~ -42 dBFS, ZCR ~220 Hz)
        $tone(0.60, 8000, 220.0);   // speech 2
        $tone(0.30, 9000, 300.0);   // loud tail "swoosh"  (~ -11 dBFS)

        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $rate).pack('V', $rate * 2).pack('v', 2).pack('v', 16)
            .'data'.pack('V', strlen($samples)).$samples;

        $words = [
            ['word' => 'alpha.', 'start' => 0.0, 'end' => 0.6],
            ['word' => 'beta', 'start' => 1.5, 'end' => 2.1],
        ];

        $features = (new AudioConverter)->analyzeChunkEnergy($wav, $words, duration: 2.4);

        // Tail: loud, well above the -38 dBFS TAILNOISE threshold.
        $this->assertNotNull($features['tail_peak_dbfs']);
        $this->assertGreaterThan(-20.0, $features['tail_peak_dbfs']);

        // The 0.9s gap after "alpha." is measured: elevated energy + low ZCR (a hum).
        $this->assertArrayHasKey(0, $features['gaps']);
        $gap = $features['gaps'][0];
        $this->assertEqualsWithDelta(0.9, $gap['dur_s'], 0.01);
        $this->assertGreaterThan(-55.0, $gap['mean_dbfs']);   // not silent
        $this->assertLessThan(-30.0, $gap['mean_dbfs']);      // but well below speech
        $this->assertLessThan(1500.0, $gap['zcr_hz']);        // tonal / low-frequency
    }

    public function test_analyze_chunk_energy_ignores_short_gaps_and_a_clean_tail(): void
    {
        config([
            'tts.asr.tail_release_ms' => 150,
            'tts.asr.boundary_gap_min_ms' => 500,
        ]);

        $rate = 44100;
        $samples = '';
        $tone = function (float $secs, int $amp, float $freq) use (&$samples, $rate): void {
            $n = (int) ($rate * $secs);
            for ($i = 0; $i < $n; $i++) {
                $samples .= pack('v', ((int) ($amp * sin(2 * M_PI * $freq * $i / $rate))) & 0xFFFF);
            }
        };

        $tone(0.60, 8000, 220.0);  // speech 1
        $tone(0.20, 0, 0.0);       // short 0.2s gap (< 500ms ⇒ not measured)
        $tone(0.60, 8000, 220.0);  // speech 2 — last word ends here
        $tone(0.40, 0, 0.0);       // silent tail

        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', $rate).pack('V', $rate * 2).pack('v', 2).pack('v', 16)
            .'data'.pack('V', strlen($samples)).$samples;

        $words = [
            ['word' => 'alpha.', 'start' => 0.0, 'end' => 0.6],
            ['word' => 'beta', 'start' => 0.8, 'end' => 1.4],
        ];

        $features = (new AudioConverter)->analyzeChunkEnergy($wav, $words, duration: 1.8);

        $this->assertSame([], $features['gaps']);                 // gap too short to inspect
        $this->assertLessThan(-38.0, $features['tail_peak_dbfs']); // silent tail, below threshold
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

    /**
     * Read a media file's container-level tags via ffprobe.
     *
     * @return array<string, string> lower-cased tag name => value
     */
    private function id3Tags(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'tts_probe_');
        try {
            file_put_contents($file, $bytes);

            $process = new Process([
                'ffprobe', '-hide_banner', '-loglevel', 'error',
                '-show_entries', 'format_tags', '-of', 'json', $file,
            ]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'ffprobe failed: '.$process->getErrorOutput());

            $json = json_decode($process->getOutput(), true);
            $tags = $json['format']['tags'] ?? [];

            return array_change_key_case(is_array($tags) ? $tags : [], CASE_LOWER);
        } finally {
            @unlink($file);
        }
    }
}
