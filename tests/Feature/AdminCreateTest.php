<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The bare `admin:create` must work on a fresh install that followed
 * .env.example (ADMIN_EMAIL / ADMIN_PASSWORD set, no arguments) — it used to
 * throw "Not enough arguments (missing: email)", a first-run dead end.
 */
class AdminCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_command_reads_admin_email_and_password_from_config(): void
    {
        config(['tts.admin.email' => 'boss@example.com', 'tts.admin.password' => 'sup3r-secret']);

        $this->artisan('admin:create')
            ->expectsOutputToContain('Created admin: boss@example.com')
            ->assertExitCode(0);

        $user = User::firstWhere('email', 'boss@example.com');
        $this->assertTrue($user->is_super_admin);
        $this->assertTrue(Hash::check('sup3r-secret', $user->password));
    }

    public function test_an_explicit_email_argument_overrides_the_env_default(): void
    {
        config(['tts.admin.email' => 'env@example.com', 'tts.admin.password' => 'pw']);

        $this->artisan('admin:create', ['email' => 'cli@example.com'])->assertExitCode(0);

        $this->assertNotNull(User::firstWhere('email', 'cli@example.com'));
        $this->assertNull(User::firstWhere('email', 'env@example.com'));
    }

    public function test_missing_email_fails_with_guidance_when_not_interactive(): void
    {
        config(['tts.admin.email' => null, 'tts.admin.password' => null]);

        $this->artisan('admin:create', ['--no-interaction' => true])
            ->expectsOutputToContain('set ADMIN_EMAIL in .env')
            ->assertExitCode(1);
    }

    public function test_missing_password_fails_with_guidance_when_not_interactive(): void
    {
        config(['tts.admin.email' => 'boss@example.com', 'tts.admin.password' => null]);

        $this->artisan('admin:create', ['--no-interaction' => true])
            ->expectsOutputToContain('set ADMIN_PASSWORD in .env')
            ->assertExitCode(1);
    }

    public function test_interactive_run_prompts_for_missing_email_and_password(): void
    {
        config(['tts.admin.email' => null, 'tts.admin.password' => null]);

        $this->artisan('admin:create')
            ->expectsQuestion('Admin email', 'typed@example.com')
            ->expectsQuestion('Password', 'typed-pw')
            ->expectsOutputToContain('Created admin: typed@example.com')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('typed-pw', User::firstWhere('email', 'typed@example.com')->password));
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->artisan('admin:create', ['email' => 'not-an-email', '--password' => 'pw'])
            ->expectsOutputToContain('not a valid email address')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_running_twice_updates_instead_of_duplicating(): void
    {
        config(['tts.admin.email' => 'boss@example.com', 'tts.admin.password' => 'first']);
        $this->artisan('admin:create')->assertExitCode(0);

        config(['tts.admin.password' => 'second']);
        $this->artisan('admin:create')
            ->expectsOutputToContain('Updated admin: boss@example.com')
            ->assertExitCode(0);

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('second', User::firstWhere('email', 'boss@example.com')->password));
    }
}
