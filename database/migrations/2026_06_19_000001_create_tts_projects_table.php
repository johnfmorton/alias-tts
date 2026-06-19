<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->uuid('voice_id')->nullable()->index();
            $table->json('settings')->nullable();
            $table->string('model_id')->nullable();
            $table->string('output_format');
            $table->integer('seed')->nullable();
            $table->longText('source_text');
            $table->longText('normalized_text');
            $table->string('status')->default('draft'); // draft | ready | stale
            $table->string('final_audio_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_projects');
    }
};
