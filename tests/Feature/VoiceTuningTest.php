<?php

namespace Tests\Feature;

use App\Models\TuningPreset;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1a of docs/STUDIO-TUNING.md: tuning knobs are first-class voice
 * defaults, settable from the dashboard and the console. (Phase 0 already wired
 * them to propagate to generation via VoiceSettingsResolver.) The edit form
 * speaks Chatterbox's native exaggeration/cfg_weight; the voice:create console
 * command still accepts the ElevenLabs-style --stability/--style for /v1 parity.
 */
class VoiceTuningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_update_saves_tuning_defaults_into_voice_settings(): void
    {
        $voice = Voice::create(['slug' => 'john', 'name' => 'John']);

        $this->actingAs($this->admin())
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'exaggeration' => 1.2,
                'cfg_weight' => 0.8,
            ])
            ->assertRedirect(route('admin.voices.index'));

        $settings = $voice->refresh()->settings;
        $this->assertSame(1.2, $settings['exaggeration']);
        $this->assertSame(0.8, $settings['cfg_weight']);
    }

    public function test_clearing_tuning_fields_removes_them(): void
    {
        $voice = Voice::create([
            'slug' => 'john',
            'name' => 'John',
            'settings' => ['exaggeration' => 1.2, 'cfg_weight' => 0.8, 'seed' => 42],
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'seed' => 42, // keep the seed; drop the tuning
            ])
            ->assertRedirect(route('admin.voices.index'));

        $settings = $voice->refresh()->settings;
        $this->assertArrayNotHasKey('exaggeration', $settings);
        $this->assertArrayNotHasKey('cfg_weight', $settings);
        $this->assertSame(42, $settings['seed']);
    }

    public function test_saving_drops_legacy_elevenlabs_style_keys(): void
    {
        // A voice tuned before v0.15.0 carries stability/style. Saving through
        // the edit form rewrites tuning in native form only — including when a
        // knob is cleared, so the legacy value can't resurface via the resolver.
        $voice = Voice::create([
            'slug' => 'john',
            'name' => 'John',
            'settings' => ['stability' => 0.8, 'style' => 0.3],
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'exaggeration' => 0.95, // cfg_weight left blank => cleared
            ])
            ->assertRedirect(route('admin.voices.index'));

        $settings = $voice->refresh()->settings;
        $this->assertSame(0.95, $settings['exaggeration']);
        $this->assertArrayNotHasKey('stability', $settings);
        $this->assertArrayNotHasKey('style', $settings);
        $this->assertArrayNotHasKey('cfg_weight', $settings);
    }

    public function test_edit_form_shows_legacy_tuning_as_its_native_equivalent(): void
    {
        // stability 0.8 => cfg_weight 0.8; style 0.3 => exaggeration 0.95.
        $voice = Voice::create([
            'slug' => 'john',
            'name' => 'John',
            'settings' => ['stability' => 0.8, 'style' => 0.3],
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('value="0.95"', false)
            ->assertSee('value="0.8"', false);
    }

    public function test_voice_edit_page_hosts_the_tuning_bench(): void
    {
        $admin = $this->admin();
        TuningPreset::create(['user_id' => $admin->id, 'name' => 'Calm narration', 'exaggeration' => 0.95, 'cfg_weight' => 0.8]);
        $voice = Voice::create(['slug' => 'john', 'name' => 'John']);

        // The bench IS step 3 now — the takes table is the knob editor, and the
        // pick rides the page's one save bar rather than a second save path.
        $this->actingAs($admin)
            ->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('Delivery defaults')
            ->assertSee('each row is a candidate default')
            ->assertSee('Calm narration')
            ->assertDontSee('Save pick as voice defaults');
    }

    public function test_the_edit_page_has_exactly_one_save_path(): void
    {
        $voice = Voice::create(['slug' => 'john', 'name' => 'John']);

        $this->actingAs($this->admin())
            ->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('data-save-bar', false)
            // The picked row writes into these; nothing else persists tuning.
            ->assertSee('data-delivery-field="exaggeration"', false)
            ->assertSee('data-delivery-field="cfg_weight"', false)
            ->assertSee('data-delivery-field="temperature"', false);
    }

    public function test_voice_edit_page_shows_only_your_presets(): void
    {
        $me = User::factory()->create(['is_super_admin' => false]);
        $other = User::factory()->create(['is_super_admin' => false]);
        TuningPreset::create(['user_id' => $me->id, 'name' => 'My warm read', 'exaggeration' => 0.8]);
        TuningPreset::create(['user_id' => $other->id, 'name' => 'Their fast read', 'cfg_weight' => 0.3]);
        $voice = Voice::create(['user_id' => $me->id, 'slug' => 'mine', 'name' => 'Mine']);

        $this->actingAs($me)->get(route('admin.voices.edit', $voice))
            ->assertOk()
            ->assertSee('My warm read')
            ->assertDontSee('Their fast read');
    }

    public function test_the_inspector_no_longer_hosts_the_bench(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.studio.index'))
            ->assertOk()
            ->assertDontSee('Tuning bench')
            ->assertSee('per-preview knobs');
    }

    public function test_update_rejects_out_of_range_tuning(): void
    {
        $voice = Voice::create(['slug' => 'john', 'name' => 'John']);

        $this->actingAs($this->admin())
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'exaggeration' => 3,
            ])
            ->assertSessionHasErrors('exaggeration');
    }

    public function test_voice_create_command_stores_tuning_defaults(): void
    {
        $this->artisan('voice:create', [
            'name' => 'John',
            '--slug' => 'john',
            '--stability' => '0.8',
            '--style' => '0.3',
        ])->assertSuccessful();

        $settings = Voice::firstWhere('slug', 'john')->settings;
        $this->assertSame(0.8, $settings['stability']);
        $this->assertSame(0.3, $settings['style']);
    }

    /**
     * Seed is no longer surfaced in the UI (a fixed seed doesn't guarantee an
     * identical take), but the edit form keeps it in a hidden field so saving an
     * existing voice doesn't silently wipe its stored seed.
     */
    public function test_voice_edit_preserves_seed_without_surfacing_it(): void
    {
        $voice = Voice::create([
            'slug' => 'john',
            'name' => 'John',
            'settings' => ['seed' => 42],
        ]);

        $res = $this->actingAs($this->admin())->get(route('admin.voices.edit', $voice));

        $res->assertOk();
        $res->assertSee('name="seed"', false);   // preserved...
        $res->assertSee('value="42"', false);
        $res->assertDontSee('Default seed', false); // ...but not shown as a control
    }
}
