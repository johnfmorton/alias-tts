<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pronunciation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Per-writer lexicon. Nullable so a shared/global seed list
            // (user_id null) and the current single-admin default are both
            // representable without inventing tenancy this feature doesn't need.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('term');                                    // verbatim, exact case (so a literal match works)
            $table->string('phonetic');                                // ASCII respelling, e.g. "dee dev"
            $table->string('category')->nullable();                    // initialism|acronym|tech_name|proper_noun|symbol_version|jargon
            $table->string('confidence')->nullable();                  // high|medium|low
            $table->string('source')->default('user');                 // user | llm
            $table->boolean('approved')->default(false);               // gate: unreviewed llm suggestions never auto-apply
            $table->string('match_mode')->default('case_sensitive');   // case_sensitive | case_insensitive
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'term']);      // a term is decided once per writer
            $table->index(['user_id', 'approved']);   // the apply / read-API query path
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pronunciation_entries');
    }
};
