<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\Enhance\EnhanceProvider;
use App\Services\Enhance\FakeEnhanceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The no-JS synchronous reference-clip cleanup path: when the "clean up" checkbox
 * is on, VoiceController enhances the uploaded clip during the POST. Cleanup is
 * degrade-safe — a failed enhance falls back to the original clip and flashes a
 * warning rather than blocking the save. (The AJAX prepare/preview flow is the
 * preferred path; this covers the plain form fallback.)
 */
class VoiceEnhanceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake enhancer (phpunit.xml default) + real ffmpeg decode; local disk.
        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.enhance.enabled' => true,
            'tts.enhance.provider' => 'fake',
        ]);
        Storage::fake('local');

        $this->dir = sys_get_temp_dir().'/tts_enhance_'.uniqid();
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_enhance_on_stores_the_cleaned_up_clip(): void
    {
        // raw=1 skips loudness-normalization so the stored reference is exactly
        // the enhancer's output — a clean byte-for-byte assertion.
        $response = $this->actingAs($this->admin())->post(route('admin.voices.store'), [
            'name' => 'Cleaned Voice',
            'enhance' => '1',
            'raw' => '1',
            'audio' => $this->wavUpload(),
            'clip_rights' => '1',
        ]);

        $voice = Voice::where('slug', 'cleaned-voice')->first();
        $this->assertNotNull($voice);
        $response->assertRedirect(route('admin.voices.edit', $voice));
        $response->assertSessionMissing('warning');

        $this->assertSame(
            (new FakeEnhanceProvider)->output(),
            Storage::disk('local')->get($voice->reference_audio_path),
            'The stored reference should be the enhancer output, not the original upload.',
        );
    }

    public function test_a_failed_enhance_falls_back_to_the_original_and_warns(): void
    {
        $this->app->bind(EnhanceProvider::class, fn () => new FakeEnhanceProvider(fail: true));

        $response = $this->actingAs($this->admin())->post(route('admin.voices.store'), [
            'name' => 'Fallback Voice',
            'enhance' => '1',
            'raw' => '1',
            'audio' => $this->wavUpload(),
            'clip_rights' => '1',
        ]);

        $voice = Voice::where('slug', 'fallback-voice')->first();
        $this->assertNotNull($voice, 'A failed cleanup must not block saving the voice.');
        $response->assertSessionHas('warning');

        // The stored clip is the decoded original, never the (failed) enhancer output.
        $stored = Storage::disk('local')->get($voice->reference_audio_path);
        $this->assertNotSame((new FakeEnhanceProvider)->output(), $stored);
        $this->assertNotEmpty($stored);
    }

    public function test_enhance_off_never_calls_the_enhancer(): void
    {
        // Even a failing enhancer is irrelevant when the box is unchecked.
        $this->app->bind(EnhanceProvider::class, fn () => new FakeEnhanceProvider(fail: true));

        $response = $this->actingAs($this->admin())->post(route('admin.voices.store'), [
            'name' => 'Plain Voice',
            'raw' => '1',
            'audio' => $this->wavUpload(),
            'clip_rights' => '1',
        ]);

        $voice = Voice::where('slug', 'plain-voice')->first();
        $this->assertNotNull($voice);
        $response->assertSessionMissing('warning');
    }

    public function test_the_create_and_edit_pages_render_the_cleanup_checkbox(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('Clean up the clip before saving')
            ->assertSee('name="enhance"', false);

        $voice = Voice::create(['user_id' => $admin->id, 'slug' => 'v', 'name' => 'V']);
        $this->actingAs($admin)->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('Clean up the replacement clip before saving');
    }

    public function test_the_create_and_edit_pages_render_the_recording_tips(): void
    {
        $admin = $this->admin();

        // Always visible — good mic technique is the #1 factor in clone quality,
        // so the tips are not dismissible and not tied to the enhance feature.
        $this->actingAs($admin)->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('Get a great recording')
            ->assertSee('full conversational volume');

        $voice = Voice::create(['user_id' => $admin->id, 'slug' => 'tips-voice', 'name' => 'Tips Voice']);
        $this->actingAs($admin)->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('Get a great recording');

        config(['tts.enhance.enabled' => false]);
        $this->actingAs($admin)->get(route('admin.voices.create'))
            ->assertOk()
            ->assertSee('Get a great recording');
    }

    public function test_the_create_page_ships_the_recorder_markup_and_scripts(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.voices.create'))->assertOk()->getContent();

        // Progressive enhancement: the recorder + segmented control ship in the
        // markup; JS reveals/activates them when getUserMedia + MediaRecorder exist.
        $this->assertStringContainsString('data-clip-mode="record"', $html); // segmented Record tab
        $this->assertStringContainsString('Pick a script to read', $html);   // the teleprompter script picker
        $this->assertStringContainsString('The old harbor wakes slowly', $html); // a reading script
        $this->assertStringContainsString('data-clip-ab', $html); // the A/B compare panel
    }

    public function test_the_checkbox_is_hidden_when_enhancement_is_disabled(): void
    {
        config(['tts.enhance.enabled' => false]);

        $this->actingAs($this->admin())->get(route('admin.voices.create'))
            ->assertOk()
            ->assertDontSee('name="enhance"', false);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function wavUpload(): UploadedFile
    {
        $path = $this->dir.'/clip.wav';
        $process = new Process([
            (string) config('tts.ffmpeg_path', 'ffmpeg'), '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=330:duration=1',
            '-ac', '1', '-ar', '44100', '-c:a', 'pcm_s16le', $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($path)) {
            $this->fail('ffmpeg could not build the test clip: '.trim($process->getErrorOutput()));
        }

        // test=true so Laravel treats it as a genuine upload (skips is_uploaded_file).
        return new UploadedFile($path, 'clip.wav', 'audio/wav', null, true);
    }
}
