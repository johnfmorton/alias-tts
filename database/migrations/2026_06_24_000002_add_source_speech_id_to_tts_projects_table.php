<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            // The /v1 Speech this project was auto-created from (api_project_mode),
            // so the API status/audio responses can surface this project's edit
            // link. Null for projects made in the control panel.
            $table->uuid('source_speech_id')->nullable()->after('origin')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            $table->dropColumn('source_speech_id');
        });
    }
};
