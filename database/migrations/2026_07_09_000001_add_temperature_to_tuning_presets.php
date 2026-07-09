<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Chatterbox's native sampling `temperature` to named tuning presets so a
 * preset can carry all three native knobs (exaggeration/cfg_weight/temperature).
 * Nullable: an existing preset leaves temperature to inherit until re-saved, so
 * saved presets keep producing the same sound. See docs/STUDIO-TUNING.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->decimal('temperature', 3, 2)->nullable()->after('cfg_weight');
        });
    }

    public function down(): void
    {
        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->dropColumn('temperature');
        });
    }
};
