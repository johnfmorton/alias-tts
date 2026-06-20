<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            // Per-chunk voice override. NULL = inherit the project's voice (the
            // chunk's picker mirrors the project voice); a value pins this chunk
            // to a specific voice regardless of the project voice. Cleared to NULL
            // if the referenced voice is deleted, so the chunk falls back to the
            // project voice rather than breaking.
            $table->foreignUuid('voice_id')->nullable()->after('break_after')
                ->constrained('voices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voice_id');
        });
    }
};
