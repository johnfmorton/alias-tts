<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\VoiceClipService;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Over-long reference clips are trimmed ONCE at save time — at a natural
 * pause, mid-silence with a fade, never mid-word — because the whole stored
 * clip ships as a data URI with EVERY chunk render while the engines only
 * read its head.
 */
class ReferenceClipTrimTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 8000;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    /** Broadband noise samples ("speech" to the energy scan). */
    private function noise(float $seconds, int $amp = 15000): string
    {
        mt_srand(4242);
        $n = (int) (self::RATE * $seconds);
        $samples = '';
        for ($i = 0; $i < $n; $i++) {
            $samples .= pack('s', mt_rand(-$amp, $amp));
        }

        return $samples;
    }

    private function silence(float $seconds): string
    {
        return str_repeat("\x00\x00", (int) (self::RATE * $seconds));
    }

    private function wrapWav(string $samples, int $channels = 1): string
    {
        $blockAlign = $channels * 2;

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', $channels)
            .pack('V', self::RATE).pack('V', self::RATE * $blockAlign)
            .pack('v', $blockAlign).pack('v', 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }

    /** 12s of "speech" with a one-second pause at 3s: noise 0–3, silence 3–4, noise 4–12. */
    private function speechWithPauseWav(): string
    {
        return $this->wrapWav($this->noise(3.0).$this->silence(1.0).$this->noise(8.0));
    }

    public function test_the_cut_lands_inside_the_pause_not_mid_word(): void
    {
        $trimmed = app(AudioConverter::class)->trimReference($this->speechWithPauseWav(), 5.0);

        $this->assertNotNull($trimmed);
        $duration = app(AudioConverter::class)->wavDurationSeconds($trimmed);
        // The only pause in the [1s, 5s] search window is 3–4s — the cut must
        // land inside it, not at the 5s cap (which would clip a "word").
        $this->assertGreaterThan(3.0, $duration);
        $this->assertLessThan(4.0, $duration);

        // The trim ends in a fade, not a click: the final ~1ms is near-silent.
        $tail = array_values((array) unpack('s*', substr($trimmed, -16)));
        $this->assertLessThan(500, max(array_map('abs', $tail)));
    }

    public function test_continuous_speech_falls_back_to_a_faded_cut_at_the_cap(): void
    {
        $trimmed = app(AudioConverter::class)->trimReference($this->wrapWav($this->noise(12.0)), 5.0);

        $this->assertNotNull($trimmed);
        $this->assertEqualsWithDelta(5.0, app(AudioConverter::class)->wavDurationSeconds($trimmed), 0.05);

        $tail = array_values((array) unpack('s*', substr($trimmed, -16)));
        $this->assertLessThan(500, max(array_map('abs', $tail)));
    }

    public function test_clips_within_the_cap_are_left_untouched(): void
    {
        $converter = app(AudioConverter::class);

        $this->assertNull($converter->trimReference($this->wrapWav($this->noise(4.0)), 5.0));
        // 0 disables the cap entirely.
        $this->assertNull($converter->trimReference($this->wrapWav($this->noise(12.0)), 0.0));
    }

    public function test_stereo_clips_trim_and_stay_stereo(): void
    {
        // Interleave two identical channels of the pause fixture.
        $mono = $this->noise(3.0).$this->silence(1.0).$this->noise(8.0);
        $stereo = '';
        foreach ((array) unpack('s*', $mono) as $s) {
            $stereo .= pack('s', $s).pack('s', $s);
        }

        $trimmed = app(AudioConverter::class)->trimReference($this->wrapWav($stereo, channels: 2), 5.0);

        $this->assertNotNull($trimmed);
        $duration = app(AudioConverter::class)->wavDurationSeconds($trimmed);
        $this->assertGreaterThan(3.0, $duration);
        $this->assertLessThan(4.0, $duration);
    }

    public function test_staging_trims_and_reports_the_original_length(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        $user = User::factory()->create();

        $clip = app(VoiceClipService::class)->stage($this->speechWithPauseWav(), enhance: false, userId: $user->id);

        $this->assertLessThan(5.5, $clip->original_duration);
        $this->assertEqualsWithDelta(12.0, $clip->trimmedFromSeconds, 0.2);
        $this->assertLessThan(
            5.5 * self::RATE * 2 + 200,
            strlen(Storage::disk('local')->get($clip->original_path)),
        );
    }

    public function test_a_direct_voice_save_caps_the_stored_clip(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);

        $voice = app(VoiceService::class)->register(
            name: 'Capped', slug: 'capped', audioBytes: $this->speechWithPauseWav(), ext: 'wav',
            normalize: false, seed: null,
        );

        $stored = Storage::disk(config('tts.storage_disk'))->get($voice->reference_audio_path);
        $duration = app(AudioConverter::class)->wavDurationSeconds($stored);
        $this->assertNotNull($duration);
        $this->assertLessThan(5.5, $duration);
    }

    public function test_the_trim_is_disabled_cleanly(): void
    {
        config(['tts.reference_max_seconds' => 0]);
        $user = User::factory()->create();

        $clip = app(VoiceClipService::class)->stage($this->speechWithPauseWav(), enhance: false, userId: $user->id);

        $this->assertEqualsWithDelta(12.0, $clip->original_duration, 0.2);
        $this->assertNull($clip->trimmedFromSeconds);
    }

    // ---- voices:trim-references (backfill for pre-cap clips) ----------------

    /** @return array{0: Voice, 1: Voice} [long, short] */
    private function backfillVoices(): array
    {
        Storage::disk('local')->put('voices/long.wav', $this->speechWithPauseWav());
        Storage::disk('local')->put('voices/short.wav', $this->wrapWav($this->noise(4.0)));

        return [
            Voice::create(['slug' => 'long-v', 'name' => 'Long V', 'reference_audio_path' => 'voices/long.wav']),
            Voice::create(['slug' => 'short-v', 'name' => 'Short V', 'reference_audio_path' => 'voices/short.wav']),
        ];
    }

    public function test_the_backfill_command_trims_only_over_cap_clips_in_place(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        [$long, $short] = $this->backfillVoices();

        // One expectation for the whole summary line (the harness lets a line
        // satisfy only one expectation). "2 missing" = the seeded built-in
        // voices, whose clips don't exist on the fake disk.
        $this->artisan('voices:trim-references')
            ->expectsOutputToContain('long-v')
            ->expectsOutputToContain('1 trimmed · 1 already within the 5s cap · 2 missing · 0 failed')
            ->assertSuccessful();

        $converter = app(AudioConverter::class);
        $this->assertLessThan(5.5, $converter->wavDurationSeconds(Storage::disk('local')->get('voices/long.wav')));
        $this->assertEqualsWithDelta(4.0, $converter->wavDurationSeconds(Storage::disk('local')->get('voices/short.wav')), 0.1);
        // WAV in, WAV out — the stored path never changes for WAV clips.
        $this->assertSame('voices/long.wav', $long->fresh()->reference_audio_path);
    }

    public function test_the_backfill_dry_run_writes_nothing(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        $this->backfillVoices();
        $before = Storage::disk('local')->get('voices/long.wav');

        $this->artisan('voices:trim-references', ['--dry-run' => true])
            ->expectsOutputToContain('would trim')
            ->assertSuccessful();

        $this->assertSame($before, Storage::disk('local')->get('voices/long.wav'));
    }

    public function test_the_backfill_voice_filter_scopes_the_run(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        Storage::disk('local')->put('voices/other.wav', $this->speechWithPauseWav());
        Voice::create(['slug' => 'other-v', 'name' => 'Other V', 'reference_audio_path' => 'voices/other.wav']);
        $this->backfillVoices();

        $this->artisan('voices:trim-references', ['--voice' => ['other-v']])
            ->expectsOutputToContain('1 trimmed')
            ->assertSuccessful();

        // Only the named voice was touched.
        $this->assertLessThan(5.5, app(AudioConverter::class)->wavDurationSeconds(Storage::disk('local')->get('voices/other.wav')));
        $this->assertEqualsWithDelta(12.0, app(AudioConverter::class)->wavDurationSeconds(Storage::disk('local')->get('voices/long.wav')), 0.2);
    }

    // ---- shared-storage guard -----------------------------------------------

    /**
     * Point tts.storage_disk at a disk the CONFIG calls s3 while the faked
     * disk behind it stays local — the guard reads config, so this exercises
     * it without reaching a real bucket.
     */
    private function pretendRemoteDisk(): void
    {
        config(['filesystems.disks.local.driver' => 's3', 'filesystems.disks.local.bucket' => 'shared-bucket']);
    }

    public function test_a_remote_storage_disk_stops_the_run_without_force(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        $this->backfillVoices();
        $before = Storage::disk('local')->get('voices/long.wav');
        $this->pretendRemoteDisk();

        // A dev machine holding production's credentials reaches production's
        // objects at the same paths — the command can't tell, so it asks.
        // One expectation per output line (the harness matches a line once).
        $this->artisan('voices:trim-references')
            ->expectsOutputToContain('Refusing to rewrite reference clips on the "local" disk (bucket shared-bucket)')
            ->expectsOutputToContain('Run it on the machine that owns that storage')
            ->assertFailed();

        $this->assertSame($before, Storage::disk('local')->get('voices/long.wav'));
    }

    public function test_force_allows_the_run_and_a_dry_run_never_needs_it(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        $this->backfillVoices();
        $this->pretendRemoteDisk();

        // Reading is always allowed; only writes are gated.
        $this->artisan('voices:trim-references', ['--dry-run' => true])
            ->expectsOutputToContain('would trim')
            ->assertSuccessful();

        $this->artisan('voices:trim-references', ['--force' => true])
            ->expectsOutputToContain('trimmed')
            ->assertSuccessful();

        $this->assertLessThan(5.5, app(AudioConverter::class)->wavDurationSeconds(Storage::disk('local')->get('voices/long.wav')));
    }

    // ---- transcripts stay in sync with the trimmed clip ---------------------

    /** A qwen voice whose stored transcript describes the FULL 12s take. */
    private function qwenVoiceWithTranscript(): Voice
    {
        Storage::disk('local')->put('voices/q.wav', $this->speechWithPauseWav());

        return Voice::create([
            'slug' => 'q-v',
            'name' => 'Q V',
            'model' => 'qwen3-tts',
            'reference_audio_path' => 'voices/q.wav',
            'settings' => ['reference_text' => 'Every word of the untrimmed take, all twelve seconds of it.'],
        ]);
    }

    private function fakeAsr(string $text): void
    {
        config(['tts.asr.enabled' => true, 'tts.asr.url' => 'http://asr.test']);
        Http::fake(['asr.test/transcribe' => Http::response(['duration' => 3.5, 'text' => $text, 'words' => []])]);
    }

    public function test_trimming_re_reads_the_clips_transcript(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        $this->fakeAsr('Only what survives the trim.');
        $voice = $this->qwenVoiceWithTranscript();

        $this->artisan('voices:trim-references', ['--voice' => ['q-v']])
            ->expectsOutputToContain('transcript re-read')
            ->assertSuccessful();

        // qwen reads the transcript ALONG with the clip — a stale one asks the
        // model to hear words that are no longer in the audio.
        $this->assertSame('Only what survives the trim.', $voice->fresh()->settings['reference_text']);
    }

    public function test_a_transcript_that_cannot_be_re_read_is_reported_not_silently_left(): void
    {
        config(['tts.reference_max_seconds' => 5.0, 'tts.asr.enabled' => false]);
        $voice = $this->qwenVoiceWithTranscript();
        $original = $voice->settings['reference_text'];

        $this->artisan('voices:trim-references', ['--voice' => ['q-v']])
            ->expectsOutputToContain('still describes the untrimmed clip')
            ->expectsOutputToContain('1 left stale')
            ->assertSuccessful();

        // The clip is trimmed either way; the transcript is left for a human.
        $this->assertLessThan(5.5, app(AudioConverter::class)->wavDurationSeconds(Storage::disk('local')->get('voices/q.wav')));
        $this->assertSame($original, $voice->fresh()->settings['reference_text']);
    }

    public function test_retranscribe_repairs_a_voice_trimmed_before_transcripts_were_refreshed(): void
    {
        // Already within the cap: nothing to trim, but the transcript is stale
        // from an earlier trim run.
        config(['tts.reference_max_seconds' => 30.0]);
        $this->fakeAsr('The already-trimmed clip, read back.');
        $voice = $this->qwenVoiceWithTranscript();

        $this->artisan('voices:trim-references', ['--voice' => ['q-v'], '--retranscribe' => true])
            ->expectsOutputToContain('0 trimmed')
            ->assertSuccessful();

        $this->assertSame('The already-trimmed clip, read back.', $voice->fresh()->settings['reference_text']);
    }

    public function test_the_dry_run_does_not_touch_the_transcript(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        $this->fakeAsr('Should never be written.');
        $voice = $this->qwenVoiceWithTranscript();
        $original = $voice->settings['reference_text'];

        $this->artisan('voices:trim-references', ['--voice' => ['q-v'], '--dry-run' => true])
            ->expectsOutputToContain('transcript would be re-read')
            ->assertSuccessful();

        $this->assertSame($original, $voice->fresh()->settings['reference_text']);
        Http::assertNothingSent();
    }

    public function test_an_empty_transcript_is_left_empty(): void
    {
        config(['tts.reference_max_seconds' => 5.0]);
        $this->fakeAsr('Unasked-for transcript.');
        $voice = $this->qwenVoiceWithTranscript();
        $voice->update(['settings' => []]);

        $this->artisan('voices:trim-references', ['--voice' => ['q-v']])->assertSuccessful();

        // Save-time auto-transcription owns the empty case; a user who cleared
        // the field shouldn't get one back from a maintenance command.
        $this->assertArrayNotHasKey('reference_text', (array) $voice->fresh()->settings);
        Http::assertNothingSent();
    }
}
