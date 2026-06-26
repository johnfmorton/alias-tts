<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The "Generate via Genblaze" Studio surface — dispatches an async run to the
 * Genblaze runner (HTTP-faked here), polls for provenance, and proxies the B2
 * audio through the app. The queue runs sync in tests, so the dispatched job
 * completes inline during the run() request.
 */
class GenblazeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tts.genblaze.runner_url' => 'http://runner.test']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_page_requires_admin(): void
    {
        $this->get(route('admin.studio.genblaze'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.studio.genblaze'))
            ->assertForbidden();
    }

    public function test_page_renders_runner_health(): void
    {
        Http::fake(['runner.test/health' => Http::response(['status' => 'ok', 'bespoken' => 'https://tts.ddev.site', 'b2' => true])]);
        Voice::create(['slug' => 'v', 'name' => 'V']);

        $this->actingAs($this->admin())
            ->get(route('admin.studio.genblaze'))
            ->assertOk()
            ->assertSee('Generate via Genblaze')
            ->assertSee('Runner up');
    }

    public function test_run_dispatches_and_status_returns_provenance_with_proxied_play_urls(): void
    {
        config(['filesystems.disks.s3.bucket' => 'johnfmorton']);
        Voice::create(['slug' => 'v', 'name' => 'V']);
        $provenance = [
            'final_url' => 'https://s3.us-west-001.backblazeb2.com/johnfmorton/genblaze/runs/x/assets/final.mp3',
            'final_manifest_hash' => 'abc123',
            'reroll_count' => 2,
            'chunks' => [
                ['position' => 0, 'attempts' => 3, 'trim_applied' => false,
                    'audio_url' => 'https://s3.us-west-001.backblazeb2.com/johnfmorton/genblaze/runs/x/assets/c0.wav',
                    'manifest_hash' => 'h0', 'verdict' => ['score' => 0.97, 'problems' => []]],
            ],
        ];
        Http::fake(['runner.test/run' => Http::response($provenance)]);

        // The run dispatches a job (runs inline on the sync queue) and returns a poll URL.
        $start = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.genblaze.run'), ['text' => 'Hello there.', 'voice' => 'v'])
            ->assertStatus(202)
            ->assertJsonStructure(['run_id', 'status', 'status_url']);

        // Polling the status surfaces the provenance, with app-proxied play URLs.
        $this->actingAs($this->admin())
            ->getJson($start->json('status_url'))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.reroll_count', 2)
            ->assertJsonPath('result.chunks.0.attempts', 3)
            ->assertJsonPath('result.final_url', $provenance['final_url'])
            ->assertJsonPath('result.final_play_url', route('admin.studio.genblaze.asset', ['key' => 'genblaze/runs/x/assets/final.mp3']))
            ->assertJsonPath('result.chunks.0.play_url', route('admin.studio.genblaze.asset', ['key' => 'genblaze/runs/x/assets/c0.wav']));

        Http::assertSent(fn ($req) => str_contains($req->url(), 'runner.test/run')
            && $req['text'] === 'Hello there.' && $req['voice'] === 'v');
    }

    public function test_run_rejects_missing_text_and_unknown_voice(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.genblaze.run'), ['voice' => 'v'])
            ->assertStatus(422);

        Voice::create(['slug' => 'v', 'name' => 'V']);
        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.genblaze.run'), ['text' => 'hi', 'voice' => 'nope'])
            ->assertStatus(422);
    }

    public function test_a_runner_failure_surfaces_through_the_status_poll(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);
        Http::fake(['runner.test/run' => Http::response('boom', 500)]);

        $start = $this->actingAs($this->admin())
            ->postJson(route('admin.studio.genblaze.run'), ['text' => 'hi', 'voice' => 'v'])
            ->assertStatus(202);

        $this->actingAs($this->admin())
            ->getJson($start->json('status_url'))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['error']);
    }

    public function test_status_404s_for_an_unknown_run(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('admin.studio.genblaze.status', 'no-such-run'))
            ->assertNotFound();
    }

    public function test_asset_proxies_a_genblaze_object_and_rejects_other_keys(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('genblaze/runs/x/assets/final.mp3', 'AUDIOBYTES');
        Storage::disk('s3')->put('speech/secret.mp3', 'PRIVATE');

        // A Genblaze provenance object is streamed through the app.
        $this->actingAs($this->admin())
            ->get(route('admin.studio.genblaze.asset', ['key' => 'genblaze/runs/x/assets/final.mp3']))
            ->assertOk();

        // Any non-genblaze key is refused — the proxy can't serve arbitrary bucket objects.
        $this->actingAs($this->admin())
            ->get(route('admin.studio.genblaze.asset', ['key' => 'speech/secret.mp3']))
            ->assertNotFound();

        // A missing genblaze key 404s rather than erroring.
        $this->actingAs($this->admin())
            ->get(route('admin.studio.genblaze.asset', ['key' => 'genblaze/runs/x/assets/missing.mp3']))
            ->assertNotFound();
    }
}
