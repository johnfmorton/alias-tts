<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-chunk "skip in final assembly" flag. A skipped chunk keeps its text,
 * audio and takes but is excluded from rebuild/preview stitching; the seal
 * receipt lists it labeled as skipped. Deliberately a dedicated column (not a
 * settings-JSON key): chunk settings feed the generation payload and tuning
 * saves, where a structural flag doesn't belong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            $table->boolean('skipped')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            $table->dropColumn('skipped');
        });
    }
};
