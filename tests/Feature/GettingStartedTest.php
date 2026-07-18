<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSetting;
use App\Services\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Dashboard "Get started" guide. Backed by the managed per-user setting
 * tts.show_getting_started (default on): every user sees the panel until they
 * hide it, can bring it back from the Dashboard, Settings, or Account page,
 * and pinning TTS_SHOW_GETTING_STARTED in .env removes the controls entirely.
 */
class GettingStartedTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_super_admin' => false]);
    }

    /** Force a managed key's `locked` flag for the duration of a test. */
    private function setLocked(string $path, bool $locked): void
    {
        $managed = config('settings.managed');
        $managed[$path]['locked'] = $locked;
        config(['settings.managed' => $managed]);
    }

    private function row(User $user): ?UserSetting
    {
        return UserSetting::where('user_id', $user->id)->where('key', 'tts.show_getting_started')->first();
    }

    public function test_a_fresh_user_sees_the_guide_with_both_starting_paths(): void
    {
        $this->actingAs($this->user())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Welcome to Alias')
            ->assertSee('Clone your voice')
            ->assertSee('Start with a built-in voice')
            ->assertSee(route('admin.voices.create'), false)
            ->assertSee(route('admin.studio.projects.create'), false)
            ->assertSee('href="#connect"', false)
            ->assertSee('id="connect"', false)
            ->assertSee('Hide this guide');
    }

    public function test_dismissing_persists_and_hides_the_guide(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('admin.dashboard.getting-started'), ['show' => false])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $row = $this->row($user);
        $this->assertNotNull($row);
        $this->assertFalse($row->value);

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Welcome to Alias')
            ->assertSee('Show getting started');
    }

    public function test_the_restore_post_redirects_and_brings_the_guide_back(): void
    {
        $user = $this->user();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.show_getting_started', 'value' => false]);

        $this->actingAs($user)
            ->post(route('admin.dashboard.getting-started'), ['show' => 1])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success');

        $this->assertTrue($this->row($user)->value);
        $this->actingAs($user)->get(route('admin.dashboard'))->assertSee('Welcome to Alias');
    }

    public function test_a_plain_form_dismiss_works_without_js(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post(route('admin.dashboard.getting-started'), ['show' => 0])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success');

        $this->assertFalse($this->row($user)->value);
    }

    public function test_show_is_required_and_boolean(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('admin.dashboard.getting-started'), [])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson(route('admin.dashboard.getting-started'), ['show' => 'banana'])
            ->assertStatus(422);
    }

    public function test_an_env_pinned_key_hides_the_controls_and_is_never_written(): void
    {
        $user = $this->user();
        $this->setLocked('tts.show_getting_started', true);

        // Pinned on: the panel shows but there is nothing to hide or restore.
        config(['tts.show_getting_started' => true]);
        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertSee('Welcome to Alias')
            ->assertDontSee('Hide this guide')
            ->assertDontSee('Show getting started');

        // A stale page posting anyway degrades to a no-op, not an error.
        $this->actingAs($user)
            ->postJson(route('admin.dashboard.getting-started'), ['show' => false])
            ->assertOk();
        $this->assertNull($this->row($user));

        // Pinned off: no panel and no restore link. The singleton SettingsManager
        // snapshotted a baseline of `true` during the requests above and would
        // restore it over this config() call — forget the instance so the next
        // request's overlay re-snapshots the pinned-off value.
        config(['tts.show_getting_started' => false]);
        $this->app->forgetInstance(SettingsManager::class);
        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertDontSee('Welcome to Alias')
            ->assertDontSee('Show getting started');
    }

    public function test_dismissal_is_per_user(): void
    {
        $dismisser = $this->user();
        $other = $this->user();

        $this->actingAs($dismisser)
            ->postJson(route('admin.dashboard.getting-started'), ['show' => false])
            ->assertOk();

        $this->actingAs($other)->get(route('admin.dashboard'))->assertSee('Welcome to Alias');
    }

    public function test_the_account_page_offers_the_restore_control_unless_pinned(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('admin.account.index'))
            ->assertOk()
            ->assertSee('Show the guide again')
            ->assertSee(route('admin.dashboard.getting-started'), false);

        $this->setLocked('tts.show_getting_started', true);
        $this->actingAs($user)->get(route('admin.account.index'))
            ->assertOk()
            ->assertDontSee('Show the guide again');
    }
}
