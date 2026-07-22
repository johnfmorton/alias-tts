<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            // SHA-256 of the take's audio bytes, computed at record time while
            // the bytes are already in hand. The receipt/verify provenance
            // (ProjectExportService::chunkRows) reads this instead of
            // re-downloading and re-hashing every chunk's audio — which grew
            // O(chunks) slow on big projects. NULL on legacy takes recorded
            // before the column existed; readers fall back to hashing then.
            $table->char('audio_sha256', 64)->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            $table->dropColumn('audio_sha256');
        });
    }
};
