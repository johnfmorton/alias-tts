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
        'api_key_id',
        'origin',
        'source_speech_id',
        'title',
        'voice_id',
        'settings',
        'model_id',
        'output_format',
        'seed',
        'source_text',
        'normalized_text',
        'status',
        'failure_reason',
        'failed_chunk_index',
        'final_audio_path',
        'mime_type',
        'expires_at',
        'final_sha256',
        'final_bytes',
        'sealed_audio_path',
        'sealed_at',
        'sealed_by_id',
        'sealed_by_name',
        'sealed_by_email',
    ];

    protected $casts = [
        'settings' => 'array',
        'status' => ProjectStatus::class,
        'seed' => 'integer',
        'failed_chunk_index' => 'integer',
        'expires_at' => 'datetime',
        'final_bytes' => 'integer',
        'sealed_at' => 'datetime',
        'sealed_by_id' => 'integer',
    ];

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class, 'voice_id');
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
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

    /**
     * Whether a human has sealed this final as the approved cut. A seal records
     * the approver, the moment, and the SHA-256 of the frozen audio snapshot; any
     * later edit/rebuild clears it (see ProjectService::clearSeal).
     */
    public function isSealed(): bool
    {
        return $this->sealed_at !== null && $this->final_sha256 !== null;
    }

    /** Human label for who approved the seal (name preferred, email fallback). */
    public function sealApprover(): ?string
    {
        return $this->sealed_by_name ?: $this->sealed_by_email;
    }
}
