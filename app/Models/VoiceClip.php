<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prepared-but-unsaved reference clip: the decoded original and (optionally) an
 * enhanced take, staged under tts.voice_clip_path so the user can A/B and pick
 * one before saving the voice. Consumed on save (single use) or pruned after
 * {@see $expires_at} by voices:prune-clips.
 *
 * @property string $token
 * @property string $original_path
 * @property string|null $enhanced_path
 * @property string|null $enhance_error
 * @property \Illuminate\Support\Carbon $expires_at
 */
class VoiceClip extends Model
{
    protected $fillable = [
        'user_id', 'token', 'original_path', 'enhanced_path',
        'original_duration', 'enhanced_duration', 'enhance_error', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'original_duration' => 'float',
            'enhanced_duration' => 'float',
        ];
    }

    /** Not yet expired. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Storage path for a variant ('original' | 'enhanced'), or null if absent. */
    public function pathFor(string $variant): ?string
    {
        return $variant === 'enhanced' ? $this->enhanced_path : $this->original_path;
    }
}
