<?php

namespace Tests\Feature;

use App\Models\MagicLoginToken;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MagicLoginTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function project(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: 'Linked project',
            voice: $voice,
            text: 'A short sentence for the project.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );
    }

    public function test_a_valid_token_logs_in_and_redirects_to_the_project(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        [, $plaintext] = MagicLoginToken::mint($admin, $project, null, 60);

        $this->get(route('projects.open', ['token' => $plaintext]))
            ->assertRedirect(route('admin.studio.projects.show', $project->id));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_token_is_single_use(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        [, $plaintext] = MagicLoginToken::mint($admin, $project, null, 60);

        $this->get(route('projects.open', ['token' => $plaintext]))
            ->assertRedirect(route('admin.studio.projects.show', $project->id));

        // A fresh request with the now-spent token must be rejected.
        $this->flushSession();
        $this->app['auth']->guard()->logout();

        $this->get(route('projects.open', ['token' => $plaintext]))
            ->assertStatus(403);

        $this->assertGuest();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $admin = $this->admin();
        $project = $this->project();
        [, $plaintext] = MagicLoginToken::mint($admin, $project, null, -1); // already past

        $this->get(route('projects.open', ['token' => $plaintext]))
            ->assertStatus(403);

        $this->assertGuest();
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->get(route('projects.open', ['token' => 'totally-made-up']))
            ->assertStatus(403);

        $this->assertGuest();
    }
}
