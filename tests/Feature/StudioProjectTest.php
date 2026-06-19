<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudioProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** A 2-chunk project (two paragraphs, each long enough to stand alone). */
    private function project(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'My project',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_create_page_requires_admin(): void
    {
        $this->get(route('admin.studio.projects.create'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.studio.projects.create'))
            ->assertForbidden();
    }

    public function test_store_creates_project_with_chunks(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $res = $this->actingAs($this->admin())->post(route('admin.studio.projects.store'), [
            'title' => 'Doc',
            'voice' => 'v',
            'text' => "First paragraph that is comfortably long enough.\n\nSecond paragraph that is also long enough.",
        ]);

        $project = TtsProject::firstWhere('title', 'Doc');
        $this->assertNotNull($project);
        $res->assertRedirect(route('admin.studio.projects.show', $project));
        $this->assertCount(2, $project->chunks);
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertSame(ChunkStatus::Pending, $project->chunks->first()->status);
    }

    public function test_editor_page_renders(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee($project->title)
            ->assertSee('Rebuild final')
            ->assertSee($project->chunks()->first()->text);
    }

    public function test_generate_chunk_persists_audio_and_is_selective(): void
    {
        $project = $this->project();
        [$first, $second] = $project->chunks()->get()->all();

        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $first]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $first->refresh();
        $second->refresh();

        // Only the targeted chunk was generated.
        $this->assertSame(ChunkStatus::Completed, $first->status);
        $this->assertNotNull($first->audio_path);
        $this->assertTrue(Storage::disk('local')->exists($first->audio_path));
        $this->assertSame(ChunkStatus::Pending, $second->status);
        $this->assertNull($second->audio_path);
    }

    public function test_chunk_audio_is_served_after_generation(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();

        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));

        $res = $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.chunks.audio', [$project, $chunk]));

        $res->assertOk();
        $this->assertStringStartsWith('audio/wav', (string) $res->headers->get('content-type'));
    }

    public function test_rebuild_requires_all_chunks_generated(): void
    {
        $project = $this->project();
        $this->actingAs($this->admin())
            ->post(route('admin.studio.projects.chunks.generate', [$project, $project->chunks()->first()]));

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertStatus(422)
            ->assertJsonPath('message', '1 chunk(s) still need to be generated before rebuilding.');
    }

    public function test_rebuild_stitches_and_serves_final(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.rebuild', $project))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $project->refresh();
        $this->assertSame(ProjectStatus::Ready, $project->status);
        $this->assertNotNull($project->final_audio_path);
        $this->assertTrue(Storage::disk('local')->exists($project->final_audio_path));

        $audio = $this->actingAs($this->admin())->get(route('admin.studio.projects.audio', $project));
        $audio->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $audio->headers->get('content-type'));
    }

    public function test_editing_a_chunk_marks_it_and_the_project_stale(): void
    {
        $project = $this->project();
        foreach ($project->chunks()->get() as $chunk) {
            $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        }
        $this->actingAs($this->admin())->postJson(route('admin.studio.projects.rebuild', $project));

        $chunk = $project->chunks()->first();
        $this->actingAs($this->admin())
            ->patchJson(route('admin.studio.projects.chunks.update', [$project, $chunk]), ['text' => 'A rewritten sentence here.'])
            ->assertOk()
            ->assertJsonPath('status', 'stale')
            ->assertJsonPath('project_status', 'stale');

        $this->assertSame(ChunkStatus::Stale, $chunk->refresh()->status);
        $this->assertSame('A rewritten sentence here.', $chunk->text);
    }

    public function test_destroy_removes_project_and_audio(): void
    {
        $project = $this->project();
        $chunk = $project->chunks()->first();
        $this->actingAs($this->admin())->post(route('admin.studio.projects.chunks.generate', [$project, $chunk]));
        $path = $chunk->refresh()->audio_path;

        $this->actingAs($this->admin())
            ->delete(route('admin.studio.projects.destroy', $project))
            ->assertRedirect(route('admin.studio.index'));

        $this->assertNull(TtsProject::find($project->id));
        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}
