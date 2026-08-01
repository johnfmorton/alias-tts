<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            // Where each chunk's audio lands inside the stitched final file:
            // an ordered list of {chunk_id, start_ms, end_ms}, recorded by
            // ProjectService::rebuild() from the stitch's ACTUAL post-trim
            // durations + seam gaps (summing stored take durations drifts —
            // every chunk is edge-trimmed before the join). The Studio's
            // follow-playback UI maps the hero player's currentTime to the
            // chunk card being heard through this. NULL = no final, or a
            // final whose stitch predates the column (carried over from the
            // Inspector, or built before this shipped) — the UI stays off
            // until the next rebuild fills it in. Describes a specific final
            // file, so it is cleared wherever final_audio_path is.
            $table->json('final_timeline')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            $table->dropColumn('final_timeline');
        });
    }
};
