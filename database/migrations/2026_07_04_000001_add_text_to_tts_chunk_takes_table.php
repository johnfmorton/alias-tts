<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            // The exact chunk text this take was synthesized from, snapshotted at
            // render time. A chunk's `text` is mutable — the user tweaks words
            // between takes — so without this the take can't say what it actually
            // read. The sealed receipt prints the SELECTED take's snapshot so the
            // "Script" always corresponds to the sealed audio (see ProjectExport
            // Service::chunkRows). Nullable: pre-existing/legacy takes have no
            // snapshot and fall back to the chunk's current text.
            $table->longText('text')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            $table->dropColumn('text');
        });
    }
};
