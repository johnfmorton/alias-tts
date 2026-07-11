<?php

use App\Services\Audio\AudioConverter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Take audio length, recorded at synthesis time (see ProjectService::recordTake())
 * so the Studio panel can print each take's duration without the browser having
 * to fetch audio metadata (take players are preload="none" on purpose — a project
 * with many takes must not fire a request per take on page load).
 *
 * Nullable: legacy takes whose file can't be read stay null and the panel simply
 * shows the duration once playback loads the metadata, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('characters');
        });

        // Backfill existing takes from their stored WAV headers. Cosmetic data,
        // so any unreadable file (missing, non-WAV, storage hiccup) is skipped
        // and that take just keeps the old load-on-play behavior.
        $disk = Storage::disk((string) config('tts.storage_disk'));
        $converter = app(AudioConverter::class);

        foreach (DB::table('tts_chunk_takes')->select('id', 'audio_path')->cursor() as $take) {
            try {
                if ($take->audio_path === null || ! $disk->exists($take->audio_path)) {
                    continue;
                }
                $seconds = $converter->wavDurationSeconds((string) $disk->get($take->audio_path));
                if ($seconds !== null) {
                    DB::table('tts_chunk_takes')->where('id', $take->id)
                        ->update(['duration_ms' => (int) round($seconds * 1000)]);
                }
            } catch (Throwable) {
                // leave null — the panel falls back to metadata-on-play
            }
        }
    }

    public function down(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });
    }
};
