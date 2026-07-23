<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A background "Duplicate project" run (TtsProjectJob type `duplicate`) is booked
 * on the SOURCE project but produces a NEW copy. These carry the result across
 * the async boundary: `result_project_id` is the copy the page auto-opens when
 * the run finishes, and `result_message` is the one-time success/adopted-voices
 * notice the copy page surfaces (and consumes) on arrival. Null on every other
 * run type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_project_jobs', function (Blueprint $table) {
            $table->uuid('result_project_id')->nullable()->after('error');
            $table->text('result_message')->nullable()->after('result_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('tts_project_jobs', function (Blueprint $table) {
            $table->dropColumn(['result_project_id', 'result_message']);
        });
    }
};
