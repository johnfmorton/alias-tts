<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSetting;
use App\Services\Asr\AsrClient;
use App\Services\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Settings page + its resolver. Settings are PER USER: precedence is
 * .env (locked, instance-wide) → the user's DB override → config default; one
 * user's overrides are merged onto config only for their own requests/jobs and
 * never leak to anyone else.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

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

    /** A full, valid set of editable values (enabled is treated as env-locked). */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'tts_asr_studio_action' => 'log',
            'tts_asr_api_action' => 'auto',
            'tts_asr_max_rerolls' => 2,
            'tts_asr_trail_s_max' => 1.2,
            'tts_asr_gap_s_max' => 1.5,
            'tts_asr_tail_cov_min' => 0.93,
            'tts_asr_trim_guard_ms' => 80,
            'tts_asr_tail_energy_dbfs_max' => -38,
            'tts_asr_tail_release_ms' => 200,
            'tts_asr_tail_over_speech_db' => 6,
            'tts_asr_boundary_gap_min_ms' => 500,
            'tts_asr_boundary_energy_dbfs_max' => -55,
            'tts_asr_boundary_zcr_max_hz' => 1500,
            'tts_api_project_mode' => 'never',
            'tts_pronunciation_llm_provider' => 'gemini',
            'tts_project_output_format' => 'mp3_44100_128',
            'tts_chunk_mode' => 'packed',
            'tts_spoken_quotes' => 'off',
        ], $overrides);
    }

    private function row(User $user, string $key): ?UserSetting
    {
        return UserSetting::where('user_id', $user->id)->where('key', $key)->first();
    }

    public function test_a_users_override_is_layered_onto_config_for_an_unlocked_key(): void
    {
        $user = $this->user();
        $this->setLocked('tts.asr.api_action', false);
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.asr.api_action', 'value' => 'auto']);

        (new SettingsManager)->applyForUser($user->id);

        $this->assertSame('auto', config('tts.asr.api_action'));
        $this->assertSame('auto', app(AsrClient::class)->apiAction());
    }

    public function test_a_locked_key_is_never_overridden_by_the_db(): void
    {
        $user = $this->user();
        config(['tts.asr.enabled' => true]);              // the env value
        $this->setLocked('tts.asr.enabled', true);
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.asr.enabled', 'value' => false]); // stale DB row

        (new SettingsManager)->applyForUser($user->id);

        $this->assertTrue(config('tts.asr.enabled'));     // .env wins, DB ignored
    }

    public function test_one_users_settings_never_apply_to_another_user(): void
    {
        // The regression: a user's saved settings used to be global and changed
        // behavior for the SuperAdmin and everyone else.
        $user = $this->user();
        $admin = $this->admin();
        config(['tts.asr.api_action' => 'auto']);
        $this->setLocked('tts.asr.api_action', false);
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.asr.api_action', 'value' => 'log']);

        $manager = new SettingsManager;

        $manager->applyForUser($user->id);
        $this->assertSame('log', config('tts.asr.api_action'));   // their own request

        $manager->applyForUser($admin->id);
        $this->assertSame('auto', config('tts.asr.api_action'));  // untouched for the admin
    }

    public function test_apply_resets_to_defaults_between_users_in_one_process(): void
    {
        // A queue worker serves many users from one process — user A's overlay
        // must not leak into user B's job.
        $a = $this->user();
        $b = $this->user();
        config(['tts.asr.max_rerolls' => 2]);
        $this->setLocked('tts.asr.max_rerolls', false);
        UserSetting::create(['user_id' => $a->id, 'key' => 'tts.asr.max_rerolls', 'value' => 5]);

        $manager = new SettingsManager;

        $manager->applyForUser($a->id);
        $this->assertSame(5, config('tts.asr.max_rerolls'));

        $manager->applyForUser($b->id);
        $this->assertSame(2, config('tts.asr.max_rerolls'));

        $manager->applyForUser(null); // no user context → pristine defaults
        $this->assertSame(2, config('tts.asr.max_rerolls'));
    }

    public function test_display_value_resolves_the_inherit_chain(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.studio_action' => null]);
        $this->setLocked('tts.asr.studio_action', false);

        // No DB row + scoped key null → inherits the shared `action`.
        $this->assertSame('auto', (new SettingsManager)->displayValue('tts.asr.studio_action'));
    }

    public function test_studio_remediation_defaults_to_auto(): void
    {
        // The interactive Studio self-heals a flagged chunk by default (the badge
        // is still shown so an admin can re-roll further by hand).
        $this->assertSame('auto', app(AsrClient::class)->studioAction());
        $this->assertSame('auto', (new SettingsManager)->displayValue('tts.asr.studio_action'));
    }

    public function test_admin_can_view_the_settings_page(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.settings.index'));

        $res->assertOk();
        $res->assertSee('Transcript QA');
        $res->assertSee('Studio remediation');
        $res->assertSee('API remediation');
    }

    public function test_non_admin_can_view_the_settings_page(): void
    {
        // The panel is open to any signed-in, active user (only Users is SuperAdmin-gated).
        $this->actingAs($this->user())
            ->get(route('admin.settings.index'))
            ->assertOk();
    }

    public function test_saving_persists_unlocked_values_for_the_saving_user_only(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true); // mirror prod: master switch in .env

        $admin = $this->admin();
        $other = $this->user();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_asr_api_action' => 'auto',
                'tts_asr_max_rerolls' => 3,
            ]))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->assertSame('auto', $this->row($admin, 'tts.asr.api_action')->value);
        $this->assertSame('log', $this->row($admin, 'tts.asr.studio_action')->value);
        $this->assertSame(3, $this->row($admin, 'tts.asr.max_rerolls')->value);
        $this->assertNull($this->row($admin, 'tts.asr.enabled')); // locked → never written

        // Scoped to the saving user — nothing was written for anyone else.
        $this->assertSame(0, UserSetting::where('user_id', $other->id)->count());

        // A fresh boot applies the saved values for that user.
        (new SettingsManager)->applyForUser($admin->id);
        $this->assertSame('auto', config('tts.asr.api_action'));
    }

    public function test_each_admin_request_runs_under_the_signed_in_users_settings(): void
    {
        // End-to-end through the ApplyUserSettings middleware: the same panel
        // page runs under different effective config per signed-in user.
        config(['tts.asr.max_rerolls' => 2]);
        $this->setLocked('tts.asr.max_rerolls', false);
        $user = $this->user();
        $admin = $this->admin();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.asr.max_rerolls', 'value' => 5]);

        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk();
        $this->assertSame(2, config('tts.asr.max_rerolls')); // the other user's 5 is nowhere

        $this->actingAs($user)->get(route('admin.settings.index'))->assertOk();
        $this->assertSame(5, config('tts.asr.max_rerolls')); // their own page, their own value
    }

    public function test_validation_rejects_an_out_of_range_value(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_asr_max_rerolls' => 99, // > max 5
            ]))
            ->assertSessionHasErrors('tts_asr_max_rerolls');

        $this->assertNull($this->row($admin, 'tts.asr.max_rerolls'));
    }

    public function test_a_locked_key_cannot_be_changed_through_a_post(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_asr_enabled' => '0', // attempt to flip the locked master switch off
            ]));

        $this->assertNull($this->row($admin, 'tts.asr.enabled')); // never written
        $this->assertTrue(config('tts.asr.enabled'));              // still on
    }

    public function test_audio_output_group_renders_with_friendly_format_labels(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.settings.index'));

        $res->assertOk();
        $res->assertSee('Audio output');
        $res->assertSee('Final audio format');
        $res->assertSee('WAV — 44.1 kHz, 16-bit (uncompressed)');
    }

    public function test_generation_group_renders_with_chunk_mode_labels(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.settings.index'));

        $res->assertOk();
        $res->assertSee('Speech generation');
        $res->assertSee('Chunking');
        $res->assertSee('Per sentence — every sentence is its own chunk');
    }

    public function test_saving_persists_the_chunk_mode(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $user = $this->user();

        $this->actingAs($user)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_chunk_mode' => 'sentence',
            ]))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->assertSame('sentence', $this->row($user, 'tts.chunk_mode')->value);
    }

    public function test_an_unknown_chunk_mode_is_rejected(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_chunk_mode' => 'words', // not an offered option
            ]))
            ->assertSessionHasErrors('tts_chunk_mode');

        $this->assertNull($this->row($admin, 'tts.chunk_mode'));
    }

    public function test_generation_group_renders_with_spoken_quotes_labels(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.settings.index'));

        $res->assertOk();
        $res->assertSee('Spoken quote marks');
        $res->assertSee('Quote and close — say "quote" and "close quote" around quoted text');
        $res->assertSee('Open only — say "quote" at the start; the closing mark is silent');
    }

    public function test_saving_persists_the_spoken_quotes_mode(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $user = $this->user();

        $this->actingAs($user)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_spoken_quotes' => 'quote_close',
            ]))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->assertSame('quote_close', $this->row($user, 'tts.spoken_quotes')->value);
    }

    public function test_an_unknown_spoken_quotes_mode_is_rejected(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_spoken_quotes' => 'loud', // not an offered option
            ]))
            ->assertSessionHasErrors('tts_spoken_quotes');

        $this->assertNull($this->row($admin, 'tts.spoken_quotes'));
    }

    public function test_saving_persists_the_project_output_format(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $user = $this->user();

        $this->actingAs($user)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_project_output_format' => 'wav_44100',
            ]))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->assertSame('wav_44100', $this->row($user, 'tts.project_output_format')->value);
    }

    public function test_an_unknown_output_format_is_rejected(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_project_output_format' => 'ogg_48000', // not an offered option
            ]))
            ->assertSessionHasErrors('tts_project_output_format');

        $this->assertNull($this->row($admin, 'tts.project_output_format'));
    }

    public function test_pronunciation_group_renders(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.settings.index'));

        $res->assertOk();
        $res->assertSee('Pronunciation pre-processor');
        $res->assertSee('Detection LLM provider');
    }

    public function test_saving_persists_the_pronunciation_provider(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $admin = $this->admin();

        // 'ollama' also proves the newest enum value survives validation.
        $this->actingAs($admin)
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_pronunciation_llm_provider' => 'ollama',
            ]))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->assertSame('ollama', $this->row($admin, 'tts.pronunciation.llm_provider')->value);
    }
}
