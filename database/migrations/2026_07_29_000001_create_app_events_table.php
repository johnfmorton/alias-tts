<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-party product analytics, aggregated on /admin/insights. One row per
 * meaningful action (project created, chunk generated, receipt downloaded,
 * API speech call, admin page view, ...), written fire-and-forget by
 * AppEvent::record(). Distinct from user_events (per-account admin audit)
 * and credit_transactions (the money/volume ledger) — this answers "which
 * features get used, by whom, how often", nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_events', function (Blueprint $table) {
            $table->id();
            // nullOnDelete: usage history must survive a user's deletion
            // (unlike user_events, whose timeline dies with its user page).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 40); // dot-namespaced, e.g. 'chunk.generated', 'page.view'
            $table->string('source', 16); // 'studio' | 'api' | 'internal' | 'web'
            $table->json('meta')->nullable(); // small context: ids, model, route
            $table->timestamp('created_at'); // no updated_at — events are immutable

            $table->index(['name', 'created_at']); // counts-by-name over time
            $table->index(['user_id', 'created_at']); // per-user activity
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_events');
    }
};
