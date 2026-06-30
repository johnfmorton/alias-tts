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

    /**
     * Slug of the primary built-in voice (the neutral US male default). This is
     * the one pre-selected in the new-project picker and the canonical fallback.
     */
    public static function defaultSlug(): string
    {
        return (string) config('tts.default_voice_slug', 'default');
    }

    /** Slug of the built-in female default voice. */
    public static function femaleDefaultSlug(): string
    {
        return (string) config('tts.default_voice_female_slug', 'default-female');
    }

    /**
     * Slugs of every built-in voice — seeded at install with a bundled reference
     * clip and protected from deletion in the admin UI.
     */
    public static function builtinSlugs(): array
    {
        return array_values(array_unique(array_filter([
            self::defaultSlug(),
            self::femaleDefaultSlug(),
        ])));
    }

    /**
     * Whether this is the primary built-in default voice — pre-selected in the
     * new-project picker and used as the canonical default.
     */
    public function isDefault(): bool
    {
        return $this->slug === self::defaultSlug();
    }

    /**
     * Whether this is one of the built-in voices (male or female default). These
     * are seeded at install and cannot be deleted.
     */
    public function isBuiltin(): bool
    {
        return in_array($this->slug, self::builtinSlugs(), true);
    }
}
