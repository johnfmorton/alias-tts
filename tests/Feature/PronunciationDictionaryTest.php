<?php

namespace Tests\Feature;

use App\Models\PronunciationEntry;
use App\Models\User;
use App\Services\Pronunciation\PronunciationDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PronunciationDictionaryTest extends TestCase
{
    use RefreshDatabase;

    private function dict(): PronunciationDictionary
    {
        return new PronunciationDictionary;
    }

    public function test_approve_suggestions_upserts_without_duplicates(): void
    {
        $user = User::factory()->create();

        $this->dict()->approveSuggestions($user->id, [
            ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
        ]);
        // Same term again, corrected by hand — updates in place, not a duplicate row.
        $this->dict()->approveSuggestions($user->id, [
            ['term' => 'DDEV', 'phonetic' => 'D dev', 'source' => 'user'],
        ]);

        $rows = PronunciationEntry::where('user_id', $user->id)->where('term', 'DDEV')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('D dev', $rows->first()->phonetic);
        $this->assertSame('user', $rows->first()->source);
        $this->assertTrue($rows->first()->approved);
    }

    public function test_a_terms_typographic_dashes_are_canonicalized_on_save(): void
    {
        // The detector routinely suggests a typographic en dash ("SHA–256") where
        // the writer's text uses a plain hyphen. Storing the canonical hyphen keeps
        // the plugin's synced copy (which matches client-side) from silently
        // failing, and dedupes en/em/hyphen spellings onto one row.
        $user = User::factory()->create();

        $created = $this->dict()->approveSuggestions($user->id, [
            ['term' => "SHA\u{2013}256", 'phonetic' => 'shah two fifty-six'],
        ])->first();
        $this->assertSame('SHA-256', $created->term);

        // An em-dash spelling of the same term updates the same row, not a new one.
        $this->dict()->upsertManual($user->id, ['term' => "SHA\u{2014}256", 'phonetic' => 'shah']);
        $this->assertCount(1, PronunciationEntry::where('user_id', $user->id)->get());
        $this->assertSame('SHA-256', PronunciationEntry::where('user_id', $user->id)->first()->term);

        // Editing an existing row canonicalizes too.
        $edited = $this->dict()->updateEntry($created->fresh(), ['term' => "AES\u{2013}256"]);
        $this->assertSame('AES-256', $edited->term);
    }

    public function test_known_terms_returns_only_approved(): void
    {
        $user = User::factory()->create();
        PronunciationEntry::create(['user_id' => $user->id, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'llm']);
        PronunciationEntry::create(['user_id' => $user->id, 'term' => 'nginx', 'phonetic' => 'engine ex', 'approved' => false, 'source' => 'llm']);

        $this->assertSame(['DDEV'], $this->dict()->knownTerms($user->id));
    }

    public function test_entries_are_strictly_scoped_per_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        PronunciationEntry::create(['user_id' => $a->id, 'term' => 'Acme', 'phonetic' => 'ack me', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => $b->id, 'term' => 'nginx', 'phonetic' => 'engine ex', 'approved' => true, 'source' => 'user']);
        // A leftover null-owner row must NOT leak into anyone's lexicon — there is
        // no shared/global tier.
        PronunciationEntry::create(['user_id' => null, 'term' => 'GIF', 'phonetic' => 'jiff', 'approved' => true, 'source' => 'user']);

        // Each writer sees only their own entry.
        $this->assertSame(['Acme'], $this->dict()->knownTerms($a->id));
        $this->assertSame(['nginx'], $this->dict()->knownTerms($b->id));
    }

    public function test_approved_map_contains_only_the_writers_own_entries(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        PronunciationEntry::create(['user_id' => $a->id, 'term' => 'GIF', 'phonetic' => 'jiff', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => $b->id, 'term' => 'GIF', 'phonetic' => 'gif hard g', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => null, 'term' => 'SQL', 'phonetic' => 'sequel', 'approved' => true, 'source' => 'user']);

        $map = $this->dict()->approvedMap($a->id);

        // Only A's own GIF — B's same-term entry and the null-owner SQL are excluded.
        $this->assertSame([['term' => 'GIF', 'phonetic' => 'jiff', 'match_mode' => 'case_sensitive']], $map);
    }

    public function test_owned_returns_only_the_writers_entries(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        PronunciationEntry::create(['user_id' => $a->id, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => $b->id, 'term' => 'nginx', 'phonetic' => 'engine ex', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => null, 'term' => 'GIF', 'phonetic' => 'jiff', 'approved' => true, 'source' => 'user']);

        $this->assertSame(['DDEV'], $this->dict()->owned($a->id)->pluck('term')->all());
    }
}
