<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Background "Generate remaining" runs for Studio projects. One row per run:
 * the queue worker executes it chunk-by-chunk and keeps the row's counters
 * current, so the project page can poll progress and the Jobs page can list,
 * inspect, and cancel runs. Durable by design (unlike the cache-only speech
 * progress store) — failed runs stay visible until the user has seen them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_project_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tts_project_id')->constrained('tts_projects')->cascadeOnDelete();
            // The project owner (whose credit the run spends) — Jobs page scoping.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // Who clicked Generate remaining (a SuperAdmin may run a foreign
            // project); the worker applies THEIR settings overlay, matching the
            // interactive per-chunk path where the requester's settings rule.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('generate_chunks');
            $table->string('status')->default('queued'); // queued|running|completed|failed|cancelled
            // Snapshot of the outstanding chunk ids at dispatch; the worker
            // re-validates each (edits/deletes may land while queued).
            $table->json('chunk_ids');
            $table->unsignedInteger('chunks_total')->default(0);
            $table->unsignedInteger('chunks_done')->default(0);
            $table->unsignedInteger('chunks_failed')->default(0);
            // Cooperative kill switch: Cancel sets it; the worker checks it
            // between chunks and winds down as 'cancelled'.
            $table->boolean('cancel_requested')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['tts_project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_project_jobs');
    }
};
