<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1a of docs/STUDIO-TUNING.md: stability/style are first-class voice
 * defaults, settable from the dashboard and the console. (Phase 0 already wired
 * them to propagate to generation via VoiceSettingsResolver.)
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
                'stability' => 0.8,
                'style' => 0.3,
            ])
            ->assertRedirect(route('admin.voices.index'));

        $settings = $voice->refresh()->settings;
        $this->assertSame(0.8, $settings['stability']);
        $this->assertSame(0.3, $settings['style']);
    }

    public function test_clearing_tuning_fields_removes_them(): void
    {
        $voice = Voice::create([
            'slug' => 'john',
            'name' => 'John',
            'settings' => ['stability' => 0.8, 'style' => 0.3, 'seed' => 42],
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'seed' => 42, // keep the seed; drop stability/style
            ])
            ->assertRedirect(route('admin.voices.index'));

        $settings = $voice->refresh()->settings;
        $this->assertArrayNotHasKey('stability', $settings);
        $this->assertArrayNotHasKey('style', $settings);
        $this->assertSame(42, $settings['seed']);
    }

    public function test_update_rejects_out_of_range_tuning(): void
    {
        $voice = Voice::create(['slug' => 'john', 'name' => 'John']);

        $this->actingAs($this->admin())
            ->put(route('admin.voices.update', $voice), [
                'name' => 'John',
                'slug' => 'john',
                'stability' => 1.5,
            ])
            ->assertSessionHasErrors('stability');
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
}
