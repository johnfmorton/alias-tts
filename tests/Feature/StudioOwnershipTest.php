<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Studio projects are personal (TtsProjectPolicy): a user sees and touches only
 * their own; a SuperAdmin retains full access. Regression cover for the launch
 * gap where any active user could list, edit, and delete everyone's projects.
 */
class StudioOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function user(bool $superAdmin = false): User
    {
        return User::factory()->create(['is_super_admin' => $superAdmin]);
    }

    private function projectOwnedBy(?User $owner, string $title = 'Owned project'): TtsProject
    {
        $voice = Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);

        return app(ProjectService::class)->createFromText(
            title: $title,
            voice: $voice,
            text: 'A sentence long enough to become a chunk of its own accord.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            userId: $owner?->id,
        );
    }

    public function test_index_lists_only_the_users_own_projects(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->projectOwnedBy($alice, 'Alice project');
        $this->projectOwnedBy($bob, 'Bob project');

        $this->actingAs($alice)->get(route('admin.studio.index'))
            ->assertOk()
            ->assertSee('Alice project')
            ->assertDontSee('Bob project')
            // Everything here is Alice's, so the Owner column doesn't render.
            ->assertDontSee('Owner');
    }

    public function test_superadmin_index_defaults_to_their_own_projects(): void
    {
        $alice = $this->user();
        $alice->update(['name' => 'Alice']);
        $this->projectOwnedBy($alice, 'Alice project');
        $admin = $this->user(superAdmin: true);
        $this->projectOwnedBy($admin, 'Admin project');

        $this->actingAs($admin)->get(route('admin.studio.index'))
            ->assertOk()
            ->assertSee('Admin project')
            ->assertDontSee('Alice project')
            // Alice still appears — as an option in the owner dropdown, which
            // lists the signed-in admin first, then the widener, then the rest.
            ->assertSeeInOrder(['(you)', 'All owners', 'Alice']);
    }

    public function test_superadmin_can_widen_the_index_to_everyones_projects(): void
    {
        $alice = $this->user();
        $alice->update(['name' => 'Alice']);
        $this->projectOwnedBy($alice, 'Alice project');

        $this->actingAs($this->user(superAdmin: true))
            ->get(route('admin.studio.index', ['owner' => 'all']))
            ->assertOk()
            ->assertSee('Alice project')
            ->assertSee('Alice')
            ->assertSee('Owner');
    }

    public function test_superadmin_can_filter_the_index_by_owner(): void
    {
        $alice = $this->user();
        $alice->update(['name' => 'Alice']);
        $bob = $this->user();
        $bob->update(['name' => 'Bob']);
        $this->projectOwnedBy($alice, 'Alice project');
        $this->projectOwnedBy($bob, 'Bob project');

        $this->actingAs($this->user(superAdmin: true))
            ->get(route('admin.studio.index', ['owner' => $alice->id]))
            ->assertOk()
            ->assertSee('Alice project')
            ->assertDontSee('Bob project');
    }

    public function test_owner_filter_never_widens_a_regular_users_view(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->projectOwnedBy($alice, 'Alice project');
        $this->projectOwnedBy($bob, 'Bob project');

        // Passing someone else's id must not leak their projects.
        $this->actingAs($alice)
            ->get(route('admin.studio.index', ['owner' => $bob->id]))
            ->assertOk()
            ->assertSee('Alice project')
            ->assertDontSee('Bob project');

        // Neither must the SuperAdmin-only widener.
        $this->actingAs($alice)
            ->get(route('admin.studio.index', ['owner' => 'all']))
            ->assertOk()
            ->assertSee('Alice project')
            ->assertDontSee('Bob project');
    }

    public function test_a_user_cannot_open_anothers_project(): void
    {
        $project = $this->projectOwnedBy($this->user());

        $this->actingAs($this->user())
            ->get(route('admin.studio.projects.show', $project))
            ->assertForbidden();
    }

    public function test_a_user_cannot_modify_or_delete_anothers_project(): void
    {
        $project = $this->projectOwnedBy($this->user());
        $intruder = $this->user();

        $this->actingAs($intruder)
            ->patch(route('admin.studio.projects.update', $project), ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->delete(route('admin.studio.projects.destroy', $project))
            ->assertForbidden();

        $this->assertSame('Owned project', $project->fresh()->title);
    }

    public function test_the_owner_and_a_superadmin_can_open_a_project(): void
    {
        $owner = $this->user();
        $project = $this->projectOwnedBy($owner);

        $this->actingAs($owner)
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk();

        $this->actingAs($this->user(superAdmin: true))
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk();
    }

    public function test_an_unowned_project_is_superadmin_only(): void
    {
        $project = $this->projectOwnedBy(null);

        $this->actingAs($this->user())
            ->get(route('admin.studio.projects.show', $project))
            ->assertForbidden();

        $this->actingAs($this->user(superAdmin: true))
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk();
    }

    public function test_superadmin_gets_the_foreign_edit_guard_on_anothers_project(): void
    {
        $alice = $this->user();
        $alice->update(['name' => 'Alice']);
        $project = $this->projectOwnedBy($alice);

        $this->actingAs($this->user(superAdmin: true))
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('id="foreign-guard"', false)
            ->assertSee("Alice's project", false)
            ->assertSee('Edit their project');
    }

    public function test_the_owner_never_sees_the_foreign_edit_guard(): void
    {
        $owner = $this->user();
        $project = $this->projectOwnedBy($owner);

        $this->actingAs($owner)
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertDontSee('id="foreign-guard"', false)
            ->assertDontSee('Edit their project');
    }

    public function test_an_unowned_project_names_its_deleted_owner_in_the_guard(): void
    {
        $project = $this->projectOwnedBy(null);

        $this->actingAs($this->user(superAdmin: true))
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee("a deleted user's project", false);
    }

    public function test_start_over_warns_a_superadmin_on_anothers_project(): void
    {
        $alice = $this->user();
        $alice->update(['name' => 'Alice']);
        $project = $this->projectOwnedBy($alice);

        $this->actingAs($this->user(superAdmin: true))
            ->get(route('admin.studio.projects.edit', $project))
            ->assertOk()
            ->assertSee("This is Alice's project.", false);

        $this->actingAs($alice)
            ->get(route('admin.studio.projects.edit', $project))
            ->assertOk()
            ->assertDontSee('This is Alice');
    }

    public function test_panel_creation_stamps_the_signed_in_owner(): void
    {
        Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);
        $user = $this->user();

        $this->actingAs($user)->post(route('admin.studio.projects.store'), [
            'title' => 'Mine',
            'voice' => 'v',
            'text' => 'A sentence long enough to become a chunk of its own accord.',
        ]);

        $this->assertSame($user->id, TtsProject::firstWhere('title', 'Mine')->user_id);
    }

    public function test_api_creation_stamps_the_keys_owner(): void
    {
        $owner = $this->user();
        $apiKey = ApiKey::generate('key', null, $owner->id);
        $voice = Voice::firstOrCreate(['slug' => 'v'], ['name' => 'V']);

        $project = app(ProjectService::class)->createFromText(
            title: 'Via API',
            voice: $voice,
            text: 'A sentence long enough to become a chunk of its own accord.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
            apiKey: $apiKey,
        );

        $this->assertSame($owner->id, $project->user_id);
    }
}
