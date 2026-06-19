<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tts_project_id')->constrained('tts_projects')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->longText('text');
            $table->string('break_after')->default('sentence'); // sentence | paragraph
            $table->string('status')->default('pending');        // pending | completed | failed | stale
            $table->string('audio_path')->nullable();            // stored RAW provider WAV
            $table->unsignedInteger('characters')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tts_project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_chunks');
    }
};
