<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid credit: a cached dollar balance on users plus an append-only
 * ledger of every grant and charge (see CreditService, the only writer).
 *
 * The balance is integer MICRO-dollars (1,000,000 = $1) so per-character
 * charges stay exact — at $0.025/1k a character is exactly 25 micro. It is
 * SIGNED because an in-flight generation that started under budget may
 * finish after the balance hits zero, pushing it negative. NULL = unlimited
 * (the api_keys.rate_limit convention). The ledger keeps BOTH the marked-up
 * amount the user was charged and the owner's actual provider cost, so the
 * SuperAdmin can reconcile against the real Replicate invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('credit_balance_micro')->nullable()->default(null)->after('studio_advanced');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete: deleting a user keeps the financial history.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);   // 'grant' | 'charge' | 'adjustment'
            $table->string('source', 40); // 'studio_generate','studio_reroll','studio_preview','studio_remediate','api','inspector','genblaze','grant','adjustment'
            $table->bigInteger('amount_micro');                  // signed: + for grants, − for charges (marked-up domain)
            $table->bigInteger('actual_cost_micro')->nullable(); // owner's provider cost, positive (charges only)
            $table->unsignedInteger('characters')->nullable();
            $table->string('model', 40)->nullable();             // catalog key, e.g. 'chatterbox'
            $table->string('reference_type', 20)->nullable();    // 'speech' | 'chunk' | 'genblaze_run'
            $table->string('reference_id', 64)->nullable();
            $table->string('note')->nullable();                  // grant/adjustment note
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // granting admin
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credit_balance_micro');
        });
    }
};
