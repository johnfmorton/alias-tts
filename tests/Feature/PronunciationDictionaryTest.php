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

    public function test_known_terms_returns_only_approved(): void
    {
        $user = User::factory()->create();
        PronunciationEntry::create(['user_id' => $user->id, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'llm']);
        PronunciationEntry::create(['user_id' => $user->id, 'term' => 'nginx', 'phonetic' => 'engine ex', 'approved' => false, 'source' => 'llm']);

        $this->assertSame(['DDEV'], $this->dict()->knownTerms($user->id));
    }

    public function test_entries_are_scoped_per_user_with_a_shared_global_seed(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        PronunciationEntry::create(['user_id' => $a->id, 'term' => 'Acme', 'phonetic' => 'ack me', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => null, 'term' => 'GIF', 'phonetic' => 'jiff', 'approved' => true, 'source' => 'user']);

        // User A sees their own entry plus the global seed; user B sees only the seed.
        $this->assertEqualsCanonicalizing(['Acme', 'GIF'], $this->dict()->knownTerms($a->id));
        $this->assertSame(['GIF'], $this->dict()->knownTerms($b->id));
    }

    public function test_approved_map_orders_a_user_override_after_the_global_entry(): void
    {
        $user = User::factory()->create();
        PronunciationEntry::create(['user_id' => null, 'term' => 'GIF', 'phonetic' => 'gif hard g', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => $user->id, 'term' => 'GIF', 'phonetic' => 'jiff', 'approved' => true, 'source' => 'user']);

        $map = $this->dict()->approvedMap($user->id);

        // Global first, the writer's override last — so it wins in the substituter
        // (whose exactMap is last-write-wins).
        $this->assertSame('jiff', end($map)['phonetic']);
    }
}
