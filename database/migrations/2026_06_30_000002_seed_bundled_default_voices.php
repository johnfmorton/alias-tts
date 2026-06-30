<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ship the built-in default voices with a real, bundled reference clip each — a
 * neutral US male (`default`) and female (`default-female`) from the VCTK corpus
 * (see database/seeders/voices/README.md). Previously `default` was reference-less
 * and fell back to Chatterbox's unconditioned native voice, which is not anchored
 * and drifts between runs; a bundled clip makes each default consistent and
 * distinct. Repurposes the existing `default` row (preserving its UUID, so
 * existing projects keep working) into the male default, and adds the female one.
 *
 * Runs on every install/upgrade via `migrate` and is idempotent: it copies each
 * clip into the configured storage disk only if absent, and never clobbers a
 * clip an admin set on a default voice themselves.
 */
return new class extends Migration
{
    /** @return array<int,array{slug:string,name:string,asset:string}> */
    private function definitions(): array
    {
        return [
            [
                'slug' => (string) config('tts.default_voice_slug', 'default'),
                'name' => 'Default voice (male)',
                'asset' => 'default-male.wav',
            ],
            [
                'slug' => (string) config('tts.default_voice_female_slug', 'default-female'),
                'name' => 'Default voice (female)',
                'asset' => 'default-female.wav',
            ],
        ];
    }

    public function up(): void
    {
        $disk = Storage::disk((string) config('tts.storage_disk'));
        $referenceDir = trim((string) config('tts.reference_path', 'voices'), '/');

        foreach ($this->definitions() as $def) {
            $storedPath = $referenceDir.'/'.$def['slug'].'.wav';
            $source = database_path('seeders/voices/'.$def['asset']);

            // Copy the bundled clip into the storage disk once (it's read back via
            // VoiceReference at synthesis time). Skip if the asset is somehow
            // missing — still set the row so the path is correct.
            if (is_file($source) && ! $disk->exists($storedPath)) {
                $disk->put($storedPath, (string) file_get_contents($source));
            }

            $existing = DB::table('voices')->where('slug', $def['slug'])->first();

            if ($existing) {
                // Don't overwrite a custom clip an admin attached to a default.
                if (! empty($existing->reference_audio_path) && $existing->reference_audio_path !== $storedPath) {
                    continue;
                }

                DB::table('voices')->where('slug', $def['slug'])->update([
                    'name' => $def['name'],
                    'reference_audio_path' => $storedPath,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('voices')->insert([
                'id' => (string) Str::uuid(),
                'slug' => $def['slug'],
                'name' => $def['name'],
                'reference_audio_path' => $storedPath,
                'settings' => null,
                'provider' => null,
                'model' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $maleSlug = (string) config('tts.default_voice_slug', 'default');
        $femaleSlug = (string) config('tts.default_voice_female_slug', 'default-female');

        // Restore the male default to its original reference-less state.
        DB::table('voices')->where('slug', $maleSlug)->update([
            'name' => 'Default voice',
            'reference_audio_path' => null,
            'updated_at' => now(),
        ]);

        // Remove the female default this migration added.
        DB::table('voices')->where('slug', $femaleSlug)->delete();
    }
};
