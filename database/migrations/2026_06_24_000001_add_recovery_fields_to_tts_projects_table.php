<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            // How this project came to exist: null = made in the control panel;
            // 'api' = auto-created from a /v1 call (api_project_mode=always);
            // 'api_failure' = a recovery project created when a /v1 generation
            // failed (api_project_mode=on_error). Drives the panel badge + prune.
            $table->string('origin')->nullable()->after('api_key_id')->index();

            // For an 'api_failure' recovery project: the provider error that ended
            // generation, and which segment index threw (so the panel can point at
            // the offending chunk).
            $table->text('failure_reason')->nullable()->after('status');
            $table->unsignedInteger('failed_chunk_index')->nullable()->after('failure_reason');

            // Auto-created projects get a TTL so they don't accumulate forever
            // (see the project prune command); panel-made projects leave it null.
            $table->timestamp('expires_at')->nullable()->after('failed_chunk_index')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            $table->dropColumn(['origin', 'failure_reason', 'failed_chunk_index', 'expires_at']);
        });
    }
};
