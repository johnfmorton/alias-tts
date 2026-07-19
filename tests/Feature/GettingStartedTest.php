<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSetting;
use App\Services\Settings\SettingsManager;
use App\Support\GettingStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-page "Getting Started" intro messages (Dashboard, Studio, Voices,
 * Pronunciations, API Keys). Each page is backed by its own managed per-user
 * bool (App\Support\GettingStarted, default on): every user sees a page's
 * message until they hide it there, dismissing one page never hides another's,
 * the Account page's "Restore Getting Started messages" button brings them all
 * back, and pinning a page's env key removes that page's controls entirely.
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

    public function test_the_account_page_offers_the_restore_control_unless_every_key_is_pinned(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('admin.account.index'))
            ->assertOk()
            ->assertSee('Getting Started messages introduce key features')
            ->assertSee('Restore Getting Started messages')
            ->assertSee(route('admin.dashboard.getting-started'), false);

        // One pinned key still leaves messages the button can restore.
        $this->setLocked('tts.show_getting_started', true);
        $this->actingAs($user)->get(route('admin.account.index'))
            ->assertOk()
            ->assertSee('Restore Getting Started messages');

        // Every key pinned = nothing to restore; the Interface card hides.
        foreach (GettingStarted::PAGES as $key) {
            $this->setLocked($key, true);
        }
        $this->actingAs($user)->get(route('admin.account.index'))
            ->assertOk()
            ->assertDontSee('Restore Getting Started messages');
    }

    public function test_each_page_shows_its_own_message_by_default(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get(route('admin.studio.index'))->assertSee('Welcome to Studio');
        $this->actingAs($user)->get(route('admin.voices.index'))->assertSee('Welcome to Voices');
        $this->actingAs($user)->get(route('admin.pronunciations.index'))->assertSee('Welcome to Pronunciations');
        $this->actingAs($user)->get(route('admin.api-keys.index'))->assertSee('Welcome to API Keys');
    }

    public function test_dismissing_one_page_hides_only_that_pages_message(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('admin.dashboard.getting-started'), ['page' => 'studio', 'show' => false])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $row = UserSetting::where('user_id', $user->id)->where('key', 'tts.show_getting_started_studio')->first();
        $this->assertNotNull($row);
        $this->assertFalse($row->value);

        $this->actingAs($user)->get(route('admin.studio.index'))->assertDontSee('Welcome to Studio');
        // The other pages' messages are untouched.
        $this->actingAs($user)->get(route('admin.voices.index'))->assertSee('Welcome to Voices');
        $this->actingAs($user)->get(route('admin.dashboard'))->assertSee('Welcome to Alias');
    }

    public function test_restore_all_brings_back_every_dismissed_message(): void
    {
        $user = $this->user();
        foreach (GettingStarted::PAGES as $key) {
            UserSetting::create(['user_id' => $user->id, 'key' => $key, 'value' => false]);
        }

        $this->actingAs($user)
            ->post(route('admin.dashboard.getting-started'), ['page' => 'all', 'show' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach (GettingStarted::PAGES as $key) {
            $this->assertTrue(UserSetting::where('user_id', $user->id)->where('key', $key)->first()->value, $key);
        }

        $this->actingAs($user)->get(route('admin.studio.index'))->assertSee('Welcome to Studio');
    }

    public function test_an_unknown_page_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->postJson(route('admin.dashboard.getting-started'), ['page' => 'health', 'show' => false])
            ->assertStatus(422);
    }

    public function test_a_pinned_page_key_hides_that_pages_dismiss_control(): void
    {
        $user = $this->user();
        $this->setLocked('tts.show_getting_started_voices', true);

        // Pinned on: the message shows but there is nothing to hide.
        config(['tts.show_getting_started_voices' => true]);
        $this->actingAs($user)->get(route('admin.voices.index'))
            ->assertSee('Welcome to Voices')
            ->assertDontSee('Hide this guide');

        // An unpinned page keeps its dismiss control.
        $this->actingAs($user)->get(route('admin.studio.index'))
            ->assertSee('Welcome to Studio')
            ->assertSee('Hide this guide');
    }
}
