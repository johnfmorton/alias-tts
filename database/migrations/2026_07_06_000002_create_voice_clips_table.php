<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging table for the "prepare a reference clip" flow: a recorded/uploaded clip
 * is decoded, optionally cleaned up (denoise + enhance), and stored as
 * original/enhanced WAVs under tts.voice_clip_path so the user can A/B and pick
 * one before saving the voice. Rows are short-lived — claimed on save (single
 * use) or pruned after expires_at (voices:prune-clips).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->string('original_path');
            $table->string('enhanced_path')->nullable();
            $table->decimal('original_duration', 8, 2)->nullable();
            $table->decimal('enhanced_duration', 8, 2)->nullable();
            $table->string('enhance_error')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_clips');
    }
};
