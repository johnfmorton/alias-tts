<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A voice_id (slug) is unique per owner, not globally: two users may each own
 * a voice called "narrator" — resolveFor() already scopes every lookup to
 * "mine + shared", so the union stays unambiguous. NULL owners (the shared
 * built-ins) are NOT constrained by this index (NULLs compare distinct in a
 * unique index); VoiceService::register() enforces global uniqueness for
 * shared slugs at the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voices', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['user_id', 'slug']);
        });
    }

    public function down(): void
    {
        // MySQL/MariaDB reuse the composite as the user_id foreign key's
        // backing index (dropping the auto-created one), so the FK needs a
        // standalone index before the composite can go. Only there — SQLite
        // doesn't index FKs, and the stray index would break a later
        // drop-column rollback of make_voices_per_user.
        $needsFkIndex = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        if ($needsFkIndex && ! Schema::hasIndex('voices', ['user_id'])) {
            Schema::table('voices', fn (Blueprint $table) => $table->index('user_id'));
        }

        // Fails if different users now share a slug — those rows must be
        // renamed before rolling back to a globally-unique slug.
        Schema::table('voices', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slug']);
            $table->unique(['slug']);
        });
    }
};
