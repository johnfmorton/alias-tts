<?php

namespace Tests\Feature;

use App\Enums\SpeechStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
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

    /** Write a file under the speech path and age its mtime past the orphan guard. */
    private function makeOldFile(string $path, int $ageHours = 48): string
    {
        Storage::disk('local')->put($path, 'ORPHAN-BYTES');
        touch(Storage::disk('local')->path($path), time() - $ageHours * 3600);

        return $path;
    }

    /** A project with a chunk, a take, a final, and a sealed file — all on disk. */
    private function makeProjectWithAudio(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v-'.Str::random(6), 'name' => 'V']);

        $project = TtsProject::create([
            'title' => 'P',
            'voice_id' => $voice->id,
            'settings' => [],
            'model_id' => 'chatterbox',
            'output_format' => 'mp3_44100_128',
            'source_text' => 'hi',
            'normalized_text' => 'hi',
            'status' => 'draft',
            'final_audio_path' => 'speech/projects/p1/final.mp3',
            'sealed_audio_path' => 'speech/projects/p1/sealed/abc.mp3',
        ]);

        $chunk = TtsChunk::create([
            'tts_project_id' => $project->id,
            'position' => 0,
            'text' => 'hi',
            'break_after' => 'sentence',
            'status' => 'completed',
            'audio_path' => 'speech/projects/p1/chunks/c1/takes/t1.wav',
            'characters' => 2,
        ]);

        TtsChunkTake::create([
            'tts_chunk_id' => $chunk->id,
            'audio_path' => 'speech/projects/p1/chunks/c1/takes/t1.wav',
            'source' => 'generate',
        ]);

        foreach (['speech/projects/p1/final.mp3', 'speech/projects/p1/sealed/abc.mp3', 'speech/projects/p1/chunks/c1/takes/t1.wav'] as $path) {
            $this->makeOldFile($path);
        }

        return $project;
    }

    public function test_orphan_sweep_deletes_unreferenced_files_and_keeps_referenced_ones(): void
    {
        $tracked = $this->makeSpeech(Carbon::now()->addDay());
        touch(Storage::disk('local')->path($tracked->audio_path), time() - 48 * 3600); // old but referenced
        $this->makeProjectWithAudio();

        $orphan = $this->makeOldFile('speech/deadbeef.mp3');
        $orphanTake = $this->makeOldFile('speech/projects/gone/chunks/x/takes/y.wav');

        $this->artisan('speech:cleanup', ['--orphans' => true])->assertExitCode(0);

        Storage::disk('local')->assertMissing($orphan);
        Storage::disk('local')->assertMissing($orphanTake);

        Storage::disk('local')->assertExists($tracked->audio_path);
        Storage::disk('local')->assertExists('speech/projects/p1/final.mp3');
        Storage::disk('local')->assertExists('speech/projects/p1/sealed/abc.mp3');
        Storage::disk('local')->assertExists('speech/projects/p1/chunks/c1/takes/t1.wav');
    }

    public function test_orphan_sweep_never_leaves_the_speech_path(): void
    {
        // Files owned by other features (or other apps on a shared disk) live
        // outside tts.storage_path and must never be candidates.
        $voiceClip = $this->makeOldFile('voices/someone.wav');
        $avatar = $this->makeOldFile('avatars/someone.png');

        $this->artisan('speech:cleanup', ['--orphans' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists($voiceClip);
        Storage::disk('local')->assertExists($avatar);
    }

    public function test_orphan_sweep_spares_files_younger_than_the_age_guard(): void
    {
        // Just written, no row yet — exactly what an in-flight generation looks like.
        Storage::disk('local')->put('speech/in-flight.mp3', 'FRESH-BYTES');

        $this->artisan('speech:cleanup', ['--orphans' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists('speech/in-flight.mp3');
    }

    public function test_orphan_sweep_age_guard_is_tunable(): void
    {
        Storage::disk('local')->put('speech/in-flight.mp3', 'FRESH-BYTES');

        $this->artisan('speech:cleanup', ['--orphans' => true, '--orphan-age' => 0])->assertExitCode(0);

        Storage::disk('local')->assertMissing('speech/in-flight.mp3');
    }

    public function test_orphan_sweep_respects_dry_run(): void
    {
        $orphan = $this->makeOldFile('speech/deadbeef.mp3');

        $this->artisan('speech:cleanup', ['--orphans' => true, '--dry-run' => true])->assertExitCode(0);

        Storage::disk('local')->assertExists($orphan);
    }

    public function test_orphans_are_untouched_without_the_flag(): void
    {
        $orphan = $this->makeOldFile('speech/deadbeef.mp3');

        $this->artisan('speech:cleanup')->assertExitCode(0);

        Storage::disk('local')->assertExists($orphan);
    }
}
