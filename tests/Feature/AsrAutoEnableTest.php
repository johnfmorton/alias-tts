<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Asr\AsrAutoEnabler;
use App\Services\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "ASR transcript QA defaults on if available": the master switch ships off, but
 * the admin health surfaces (health page / tts:doctor) flip it on the first time
 * they see the Whisper sidecar healthy — unless the admin has pinned it in .env
 * or saved a choice. See {@see AsrAutoEnabler}.
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

    public function test_it_enables_when_the_sidecar_is_healthy_and_unset(): void
    {
        $this->fakeSidecar();

        $this->assertTrue($this->enabler()->attempt());
        $this->assertTrue(config('tts.asr.enabled'));
        $this->assertTrue(Setting::find('tts.asr.enabled')->value); // persisted; admin can flip it off
    }

    public function test_it_is_a_no_op_when_already_enabled(): void
    {
        config(['tts.asr.enabled' => true]);
        Http::fake();

        $this->assertFalse($this->enabler()->attempt());
        $this->assertNull(Setting::find('tts.asr.enabled')); // nothing written
        Http::assertNothingSent();                           // and the sidecar was never probed
    }

    public function test_it_respects_an_admin_who_saved_it_off(): void
    {
        Setting::create(['key' => 'tts.asr.enabled', 'value' => false]);
        $this->fakeSidecar(); // healthy — but the admin already chose

        $this->assertFalse($this->enabler()->attempt());
        $this->assertFalse((bool) config('tts.asr.enabled'));
        $this->assertFalse(Setting::find('tts.asr.enabled')->value); // unchanged
    }

    public function test_it_respects_an_env_pinned_switch(): void
    {
        $this->setLocked('tts.asr.enabled', true);
        $this->fakeSidecar();

        $this->assertFalse($this->enabler()->attempt());
        $this->assertNull(Setting::find('tts.asr.enabled')); // .env is read-only; never written to DB
    }

    public function test_it_does_nothing_when_the_sidecar_is_unreachable(): void
    {
        $this->fakeSidecar(http: 500);

        $this->assertFalse($this->enabler()->attempt());
        $this->assertFalse((bool) config('tts.asr.enabled'));
        $this->assertNull(Setting::find('tts.asr.enabled'));
    }

    public function test_it_does_nothing_when_the_model_is_not_loaded(): void
    {
        $this->fakeSidecar(status: 'loading'); // up, but the model has not loaded

        $this->assertFalse($this->enabler()->attempt());
        $this->assertNull(Setting::find('tts.asr.enabled'));
    }

    public function test_the_health_page_auto_enables_and_shows_a_notice(): void
    {
        $this->fakeSidecar();
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.health'))
            ->assertOk()
            ->assertSee('turned on automatically');

        $this->assertTrue(Setting::find('tts.asr.enabled')->value);
    }

    public function test_tts_doctor_auto_enables(): void
    {
        $this->fakeSidecar();

        $this->artisan('tts:doctor')
            ->expectsOutputToContain('ASR transcript QA enabled automatically');

        $this->assertTrue(Setting::find('tts.asr.enabled')->value);
    }
}
