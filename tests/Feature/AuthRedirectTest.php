<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A guest who hits a protected admin URL should be sent to login and, after
 * authenticating, returned to the URL they originally requested — not dumped on
 * the dashboard.
 */
class AuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_returned_to_the_originally_requested_url_after_login(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'password' => 'secret123']);

        // Guest hits a protected URL → bounced to login (intended URL stashed).
        $this->get('/admin/settings')->assertRedirect(route('login'));

        // After a successful login, land back on the originally requested URL.
        $this->post(route('login.submit'), ['email' => $admin->email, 'password' => 'secret123'])
            ->assertRedirect('/admin/settings');
    }

    public function test_direct_login_without_an_intended_url_lands_on_the_dashboard(): void
    {
        // Without the Genblaze runner configured, the dashboard is the landing page.
        config(['tts.genblaze.runner_url' => '']);
        $admin = User::factory()->create(['is_super_admin' => true, 'password' => 'secret123']);

        $this->post(route('login.submit'), ['email' => $admin->email, 'password' => 'secret123'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_login_lands_on_genblaze_when_the_runner_is_configured(): void
    {
        // With the headline feature live, a fresh login should land judges on it.
        config(['tts.genblaze.runner_url' => 'http://runner.test']);
        $admin = User::factory()->create(['is_super_admin' => true, 'password' => 'secret123']);

        $this->post(route('login.submit'), ['email' => $admin->email, 'password' => 'secret123'])
            ->assertRedirect(route('admin.studio.genblaze'));
    }
}
