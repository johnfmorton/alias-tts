<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthPageTest extends TestCase
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

    public function test_health_page_requires_login(): void
    {
        $this->get(route('admin.health'))->assertRedirect(route('login'));
    }

    public function test_health_page_visible_to_non_admin(): void
    {
        // The panel is open to any signed-in, active user (only Users is SuperAdmin-gated).
        $this->actingAs(User::factory()->create(['is_super_admin' => false]))
            ->get(route('admin.health'))
            ->assertOk();
    }

    public function test_the_shell_loads_fast_without_running_the_checks(): void
    {
        // The page paints instantly: it renders the report container + the live
        // provider test panel, but does NOT inline the (slow) check results —
        // those are fetched from the async results endpoint by the browser.
        $this->actingAs($this->admin())
            ->get(route('admin.health'))
            ->assertOk()
            ->assertSee('Live provider test')
            ->assertSee('data-health-report', false)
            ->assertDontSee('PHP version');
    }

    public function test_results_endpoint_requires_login(): void
    {
        $this->get(route('admin.health.results'))->assertRedirect(route('login'));
    }

    public function test_results_endpoint_renders_the_per_check_results(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.health.results'))
            ->assertOk()
            ->assertSee('PHP version')
            ->assertSee('Database')
            ->assertSee('Provider')
            ->assertSee('Queue')
            ->assertSee('Scheduler')
            ->assertSee('Run checks')
            ->assertSee('Run live checks');
    }

    public function test_deep_mode_renders_the_live_note(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.health.results', ['deep' => 1]))
            ->assertOk()
            ->assertSee('Live mode:');
    }
}
