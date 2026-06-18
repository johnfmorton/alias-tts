<?php

namespace Tests\Feature;

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

    public function test_provider_test_requires_admin(): void
    {
        $this->post(route('admin.health.test.short'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->post(route('admin.health.test.short'))
            ->assertForbidden();
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
        $this->actingAs($this->admin())
            ->post(route('admin.health.test.short'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'No voice configured — add a voice first.');
    }

    public function test_long_test_completes_inline_and_serves_audio(): void
    {
        // QUEUE_CONNECTION=sync in tests, so the queued job runs during dispatch.
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $queued = $this->actingAs($this->admin())->post(route('admin.health.test.long'));
        $queued->assertOk()->assertJsonPath('status', 'completed');

        $id = $queued->json('id');

        $this->actingAs($this->admin())
            ->getJson(route('admin.health.test.status', ['id' => $id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $audio = $this->actingAs($this->admin())->get(route('admin.health.test.audio', ['id' => $id]));
        $audio->assertOk();
        $this->assertStringStartsWith('audio/mpeg', (string) $audio->headers->get('content-type'));
        $this->assertNotEmpty($audio->getContent());
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
