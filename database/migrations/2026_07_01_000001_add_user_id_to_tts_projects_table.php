<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            // The project's owner. Projects created in the panel are stamped with
            // the signed-in user; API-created projects with their key's owner.
            // Mirrors api_key_id's constraint-less style; null means "owner was
            // deleted" and only a SuperAdmin can reach it (TtsProjectPolicy).
            $table->unsignedBigInteger('user_id')->nullable()->after('api_key_id')->index();
        });

        // Backfill: API-origin projects belong to their key's owner; everything
        // else predates multi-user Studio and belongs to the original SuperAdmin.
        DB::statement(<<<'SQL'
            UPDATE tts_projects
            SET user_id = (SELECT user_id FROM api_keys WHERE api_keys.id = tts_projects.api_key_id)
            WHERE api_key_id IS NOT NULL
        SQL);

        $superAdminId = DB::table('users')->where('is_super_admin', true)->orderBy('id')->value('id');
        if ($superAdminId !== null) {
            DB::table('tts_projects')->whereNull('user_id')->update(['user_id' => $superAdminId]);
        }
    }

    public function down(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            // SQLite refuses to drop a column that still has an index on it.
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
