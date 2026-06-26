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

    public function test_unchecked_rows_are_skipped(): void
    {
        $admin = $this->admin();

        // Submit a suggestion but approve nothing → no dictionary write, text as-is.
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

        $this->assertSame(0, PronunciationEntry::count());
        $this->assertStringContainsString('DDEV', TtsProject::first()->source_text);
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
