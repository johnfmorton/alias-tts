<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function otp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    /** Enable 2FA (pending secret + confirmed) on a user for challenge tests. */
    private function enroll(User $user): string
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
        ])->save();

        return $secret;
    }

    public function test_enabling_creates_a_pending_secret(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.account.2fa.enable'))->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorPending());
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_confirming_with_a_valid_code_enables_and_shows_recovery_codes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('admin.account.2fa.enable'));
        $secret = $user->refresh()->two_factor_secret;

        $this->actingAs($user)
            ->post(route('admin.account.2fa.confirm'), ['code' => $this->otp($secret)])
            ->assertSessionHas('recovery_codes');

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_confirming_with_a_bad_code_stays_pending(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('admin.account.2fa.enable'));

        $this->actingAs($user)
            ->post(route('admin.account.2fa.confirm'), ['code' => '000000'])
            ->assertSessionHas('error');

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    public function test_disabling_confirmed_2fa_requires_the_password(): void
    {
        $user = User::factory()->create(['password' => 'pw']);
        $this->enroll($user);

        $this->actingAs($user)->delete(route('admin.account.2fa.disable'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());

        $this->actingAs($user)->delete(route('admin.account.2fa.disable'), ['password' => 'pw'])->assertRedirect();
        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    public function test_login_with_2fa_redirects_to_the_challenge_without_authenticating(): void
    {
        $user = User::factory()->create(['password' => 'pw']);
        $this->enroll($user);

        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'pw'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_challenge_with_a_valid_totp_completes_login(): void
    {
        $user = User::factory()->create(['password' => 'pw']);
        $secret = $this->enroll($user);

        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'pw']);

        $this->post(route('two-factor.verify'), ['code' => $this->otp($secret)])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_challenge_with_a_recovery_code_logs_in_and_consumes_it(): void
    {
        $user = User::factory()->create(['password' => 'pw']);
        $this->enroll($user);

        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'pw']);

        $this->post(route('two-factor.verify'), ['code' => 'aaaa-bbbb'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('aaaa-bbbb', $user->refresh()->two_factor_recovery_codes);
    }

    public function test_challenge_page_redirects_to_login_without_a_pending_2fa(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_login_without_2fa_authenticates_normally(): void
    {
        $user = User::factory()->create(['password' => 'pw']);

        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'pw']);
        $this->assertAuthenticatedAs($user);
    }
}
