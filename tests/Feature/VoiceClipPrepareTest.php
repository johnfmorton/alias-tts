<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceClip;
use App\Services\Enhance\EnhanceProvider;
use App\Services\Enhance\FakeEnhanceProvider;
use App\Services\VoiceClipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The AJAX prepare/preview flow: POST a recorded/uploaded clip, get back staged
 * original + enhanced WAVs to A/B, then save the voice from the chosen take
 * byte-for-byte. Covers the prepare endpoint, the ranged A/B audio route, token
 * consumption on save (single-use, owner-scoped), and pruning.
 */
class VoiceClipPrepareTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.enhance.enabled' => true,
            'tts.enhance.provider' => 'fake',
        ]);
        Storage::fake('local');

        $this->dir = sys_get_temp_dir().'/tts_prepare_'.uniqid();
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ---- prepare ------------------------------------------------------------

    public function test_prepare_stages_original_and_enhanced_variants(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
            'enhance' => '1',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'enhance_error' => null]);
        $body = $response->json();

        $this->assertSame(40, strlen($body['token']));
        $this->assertNotNull($body['enhanced']);
        $this->assertStringContainsString('original', $body['original']['url']);

        $clip = VoiceClip::where('token', $body['token'])->firstOrFail();
        Storage::disk('local')->assertExists($clip->original_path);
        Storage::disk('local')->assertExists($clip->enhanced_path);
        $this->assertSame((new FakeEnhanceProvider)->output(), Storage::disk('local')->get($clip->enhanced_path));
    }

    public function test_prepare_with_enhance_off_has_no_enhanced_variant(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
        ]);

        $response->assertOk()->assertJson(['enhanced' => null]);

        $clip = VoiceClip::where('token', $response->json('token'))->firstOrFail();
        $this->assertNull($clip->enhanced_path);
    }

    public function test_prepare_is_degrade_safe_when_enhancement_fails(): void
    {
        $this->app->bind(EnhanceProvider::class, fn () => new FakeEnhanceProvider(fail: true));

        $response = $this->actingAs($this->admin())->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
            'enhance' => '1',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('enhanced'));
        $this->assertNotNull($response->json('enhance_error'));
    }

    public function test_prepare_rejects_a_non_audio_file(): void
    {
        $this->actingAs($this->admin())->post(route('admin.voices.clips.store'), [
            'audio' => UploadedFile::fake()->createWithContent('notes.txt', 'not audio at all'),
        ])->assertStatus(422);
    }

    public function test_prepare_rejects_an_over_long_clip(): void
    {
        config(['tts.enhance.max_clip_seconds' => 1]);

        $this->actingAs($this->admin())->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(seconds: 2),
        ])->assertStatus(422)->assertJsonFragment(['message' => 'That clip is too long (2s) — keep it under 1s.']);
    }

    // ---- A/B audio route ----------------------------------------------------

    public function test_the_variant_audio_route_supports_ranges_and_is_owner_scoped(): void
    {
        $admin = $this->admin();
        $token = $this->actingAs($admin)->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
            'enhance' => '1',
        ])->json('token');

        $url = route('admin.voices.clips.audio', ['clip' => $token, 'variant' => 'enhanced']);

        // Range request → 206 slice (iOS Safari relies on this).
        $this->actingAs($admin)->get($url, ['Range' => 'bytes=0-9'])
            ->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes');

        // A different user cannot read it.
        $this->actingAs($this->admin())->get($url)->assertNotFound();
    }

    // ---- consumption on save ------------------------------------------------

    public function test_saving_with_a_token_stores_the_chosen_take_byte_for_byte(): void
    {
        $admin = $this->admin();
        $token = $this->actingAs($admin)->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
            'enhance' => '1',
        ])->json('token');

        // raw=1 skips normalization so the stored reference is exactly the chosen
        // (enhanced) take — byte-for-byte with what was previewed.
        $this->actingAs($admin)->post(route('admin.voices.store'), [
            'name' => 'Previewed Voice',
            'clip_token' => $token,
            'clip_choice' => 'enhanced',
            'raw' => '1',
        ])->assertRedirect();

        $voice = Voice::where('slug', 'previewed-voice')->firstOrFail();
        $this->assertSame((new FakeEnhanceProvider)->output(), Storage::disk('local')->get($voice->reference_audio_path));

        // Single-use: the staged clip is gone.
        $this->assertDatabaseMissing('voice_clips', ['token' => $token]);
        Storage::disk('local')->assertMissing('voice-clips/'.$token.'/enhanced.wav');
    }

    public function test_a_foreign_token_is_rejected_and_creates_no_voice(): void
    {
        $owner = $this->admin();
        $token = $this->actingAs($owner)->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
        ])->json('token');

        $this->actingAs($this->admin())->post(route('admin.voices.store'), [
            'name' => 'Thief Voice',
            'clip_token' => $token,
            'clip_choice' => 'original',
        ])->assertRedirect(route('admin.voices.create'))->assertSessionHas('error');

        $this->assertNull(Voice::where('slug', 'thief-voice')->first());
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $admin = $this->admin();
        $token = $this->actingAs($admin)->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
        ])->json('token');

        VoiceClip::where('token', $token)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($admin)->post(route('admin.voices.store'), [
            'name' => 'Stale Voice',
            'clip_token' => $token,
            'clip_choice' => 'original',
        ])->assertSessionHas('error');

        $this->assertNull(Voice::where('slug', 'stale-voice')->first());
    }

    public function test_choosing_enhanced_when_none_exists_is_rejected(): void
    {
        $admin = $this->admin();
        $token = $this->actingAs($admin)->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(), // enhance off → no enhanced variant
        ])->json('token');

        $this->actingAs($admin)->post(route('admin.voices.store'), [
            'name' => 'No Enhanced Voice',
            'clip_token' => $token,
            'clip_choice' => 'enhanced',
        ])->assertSessionHas('error');
    }

    // ---- pruning ------------------------------------------------------------

    public function test_prune_removes_expired_clips_and_keeps_live_ones(): void
    {
        $admin = $this->admin();
        $liveToken = $this->actingAs($admin)->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
        ])->json('token');
        $deadToken = $this->actingAs($admin)->post(route('admin.voices.clips.store'), [
            'audio' => $this->wavUpload(),
        ])->json('token');

        VoiceClip::where('token', $deadToken)->update(['expires_at' => now()->subHour()]);

        $removed = app(VoiceClipService::class)->pruneExpired();

        $this->assertSame(1, $removed);
        $this->assertDatabaseHas('voice_clips', ['token' => $liveToken]);
        $this->assertDatabaseMissing('voice_clips', ['token' => $deadToken]);
        Storage::disk('local')->assertExists('voice-clips/'.$liveToken.'/original.wav');
        Storage::disk('local')->assertMissing('voice-clips/'.$deadToken.'/original.wav');
    }

    // ---- helpers ------------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function wavUpload(int $seconds = 1): UploadedFile
    {
        $path = $this->dir.'/clip_'.uniqid().'.wav';
        $process = new Process([
            (string) config('tts.ffmpeg_path', 'ffmpeg'), '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', "sine=frequency=330:duration={$seconds}",
            '-ac', '1', '-ar', '44100', '-c:a', 'pcm_s16le', $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path)) {
            $this->fail('ffmpeg could not build the test clip: '.trim($process->getErrorOutput()));
        }

        return new UploadedFile($path, basename($path), 'audio/wav', null, true);
    }
}
