<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            // Per-chunk tuning override (stability/style). null = inherit the
            // project's settings. See docs/STUDIO-TUNING.md Phase 2.
            $table->json('settings')->nullable()->after('break_after');
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
