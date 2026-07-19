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

    public function test_review_screen_surfaces_already_approved_terms_that_apply_to_the_text(): void
    {
        $admin = $this->admin();
        // Approved before → filtered out of the suggestion list, so it would be
        // applied silently. It must still show in the "already in your dictionary"
        // panel so the writer can see (and remove) it.
        PronunciationEntry::create([
            'user_id' => $admin->id, 'term' => 'MP3', 'phonetic' => 'em pee three',
            'source' => 'llm', 'approved' => true, 'match_mode' => 'case_sensitive',
        ]);
        // Approved but absent from this text → must NOT be listed.
        PronunciationEntry::create([
            'user_id' => $admin->id, 'term' => 'GraphQL', 'phonetic' => 'graf cue el',
            'source' => 'llm', 'approved' => true, 'match_mode' => 'case_sensitive',
        ]);

        // A new suggestion so the review screen renders instead of creating.
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
        ])]);

        $this->actingAs($admin)
            ->post(route('admin.studio.projects.review'), ['text' => 'Get an MP3 from DDEV.', 'voice' => 'v'])
            ->assertOk()
            ->assertSee('Already in your dictionary')
            ->assertSee('em pee three')
            ->assertDontSee('graf cue el')
            ->assertViewHas('autoApplied', fn ($autoApplied) => $autoApplied->pluck('term')->all() === ['MP3']);
    }

    public function test_destroy_returns_json_when_the_request_wants_json(): void
    {
        $admin = $this->admin();
        $entry = PronunciationEntry::create([
            'user_id' => $admin->id, 'term' => 'MP3', 'phonetic' => 'em pee three',
            'source' => 'llm', 'approved' => true, 'match_mode' => 'case_sensitive',
        ]);

        // The review screen removes an auto-applied term inline via fetch.
        $this->actingAs($admin)
            ->deleteJson(route('admin.pronunciations.destroy', $entry))
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertNull(PronunciationEntry::find($entry->id));
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

    // --- Async gate: detect() (JSON) + token hand-off to review() ------------

    public function test_detect_returns_a_token_and_creates_nothing_when_suggestions_exist(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
        ])]);

        $res = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.detect'), ['text' => 'Install DDEV first.', 'voice' => 'v'])
            ->assertOk();

        $this->assertNotEmpty($res->json('token'));
        // The gate is read-only — nothing is persisted until the client commits.
        $this->assertSame(0, TtsProject::count());
    }

    public function test_detect_reports_skip_when_there_is_nothing_to_review(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response(['available' => true, 'substitutions' => []])]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.detect'), ['text' => 'Plain text here.', 'voice' => 'v'])
            ->assertOk()
            ->assertJson(['skip' => true]);

        $this->assertSame(0, TtsProject::count());
    }

    public function test_reviews_token_renders_cached_suggestions_without_re_running_the_check(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
        ])]);

        $admin = $this->admin();
        $token = $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.detect'), ['text' => 'Install DDEV first.', 'voice' => 'v'])
            ->json('token');

        // Re-posting with the token renders the review screen from the cache…
        $this->actingAs($admin)
            ->post(route('admin.studio.projects.review'), [
                'text' => 'Install DDEV first.',
                'voice' => 'v',
                'detect_token' => $token,
            ])
            ->assertOk()
            ->assertSee('DDEV')
            ->assertSee('dee dev');

        // …paying for exactly one runner call (detect), not a second in review.
        Http::assertSentCount(1);
        $this->assertSame(0, TtsProject::count());
    }

    public function test_review_token_is_one_shot_and_falls_back_to_an_inline_check(): void
    {
        Http::fake(['runner.test/pronounce' => Http::response([
            'available' => true,
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
        ])]);

        $admin = $this->admin();
        $token = $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.detect'), ['text' => 'Install DDEV first.', 'voice' => 'v'])
            ->json('token');

        // First use consumes the token.
        $this->actingAs($admin)->post(route('admin.studio.projects.review'), [
            'text' => 'Install DDEV first.', 'voice' => 'v', 'detect_token' => $token,
        ])->assertOk();

        // A replay finds nothing cached and re-runs the check inline (still works,
        // just pays for another call) — proving the token can't be reused.
        $this->actingAs($admin)->post(route('admin.studio.projects.review'), [
            'text' => 'Install DDEV first.', 'voice' => 'v', 'detect_token' => $token,
        ])->assertOk()->assertSee('dee dev');

        Http::assertSentCount(2);
    }

    public function test_skip_uses_store_to_create_the_project_without_the_check(): void
    {
        // Skip hands off to store(): the create form's data, no pronunciation
        // runner call at all, straight to the project page with chunks.
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.store'), ['text' => 'Install DDEV first.', 'voice' => 'v'])
            ->assertRedirect();

        $this->assertSame(1, TtsProject::count());
        Http::assertNothingSent();
    }
}
