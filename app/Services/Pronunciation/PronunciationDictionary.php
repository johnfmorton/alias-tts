<?php

namespace App\Services\Pronunciation;

use App\Models\PronunciationEntry;
use Illuminate\Support\Collection;

/**
 * Reads and curates the per-writer pronunciation lexicon stored in
 * {@see PronunciationEntry}. This service owns the canonical dictionary; the
 * Studio review screen approves LLM suggestions into it, and the Craft plugin
 * later syncs the approved set via the read API.
 *
 * Dictionaries are STRICTLY per-user: every read is scoped to exactly one owner
 * (see {@see PronunciationEntry::scopeOwnedBy}). There is no shared/global tier —
 * one writer's entries never apply to anyone else.
 */
class PronunciationDictionary
{
    /**
     * Approved terms (verbatim) to hand the detector as `known_terms` so the LLM
     * skips already-decided words.
     *
     * @return list<string>
     */
    public function knownTerms(?int $userId): array
    {
        return PronunciationEntry::query()
            ->ownedBy($userId)
            ->approved()
            ->pluck('term')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The writer's approved entries as substitution-map rows for
     * {@see PronunciationSubstituter::apply()}.
     *
     * @return list<array{term: string, phonetic: string, match_mode: string}>
     */
    public function approvedMap(?int $userId): array
    {
        return PronunciationEntry::query()
            ->ownedBy($userId)
            ->approved()
            ->get()
            ->map(fn (PronunciationEntry $e) => $e->toMapEntry())
            ->all();
    }

    /**
     * Persist a batch of approved suggestions from the review screen. Each is
     * upserted on (user_id, term) so a term is decided once.
     *
     * @param  list<array<string, mixed>>  $suggestions
     * @return Collection<int, PronunciationEntry>
     */
    public function approveSuggestions(?int $userId, array $suggestions): Collection
    {
        return collect($suggestions)
            ->map(fn (array $s) => $this->upsert($userId, $s, approved: true))
            ->filter()
            ->values();
    }

    /**
     * Terms the writer has explicitly declined — rows kept with
     * `approved = false` — lowercased for case-insensitive matching. The review
     * screen consults this so a declined term is never pre-checked again, and
     * auto-apply paths (Genblaze runs) skip these outright.
     *
     * @return list<string>
     */
    public function rejectedTerms(?int $userId): array
    {
        return PronunciationEntry::query()
            ->ownedBy($userId)
            ->where('approved', false)
            ->pluck('term')
            ->map(fn (string $t) => mb_strtolower($t))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Remember suggestions the writer left unchecked on the review screen as
     * declined entries (`approved = false`), so later runs stop pre-checking
     * them. Never downgrades: a term that is already approved (e.g. a stale
     * form re-submitted after approval elsewhere) is left untouched.
     *
     * @param  list<array<string, mixed>>  $suggestions
     */
    public function rejectSuggestions(?int $userId, array $suggestions): void
    {
        $approved = array_map(fn ($t) => mb_strtolower($t), $this->knownTerms($userId));

        collect($suggestions)
            ->reject(fn (array $s) => in_array(mb_strtolower(trim((string) ($s['term'] ?? ''))), $approved, true))
            ->each(fn (array $s) => $this->upsert($userId, $s, approved: false));
    }

    /**
     * Add or correct an entry by hand (the editable-lexicon UI). Always recorded
     * as `source = user` and approved.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertManual(?int $userId, array $data): ?PronunciationEntry
    {
        return $this->upsert($userId, ['source' => 'user'] + $data, approved: true);
    }

    /**
     * The writer's own lexicon (approved or not), for the management UI. Strictly
     * this user's entries — never another writer's.
     *
     * @return Collection<int, PronunciationEntry>
     */
    public function owned(?int $userId): Collection
    {
        return PronunciationEntry::query()
            ->ownedBy($userId)
            ->orderBy('term')
            ->get();
    }

    /**
     * Update an existing entry in place (the edit form). Unlike {@see upsertManual},
     * this targets a specific row by id, so it can rename the `term`. Only the keys
     * present in $data are written; `approved` is coerced to bool when given.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateEntry(PronunciationEntry $entry, array $data): PronunciationEntry
    {
        $entry->update([
            'term' => trim((string) ($data['term'] ?? $entry->term)),
            'phonetic' => trim((string) ($data['phonetic'] ?? $entry->phonetic)),
            'category' => $data['category'] ?? null,
            'confidence' => $data['confidence'] ?? null,
            'note' => $data['note'] ?? null,
            'match_mode' => in_array($data['match_mode'] ?? null, ['case_sensitive', 'case_insensitive'], true)
                ? $data['match_mode']
                : $entry->match_mode,
            'approved' => array_key_exists('approved', $data) ? (bool) $data['approved'] : $entry->approved,
        ]);

        return $entry;
    }

    public function forget(PronunciationEntry $entry): void
    {
        $entry->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsert(?int $userId, array $data, bool $approved): ?PronunciationEntry
    {
        $term = trim((string) ($data['term'] ?? ''));
        $phonetic = trim((string) ($data['phonetic'] ?? ''));
        if ($term === '' || $phonetic === '') {
            return null;
        }

        return PronunciationEntry::updateOrCreate(
            ['user_id' => $userId, 'term' => $term],
            [
                'phonetic' => $phonetic,
                'category' => $data['category'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'note' => $data['note'] ?? null,
                'match_mode' => in_array($data['match_mode'] ?? null, ['case_sensitive', 'case_insensitive'], true)
                    ? $data['match_mode']
                    : 'case_sensitive',
                'source' => in_array($data['source'] ?? null, ['user', 'llm'], true)
                    ? $data['source']
                    : 'llm',
                'approved' => $approved,
            ],
        );
    }
}
