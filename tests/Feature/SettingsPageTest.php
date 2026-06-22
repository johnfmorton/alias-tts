<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Asr\AsrClient;
use App\Services\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin Settings page + its resolver. Precedence is
 * .env (locked) → DB override → config default; the DB layer is merged onto
 * config at boot and never overrides an env-pinned key.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
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
        ], $overrides);
    }

    public function test_db_override_is_layered_onto_config_for_an_unlocked_key(): void
    {
        $this->setLocked('tts.asr.api_action', false);
        Setting::create(['key' => 'tts.asr.api_action', 'value' => 'auto']);

        (new SettingsManager)->applyToConfig();

        $this->assertSame('auto', config('tts.asr.api_action'));
        $this->assertSame('auto', app(AsrClient::class)->apiAction());
    }

    public function test_a_locked_key_is_never_overridden_by_the_db(): void
    {
        config(['tts.asr.enabled' => true]);              // the env value
        $this->setLocked('tts.asr.enabled', true);
        Setting::create(['key' => 'tts.asr.enabled', 'value' => false]); // stale DB row

        (new SettingsManager)->applyToConfig();

        $this->assertTrue(config('tts.asr.enabled'));     // .env wins, DB ignored
    }

    public function test_display_value_resolves_the_inherit_chain(): void
    {
        config(['tts.asr.action' => 'auto', 'tts.asr.studio_action' => null]);
        $this->setLocked('tts.asr.studio_action', false);

        // No DB row + scoped key null → inherits the shared `action`.
        $this->assertSame('auto', (new SettingsManager)->displayValue('tts.asr.studio_action'));
    }

    public function test_admin_can_view_the_settings_page(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.settings.index'));

        $res->assertOk();
        $res->assertSee('ASR transcript QA');
        $res->assertSee('Studio remediation');
        $res->assertSee('API remediation');
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_saving_persists_unlocked_values(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true); // mirror prod: master switch in .env

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_asr_api_action' => 'auto',
                'tts_asr_max_rerolls' => 3,
            ]))
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->assertSame('auto', Setting::find('tts.asr.api_action')->value);
        $this->assertSame('log', Setting::find('tts.asr.studio_action')->value);
        $this->assertSame(3, Setting::find('tts.asr.max_rerolls')->value);
        $this->assertNull(Setting::find('tts.asr.enabled')); // locked → never written

        // A fresh boot applies the saved values to config.
        (new SettingsManager)->applyToConfig();
        $this->assertSame('auto', config('tts.asr.api_action'));
    }

    public function test_validation_rejects_an_out_of_range_value(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_asr_max_rerolls' => 99, // > max 5
            ]))
            ->assertSessionHasErrors('tts_asr_max_rerolls');

        $this->assertNull(Setting::find('tts.asr.max_rerolls'));
    }

    public function test_a_locked_key_cannot_be_changed_through_a_post(): void
    {
        config(['tts.asr.enabled' => true]);
        $this->setLocked('tts.asr.enabled', true);

        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), $this->validPayload([
                'tts_asr_enabled' => '0', // attempt to flip the locked master switch off
            ]));

        $this->assertNull(Setting::find('tts.asr.enabled')); // never written
        $this->assertTrue(config('tts.asr.enabled'));        // still on
    }
}
