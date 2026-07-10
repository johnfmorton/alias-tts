<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lifetime generation-spend counters. Every real synthesis bumps them (see
 * ProjectService::recordTake()); deleting or pruning takes — or deleting a
 * whole chunk — never lowers them, because the provider was already paid.
 * That's also why these are COLUMNS and not a SUM over tts_chunk_takes: take
 * rows are deletable, spend is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            $table->unsignedBigInteger('spent_characters')->default(0);
        });

        Schema::table('tts_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('spent_characters')->default(0);
        });

        // Backfill from the takes that still exist — the best available floor
        // (anything pruned or deleted before this feature is unknowable).
        // 'use' takes re-record already-paid preview bytes and 'duplicate'
        // takes are byte-copies made by Duplicate project: neither called the
        // provider, so neither counts.
        DB::statement(<<<'SQL'
            UPDATE tts_chunks SET spent_characters = (
                SELECT COALESCE(SUM(characters), 0) FROM tts_chunk_takes
                WHERE tts_chunk_takes.tts_chunk_id = tts_chunks.id
                  AND tts_chunk_takes.source NOT IN ('use', 'duplicate')
            )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE tts_projects SET spent_characters = (
                SELECT COALESCE(SUM(spent_characters), 0) FROM tts_chunks
                WHERE tts_chunks.tts_project_id = tts_projects.id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            $table->dropColumn('spent_characters');
        });

        Schema::table('tts_projects', function (Blueprint $table) {
            $table->dropColumn('spent_characters');
        });
    }
};
