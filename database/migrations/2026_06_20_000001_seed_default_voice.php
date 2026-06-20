<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed the built-in default voice — Chatterbox's native voice, with no reference
 * clip — so a fresh install can generate audio without first uploading a custom
 * voice. Runs on every install/upgrade via `migrate` (production deploys run
 * `migrate --force` but not `db:seed`), and is idempotent. The whole stack
 * already supports a reference-less voice: the provider omits `audio_prompt`,
 * and reference_audio_path is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $slug = (string) config('tts.default_voice_slug', 'default');

        if (DB::table('voices')->where('slug', $slug)->exists()) {
            return;
        }

        DB::table('voices')->insert([
            'id' => (string) Str::uuid(),
            'slug' => $slug,
            'name' => 'Default voice',
            'reference_audio_path' => null, // no clip -> Chatterbox's native voice
            'settings' => null,             // falls back to config default_voice_settings
            'provider' => null,
            'model' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('voices')
            ->where('slug', (string) config('tts.default_voice_slug', 'default'))
            ->whereNull('reference_audio_path') // don't drop it if an admin added a clip
            ->delete();
    }
};
