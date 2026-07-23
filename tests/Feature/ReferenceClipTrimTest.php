<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\VoiceClipService;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
