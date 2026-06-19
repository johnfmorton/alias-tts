<?php

namespace App\Models;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An editable text-to-speech project: the source text, its normalized form, and
 * an ordered set of {@see TtsChunk}s each holding its own generated audio. The
 * final file is stitched from the chunks' stored audio, so editing one sentence
 * only re-synthesizes that one chunk and re-concatenates locally — no full
 * re-generation. (Chatterbox is non-deterministic even with a fixed seed, so the
 * audio must be persisted, not reproduced from the seed.)
 */
class TtsProject extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'voice_id',
        'settings',
        'model_id',
        'output_format',
        'seed',
        'source_text',
        'normalized_text',
        'status',
        'final_audio_path',
        'mime_type',
    ];

    protected $casts = [
        'settings' => 'array',
        'status' => ProjectStatus::class,
        'seed' => 'integer',
    ];

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class, 'voice_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(TtsChunk::class)->orderBy('position');
    }

    /** Every chunk has been generated (none pending/failed/stale). */
    public function isFullyGenerated(): bool
    {
        return ! $this->chunks()->where('status', '!=', ChunkStatus::Completed->value)->exists();
    }
}
