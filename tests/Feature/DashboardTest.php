<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Voice;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_landing_is_public(): void
    {
        $this->get('/')->assertOk()->assertSee('Bespoken TTS');
    }

    public function test_admin_requires_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_admin_can_log_in_and_out(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'password' => 'secret123']);

        $this->post(route('login.submit'), ['email' => $admin->email, 'password' => 'secret123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_can_create_toggle_and_delete_api_key(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.api-keys.store'), ['name' => 'test'])
            ->assertRedirect(route('admin.api-keys.index'))
            ->assertSessionHas('new_key');

        $key = ApiKey::firstWhere('name', 'test');
        $this->assertNotNull($key);
        $this->assertTrue($key->is_active);

        $this->actingAs($admin)->post(route('admin.api-keys.toggle', $key));
        $this->assertFalse($key->fresh()->is_active);

        $this->actingAs($admin)->delete(route('admin.api-keys.destroy', $key));
        $this->assertNull(ApiKey::find($key->id));
    }

    public function test_admin_can_upload_and_delete_voice(): void
    {
        $admin = $this->admin();
        $file = UploadedFile::fake()->createWithContent('ref.wav', $this->silentWav(0.3));

        $this->actingAs($admin)->post(route('admin.voices.store'), [
            'name' => 'Test Voice',
            'slug' => 'test-voice',
            'audio' => $file,
        ])->assertRedirect(route('admin.voices.index'));

        $voice = Voice::firstWhere('slug', 'test-voice');
        $this->assertNotNull($voice);
        $this->assertNotNull($voice->reference_audio_path);
        Storage::disk('local')->assertExists($voice->reference_audio_path);

        $this->actingAs($admin)->delete(route('admin.voices.destroy', $voice));
        $this->assertNull(Voice::firstWhere('slug', 'test-voice'));
    }

    public function test_admin_can_test_a_voice(): void
    {
        $admin = $this->admin();
        $voice = Voice::create(['slug' => 'tv', 'name' => 'TV']);

        $response = $this->actingAs($admin)->post(route('admin.voices.test', $voice));

        $response->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $response->headers->get('content-type'));
        $this->assertNotEmpty($response->getContent());
    }

    public function test_authenticated_user_is_sent_from_login_to_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->get('/login')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_landing_links_to_dashboard_for_authenticated_users(): void
    {
        // Guests are pointed at the login page...
        $this->get('/')->assertOk()->assertSee('/login');

        // ...an authenticated admin goes straight to the dashboard.
        $this->actingAs($this->admin())->get('/')->assertOk()->assertSee('/admin');
    }

    public function test_admin_can_update_a_voice(): void
    {
        $admin = $this->admin();
        $voice = app(VoiceService::class)->register('Old', 'old-slug', $this->silentWav(0.2), 'wav', false, 5);
        $oldRef = $voice->reference_audio_path;

        $this->actingAs($admin)->put(route('admin.voices.update', $voice), [
            'name' => 'New Name',
            'slug' => 'new-slug',
            'seed' => 9,
        ])->assertRedirect(route('admin.voices.index'));

        $voice->refresh();
        $this->assertSame('new-slug', $voice->slug);
        $this->assertSame('New Name', $voice->name);
        $this->assertSame(9, $voice->settings['seed']);
        $this->assertStringContainsString('new-slug', $voice->reference_audio_path);
        Storage::disk('local')->assertExists($voice->reference_audio_path);
        Storage::disk('local')->assertMissing($oldRef);
    }

    public function test_update_rejects_a_duplicate_slug(): void
    {
        $admin = $this->admin();
        Voice::create(['slug' => 'taken', 'name' => 'Taken']);
        $voice = Voice::create(['slug' => 'mine', 'name' => 'Mine']);

        $this->actingAs($admin)->put(route('admin.voices.update', $voice), [
            'name' => 'Mine',
            'slug' => 'taken',
        ])->assertSessionHasErrors('slug');

        $this->assertSame('mine', $voice->fresh()->slug);
    }

    public function test_admin_can_regenerate_an_api_key(): void
    {
        $admin = $this->admin();
        $key = ApiKey::generate('rotate-me', 100);
        $old = $key->key;

        $this->actingAs($admin)->post(route('admin.api-keys.regenerate', $key))
            ->assertRedirect(route('admin.api-keys.index'))
            ->assertSessionHas('new_key');

        $key->refresh();
        $this->assertNotSame($old, $key->key);
        $this->assertStringStartsWith('sk_', $key->key);
        $this->assertSame('rotate-me', $key->name);
        $this->assertSame(100, $key->rate_limit);

        // The old value no longer authenticates against the API.
        Voice::create(['slug' => 'rk', 'name' => 'RK']);
        $this->withHeaders(['xi-api-key' => $old])
            ->postJson('/v1/text-to-speech/rk', ['text' => 'hi'])
            ->assertStatus(401);
    }

    public function test_footer_shows_the_app_version_for_authenticated_users(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('v'.config('app.version'));
    }

    public function test_footer_version_links_to_its_github_release(): void
    {
        config(['app.source_url' => 'https://github.com/acme/repo']);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('https://github.com/acme/repo/releases/tag/v'.config('app.version'), escape: false);
    }

    public function test_footer_version_is_plain_text_when_source_url_is_empty(): void
    {
        config(['app.source_url' => '']);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('v'.config('app.version'))
            ->assertDontSee('/releases/tag/', escape: false);
    }

    public function test_footer_version_is_not_shown_to_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('v'.config('app.version'));
    }

    private function silentWav(float $seconds): string
    {
        $sampleRate = 44100;
        $channels = 1;
        $bits = 16;
        $numSamples = (int) ($sampleRate * $seconds);
        $dataSize = $numSamples * $channels * ($bits / 8);
        $byteRate = $sampleRate * $channels * ($bits / 8);
        $blockAlign = $channels * ($bits / 8);

        $header = 'RIFF'.pack('V', 36 + $dataSize).'WAVE';
        $header .= 'fmt '.pack('V', 16).pack('v', 1).pack('v', $channels)
            .pack('V', $sampleRate).pack('V', $byteRate)
            .pack('v', $blockAlign).pack('v', $bits);
        $header .= 'data'.pack('V', $dataSize);

        return $header.str_repeat("\x00", $dataSize);
    }
}
