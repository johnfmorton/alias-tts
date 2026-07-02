<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Voice maps an ElevenLabs-style {voice_id} (its `slug`) to a stored
 * reference audio sample used for zero-shot cloning, plus optional defaults.
 *
 * Voices are PER USER: a custom voice belongs to whoever created it and is
 * visible only to them (SuperAdmins see everything on the Voices page). A NULL
 * owner means shared — the bundled built-in defaults every user sees.
 */
class Voice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
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

    /** The user who owns this voice; null for the shared built-ins. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Voices this user can see and generate with: the shared ones plus their own. */
    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->whereNull('voices.user_id');
            if ($userId !== null) {
                $q->orWhere('voices.user_id', $userId);
            }
        });
    }

    /**
     * Fallback ordering when a user has not ranked a voice: the built-in
     * default first — the pre-selected option should be the first thing in the
     * list, not buried mid-alphabet — then the female built-in, then by name.
     * The user's saved drag order (see {@see orderedFor()}) takes precedence.
     */
    public function scopePickerOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE voices.slug WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                self::defaultSlug(),
                self::femaleDefaultSlug(),
            ])
            ->orderBy('voices.name');
    }

    /**
     * The user's personal drag order (voice_orders pivot) applied to a voices
     * query: ranked voices first in their saved positions, unranked ones after
     * in {@see scopePickerOrder} order. Does NOT filter — compose with
     * visibleTo() (pickers) or use alone (SuperAdmin's full Voices page).
     */
    public static function orderedQuery(?int $userId): Builder
    {
        return self::query()
            ->leftJoin('voice_orders', function ($join) use ($userId) {
                $join->on('voice_orders.voice_id', '=', 'voices.id')
                    ->where('voice_orders.user_id', '=', $userId ?? 0);
            })
            ->select('voices.*')
            ->orderByRaw('voice_orders.position IS NULL')
            ->orderBy('voice_orders.position')
            ->pickerOrder();
    }

    /**
     * What every voice PICKER renders for this user: the voices they can see,
     * in their own order. The first result is what the New Project form
     * pre-selects, so users control their effective default voice by dragging.
     */
    public static function orderedFor(?int $userId): Builder
    {
        return self::orderedQuery($userId)->visibleTo($userId);
    }

    /**
     * Resolve a public voice identifier (the value used in the URL path) to a
     * Voice. Matches the human-friendly slug first, then the UUID primary key,
     * so a slug can be set equal to an existing ElevenLabs voice_id.
     *
     * Unscoped — for trusted internal callers only. User-facing paths (admin
     * pages, /v1 keys) must use {@see resolveFor()} so one user can never
     * generate with another's voice.
     */
    public static function resolve(string $voiceId): ?self
    {
        return self::where('slug', $voiceId)->first()
            ?? self::whereKey($voiceId)->first();
    }

    /** {@see resolve()}, restricted to the voices this user can see. */
    public static function resolveFor(string $voiceId, ?int $userId): ?self
    {
        return self::visibleTo($userId)->where('slug', $voiceId)->first()
            ?? self::visibleTo($userId)->whereKey($voiceId)->first();
    }

    /** Can this user see (and generate with) this voice? */
    public function isVisibleTo(?User $user): bool
    {
        return $this->user_id === null
            || ($user !== null && ($this->user_id === $user->id || $user->isSuperAdmin()));
    }

    /**
     * Can this user edit or delete this voice? Owned voices: the owner or a
     * SuperAdmin. Shared voices (built-ins and any ownerless row): SuperAdmin
     * only — editing them changes what every user hears.
     */
    public function isManagedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isSuperAdmin() || ($this->user_id !== null && $this->user_id === $user->id);
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
