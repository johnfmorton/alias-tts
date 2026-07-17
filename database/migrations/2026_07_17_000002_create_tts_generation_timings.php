<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned per-model generation timing, so the app can estimate how long the
 * remaining chunks of a run will take. One row per engine holds a rolling
 * aggregate (a sample count and the summed wall-clock milliseconds) that the
 * generation chokepoints increment after every successful render; the average
 * = sum_ms / samples is the per-chunk prior. Same shape and rationale as
 * tts_spend_counters — a table, not a JSON column, so the increments stay plain
 * portable query-builder calls on sqlite (tests) and mysql (prod).
 *
 * The wall-clock timed at ProjectService::generateChunk() spans the whole
 * method, so any auto-reroll cost is folded into the average (the chosen
 * "bake it in" model) — no separate reroll-rate column is needed.
 *
 * Also adds tts_project_jobs.estimated_ms: the up-front estimate computed when
 * a "Generate remaining" run is created, shown as the pre-run number and used
 * as the seed for the live ETA until the first chunk completes. Bundled into
 * this one migration so a single new step covers both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_generation_timings', function (Blueprint $table) {
            $table->id();
            $table->string('model')->unique(); // catalog key, e.g. 'chatterbox'
            // Number of timed generations, and their summed wall-clock ms.
            // Never decremented — a deleted take's time still informs the model.
            $table->unsignedBigInteger('samples')->default(0);
            $table->unsignedBigInteger('sum_ms')->default(0);
            $table->timestamps();
        });

        Schema::table('tts_project_jobs', function (Blueprint $table) {
            // Up-front estimate (ms) for the whole run, from the learned model.
            $table->unsignedInteger('estimated_ms')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('tts_project_jobs', function (Blueprint $table) {
            $table->dropColumn('estimated_ms');
        });

        Schema::dropIfExists('tts_generation_timings');
    }
};
