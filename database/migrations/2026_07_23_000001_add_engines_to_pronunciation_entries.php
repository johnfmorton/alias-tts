<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-entry engine scoping: which catalog engines a respelling applies to.
     * NULL (every existing row) = all engines — the historical behavior —
     * so no data migration is needed. A JSON list of catalog keys (e.g.
     * ["chatterbox","chatterbox-turbo"]) limits the entry to engines that
     * actually need the help; engines that pronounce the term correctly
     * (qwen, often) get the original text.
     */
    public function up(): void
    {
        Schema::table('pronunciation_entries', function (Blueprint $table) {
            $table->json('engines')->nullable()->after('match_mode');
        });
    }

    public function down(): void
    {
        Schema::table('pronunciation_entries', function (Blueprint $table) {
            $table->dropColumn('engines');
        });
    }
};
