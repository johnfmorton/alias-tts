<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            // The engine (tts.models catalog key, e.g. 'chatterbox-turbo') this
            // take rendered on, stamped at record time. The take's voice_id
            // almost answers this — but a voice's engine can be edited later,
            // which would silently rewrite what old receipts report. Receipts
            // and /verify prefer this frozen key (ProjectExportService). NULL
            // on legacy takes; readers fall back to the take's/chunk's voice.
            $table->string('model')->nullable()->after('voice_id');
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunk_takes', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
