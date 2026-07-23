<?php

namespace App\Models;

use App\Services\Pronunciation\PronunciationDictionary;
use App\Services\Pronunciation\PronunciationSubstituter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One learned pronunciation: a verbatim source `term` and the ASCII `phonetic`
 * respelling the TTS engine should read instead (e.g. "DDEV" => "dee dev").
 * Entries accumulate per writer as they create Studio projects, building a
 * personal lexicon. This service owns the canonical dictionary; the Craft plugin
 * syncs the approved set (read API) and applies the find-and-replace upstream of
 * whatever TTS backend is in use.
 *
 * @see PronunciationSubstituter  applies entries to text
 * @see PronunciationDictionary   load / approve / persist
 */
class PronunciationEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'term',
        'phonetic',
        'category',
        'confidence',
        'source',
        'approved',
        'match_mode',
        'engines',
        'note',
    ];

    protected $casts = [
        'approved' => 'boolean',
        'engines' => 'array',
    ];

    /**
     * Whether this entry applies when rendering with $engine. NULL/empty
     * `engines` = every engine (the historical behavior and the default);
     * a list limits the respelling to engines that need the help.
     */
    public function appliesTo(string $engine): bool
    {
        return $this->engines === null
            || $this->engines === []
            || in_array($engine, $this->engines, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Entries owned by EXACTLY this user. Dictionaries are strictly per-user — a
     * writer's lexicon applies to that writer alone, never to anyone else — so
     * this deliberately does not fold in a shared/global (null-owner) tier.
     * (`where('user_id', null)` is not the same as `whereNull` in SQL, so the
     * null case must branch explicitly.)
     */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $userId === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', $userId);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approved', true);
    }

    /**
     * The shape {@see PronunciationSubstituter::apply()}
     * consumes.
     *
     * @return array{term: string, phonetic: string, match_mode: string}
     */
    public function toMapEntry(): array
    {
        return [
            'term' => $this->term,
            'phonetic' => $this->phonetic,
            'match_mode' => $this->match_mode,
        ];
    }
}
