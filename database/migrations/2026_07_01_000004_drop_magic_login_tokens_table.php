<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the single-use auto-login ("magic login") feature. The link — minted
 * by POST /v1/projects and redeemed at /projects/open/{token} — logged the
 * visitor into the panel, but always as the SuperAdmin, so once the panel opened
 * to regular users any of them could mint one and escalate. The whole feature is
 * gone; this drops its now-unused table. down() recreates the original schema so
 * the migration is reversible, but nothing writes to it anymore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('magic_login_tokens');
    }

    public function down(): void
    {
        Schema::create('magic_login_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('tts_project_id');
            $table->uuid('api_key_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->foreign('tts_project_id')->references('id')->on('tts_projects')->cascadeOnDelete();
        });
    }
};
