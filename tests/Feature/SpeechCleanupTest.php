<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpeechCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function makeSpeech(Carbon $expiresAt): Speech
    {
        $key = ApiKey::generate('t');
        $voice = Voice::create(['slug' => 'v-'.Str::random(6), 'name' => 'V']);

        $path = trim((string) config('tts.storage_path', 'speech'), '/').'/'.Str::uuid().'.mp3';
        Storage::disk('local')->put($path, 'AUDIO-BYTES');

        return Speech::create([
            'api_key_id' => $key->id,
            'voice_id' => $voice->id,
            'text' => 'hi',
            'cache_hash' => Str::random(16),
            'settings' => [],
            'model_id' => 'eleven_v3',
            'output_format' => 'mp3_44100_128',
            'status' => SpeechStatus::Completed,
            'audio_path' => $path,
            'mime_type' => 'audio/mpeg',
            'characters' => 2,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_it_deletes_expired_records_and_keeps_fresh_ones(): void
    {
        $expired = $this->makeSpeech(Carbon::now()->subDay());
        $fresh = $this->makeSpeech(Carbon::now()->addDay());

        $this->artisan('speech:cleanup')->assertExitCode(0);

        $this->assertNull(Speech::find($expired->id), 'Expired record should be deleted.');
        $this->assertNotNull(Speech::find($fresh->id), 'Fresh record should be kept.');

        Storage::disk('local')->assertMissing($expired->audio_path);
        Storage::disk('local')->assertExists($fresh->audio_path);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $expired = $this->makeSpeech(Carbon::now()->subDay());

        $this->artisan('speech:cleanup', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull(Speech::find($expired->id));
        Storage::disk('local')->assertExists($expired->audio_path);
    }

    public function test_before_override_controls_the_cutoff(): void
    {
        // Expires in 2 days; not expired now, but is "before" a cutoff 3 days out.
        $soon = $this->makeSpeech(Carbon::now()->addDays(2));

        $this->artisan('speech:cleanup', ['--before' => Carbon::now()->addDays(3)->toDateTimeString()])
            ->assertExitCode(0);

        $this->assertNull(Speech::find($soon->id));
    }
}
