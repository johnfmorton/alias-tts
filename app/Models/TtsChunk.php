<?php

namespace App\Models;

use App\Enums\ChunkStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sentence-ish unit of a {@see TtsProject}: its text, the pause that should
 * follow it ('sentence'|'paragraph'), and the path to its own generated raw
 * audio. Regenerating a chunk replaces only its audio; the project's final file
 * is rebuilt by concatenating every chunk's audio in order.
 */
class TtsChunk extends Model
{
    use HasUuids;

    protected $fillable = [
        'tts_project_id',
        'position',
        'text',
        'break_after',
        'voice_id',
        'settings',
        'status',
        'audio_path',
        'characters',
        'error_message',
    ];

    protected $casts = [
        'status' => ChunkStatus::class,
        'position' => 'integer',
        'characters' => 'integer',
        'settings' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(TtsProject::class, 'tts_project_id');
    }

    /**
     * The chunk's explicit voice override, or null when it inherits the project
     * voice. Generation uses {@see TtsChunk::voice} ?? the project voice.
     */
    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class, 'voice_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === ChunkStatus::Completed;
    }
}
