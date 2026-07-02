<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthProviderTestTest extends TestCase
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

    public function test_provider_test_requires_authentication(): void
    {
        // Guests are bounced to login; the panel itself (incl. this billable action)
        // is open to any signed-in, active user — no longer SuperAdmin-gated.
        $this->post(route('admin.health.test.short'))->assertRedirect(route('login'));
    }

    public function test_short_test_returns_audio(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $res = $this->actingAs($this->admin())->post(route('admin.health.test.short'));

        $res->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $res->headers->get('content-type'));
        $this->assertNotEmpty($res->getContent());
    }

    public function test_short_test_without_a_voice_is_unprocessable(): void
    {
        Voice::query()->delete(); // drop the seeded default to hit the empty-state guard

        $this->actingAs($this->admin())
            ->post(route('admin.health.test.short'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'No voice configured — add a voice first.');
    }

    public function test_long_test_completes_inline_and_serves_audio(): void
    {
        // QUEUE_CONNECTION=sync in tests, so the queued job runs during dispatch.
        // One admin throughout: tests run under the CLICKING user's own dashboard
        // key, so only the initiator can poll their test.
        Voice::create(['slug' => 'v', 'name' => 'V']);
        $admin = $this->admin();

        $queued = $this->actingAs($admin)->post(route('admin.health.test.long'));
        $queued->assertOk()->assertJsonPath('status', 'completed');

        $id = $queued->json('id');

        $this->actingAs($admin)
            ->getJson(route('admin.health.test.status', ['id' => $id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $audio = $this->actingAs($admin)->get(route('admin.health.test.audio', ['id' => $id]));
        $audio->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $audio->headers->get('content-type'));
        $this->assertNotEmpty($audio->getContent());
    }

    public function test_the_dashboard_key_is_per_user(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);
        $alice = $this->admin();
        $bob = $this->admin();

        $this->actingAs($alice)->post(route('admin.health.test.short'))->assertOk();
        $this->actingAs($bob)->post(route('admin.health.test.short'))->assertOk();

        $keys = ApiKey::where('name', 'dashboard')->get();
        $this->assertCount(2, $keys);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id], $keys->pluck('user_id')->all());
    }

    public function test_another_user_cannot_poll_your_test_speech(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $id = $this->actingAs($this->admin())
            ->post(route('admin.health.test.long'))
            ->json('id');

        $this->actingAs($this->admin()) // a different user
            ->getJson(route('admin.health.test.status', ['id' => $id]))
            ->assertStatus(404);
    }

    public function test_long_test_accepts_a_specific_voice(): void
    {
        Voice::create(['slug' => 'alpha', 'name' => 'Alpha']);
        Voice::create(['slug' => 'beta', 'name' => 'Beta']);

        $this->actingAs($this->admin())
            ->post(route('admin.health.test.long'), ['voice' => 'beta'])
            ->assertOk()
            ->assertJsonPath('voice', 'Beta');
    }

    public function test_long_test_refuses_when_no_worker_is_running(): void
    {
        // Real (non-sync) queue with no worker heartbeat: fail fast instead of
        // enqueuing a job that would hang.
        config(['queue.default' => 'database']);
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $this->actingAs($this->admin())
            ->post(route('admin.health.test.long'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'No queue worker is running — start one (php artisan queue:work) before testing async generation.');
    }

    public function test_status_endpoint_rejects_an_unknown_id(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('admin.health.test.status', ['id' => 'does-not-exist']))
            ->assertStatus(404);
    }
}
