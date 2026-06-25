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
 * A `null` $userId addresses the shared/global seed list; a non-null id layers a
 * writer's own entries on top of it (see {@see PronunciationEntry::scopeForUser}).
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
            ->forUser($userId)
            ->approved()
            ->pluck('term')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The approved entries as substitution-map rows for
     * {@see PronunciationSubstituter::apply()}. Global entries are ordered first
     * so a writer's own override wins when both define the same term.
     *
     * @return list<array{term: string, phonetic: string, match_mode: string}>
     */
    public function approvedMap(?int $userId): array
    {
        return PronunciationEntry::query()
            ->forUser($userId)
            ->approved()
            ->orderByRaw('user_id IS NULL DESC')
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
     * The full lexicon for a writer (approved or not), for the management UI.
     *
     * @return Collection<int, PronunciationEntry>
     */
    public function all(?int $userId): Collection
    {
        return PronunciationEntry::query()
            ->forUser($userId)
            ->orderBy('term')
            ->get();
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
