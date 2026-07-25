<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account audit events for the user detail page's timeline. Money already has
 * its own ledger (credit_transactions); this records the non-money changes —
 * role/status flips, creation/invite, forced password resets — with who did
 * them, so the page can interleave everything into one account statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_events', function (Blueprint $table) {
            $table->id();
            // Cascade: the timeline only exists on the user's own page, so it
            // goes with the user (unlike the financial ledger, which is kept).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // 'created' | 'invited' | 'role_change' | 'status_change' | 'password_reset'
            $table->json('meta')->nullable(); // e.g. {"from":"User","to":"SuperAdmin"}
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // the acting admin
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_events');
    }
};
