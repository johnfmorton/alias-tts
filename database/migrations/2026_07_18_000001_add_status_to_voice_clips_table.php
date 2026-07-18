<?php

use App\Jobs\PrepareVoiceClipJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cleanup (denoise + enhance) now runs off the request cycle in a queued job
 * ({@see PrepareVoiceClipJob}) so a long recording can't hold an open
 * POST past the gateway's read timeout (the 504 that blocked new users). This
 * column lets the browser poll a staged clip: 'processing' while the job runs,
 * 'ready' once the enhanced take (or the degrade-safe original) is in place.
 * Defaults to 'ready' so any pre-existing row and the enhance-off path need no
 * wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_clips', function (Blueprint $table) {
            $table->string('status', 20)->default('ready')->after('enhance_error');
        });
    }

    public function down(): void
    {
        Schema::table('voice_clips', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
