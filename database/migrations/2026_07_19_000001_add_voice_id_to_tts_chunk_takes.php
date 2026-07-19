<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            // The EFFECTIVE voice this take was rendered with (the chunk's override,
            // else the project voice — resolved at record time, never the nullable
            // override). Selecting a take restores this onto the chunk so the picker,
            // the engine knobs, and a follow-up Regenerate all match the audio. NULL
            // only for legacy takes recorded before this column existed, or a take
            // whose voice was later deleted (nullOnDelete) — the select path then
            // leaves the chunk's voice untouched, as it already does for null text.
            $table->foreignUuid('voice_id')->nullable()->after('audio_path')
                ->constrained('voices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voice_id');
        });
    }
};
