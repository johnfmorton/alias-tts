<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            // Which API key created the project (null for projects made in the
            // control panel). Mirrors speeches.api_key_id; the seam for per-user
            // ownership once API keys are tied to users.
            $table->uuid('api_key_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            $table->dropColumn('api_key_id');
        });
    }
};
