<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            // A "sealed" project is one a human has approved as the authoritative
            // final. Sealing is orthogonal to the draft/ready/stale lifecycle: a
            // sealed project is still "ready" and any later edit auto-clears the
            // seal (see ProjectService::clearSeal). sealed_at is the indicator.

            // Lowercase hex SHA-256 of the SEALED audio bytes (the frozen snapshot,
            // not the live final — ffmpeg MP3 output is not byte-deterministic, so
            // the hash is taken once over the exact approved bytes). Not indexed:
            // the convenience tier never looks projects up by hash.
            $table->string('final_sha256', 64)->nullable()->after('mime_type');
            // Byte length of the sealed audio — a cheap cross-check shown on the receipt.
            $table->unsignedBigInteger('final_bytes')->nullable()->after('final_sha256');
            // Immutable snapshot path projects/{id}/sealed/{sha}.{ext}; the receipt
            // ships these bytes so it's verifiable even after the project changes.
            $table->string('sealed_audio_path')->nullable()->after('final_bytes');

            $table->timestamp('sealed_at')->nullable()->after('sealed_audio_path');

            // The approver (users.id is an integer auto-increment). Kept loose (no
            // FK); name/email are a denormalized snapshot so the receipt stays
            // truthful even if the user is later renamed or deleted.
            $table->unsignedBigInteger('sealed_by_id')->nullable()->after('sealed_at');
            $table->string('sealed_by_name')->nullable()->after('sealed_by_id');
            $table->string('sealed_by_email')->nullable()->after('sealed_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('tts_projects', function (Blueprint $table) {
            $table->dropColumn([
                'final_sha256',
                'final_bytes',
                'sealed_audio_path',
                'sealed_at',
                'sealed_by_id',
                'sealed_by_name',
                'sealed_by_email',
            ]);
        });
    }
};
