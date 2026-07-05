<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class VoiceTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    public function test_export_import_round_trip(): void
    {
        $service = app(VoiceService::class);
        $voice = $service->register('Round Trip', 'round-trip', $this->silentWav(0.2), 'wav', false, 111);

        $zip = $service->export($voice);
        $this->assertNotEmpty($zip);
        $this->assertSame('PK', substr($zip, 0, 2)); // zip magic bytes

        $service->delete($voice);
        $this->assertNull(Voice::firstWhere('slug', 'round-trip'));

        $imported = $service->import($zip);
        $this->assertSame('round-trip', $imported->slug);
        $this->assertSame('Round Trip', $imported->name);
        $this->assertSame(111, $imported->settings['seed']);
        $this->assertNotNull($imported->reference_audio_path);
        Storage::disk('local')->assertExists($imported->reference_audio_path);
    }

    public function test_import_rejects_a_non_archive(): void
    {
        $this->expectException(Throwable::class);

        app(VoiceService::class)->import('this is not a zip');
    }

    public function test_admin_can_export_and_import_via_dashboard(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $service = app(VoiceService::class);
        $voice = $service->register('Dash Voice', 'dash-voice', $this->silentWav(0.2), 'wav', false, 7);

        $export = $this->actingAs($admin)->get(route('admin.voices.export', $voice));
        $export->assertOk();
        $this->assertStringContainsString('application/zip', (string) $export->headers->get('content-type'));
        $zipBytes = $export->getContent();
        $this->assertNotEmpty($zipBytes);

        $service->delete($voice);
        $this->assertNull(Voice::firstWhere('slug', 'dash-voice'));

        // Old ".bespoken-voice.zip" name on purpose: import is content-based
        // (reads voice.json), so archives exported before the Alias rename still import.
        $upload = UploadedFile::fake()->createWithContent('dash-voice.bespoken-voice.zip', $zipBytes);
        $this->actingAs($admin)->post(route('admin.voices.import'), ['archive' => $upload])
            ->assertRedirect(route('admin.voices.index'));

        $imported = Voice::firstWhere('slug', 'dash-voice');
        $this->assertNotNull($imported);
        $this->assertSame(7, $imported->settings['seed']);
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
