<?php

namespace Tests\Feature;

use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Start over" must re-open the writer's ORIGINAL text, not the respelled form.
 * The pronunciation substitution belongs only to the normalized/chunked text the
 * voice reads — `source_text` keeps what the writer actually typed.
 */
class StudioStartOverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.pronunciation.enabled' => true,
        ]);
        Storage::fake('local');
        Voice::create(['slug' => 'v', 'name' => 'V']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_apply_keeps_original_source_text_and_respells_only_the_chunks(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.apply'), [
                'text' => 'Install DDEV first.',
                'voice' => 'v',
                'approve' => ['0'],
                'substitutions' => [
                    ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
                ],
            ])
            ->assertRedirect();

        $project = TtsProject::first();
        $this->assertStringContainsString('DDEV', $project->source_text);
        $this->assertStringNotContainsString('dee dev', $project->source_text);
        $this->assertStringContainsString('dee dev', $project->normalized_text);
        // The chunk the voice reads carries the respelling.
        $this->assertStringContainsString('dee dev', $project->chunks()->first()->text);
    }

    public function test_start_over_reopens_the_original_and_reset_reapplies_the_dictionary(): void
    {
        $admin = $this->admin();

        // Create via the apply path: approve DDEV -> dee dev (also accumulates it).
        $this->actingAs($admin)->post(route('admin.studio.projects.apply'), [
            'text' => 'Install DDEV first.',
            'voice' => 'v',
            'approve' => ['0'],
            'substitutions' => [
                ['term' => 'DDEV', 'phonetic' => 'dee dev', 'category' => 'initialism', 'confidence' => 'high'],
            ],
        ])->assertRedirect();

        $project = TtsProject::first();

        // "Start over" re-opens the ORIGINAL text, not the respelling.
        $this->actingAs($admin)
            ->get(route('admin.studio.projects.edit', $project))
            ->assertOk()
            ->assertSee('Install DDEV first.')
            ->assertDontSee('dee dev');

        // Re-submitting the original re-applies the (now-accumulated) dictionary.
        $this->actingAs($admin)
            ->post(route('admin.studio.projects.reset', $project), ['text' => 'Install DDEV first.'])
            ->assertRedirect();

        $project->refresh();
        $this->assertStringContainsString('DDEV', $project->source_text);
        $this->assertStringContainsString('dee dev', $project->normalized_text);
    }
}
