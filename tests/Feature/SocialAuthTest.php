<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-id',
            'services.google.client_secret' => 'test-secret',
        ]);
    }

    private function mockSocialite(string $id = 'gid-1', string $email = 'amara@example.com'): void
    {
        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($id);
        $user->shouldReceive('getName')->andReturn('Amara');
        $user->shouldReceive('getEmail')->andReturn($email);
        $user->shouldReceive('getAvatar')->andReturn(null);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->andReturn($provider);
    }

    public function test_unknown_provider_404s(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('oauth.redirect', 'twitter'))
            ->assertNotFound();
    }

    public function test_an_unconfigured_provider_is_dormant(): void
    {
        // No creds set → the button/route refuses gracefully instead of erroring.
        $this->actingAs(User::factory()->create())
            ->get(route('oauth.redirect', 'google'))
            ->assertRedirect(route('admin.account.index'));
    }

    public function test_a_signed_in_user_connects_a_provider(): void
    {
        $this->configureGoogle();
        $this->mockSocialite('gid-99');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['oauth.intent' => 'connect'])
            ->get(route('oauth.callback', 'google'))
            ->assertRedirect(route('admin.account.index'));

        $this->assertDatabaseHas('connected_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'gid-99',
        ]);
    }

    public function test_connecting_an_identity_already_linked_elsewhere_is_refused(): void
    {
        $this->configureGoogle();
        $this->mockSocialite('gid-taken');
        $other = User::factory()->create();
        ConnectedAccount::create(['user_id' => $other->id, 'provider' => 'google', 'provider_id' => 'gid-taken']);

        $me = User::factory()->create();
        $this->actingAs($me)
            ->withSession(['oauth.intent' => 'connect'])
            ->get(route('oauth.callback', 'google'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('connected_accounts', ['user_id' => $me->id, 'provider' => 'google']);
    }

    public function test_a_guest_signs_in_through_a_linked_provider(): void
    {
        $this->configureGoogle();
        $this->mockSocialite('gid-42');
        $user = User::factory()->create();
        ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'gid-42']);

        $this->withSession(['oauth.intent' => 'login'])
            ->get(route('oauth.callback', 'google'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_sso_login_still_requires_the_2fa_challenge(): void
    {
        $this->configureGoogle();
        $this->mockSocialite('gid-2fa');
        $user = User::factory()->create();
        ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'gid-2fa']);
        $user->forceFill([
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaa-bbbb'],
        ])->save();

        $this->withSession(['oauth.intent' => 'login'])
            ->get(route('oauth.callback', 'google'))
            ->assertRedirect(route('two-factor.challenge'));

        // SSO proved the identity but the local second factor still gates the session.
        $this->assertGuest();
    }

    public function test_connecting_a_provider_requires_the_password(): void
    {
        $this->configureGoogle();
        $user = User::factory()->create(['password' => 'pw']);

        $this->actingAs($user)
            ->post(route('admin.account.connections.connect', 'google'))
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->post(route('admin.account.connections.connect', 'google'), ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    public function test_sso_login_with_no_linked_account_is_rejected(): void
    {
        $this->configureGoogle();
        $this->mockSocialite('gid-unknown');

        $this->withSession(['oauth.intent' => 'login'])
            ->get(route('oauth.callback', 'google'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_disconnect_removes_the_link(): void
    {
        $user = User::factory()->create();
        ConnectedAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'gid-1']);

        $this->actingAs($user)
            ->delete(route('admin.account.connections.disconnect', 'google'))
            ->assertRedirect(route('admin.account.index'));

        $this->assertDatabaseMissing('connected_accounts', ['user_id' => $user->id, 'provider' => 'google']);
    }
}
