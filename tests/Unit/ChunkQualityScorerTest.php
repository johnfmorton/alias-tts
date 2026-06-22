<?php

namespace Tests\Unit;

use App\Services\Asr\ChunkQualityScorer;
use Tests\TestCase;

/**
 * The scorer is pure (no network/DB), so we feed it canned Whisper-style
 * transcripts — one per failure mode the ASR round-trip is meant to catch — and
 * assert the verdict. Thresholds mirror the config defaults.
 */
class ChunkQualityScorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.asr.trail_s_max' => 1.2,
            'tts.asr.gap_s_max' => 1.5,
            'tts.asr.tail_cov_min' => 0.93,
            'tts.asr.trim_guard_ms' => 80,
            'tts.asr.tail_energy_dbfs_max' => -38,
            'tts.asr.boundary_energy_dbfs_max' => -55,
            'tts.asr.boundary_zcr_max_hz' => 1500,
        ]);
    }

    private function scorer(): ChunkQualityScorer
    {
        return new ChunkQualityScorer;
    }

    /**
     * Build a transcript payload from [word, start, end] triples.
     *
     * @param  array<int, array{0:string,1:float,2:float}>  $words
     */
    private function transcript(array $words, float $duration): array
    {
        return [
            'duration' => $duration,
            'text' => implode(' ', array_map(fn ($w) => $w[0], $words)),
            'words' => array_map(fn ($w) => ['word' => $w[0], 'start' => $w[1], 'end' => $w[2]], $words),
        ];
    }

    /** Consecutive words, 0.4s each back-to-back starting at $start. */
    private function fluentWords(array $tokens, float $start = 0.0): array
    {
        $words = [];
        $t = $start;
        foreach ($tokens as $tok) {
            $words[] = [$tok, $t, $t + 0.4];
            $t += 0.4;
        }

        return $words;
    }

    public function test_clean_chunk_passes(): void
    {
        $tokens = ['The', 'quick', 'brown', 'fox', 'jumps'];
        $words = $this->fluentWords($tokens);
        $transcript = $this->transcript($words, duration: 2.3); // last word ends 2.0

        $v = $this->scorer()->score('The quick brown fox jumps.', $transcript);

        $this->assertTrue($v->ok);
        $this->assertSame([], $v->problems);
        $this->assertSame(1.0, $v->score);
        $this->assertSame(1.0, $v->tailCov);
        $this->assertNull($v->trimAtMs);
    }

    public function test_truncation_is_flagged_when_transcript_misses_the_end(): void
    {
        // Source has 9 words; the take only got the first 4.
        $words = $this->fluentWords(['the', 'quick', 'brown', 'fox']);
        $transcript = $this->transcript($words, duration: 1.8);

        $v = $this->scorer()->score('the quick brown fox jumps over the lazy dog', $transcript);

        $this->assertFalse($v->ok);
        $this->assertContains('TRUNC', $v->problems);
        $this->assertLessThan(0.93, $v->tailCov);
    }

    public function test_long_tail_is_flagged_and_yields_a_trim_point(): void
    {
        $words = $this->fluentWords(['hello', 'there', 'friend']); // last word ends 1.2
        $transcript = $this->transcript($words, duration: 4.5);    // ~3.3s of junk after

        $v = $this->scorer()->score('hello there friend', $transcript);

        $this->assertFalse($v->ok);
        $this->assertContains('TAIL', $v->problems);
        $this->assertNotContains('TRUNC', $v->problems); // full coverage
        // trimAtMs = (lastEnd 1.2 + guard 0.08) * 1000
        $this->assertSame(1280, $v->trimAtMs);
    }

    public function test_mid_stream_pause_is_flagged(): void
    {
        // A 2.0s gap between "two" and "three".
        $words = [
            ['one', 0.0, 0.4],
            ['two', 0.4, 0.8],
            ['three', 2.8, 3.2],
            ['four', 3.2, 3.6],
        ];
        $transcript = $this->transcript($words, duration: 3.8);

        $v = $this->scorer()->score('one two three four', $transcript);

        $this->assertFalse($v->ok);
        $this->assertContains('PAUSE', $v->problems);
    }

    public function test_no_speech_is_flagged(): void
    {
        $v = $this->scorer()->score('anything at all', ['duration' => 5.0, 'text' => '', 'words' => []]);

        $this->assertFalse($v->ok);
        $this->assertSame(['NOSPEECH'], $v->problems);
        $this->assertSame(0, $v->wordCount);
    }

    public function test_minor_recognition_errors_still_pass(): void
    {
        // One mis-heard word out of six should not flag a clean take.
        $words = $this->fluentWords(['the', 'cancelation', 'process', 'is', 'a', 'dark']);
        // "cancelation" mis-heard as "cancellation" — still aligns around it.
        $words[1][0] = 'cancellation';
        $transcript = $this->transcript($words, duration: 2.6);

        $v = $this->scorer()->score('the cancelation process is a dark', $transcript);

        $this->assertTrue($v->ok, 'minor ASR error should not flag the chunk');
    }

    public function test_loud_short_tail_is_flagged_as_tailnoise_and_trimmable(): void
    {
        // Tail is only 0.6s (under the 1.2s TAIL threshold) but LOUD — a swoosh
        // the duration signal misses. Energy catches it; it is lossless-trimmable.
        $words = $this->fluentWords(['hello', 'there', 'friend']); // last word ends 1.2
        $transcript = $this->transcript($words, duration: 1.8);    // trail 0.6 < 1.2

        $v = $this->scorer()->score('hello there friend', $transcript, [
            'tail_peak_dbfs' => -10.0,
            'gaps' => [],
        ]);

        $this->assertFalse($v->ok);
        $this->assertContains('TAILNOISE', $v->problems);
        $this->assertNotContains('TAIL', $v->problems); // trail_s under threshold
        $this->assertSame(1280, $v->trimAtMs);          // (1.2 + 0.08 guard) * 1000
        $this->assertSame(-10.0, $v->tailPeakDbfs);
    }

    public function test_quiet_tail_is_not_flagged_as_tailnoise(): void
    {
        $words = $this->fluentWords(['hello', 'there', 'friend']);
        $transcript = $this->transcript($words, duration: 1.8);

        $v = $this->scorer()->score('hello there friend', $transcript, [
            'tail_peak_dbfs' => -45.0, // below -38 threshold
            'gaps' => [],
        ]);

        $this->assertTrue($v->ok);
        $this->assertNotContains('TAILNOISE', $v->problems);
        $this->assertSame(-45.0, $v->tailPeakDbfs);
    }

    /** A take whose only gap (after a period) is a tonal hum, not silence. */
    private function boundaryHumTranscript(): array
    {
        // "noise." ends 0.8; "some" starts 1.9 → a 1.1s gap (< 1.5, so no PAUSE).
        $words = [
            ['the', 0.0, 0.4],
            ['noise.', 0.4, 0.8],
            ['some', 1.9, 2.3],
            ['more', 2.3, 2.7],
        ];

        return $this->transcript($words, duration: 2.9);
    }

    public function test_boundary_hum_is_flagged_as_bndnoise(): void
    {
        $v = $this->scorer()->score('the noise some more', $this->boundaryHumTranscript(), [
            'tail_peak_dbfs' => -60.0,
            'gaps' => [
                1 => ['dur_s' => 1.1, 'mean_dbfs' => -43.0, 'zcr_hz' => 800.0], // gap after "noise."
            ],
        ]);

        $this->assertFalse($v->ok);
        $this->assertContains('BNDNOISE', $v->problems);
        $this->assertNotContains('PAUSE', $v->problems); // gap under the time threshold
        $this->assertSame('noise.', $v->boundaryNoise['after']);
        $this->assertNull($v->trimAtMs); // mid-stream: re-roll, not trim
    }

    public function test_boundary_gap_with_speech_residue_is_not_flagged(): void
    {
        // Same elevated gap, but broadband (high ZCR) — normal co-articulation,
        // not a hum.
        $v = $this->scorer()->score('the noise some more', $this->boundaryHumTranscript(), [
            'tail_peak_dbfs' => -60.0,
            'gaps' => [
                1 => ['dur_s' => 1.1, 'mean_dbfs' => -43.0, 'zcr_hz' => 4200.0],
            ],
        ]);

        $this->assertTrue($v->ok);
        $this->assertNull($v->boundaryNoise);
    }

    public function test_tonal_gap_not_at_a_boundary_is_not_flagged(): void
    {
        // Identical tonal gap, but the preceding word has no punctuation, so it is
        // mid-clause — not a sentence/comma seam where Chatterbox re-attacks.
        $words = [
            ['the', 0.0, 0.4],
            ['noise', 0.4, 0.8], // no period
            ['some', 1.9, 2.3],
            ['more', 2.3, 2.7],
        ];
        $transcript = $this->transcript($words, duration: 2.9);

        $v = $this->scorer()->score('the noise some more', $transcript, [
            'tail_peak_dbfs' => -60.0,
            'gaps' => [
                1 => ['dur_s' => 1.1, 'mean_dbfs' => -43.0, 'zcr_hz' => 800.0],
            ],
        ]);

        $this->assertTrue($v->ok);
    }

    public function test_energy_signals_are_skipped_without_audio_features(): void
    {
        // No audio features ⇒ duration-only behaviour, fully backward compatible.
        $words = $this->fluentWords(['hello', 'there', 'friend']);
        $transcript = $this->transcript($words, duration: 1.8);

        $v = $this->scorer()->score('hello there friend', $transcript); // no $audio

        $this->assertTrue($v->ok);
        $this->assertNull($v->tailPeakDbfs);
        $this->assertNull($v->boundaryNoise);
        $this->assertArrayNotHasKey('tail_peak_dbfs', $v->toArray());
    }
}
