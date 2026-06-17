<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Voice maps an ElevenLabs-style {voice_id} (its `slug`) to a stored
 * reference audio sample used for zero-shot cloning, plus optional defaults.
 */
class Voice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'reference_audio_path',
        'settings',
        'provider',
        'model',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function speeches(): HasMany
    {
        return $this->hasMany(Speech::class, 'voice_id');
    }

    /**
     * Resolve a public voice identifier (the value used in the URL path) to a
     * Voice. Matches the human-friendly slug first, then the UUID primary key,
     * so a slug can be set equal to an existing ElevenLabs voice_id.
     */
    public static function resolve(string $voiceId): ?self
    {
        return self::where('slug', $voiceId)->first()
            ?? self::whereKey($voiceId)->first();
    }
}
