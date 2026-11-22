<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voices', function (Blueprint $table) {
            // The last "Test" sample rendered on the Voices page, kept so later
            // visits can replay it instead of paying for a new render. The
            // fingerprint captures the voice's state when it was rendered
            // (see App\Services\VoicePreview): a preview is only offered while
            // it still matches, so a sample of an older clip or tuning is
            // never played back as the voice's current sound.
            $table->string('preview_audio_path')->nullable()->after('reference_audio_path');
            $table->char('preview_fingerprint', 64)->nullable()->after('preview_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('voices', function (Blueprint $table) {
            $table->dropColumn(['preview_audio_path', 'preview_fingerprint']);
        });
    }
};
