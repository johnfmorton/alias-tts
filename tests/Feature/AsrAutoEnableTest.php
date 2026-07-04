<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSetting;
use App\Services\Asr\AsrAutoEnabler;
use App\Services\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "ASR transcript QA defaults on if available": the master switch ships off, but
 * the admin health surfaces flip it on the first time they see the Whisper
 * sidecar healthy — per user, since settings are per-user: the health page
 * enables it for the visiting user, `tts:doctor` for every user without an
 * explicit choice. Never when it's pinned in .env or the user already chose.
 * See {@see AsrAutoEnabler}.
 */
class AsrAutoEnableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tts.provider' => 'fake',
            'tts.storage_disk' => 'local',
            'tts.asr.enabled' => false,
            'tts.asr.url' => 'http://asr.test',
        ]);
        Storage::fake('local');
    }

    /**
     * A fresh enabler whose SettingsManager reflects the current DB. The manager
     * is a boot-time singleton that caches its overrides, so a row created mid-test
     * is only seen by a fresh instance — exactly what a real next request gets.
     */
    private function enabler(): AsrAutoEnabler
    {
        $this->app->forgetInstance(SettingsManager::class);

        return app(AsrAutoEnabler::class);
    }

    private function fakeSidecar(string $status = 'ok', int $http = 200): void
    {
        Http::fake([
            'asr.test/health' => Http::response(['status' => $status, 'model' => 'tiny'], $http),
            '*' => Http::response('', 200),
        ]);
    }

    /** Force a managed key's `locked` (pinned-in-.env) flag for the test. */
    private function setLocked(string $path, bool $locked): void
    {
        $managed = config('settings.managed');
        $managed[$path]['locked'] = $locked;
        config(['settings.managed' => $managed]);
    }

    private function row(User $user): ?UserSetting
    {
        return UserSetting::where('user_id', $user->id)->where('key', 'tts.asr.enabled')->first();
    }

    public function test_it_enables_for_the_user_when_the_sidecar_is_healthy_and_unset(): void
    {
        $this->fakeSidecar();
        $user = User::factory()->create();

        $this->assertTrue($this->enabler()->attempt($user->id));
        $this->assertTrue(config('tts.asr.enabled'));
        $this->assertTrue($this->row($user)->value); // persisted; the user can flip it off
    }

    public function test_it_is_a_no_op_when_already_enabled(): void
    {
        config(['tts.asr.enabled' => true]);
        Http::fake();
        $user = User::factory()->create();

        $this->assertFalse($this->enabler()->attempt($user->id));
        $this->assertNull($this->row($user)); // nothing written
        Http::assertNothingSent();            // and the sidecar was never probed
    }

    public function test_it_respects_a_user_who_saved_it_off(): void
    {
        $user = User::factory()->create();
        UserSetting::create(['user_id' => $user->id, 'key' => 'tts.asr.enabled', 'value' => false]);
        $this->fakeSidecar(); // healthy — but the user already chose

        $this->assertFalse($this->enabler()->attempt($user->id));
        $this->assertFalse((bool) config('tts.asr.enabled'));
        $this->assertFalse($this->row($user)->value); // unchanged
    }

    public function test_it_respects_an_env_pinned_switch(): void
    {
        $this->setLocked('tts.asr.enabled', true);
        $this->fakeSidecar();
        $user = User::factory()->create();

        $this->assertFalse($this->enabler()->attempt($user->id));
        $this->assertNull($this->row($user)); // .env is read-only; never written to DB
    }

    public function test_it_does_nothing_when_the_sidecar_is_unreachable(): void
    {
        $this->fakeSidecar(http: 500);
        $user = User::factory()->create();

        $this->assertFalse($this->enabler()->attempt($user->id));
        $this->assertFalse((bool) config('tts.asr.enabled'));
        $this->assertNull($this->row($user));
    }

    public function test_it_does_nothing_when_the_model_is_not_loaded(): void
    {
        $this->fakeSidecar(status: 'loading'); // up, but the model has not loaded
        $user = User::factory()->create();

        $this->assertFalse($this->enabler()->attempt($user->id));
        $this->assertNull($this->row($user));
    }

    public function test_the_health_page_auto_enables_for_the_visitor_and_shows_a_notice(): void
    {
        $this->fakeSidecar();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $bystander = User::factory()->create();

        // The auto-enable + notice live in the async results fragment (the checks
        // run there, not on the page shell) — that's what the browser fetches.
        $this->actingAs($admin)
            ->get(route('admin.health.results'))
            ->assertOk()
            ->assertSee('turned on automatically');

        $this->assertTrue($this->row($admin)->value);
        $this->assertNull($this->row($bystander)); // only the visitor, not everyone
    }

    public function test_tts_doctor_auto_enables_for_every_undecided_user(): void
    {
        $this->fakeSidecar();
        $undecided = User::factory()->create();
        $optedOut = User::factory()->create();
        UserSetting::create(['user_id' => $optedOut->id, 'key' => 'tts.asr.enabled', 'value' => false]);

        $this->artisan('tts:doctor')
            ->expectsOutputToContain('ASR transcript QA enabled automatically for 1 user(s)');

        $this->assertTrue($this->row($undecided)->value);
        $this->assertFalse($this->row($optedOut)->value); // their explicit "off" stands
    }
}
