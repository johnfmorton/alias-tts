<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "Generate via Genblaze" Studio surface — proxies a run to the Genblaze
 * runner and renders its provenance. The runner itself is HTTP-faked here.
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

    public function test_run_proxies_provenance_from_the_runner(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);
        $provenance = [
            'final_url' => 'https://b2/final.mp3',
            'final_manifest_hash' => 'abc123',
            'reroll_count' => 2,
            'chunks' => [
                ['position' => 0, 'attempts' => 3, 'trim_applied' => false, 'audio_url' => 'https://b2/c0.wav', 'manifest_hash' => 'h0', 'verdict' => ['score' => 0.97, 'problems' => []]],
            ],
        ];
        Http::fake(['runner.test/run' => Http::response($provenance)]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.genblaze.run'), ['text' => 'Hello there.', 'voice' => 'v'])
            ->assertOk()
            ->assertJsonPath('reroll_count', 2)
            ->assertJsonPath('chunks.0.attempts', 3)
            ->assertJsonPath('final_url', 'https://b2/final.mp3');

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

    public function test_run_surfaces_a_runner_failure_as_502(): void
    {
        Voice::create(['slug' => 'v', 'name' => 'V']);
        Http::fake(['runner.test/run' => Http::response('boom', 500)]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.genblaze.run'), ['text' => 'hi', 'voice' => 'v'])
            ->assertStatus(502)
            ->assertJsonStructure(['message']);
    }
}
