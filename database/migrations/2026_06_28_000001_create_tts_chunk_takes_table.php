<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_chunk_takes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tts_chunk_id')->constrained('tts_chunks')->cascadeOnDelete();
            $table->string('audio_path');           // immutable per-take RAW provider WAV
            $table->json('settings')->nullable();   // tuning snapshot at synth time (native or EL keys)
            $table->string('source');               // generate | reroll | preview | use | remediate | legacy
            $table->float('asr_score')->nullable();
            $table->json('asr_report')->nullable();
            $table->unsignedInteger('characters')->nullable();
            $table->integer('seed')->nullable();
            $table->timestamps();

            $table->index(['tts_chunk_id', 'created_at']);
        });

        // Backfill: every already-generated chunk becomes one "legacy" take that
        // references its existing audio file IN PLACE — no bytes are moved (a take
        // row simply points at wherever the audio already lives, and the migration
        // stays disk/S3-agnostic). New takes go under chunks/{id}/takes/{take}.wav;
        // the legacy take keeps the old chunks/{id}.wav path, which still equals the
        // chunk's audio_path, so the selection pointer is already consistent.
        foreach (DB::table('tts_chunks')->whereNotNull('audio_path')->cursor() as $chunk) {
            DB::table('tts_chunk_takes')->insert([
                'id' => Str::orderedUuid()->toString(),
                'tts_chunk_id' => $chunk->id,
                'audio_path' => $chunk->audio_path,
                'settings' => $chunk->settings,     // raw JSON string or null — already valid JSON
                'source' => 'legacy',
                'asr_score' => $chunk->asr_score,
                'asr_report' => $chunk->asr_report, // raw JSON string or null
                'characters' => $chunk->characters,
                'seed' => null,
                'created_at' => $chunk->updated_at,
                'updated_at' => $chunk->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_chunk_takes');
    }
};
