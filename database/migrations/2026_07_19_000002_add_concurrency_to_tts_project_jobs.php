<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_project_jobs', function (Blueprint $table) {
            // Bounded-concurrency runs (docs/GENERATION-CONCURRENCY.md).
            // `concurrency` marks HOW this run is executed: NULL = the legacy
            // serial loop (GenerateProjectChunksJob), N >= 1 = N claim-based
            // worker jobs (GenerateProjectChunkWorkerJob). Stamped at dispatch
            // so a flag flip mid-run can't change an active run's semantics.
            $table->unsignedTinyInteger('concurrency')->nullable()->after('chunks_failed');
            // Claim cursor into chunk_ids for concurrency-mode runs: entries
            // below it are claimed (in flight or finished), entries at/above it
            // are unclaimed. Moves only under the run row's lock, which is what
            // lets queueChunk() keep inserting on ground no worker has reached.
            $table->unsignedInteger('chunks_claimed')->default(0)->after('concurrency');
        });
    }

    public function down(): void
    {
        Schema::table('tts_project_jobs', function (Blueprint $table) {
            $table->dropColumn(['concurrency', 'chunks_claimed']);
        });
    }
};
