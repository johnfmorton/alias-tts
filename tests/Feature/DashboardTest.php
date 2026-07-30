<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Voice;
use App\Services\Credit\CreditService;
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
        $this->get('/')->assertOk()->assertSee('Alias TTS');
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

    public function test_non_admin_can_view_the_dashboard(): void
    {
        // The panel is open to any signed-in, active user (only Users is SuperAdmin-gated).
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_health_link_is_hidden_from_a_non_admin_but_shown_to_an_admin(): void
    {
        $healthUrl = route('admin.health');

        // Nav (dropdown + mobile sheet) and the dashboard "System" tile both drop
        // the Health link for a regular user, matching the SuperAdmin route gate.
        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get('/admin')
            ->assertOk()
            ->assertDontSee($healthUrl);

        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertSee($healthUrl);
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

        $res = $this->actingAs($admin)->post(route('admin.voices.store'), [
            'name' => 'Test Voice',
            'slug' => 'test-voice',
            'audio' => $file,
            'clip_rights' => '1',
        ]);

        $voice = Voice::firstWhere('slug', 'test-voice');
        $this->assertNotNull($voice);
        // Creation lands on the edit page — tuning by ear lives there.
        $res->assertRedirect(route('admin.voices.edit', $voice));
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
        $key = ApiKey::generate('rotate-me', 100, $admin->id);
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

    public function test_admin_can_reset_the_dashboard_api_key(): void
    {
        $admin = $this->admin();
        $key = ApiKey::generate('connection', null, $admin->id);
        $old = $key->key;

        $this->actingAs($admin)->post(route('admin.dashboard.reset-key'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success');

        $key->refresh();
        $this->assertNotSame($old, $key->key);
        $this->assertStringStartsWith('sk_', $key->key);

        // The leaked value no longer authenticates against the API.
        Voice::create(['slug' => 'rk', 'name' => 'RK']);
        $this->withHeaders(['xi-api-key' => $old])
            ->postJson('/v1/text-to-speech/rk', ['text' => 'hi'])
            ->assertStatus(401);
    }

    public function test_reset_never_touches_an_unowned_or_shared_key(): void
    {
        // Keys are strictly per-user now: a user with no key of their own can't reset
        // (or silently claim) a legacy unowned key — it's left untouched.
        $admin = $this->admin();
        $legacy = ApiKey::generate('legacy', null, null);

        $this->actingAs($admin)->post(route('admin.dashboard.reset-key'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');

        $this->assertNull($legacy->fresh()->user_id);
    }

    public function test_reset_only_touches_the_current_users_own_key(): void
    {
        $alice = $this->admin();
        $bob = $this->admin();
        $aliceKey = ApiKey::generate('alice', null, $alice->id);
        $bobKey = ApiKey::generate('bob', null, $bob->id);
        $aliceOld = $aliceKey->key;
        $bobOld = $bobKey->key;

        $this->actingAs($bob)->post(route('admin.dashboard.reset-key'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame($aliceOld, $aliceKey->fresh()->key, "Alice's key must be untouched");
        $this->assertNotSame($bobOld, $bobKey->fresh()->key);
    }

    public function test_reset_with_no_key_shows_an_error(): void
    {
        $this->actingAs($this->admin())->post(route('admin.dashboard.reset-key'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_owned_active_for_returns_only_the_users_own_key(): void
    {
        $admin = $this->admin();
        ApiKey::generate('legacy', null, null);
        $owned = ApiKey::generate('owned', null, $admin->id);

        $this->assertSame($owned->id, ApiKey::ownedActiveFor($admin->id)->id);

        // A different user with no key of their own gets NOTHING — never the shared
        // or legacy key. (This is the cross-user leak we're preventing.)
        $other = $this->admin();
        $this->assertNull(ApiKey::ownedActiveFor($other->id));
    }

    public function test_dashboard_shows_the_resolved_key_and_a_reset_control(): void
    {
        $admin = $this->admin();
        $key = ApiKey::generate('connection', null, $admin->id);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee($key->key)
            ->assertSee('admin/reset-api-key', escape: false);
    }

    public function test_dashboard_shows_curl_examples_for_both_dialects(): void
    {
        $admin = $this->admin();
        $key = ApiKey::generate('connection', null, $admin->id);
        Voice::create(['slug' => 'curl-voice', 'name' => 'Curl']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('/v1/text-to-speech/', escape: false)
            ->assertSee('/v1/audio/speech', escape: false)
            ->assertSee('Authorization: Bearer')
            // The examples render with the user's real key and swap voices client-side.
            ->assertSee($key->key)
            ->assertSee('data-example-voice', escape: false)
            ->assertSee('data-voice-chip="curl-voice"', escape: false);
    }

    public function test_curl_examples_use_a_placeholder_when_no_api_key_exists(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('YOUR_API_KEY');
    }

    public function test_dashboard_shows_credit_balance_for_a_metered_user(): void
    {
        // Granting to an unlimited user flips them to metered — mirrors the admin grant flow.
        $user = User::factory()->create(['is_super_admin' => false]);
        app(CreditService::class)->grant($user, 5_000_000, $this->admin());

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Prepaid credit')
            ->assertSee('$5.00 available');
    }

    public function test_dashboard_hides_credit_for_an_unlimited_user(): void
    {
        // The default account has a NULL balance (unlimited) — no credit readout at all.
        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Prepaid credit');
    }

    public function test_out_of_credit_balance_is_still_shown_and_flagged(): void
    {
        // A metered-but-empty user still sees the row — flagged amber via text-warn.
        $user = User::factory()->create(['is_super_admin' => false]);
        app(CreditService::class)->grant($user, 0, $this->admin());

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('$0.00 available')
            ->assertSee('text-warn', escape: false);
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
