<?php

namespace App\Models;

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
 * @see \App\Services\Pronunciation\PronunciationSubstituter  applies entries to text
 * @see \App\Services\Pronunciation\PronunciationDictionary   load / approve / persist
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
        'note',
    ];

    protected $casts = [
        'approved' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Entries owned by $userId plus any shared/global (null-owner) entries, so a
     * writer always sees the global seed list layered under their own lexicon.
     */
    public function scopeForUser(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->whereNull('user_id');
            if ($userId !== null) {
                $q->orWhere('user_id', $userId);
            }
        });
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approved', true);
    }

    /**
     * The shape {@see \App\Services\Pronunciation\PronunciationSubstituter::apply()}
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
