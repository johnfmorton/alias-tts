<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved take (audio render) of a {@see TtsChunk}. Every synthesis — Generate,
 * Re-roll, Preview, "Use this take", and auto-remediation — records a take with
 * its own immutable file, so the user can audition earlier takes, re-select a
 * better one, or delete the duds ("keep every take"). The chunk's audio_path
 * points at whichever take is currently selected; older takes are auto-pruned
 * (see config `tts.takes`).
 */
class TtsChunkTake extends Model
{
    use HasUuids;

    protected $fillable = [
        'tts_chunk_id',
        'audio_path',
        'text',
        'settings',
        'source',
        'asr_score',
        'asr_report',
        'characters',
        'seed',
    ];

    protected $casts = [
        'settings' => 'array',
        'asr_score' => 'float',
        'asr_report' => 'array',
        'characters' => 'integer',
        'seed' => 'integer',
    ];

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(TtsChunk::class, 'tts_chunk_id');
    }

    /**
     * Presentation data for the per-take ASR badge, or null when there's nothing
     * to show. A take's audio is always a completed render, so there is no status
     * gate — the formatting is shared with {@see TtsChunk::asrBadge()} via the
     * static helper so the two never drift.
     *
     * @return array{tone: string, text: string, title: string}|null
     */
    public function asrBadge(): ?array
    {
        return TtsChunk::asrBadgeFrom($this->asr_report);
    }
}
