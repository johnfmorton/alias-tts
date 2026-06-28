<?php

use App\Services\Tts\ChatterboxTuning;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move named tuning presets from the ElevenLabs-style stability/style columns to
 * Chatterbox's native exaggeration/cfg_weight, matching the rest of the Studio.
 * Existing presets are backfilled through the same map the provider uses, so a
 * saved preset keeps producing the same sound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->decimal('exaggeration', 3, 2)->nullable()->after('name');
            $table->decimal('cfg_weight', 3, 2)->nullable()->after('exaggeration');
        });

        foreach (DB::table('tuning_presets')->get() as $preset) {
            // A null knob stays null (inherit); a set EL knob maps to its native pair.
            $native = ChatterboxTuning::resolveNative([
                'stability' => $preset->stability,
                'style' => $preset->style,
            ]);
            DB::table('tuning_presets')->where('id', $preset->id)->update([
                'exaggeration' => $preset->style === null ? null : $native['exaggeration'],
                'cfg_weight' => $preset->stability === null ? null : $native['cfg_weight'],
            ]);
        }

        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->dropColumn(['stability', 'style']);
        });
    }

    public function down(): void
    {
        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->decimal('stability', 3, 2)->nullable()->after('name');
            $table->decimal('style', 3, 2)->nullable()->after('stability');
        });

        foreach (DB::table('tuning_presets')->get() as $preset) {
            // Invert the map (stability = cfg_weight; style = (exaggeration - 0.5) / 1.5).
            DB::table('tuning_presets')->where('id', $preset->id)->update([
                'stability' => $preset->cfg_weight,
                'style' => $preset->exaggeration === null ? null : max(0.0, min(1.0, round(((float) $preset->exaggeration - 0.5) / 1.5, 2))),
            ]);
        }

        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->dropColumn(['exaggeration', 'cfg_weight']);
        });
    }
};
