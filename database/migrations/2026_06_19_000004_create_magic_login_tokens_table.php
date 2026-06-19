<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_login_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // sha256 of the raw token; the URL carries the raw token, never this.
            $table->string('token_hash')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('tts_project_id');
            $table->uuid('api_key_id')->nullable(); // who minted it, for audit
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable(); // set on first redemption => single use
            $table->timestamps();

            $table->foreign('tts_project_id')->references('id')->on('tts_projects')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_login_tokens');
    }
};
