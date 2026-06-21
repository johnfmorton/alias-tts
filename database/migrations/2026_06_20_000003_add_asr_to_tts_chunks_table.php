<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            // Result of the optional ASR transcript QA pass (see config/tts.php
            // `asr` and docs/ASR-SETUP.md). asr_score is the transcript↔source
            // coverage (1.0 = full match); asr_report holds the verdict, the
            // detected problems (TAIL/PAUSE/TRUNC), and the raw metrics so Studio
            // and the health page can surface why a chunk was flagged. Both null
            // until the chunk has been scored (or when ASR is disabled).
            $table->float('asr_score')->nullable()->after('error_message');
            $table->json('asr_report')->nullable()->after('asr_score');
        });
    }

    public function down(): void
    {
        Schema::table('tts_chunks', function (Blueprint $table) {
            $table->dropColumn(['asr_score', 'asr_report']);
        });
    }
};
