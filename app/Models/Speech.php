<?php

namespace App\Models;

use App\Enums\SpeechStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated (or cached) speech result. Powers both history and the
 * text+voice+settings cache that lets repeated requests skip the GPU call.
 */
class Speech extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'speeches';

    protected $fillable = [
        'api_key_id',
        'voice_id',
        'text',
        'cache_hash',
        'settings',
        'model_id',
        'output_format',
        'status',
        'audio_path',
        'mime_type',
        'characters',
        'error_message',
        'expires_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'status' => SpeechStatus::class,
        'characters' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class, 'voice_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === SpeechStatus::Completed;
    }
}
