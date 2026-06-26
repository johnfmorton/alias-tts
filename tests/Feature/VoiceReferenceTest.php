<?php

namespace Tests\Feature;

use App\Models\Voice;
use App\Services\Tts\VoiceReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A voice's reference clip must resolve to a real LOCAL filesystem path for the
 * providers, regardless of TTS_STORAGE_DISK — the switch to S3 broke this
 * (`Storage::disk('s3')->path()` isn't a file), so cloned voices failed.
 */
class VoiceReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_when_the_voice_has_no_reference(): void
    {
        $voice = Voice::create(['slug' => 'd', 'name' => 'D']);

        $this->assertNull(VoiceReference::localPath($voice));
        $this->assertNull(VoiceReference::localPath(null));
    }

    public function test_uses_a_local_clip_in_place_even_when_the_disk_is_s3(): void
    {
        // A clip uploaded before a switch to S3 still lives on the local disk.
        config(['tts.storage_disk' => 's3']);
        Storage::fake('s3');
        Storage::fake('local');
        Storage::disk('local')->put('voices/old.wav', 'LOCALBYTES');

        $voice = Voice::create(['slug' => 'a', 'name' => 'A', 'reference_audio_path' => 'voices/old.wav']);

        $path = VoiceReference::localPath($voice);
        $this->assertNotNull($path);
        $this->assertStringEndsWith('voices/old.wav', $path);
    }

    public function test_caches_an_s3_clip_down_to_a_local_path(): void
    {
        config(['tts.storage_disk' => 's3']);
        Storage::fake('s3');
        Storage::fake('local');
        Storage::disk('s3')->put('voices/new.wav', 'REMOTEBYTES');

        $voice = Voice::create(['slug' => 'b', 'name' => 'B', 'reference_audio_path' => 'voices/new.wav']);

        $path = VoiceReference::localPath($voice);
        $this->assertNotNull($path);
        $this->assertTrue(Storage::disk('local')->exists('voices/new.wav'));
        $this->assertSame('REMOTEBYTES', Storage::disk('local')->get('voices/new.wav'));
    }

    public function test_null_when_the_clip_is_gone_from_every_disk(): void
    {
        config(['tts.storage_disk' => 's3']);
        Storage::fake('s3');
        Storage::fake('local');

        $voice = Voice::create(['slug' => 'c', 'name' => 'C', 'reference_audio_path' => 'voices/missing.wav']);

        $this->assertNull(VoiceReference::localPath($voice));
    }
}
