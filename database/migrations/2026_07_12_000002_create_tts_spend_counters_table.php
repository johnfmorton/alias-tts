<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-model lifetime spend counters. `spent_characters` on chunks/projects
 * stays the all-model total; these rows split it by engine so each model's
 * own per-1k rate prices its own characters (see GenerationCost). Chosen as a
 * table (not a JSON column) so increments stay plain, portable query-builder
 * calls on both sqlite (tests) and mysql (prod).
 *
 * Backfill: every pre-existing spent character was rendered on classic
 * chatterbox — the only engine that existed — so each nonzero counter seeds
 * one 'chatterbox' row. Same lifetime semantics as the totals: never
 * decremented, deleting takes/chunks doesn't lower them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_spend_counters', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type'); // 'chunk' | 'project'
            $table->string('owner_id');   // the chunk's/project's uuid
            $table->string('model');      // catalog key, e.g. 'chatterbox'
            $table->unsignedBigInteger('characters')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'model']);
        });

        $now = now();

        DB::statement(
            "INSERT INTO tts_spend_counters (owner_type, owner_id, model, characters, created_at, updated_at)
             SELECT 'chunk', id, 'chatterbox', spent_characters, ?, ? FROM tts_chunks WHERE spent_characters > 0",
            [$now, $now],
        );

        DB::statement(
            "INSERT INTO tts_spend_counters (owner_type, owner_id, model, characters, created_at, updated_at)
             SELECT 'project', id, 'chatterbox', spent_characters, ?, ? FROM tts_projects WHERE spent_characters > 0",
            [$now, $now],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_spend_counters');
    }
};
