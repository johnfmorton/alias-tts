<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speeches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('api_key_id')->nullable()->index();
            $table->uuid('voice_id')->nullable()->index();
            $table->longText('text');
            $table->string('cache_hash')->index();
            $table->json('settings')->nullable();
            $table->string('model_id')->nullable();
            $table->string('output_format');
            $table->string('status')->default('pending');
            $table->string('audio_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('characters')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['voice_id', 'cache_hash', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speeches');
    }
};
