<?php

namespace Tests\Feature;

use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The clip-replacement migration (2026_07_06_000001) swaps the old bundled
 * default-voice clips for the new rights-clean ones on installs that already
 * ran the seeding migration. It must restore a missing bundled clip, but never
 * clobber a custom clip an admin uploaded — which lands at the very same
 * `voices/<slug>.wav` path, so the migration guards on content hash, not path.
 */
class BundledVoiceClipReplacementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Roll back to (and re-run) the replacement migration. Six steps because the
     * voice-clips staging table, the per-user slug scoping, the preset temperature
     * column, the spent-characters counters, and the take-duration column now sit
     * on top of it; rewinding all six and re-migrating re-runs the replacement's
     * up() (its own down() is a no-op).
     */
    private function rerunReplacementMigration(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 6]);
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_restores_a_missing_bundled_clip(): void
    {
        Storage::fake('local');

        $this->rerunReplacementMigration();

        $voice = Voice::where('slug', Voice::defaultSlug())->firstOrFail();
        $this->assertSame(
            file_get_contents($voice->builtinSeedAsset()),
            Storage::disk('local')->get('voices/default.wav'),
        );
    }

    public function test_never_overwrites_a_custom_clip_at_the_bundled_path(): void
    {
        Storage::fake('local');
        // An admin's own upload for the built-in voice — same path, foreign bytes.
        Storage::disk('local')->put('voices/default.wav', 'CUSTOM ADMIN CLIP');

        $this->rerunReplacementMigration();

        $this->assertSame('CUSTOM ADMIN CLIP', Storage::disk('local')->get('voices/default.wav'));
    }

    public function test_leaves_a_repointed_voice_alone(): void
    {
        Storage::fake('local');
        Voice::where('slug', Voice::defaultSlug())->firstOrFail()
            ->update(['reference_audio_path' => 'voices/somewhere-else.wav']);

        $this->rerunReplacementMigration();

        $this->assertFalse(Storage::disk('local')->exists('voices/default.wav'));
    }
}
