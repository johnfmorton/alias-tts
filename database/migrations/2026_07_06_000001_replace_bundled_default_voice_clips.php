<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Replace the bundled default-voice clips (VCTK-derived) with the new
 * rights-clean recordings shipping in database/seeders/voices/ — the VCTK
 * clips had to be withdrawn for license reasons, so this rolls the new audio
 * out to installs that already ran the original seeding migration (which is
 * recorded as run and never re-executes).
 *
 * Overwrites a stored clip ONLY when its content hash matches one of the old
 * bundled assets: admin uploads for a built-in voice land at the very same
 * `voices/<slug>.wav` path, so path equality alone cannot distinguish a custom
 * clip from ours — but its bytes can. A missing clip is restored when the row
 * still points at the bundled path. Either way the local-disk cache copy is
 * purged so a stale clip can't keep being served (VoiceReference prefers the
 * local cache over the remote disk).
 */
return new class extends Migration
{
    /**
     * SHA-256 of every bundled clip this migration is allowed to replace: the
     * two VCTK-derived assets shipped by 2026_06_30_000002.
     *
     * @var array<int,string>
     */
    private const REPLACEABLE_HASHES = [
        'f739f764bc10fc76f8268374aae2a96c692efa3c8b91623ae33cc80439ca1201', // default-male.wav (VCTK p311)
        '4bd579e7f008f064e1c58b2b92f271ecd50a4e6d070509031444be6721f0fd75', // default-female.wav (VCTK p333)
    ];

    /** @return array<string,string> slug => bundled asset filename */
    private function definitions(): array
    {
        return [
            (string) config('tts.default_voice_slug', 'default') => 'default-male.wav',
            (string) config('tts.default_voice_female_slug', 'default-female') => 'default-female.wav',
        ];
    }

    public function up(): void
    {
        $diskName = (string) config('tts.storage_disk');
        $disk = Storage::disk($diskName);
        $referenceDir = trim((string) config('tts.reference_path', 'voices'), '/');

        foreach ($this->definitions() as $slug => $asset) {
            $storedPath = $referenceDir.'/'.$slug.'.wav';
            $source = database_path('seeders/voices/'.$asset);
            if (! is_file($source)) {
                continue;
            }

            $row = DB::table('voices')->where('slug', $slug)->first();

            $existing = $disk->exists($storedPath) ? (string) $disk->get($storedPath) : null;

            if ($existing !== null) {
                // Replace only our own old clip; anything else at this path is
                // an admin's custom upload (or already the new asset).
                if (! in_array(hash('sha256', $existing), self::REPLACEABLE_HASHES, true)) {
                    continue;
                }
            } elseif (! $row || $row->reference_audio_path !== $storedPath) {
                // Nothing stored and the voice doesn't use the bundled path —
                // nothing of ours to replace or restore.
                continue;
            }

            $disk->put($storedPath, (string) file_get_contents($source));

            // Drop the stale local cache copy so the next synthesis re-caches
            // the new clip instead of serving the old voice from cache.
            if ($diskName !== 'local') {
                Storage::disk('local')->delete($storedPath);
            }
        }
    }

    public function down(): void
    {
        // Irreversible by design: the old VCTK assets no longer ship with the
        // app, so there is nothing to restore the previous bytes from.
    }
};
