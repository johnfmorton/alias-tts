<?php

namespace Tests\Feature;

use App\Models\PronunciationEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin screen for reviewing/editing the signed-in writer's pronunciation
 * dictionary. Strictly per-user: a writer sees and edits only their own terms.
 */
class PronunciationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_index_lists_only_the_writers_own_entries(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        PronunciationEntry::create(['user_id' => $a->id, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'user']);
        PronunciationEntry::create(['user_id' => $b->id, 'term' => 'nginx', 'phonetic' => 'engine ex', 'approved' => true, 'source' => 'user']);

        $this->actingAs($a)
            ->get(route('admin.pronunciations.index'))
            ->assertOk()
            ->assertSee('DDEV')
            ->assertDontSee('nginx');
    }

    public function test_store_creates_an_approved_owned_entry(): void
    {
        $a = $this->admin();

        $this->actingAs($a)
            ->post(route('admin.pronunciations.store'), [
                'term' => 'kubectl', 'phonetic' => 'cube control',
                'match_mode' => 'case_sensitive', 'category' => 'tech_name', 'confidence' => 'high',
            ])
            ->assertRedirect(route('admin.pronunciations.index'));

        $entry = PronunciationEntry::where('term', 'kubectl')->first();
        $this->assertNotNull($entry);
        $this->assertSame($a->id, $entry->user_id);
        $this->assertTrue($entry->approved);
        $this->assertSame('user', $entry->source);
    }

    public function test_store_rejects_a_duplicate_term_for_the_same_user(): void
    {
        $a = $this->admin();
        PronunciationEntry::create(['user_id' => $a->id, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'user']);

        $this->actingAs($a)
            ->post(route('admin.pronunciations.store'), ['term' => 'DDEV', 'phonetic' => 'D dev', 'match_mode' => 'case_sensitive'])
            ->assertSessionHasErrors('term');

        $this->assertSame(1, PronunciationEntry::where('term', 'DDEV')->count());
    }

    public function test_update_edits_fields_and_toggles_approved(): void
    {
        $a = $this->admin();
        $entry = PronunciationEntry::create(['user_id' => $a->id, 'term' => 'GIF', 'phonetic' => 'gif', 'approved' => false, 'source' => 'llm']);

        $this->actingAs($a)
            ->put(route('admin.pronunciations.update', $entry), [
                'term' => 'GIF', 'phonetic' => 'jiff', 'match_mode' => 'case_insensitive', 'approved' => '1',
            ])
            ->assertRedirect(route('admin.pronunciations.index'));

        $entry->refresh();
        $this->assertSame('jiff', $entry->phonetic);
        $this->assertSame('case_insensitive', $entry->match_mode);
        $this->assertTrue($entry->approved);
    }

    public function test_a_writer_cannot_touch_another_writers_entry(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $entry = PronunciationEntry::create(['user_id' => $a->id, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'user']);

        $this->actingAs($b)->get(route('admin.pronunciations.edit', $entry))->assertNotFound();
        $this->actingAs($b)->put(route('admin.pronunciations.update', $entry), [
            'term' => 'DDEV', 'phonetic' => 'hacked', 'match_mode' => 'case_sensitive',
        ])->assertNotFound();
        $this->actingAs($b)->delete(route('admin.pronunciations.destroy', $entry))->assertNotFound();

        $this->assertSame('dee dev', $entry->fresh()->phonetic);
    }

    public function test_destroy_removes_the_entry(): void
    {
        $a = $this->admin();
        $entry = PronunciationEntry::create(['user_id' => $a->id, 'term' => 'DDEV', 'phonetic' => 'dee dev', 'approved' => true, 'source' => 'user']);

        $this->actingAs($a)
            ->delete(route('admin.pronunciations.destroy', $entry))
            ->assertRedirect(route('admin.pronunciations.index'));

        $this->assertDatabaseMissing('pronunciation_entries', ['id' => $entry->id]);
    }
}
