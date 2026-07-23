<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\PronunciationEntry;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\Pronunciation\PronunciationDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-entry engine scoping: engines differ in what they mispronounce (qwen
 * handles many terms Chatterbox needs help with), so an entry can be limited
 * to the engines that need it. NULL engines = applies everywhere (every
 * pre-existing row keeps its behavior).
 */
class PronunciationEngineScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function dict(): PronunciationDictionary
    {
        return new PronunciationDictionary;
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function scopedEntry(int $userId): PronunciationEntry
    {
        return $this->dict()->upsertManual($userId, [
            'term' => 'Alias TTS',
            'phonetic' => 'Aileus tee tee ess',
            'engines' => ['chatterbox', 'chatterbox-turbo'],
        ]);
    }

    public function test_approved_map_filters_by_engine(): void
    {
        $user = User::factory()->create();
        $this->scopedEntry($user->id);
        $this->dict()->upsertManual($user->id, ['term' => 'DDEV', 'phonetic' => 'dee dev']); // unscoped

        $terms = fn (?string $engine) => array_column($this->dict()->approvedMap($user->id, $engine), 'term');

        $this->assertSame(['Alias TTS', 'DDEV'], $terms('chatterbox'));
        $this->assertSame(['Alias TTS', 'DDEV'], $terms('chatterbox-turbo'));
        // Qwen pronounces the brand correctly — only the unscoped entry applies.
        $this->assertSame(['DDEV'], $terms('qwen3-tts'));
        // No engine = the full lexicon (plugin sync / management surfaces).
        $this->assertSame(['Alias TTS', 'DDEV'], $terms(null));
    }

    public function test_engine_lists_normalize_on_save(): void
    {
        $user = User::factory()->create();

        // All engines = the applies-everywhere default → stored as NULL.
        $all = $this->dict()->upsertManual($user->id, [
            'term' => 'A', 'phonetic' => 'ay',
            'engines' => ['chatterbox', 'chatterbox-turbo', 'qwen3-tts'],
        ]);
        $this->assertNull($all->engines);

        // Unknown keys drop; the rest keep catalog order.
        $scoped = $this->dict()->upsertManual($user->id, [
            'term' => 'B', 'phonetic' => 'bee',
            'engines' => ['qwen3-tts', 'discontinued-model', 'chatterbox'],
        ]);
        $this->assertSame(['chatterbox', 'qwen3-tts'], $scoped->engines);

        // Nothing valid = NULL (an entry can't apply nowhere — delete it instead).
        $empty = $this->dict()->upsertManual($user->id, ['term' => 'C', 'phonetic' => 'sea', 'engines' => []]);
        $this->assertNull($empty->engines);
    }

    public function test_a_chatterbox_project_respells_but_a_qwen_project_does_not(): void
    {
        $admin = $this->admin();
        $this->scopedEntry($admin->id);

        $classic = Voice::create(['slug' => 'classic-v', 'name' => 'Classic V']);
        $qwen = Voice::create([
            'slug' => 'qwen-v', 'name' => 'Qwen V', 'model' => 'qwen3-tts',
            'settings' => ['preset_voice' => 'Serena'],
        ]);

        $text = 'Alias TTS is the name of this application, spoken aloud.';

        $this->actingAs($admin)->post(route('admin.studio.projects.store'), [
            'title' => 'Classic pron', 'text' => $text, 'voice' => 'classic-v',
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.studio.projects.store'), [
            'title' => 'Qwen pron', 'text' => $text, 'voice' => 'qwen-v',
        ])->assertRedirect();

        $classicChunk = TtsProject::where('title', 'Classic pron')->firstOrFail()->chunks()->first();
        $qwenChunk = TtsProject::where('title', 'Qwen pron')->firstOrFail()->chunks()->first();

        $this->assertStringContainsString('Aileus tee tee ess', $classicChunk->text);
        $this->assertStringContainsString('Alias TTS', $qwenChunk->text);
        $this->assertStringNotContainsString('Aileus', $qwenChunk->text);
    }

    public function test_review_approval_stores_the_batch_engine_scope(): void
    {
        $admin = $this->admin();
        $voice = Voice::create(['slug' => 'review-v', 'name' => 'Review V']);

        $this->actingAs($admin)->post(route('admin.studio.projects.apply'), [
            'title' => 'Scoped review',
            'text' => 'Alias TTS reads this.',
            'voice' => 'review-v',
            'approve' => [0],
            'substitutions' => [
                ['term' => 'Alias TTS', 'phonetic' => 'Aileus tee tee ess'],
            ],
            'engines' => ['chatterbox', 'chatterbox-turbo'],
        ])->assertRedirect();

        $entry = PronunciationEntry::where('term', 'Alias TTS')->firstOrFail();
        $this->assertTrue($entry->approved);
        $this->assertSame(['chatterbox', 'chatterbox-turbo'], $entry->engines);
        // The respelling keeps its capital A — stored verbatim, never lowercased.
        $this->assertSame('Aileus tee tee ess', $entry->phonetic);
    }

    public function test_the_lexicon_form_saves_and_clears_the_scope(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.pronunciations.store'), [
            'term' => 'Alias TTS',
            'phonetic' => 'Aileus tee tee ess',
            'match_mode' => 'case_sensitive',
            'engines' => ['chatterbox'],
        ])->assertRedirect();

        $entry = PronunciationEntry::where('term', 'Alias TTS')->firstOrFail();
        $this->assertSame(['chatterbox'], $entry->engines);

        // Re-checking every engine (or unchecking all — the checkboxes then
        // submit nothing) clears back to applies-everywhere.
        $this->actingAs($admin)->put(route('admin.pronunciations.update', $entry), [
            'term' => 'Alias TTS',
            'phonetic' => 'Aileus tee tee ess',
            'match_mode' => 'case_sensitive',
            'approved' => '1',
        ])->assertRedirect();

        $this->assertNull($entry->fresh()->engines);
    }

    public function test_the_read_api_exposes_the_engine_scope(): void
    {
        $user = User::factory()->create();
        $key = ApiKey::generate('scope-sync', userId: $user->id);
        $this->scopedEntry($user->id);

        $this->withHeaders(['xi-api-key' => $key->key])
            ->getJson('/v1/pronunciations')
            ->assertOk()
            ->assertJsonPath('entries.0.term', 'Alias TTS')
            ->assertJsonPath('entries.0.engines', ['chatterbox', 'chatterbox-turbo']);
    }

    public function test_the_lexicon_form_rejects_unknown_engines(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.pronunciations.create'))
            ->post(route('admin.pronunciations.store'), [
                'term' => 'X',
                'phonetic' => 'ex',
                'match_mode' => 'case_sensitive',
                'engines' => ['gpt-voice-9000'],
            ])
            ->assertSessionHasErrors('engines.0');
    }
}
