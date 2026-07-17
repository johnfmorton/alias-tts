<?php

namespace Tests\Feature;

use App\Models\PronunciationEntry;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The new pre-chunking review screen wired into the create-project flow: detect
 * (faked runner) -> review -> approve -> apply + create. Degrades to a direct
 * create when there is nothing to review or the feature is off.
 */
class PronunciationReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.genblaze.runner_url' => 'http://runner.test',
            'tts.pronunciation.enabled' => true,
        ]);
        Storage::fake('local');
        Voice::create(['slug' => 'v', 'name' => 'V']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_review_renders_suggestions_without_creating_a_project(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
            'provenance' => ['provider' => 'replicate', 'model' => 'meta/meta-llama-3.1-8b-instruct'],
        ])]);

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.review'), ['text' => 'Install DDEV first.', 'voice' => 'v'])
            ->assertOk()
            ->assertSee('DDEV')
            ->assertSee('dee dev');

        $this->assertSame(0, TtsProject::count());
    }

    public function test_apply_persists_dictionary_and_creates_project_with_substitution(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.apply'), [
                'text' => 'Install DDEV first.',
                'voice' => 'v',
                'approve' => ['0'],
                'substitutions' => [
                    ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
                ],
            ])
            ->assertRedirect();

        $entry = PronunciationEntry::where('term', 'DDEV')->first();
        $this->assertNotNull($entry);
        $this->assertTrue($entry->approved);
        $this->assertSame($admin->id, $entry->user_id);

        $project = TtsProject::first();
        $this->assertNotNull($project);
        // The original text is preserved as the source; the respelling is applied
        // only to the normalized/chunked text the voice reads.
        $this->assertStringContainsString('DDEV', $project->source_text);
        $this->assertStringContainsString('dee dev', $project->normalized_text);
    }

    public function test_unchecked_rows_are_skipped_and_remembered_as_declined(): void
    {
        $admin = $this->admin();

        // Submit a suggestion but approve nothing → text as-is, and the "no" is
        // recorded as a declined (unapproved) entry so review stops pre-checking it.
        $this->actingAs($admin)
            ->post(route('admin.studio.projects.apply'), [
                'text' => 'Install DDEV first.',
                'voice' => 'v',
                'approve' => [],
                'substitutions' => [
                    ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
                ],
            ])
            ->assertRedirect();

        $entry = PronunciationEntry::where('term', 'DDEV')->first();
        $this->assertNotNull($entry);
        $this->assertFalse($entry->approved);
        $this->assertSame($admin->id, $entry->user_id);

        $project = TtsProject::first();
        $this->assertStringContainsString('DDEV', $project->source_text);
        $this->assertStringNotContainsString('dee dev', $project->normalized_text);
    }

    public function test_previously_declined_terms_render_unchecked(): void
    {
        $admin = $this->admin();
        PronunciationEntry::create([
            'user_id' => $admin->id,
            'term' => 'Laravel',
            'phonetic' => 'lar-a-vel',
            'source' => 'llm',
            'approved' => false,
            'match_mode' => 'case_sensitive',
        ]);

        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'Laravel', 'phonetic' => 'lar-a-vel', 'category' => 'tech_name', 'confidence' => 'high'],
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
        ])]);

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.review'), ['text' => 'Laravel needs DDEV.', 'voice' => 'v'])
            ->assertOk()
            // Still offered (the writer can change their mind per project)…
            ->assertSee('Laravel')
            ->assertSee('skipped before')
            // …but only the undecided high-confidence term is pre-checked.
            ->assertViewHas('suggestions', function (array $suggestions) {
                $byTerm = collect($suggestions)->keyBy('term');

                return $byTerm['Laravel']['checked'] === false
                    && $byTerm['Laravel']['previously_rejected'] === true
                    && $byTerm['DDEV']['checked'] === true
                    && $byTerm['DDEV']['previously_rejected'] === false;
            });
    }

    public function test_declining_never_downgrades_an_approved_entry(): void
    {
        $admin = $this->admin();
        PronunciationEntry::create([
            'user_id' => $admin->id,
            'term' => 'DDEV',
            'phonetic' => 'dee dev',
            'source' => 'llm',
            'approved' => true,
            'match_mode' => 'case_sensitive',
        ]);

        // A stale review form re-submits DDEV unchecked → the approved entry survives.
        $this->actingAs($admin)
            ->post(route('admin.studio.projects.apply'), [
                'text' => 'Install DDEV first.',
                'voice' => 'v',
                'approve' => [],
                'substitutions' => [
                    ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
                ],
            ])
            ->assertRedirect();

        $this->assertTrue(PronunciationEntry::where('term', 'DDEV')->first()->approved);
    }

    public function test_duplicate_terms_are_collapsed_keeping_highest_confidence(): void
    {
        // The LLM sometimes lists a term once per occurrence in the text.
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'Llama', 'phonetic' => 'lama', 'category' => 'tech_name', 'confidence' => 'low'],
                ['term' => 'Llama', 'phonetic' => 'lama', 'category' => 'tech_name', 'confidence' => 'high'],
                ['term' => 'LLAMA', 'phonetic' => 'lama', 'category' => 'tech_name', 'confidence' => 'medium'],
            ],
        ])]);

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.review'), ['text' => 'Llama and Llama and LLAMA.', 'voice' => 'v'])
            ->assertOk()
            ->assertViewHas('suggestions', function (array $suggestions) {
                return count($suggestions) === 1
                    && $suggestions[0]['term'] === 'Llama'
                    && $suggestions[0]['confidence'] === 'high';
            });
    }

    public function test_review_creates_directly_when_no_new_suggestions(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response(['available' => true, 'substitutions' => []])]);

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.review'), ['text' => 'Plain text here.', 'voice' => 'v'])
            ->assertRedirect();

        $this->assertSame(1, TtsProject::count());
    }

    public function test_review_creates_directly_and_is_silent_when_disabled(): void
    {
        config(['tts.pronunciation.enabled' => false]);
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.review'), ['text' => 'Install DDEV first.', 'voice' => 'v'])
            ->assertRedirect();

        $this->assertSame(1, TtsProject::count());
        Http::assertNothingSent();
    }
}
